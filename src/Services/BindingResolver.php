<?php

namespace Blemli\FilamentMouseless\Services;

use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\UserSetting;
use Blemli\FilamentMouseless\Support\Keys;
use Blemli\FilamentMouseless\Support\Shield;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class BindingResolver
{
    private static bool $loggedMissingTable = false;

    /** @var array<int, array> Request-scoped memo — flushed after every layout mutation. */
    private array $resolved = [];

    public function __construct(
        protected PresetRegistry $registry,
        protected CustomActionRegistry $customActions,
    ) {}

    public function flush(): void
    {
        $this->resolved = [];
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
        $configDefault = FilamentMouselessPlugin::defaultPresetSlug() ?: 'english-default';

        // No explicit selection → follow the UI locale; switching the app
        // language switches the default preset automatically.
        $localeDefault = $this->registry->builtInForLocale(app()->getLocale())['slug'] ?? $configDefault;
        $activeSlug = $settings?->active_preset_slug ?: $localeDefault;

        $preset = $this->registry->find($activeSlug, $userId)
            ?? $this->registry->find($localeDefault, $userId)
            ?? $this->registry->find($configDefault, $userId);

        $disabled = array_values(array_unique(array_merge(
            (array) ($preset['disabled_actions'] ?? []),
            FilamentMouselessPlugin::disabledActionIds(),
        )));

        // Merge the parent-preset chain underneath this preset so bindings added
        // to a built-in preset *after* a user forked it still reach the fork
        // (forks snapshot the full binding set, so they miss later additions).
        // Walk child → ancestor, letting nearer presets win; an explicit null
        // (a user-unbound action) survives because nulls are filtered only after
        // the merge is complete.
        $presetBindings = [];
        $cursor = $preset;
        $seen = [];
        while ($cursor !== null) {
            $presetBindings = array_merge((array) ($cursor['bindings'] ?? []), $presetBindings);
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
        foreach ($this->customActions->managedDefaults() as $id => $combo) {
            if (! array_key_exists($id, $presetBindings)) {
                $bindings[$id] = $combo;
            }
        }

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
        return FilamentMouselessPlugin::listModeIgnoreSelectors();
    }
}
