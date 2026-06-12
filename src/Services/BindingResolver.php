<?php

namespace Blemli\FilamentMouseless\Services;

use Blemli\FilamentMouseless\Models\UserSetting;
use Blemli\FilamentMouseless\Support\Shield;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class BindingResolver
{
    private static bool $loggedMissingTable = false;

    /** @var array<int, array> Request-scoped memo — flushed after every layout mutation. */
    private array $resolved = [];

    public function __construct(protected PresetRegistry $registry) {}

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
        $configDefault = config('mouseless.default_preset') ?: 'english-default';

        // No explicit selection → follow the UI locale; switching the app
        // language switches the default preset automatically.
        $localeDefault = $this->registry->builtInForLocale(app()->getLocale())['slug'] ?? $configDefault;
        $activeSlug = $settings?->active_preset_slug ?: $localeDefault;

        $preset = $this->registry->find($activeSlug, $userId)
            ?? $this->registry->find($localeDefault, $userId)
            ?? $this->registry->find($configDefault, $userId);

        $disabled = array_values(array_unique(array_merge(
            (array) ($preset['disabled_actions'] ?? []),
            (array) config('mouseless.disabled_actions', []),
        )));

        $bindings = array_filter($preset['bindings'] ?? [], fn ($v) => $v !== null);

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
        return (array) config('mouseless.reserved_keys', []);
    }

    public function listModeIgnoreSelectors(): array
    {
        return (array) config('mouseless.list_mode.ignore_in', []);
    }
}
