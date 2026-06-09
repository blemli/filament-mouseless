<?php

namespace Blemli\FilamentMouseless\Services;

use Blemli\FilamentMouseless\Models\UserSetting;
use Blemli\FilamentMouseless\Support\Shield;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

class BindingResolver
{
    private static bool $loggedMissingTable = false;

    public function __construct(protected PresetRegistry $registry) {}

    /**
     * Build the effective binding map for a user.
     *
     * @return array{
     *   bindings: array<string, string>,
     *   preset: ?array,
     *   overrides: array<string, string>,
     *   disabled: array<int, string>,
     * }
     */
    public function forUser(?int $userId): array
    {
        // Shield master switch: when the user lacks `mouseless_use`, hand back
        // an empty binding map so the JS engine and help overlay no-op.
        if ($userId && ! Shield::userMayUse()) {
            return ['bindings' => [], 'preset' => null, 'overrides' => [], 'disabled' => []];
        }

        $disabled = (array) config('mouseless.disabled_actions', []);

        $settings = $userId ? $this->loadSettings($userId) : null;
        $configDefault = config('mouseless.default_preset') ?: 'english-default';

        // If the user hasn't picked a preset and the configured default doesn't
        // match the current UI locale, prefer a locale-matched built-in preset.
        $localeDefault = $this->presetForLocale(app()->getLocale()) ?? $configDefault;
        $fallbackSlug = $configDefault;
        $activeSlug = $settings?->active_preset_slug ?: $localeDefault;

        $preset = $this->registry->find($activeSlug, $userId)
            ?? $this->registry->find($localeDefault, $userId)
            ?? $this->registry->find($fallbackSlug, $userId);

        $base = $preset['bindings'] ?? [];
        $overrides = $settings?->overrides ?? [];

        $bindings = array_merge($base, array_filter($overrides, fn ($v) => $v !== null));

        foreach ($disabled as $actionId) {
            unset($bindings[$actionId]);
        }

        return [
            'bindings' => $bindings,
            'preset' => $preset,
            'overrides' => $overrides,
            'disabled' => array_values($disabled),
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

    /**
     * Find a built-in preset matching the given locale (e.g. 'de' → 'german-default').
     */
    protected function presetForLocale(string $locale): ?string
    {
        foreach ($this->registry->builtIn() as $slug => $preset) {
            if (($preset['locale'] ?? null) === $locale) {
                return $slug;
            }
        }

        return null;
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
