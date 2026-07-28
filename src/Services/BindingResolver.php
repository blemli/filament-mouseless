<?php

namespace Blemli\FilamentMouseless\Services;

use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\AdminOverride;
use Blemli\FilamentMouseless\Models\Statistic;
use Blemli\FilamentMouseless\Models\UserSetting;
use Blemli\FilamentMouseless\Support\Keys;
use Blemli\FilamentMouseless\Support\Shield;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class BindingResolver
{
    private static bool $loggedMissingTable = false;

    /** @var array<int, array> Request-scoped memo — flushed after every layout mutation. */
    private array $resolved = [];

    /** @var array<int, array<string, array>> Per-user outcome of the admin-override layer (for display). */
    private array $adminDecisions = [];

    /** @var array<int, array<string, string>> Per-user first-keyboard-use dates memo. */
    private array $firstUses = [];

    public function __construct(
        protected PresetRegistry $registry,
        protected CustomActionRegistry $customActions,
    ) {}

    public function flush(): void
    {
        $this->resolved = [];
        $this->adminDecisions = [];
        $this->firstUses = [];
    }

    /**
     * Build the effective binding map for a user. The user's whole layout
     * lives in their preset — a `null` binding means "unbound", and the
     * preset's own `disabled_actions` (plus config) are excluded entirely.
     *
     * Memoized per user: the table evaluates dozens of closures per render
     * that all start from this resolution.
     *
     * @return array{
     *   bindings: array<string, string>,
     *   preset: ?array,
     *   disabled: array<int, string>,
     * }
     */
    public function forUser(?int $userId): array
    {
        return $this->resolved[$userId ?? 0] ??= $this->resolveForUser($userId);
    }

    protected function resolveForUser(?int $userId): array
    {
        // Shield master switch: when the user lacks `mouseless_use`, hand back
        // an empty binding map so the JS engine and help overlay no-op.
        if ($userId && ! Shield::userMayUse()) {
            return ['bindings' => [], 'preset' => null, 'disabled' => []];
        }

        $settings = $userId ? $this->loadSettings($userId) : null;
        // The admin page's Standard-Preset choice wins over the config file.
        $configDefault = AdminOverride::defaultPresetSlug()
            ?: (config('mouseless.default_preset') ?: 'english-default');

        // No explicit selection → follow the UI locale; switching the app
        // language switches the default preset automatically.
        $localeDefault = $this->registry->builtInForLocale(app()->getLocale())['slug'] ?? $configDefault;
        $activeSlug = $settings?->active_preset_slug ?: $localeDefault;

        $preset = $this->registry->find($activeSlug, $userId)
            ?? $this->registry->find($localeDefault, $userId)
            ?? $this->registry->find($configDefault, $userId);

        // Admin disables are hard for everyone, like the config ones.
        $disabled = array_values(array_unique(array_merge(
            (array) ($preset['disabled_actions'] ?? []),
            (array) config('mouseless.disabled_actions', []),
            AdminOverride::disabledActions(),
        )));

        // Merge the parent-preset chain underneath this preset so bindings added
        // to a built-in preset *after* a user forked it still reach the fork
        // (forks snapshot the full binding set, so they miss later additions).
        // Walk child → ancestor, letting nearer presets win; an explicit null
        // (a user-unbound action) survives because nulls are filtered only after
        // the merge is complete. The ancestors-only merge is kept separately:
        // comparing a fork's value against it tells apart the user's explicit
        // rebinds from snapshot entries they never touched.
        $presetBindings = [];
        $ancestorBindings = [];
        $cursor = $preset;
        $seen = [];
        $isPresetItself = true;
        while ($cursor !== null) {
            $presetBindings = array_merge((array) ($cursor['bindings'] ?? []), $presetBindings);
            if (! $isPresetItself) {
                $ancestorBindings = array_merge((array) ($cursor['bindings'] ?? []), $ancestorBindings);
            }
            $isPresetItself = false;
            $parentSlug = $cursor['parent_slug'] ?? null;
            if ($parentSlug === null || isset($seen[$parentSlug])) {
                break;
            }
            $seen[$parentSlug] = true;
            $cursor = $this->registry->find($parentSlug, $userId);
        }

        $bindings = array_filter($presetBindings, fn ($v) => $v !== null);

        // Managed custom actions fall back to their code-defined combo, but
        // only when the preset says nothing about them. An explicit null in the
        // preset means the user unbound it — leave it unbound. A non-null value
        // is an override and already sits in $bindings above.
        $customDefaults = $this->customActions->managedDefaults();
        foreach ($customDefaults as $id => $combo) {
            if (! array_key_exists($id, $presetBindings)) {
                $bindings[$id] = $combo;
            }
        }

        $bindings = $this->applyAdminOverrides(
            $userId,
            $bindings,
            $preset,
            $ancestorBindings,
            $customDefaults,
        );

        foreach ($disabled as $actionId) {
            unset($bindings[$actionId]);
        }

        return [
            'bindings' => $bindings,
            'preset' => $preset,
            'disabled' => $disabled,
        ];
    }

    /**
     * Layer the admin's default overrides (AdminOverride rows) over a user's
     * resolution. Per overridden action, strongest claim wins:
     *
     *   1. The user's own explicit rebind (fork value ≠ what its parent chain
     *      says) — admin rebinds never touch it.
     *   2. A key the user actively used before the admin changed it — they
     *      keep the old key ("grandfathered"); requires ->statistics().
     *   3. The admin's new default — everyone else.
     *
     * A duplicate guard runs afterwards: when a kept/explicit/built-in key
     * collides with a freshly applied admin default, the admin default is the
     * one dropped (to unbound) — a key under someone's fingers never silently
     * changes meaning.
     *
     * @param  array<string, string>  $bindings  pre-admin effective map
     * @param  array<string, ?string>  $ancestorBindings
     * @param  array<string, string>  $customDefaults
     * @return array<string, string>
     */
    protected function applyAdminOverrides(?int $userId, array $bindings, ?array $preset, array $ancestorBindings, array $customDefaults): array
    {
        $decisions = [];
        $rebinding = array_filter(AdminOverride::current(), AdminOverride::rebinds(...));

        if ($rebinding === []) {
            $this->adminDecisions[$userId ?? 0] = [];

            return $bindings;
        }

        $ownsPreset = $userId !== null && (($preset['owner_user_id'] ?? null) === $userId);

        // A binding is the user's own choice when their fork stores a value
        // different from what the parent chain (or a custom action's code
        // default) would give. Snapshot entries equal to the inherited value
        // carry no intent and stay overridable.
        $isExplicit = function (string $actionId) use ($ownsPreset, $preset, $ancestorBindings, $customDefaults): bool {
            if (! $ownsPreset) {
                return false;
            }

            $own = (array) ($preset['bindings'] ?? []);
            if (! array_key_exists($actionId, $own)) {
                return false;
            }

            $inherited = $ancestorBindings[$actionId] ?? $customDefaults[$actionId] ?? null;

            return Keys::normalize($own[$actionId]) !== Keys::normalize($inherited);
        };

        // Claim strength for the duplicate guard. Freshly applied admin
        // defaults ('applied') are weakest on purpose: on a collision with a
        // key someone already has (explicit, kept, or plain built-in), the
        // admin default loses.
        $strength = [];

        foreach ($rebinding as $actionId => $override) {
            if ($isExplicit($actionId)) {
                $decisions[$actionId] = ['state' => 'explicit', 'default' => $override['combo']];

                continue;
            }

            // Grandfathering: keyboard usage that predates the change keeps
            // the old key. Usage between the first override and a later combo
            // change means the user had adopted the previous override combo.
            if ($userId !== null && FilamentMouselessPlugin::statisticsEnabled()) {
                $firstUse = $this->firstUseDates($userId)[$actionId] ?? null;

                if ($firstUse !== null && $override['first_set_at'] !== null && $firstUse <= $override['first_set_at']) {
                    $decisions[$actionId] = [
                        'state' => 'kept',
                        'combo' => $bindings[$actionId] ?? null,
                        'default' => $override['combo'],
                    ];
                    $strength[$actionId] = 2;

                    continue;
                }

                if ($firstUse !== null
                    && $override['previous'] !== null
                    && $override['first_set_at'] !== null
                    && $override['combo_set_at'] !== null
                    && $firstUse > $override['first_set_at']
                    && $firstUse <= $override['combo_set_at']) {
                    $bindings[$actionId] = $override['previous'];
                    $decisions[$actionId] = [
                        'state' => 'kept',
                        'combo' => $override['previous'],
                        'default' => $override['combo'],
                    ];
                    $strength[$actionId] = 2;

                    continue;
                }
            }

            if ($override['combo'] === null) {
                unset($bindings[$actionId]);
            } else {
                $bindings[$actionId] = $override['combo'];
            }

            $decisions[$actionId] = ['state' => 'applied', 'combo' => $override['combo']];
            $strength[$actionId] = 1;
        }

        // Duplicate guard — only arbitrates collisions the admin layer is
        // involved in; pre-existing preset duplicates are left alone.
        $byCombo = [];
        foreach ($bindings as $actionId => $combo) {
            $byCombo[Keys::normalize($combo) ?? $combo][] = $actionId;
        }

        foreach ($byCombo as $actions) {
            if (count($actions) < 2 || array_intersect($actions, array_keys($strength)) === []) {
                continue;
            }

            $rank = fn (string $actionId): int => $strength[$actionId]
                ?? ($isExplicit($actionId) ? 4 : 3);

            usort($actions, fn (string $a, string $b): int => $rank($b) <=> $rank($a));

            foreach (array_slice($actions, 1) as $loser) {
                unset($bindings[$loser]);
                $decisions[$loser] = [
                    'state' => 'conflict',
                    'default' => $rebinding[$loser]['combo'] ?? null,
                ];
            }
        }

        $this->adminDecisions[$userId ?? 0] = $decisions;

        return $bindings;
    }

    /**
     * How the admin-override layer treated each overridden action for this
     * user — 'explicit' (user binding won), 'kept' (grandfathered old key),
     * 'applied' (admin default active) or 'conflict' (dropped to unbound).
     * Display-only; resolves the user first so the memo is warm.
     *
     * @return array<string, array{state: string, combo?: ?string, default?: ?string}>
     */
    public function adminOverridesFor(?int $userId): array
    {
        $this->forUser($userId);

        return $this->adminDecisions[$userId ?? 0] ?? [];
    }

    /**
     * @return array<string, string> action => Y-m-d of first keyboard use
     */
    protected function firstUseDates(int $userId): array
    {
        return $this->firstUses[$userId] ??= (function () use ($userId): array {
            try {
                if (! Schema::hasTable('mouseless_statistics')) {
                    return [];
                }

                return Statistic::firstKeyboardUseDates($userId);
            } catch (\Throwable) {
                return [];
            }
        })();
    }

    /**
     * Read the user's settings row, swallowing the "table missing" error and
     * logging a one-shot hint pointing at ->stateless(). Other QueryExceptions
     * are re-thrown — they indicate a real DB problem worth surfacing.
     */
    protected function loadSettings(int $userId): ?UserSetting
    {
        try {
            return UserSetting::forUser($userId);
        } catch (QueryException $e) {
            if (! $this->isMissingTableError($e)) {
                throw $e;
            }

            if (! self::$loggedMissingTable) {
                self::$loggedMissingTable = true;
                Log::error('[filament-mouseless] The mouseless_user_settings table is missing. To enable per-user shortcuts, run `php artisan vendor:publish --tag=filament-mouseless-migrations` and then `php artisan migrate`. Otherwise call ->stateless() on the plugin in your Panel provider to disable per-user shortcuts.');
            }

            return null;
        }
    }

    protected function isMissingTableError(QueryException $e): bool
    {
        // SQLSTATE 42S02 = MySQL/SQLServer base table not found.
        // SQLSTATE 42P01 = Postgres undefined_table.
        // SQLite reports HY000 with "no such table" in the message.
        $sqlState = $e->getCode();
        if (in_array($sqlState, ['42S02', '42P01'], true)) {
            return true;
        }

        return str_contains($e->getMessage(), 'no such table')
            || str_contains($e->getMessage(), 'does not exist');
    }

    public function reservedKeys(): array
    {
        return Keys::reservedKeys();
    }

    public function listModeIgnoreSelectors(): array
    {
        return (array) config('mouseless.list_mode.ignore_in', []);
    }
}
