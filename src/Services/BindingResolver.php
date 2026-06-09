<?php

namespace Blemli\FilamentMouseless\Services;

use Blemli\FilamentMouseless\Models\UserSetting;

class BindingResolver
{
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
        $disabled = (array) config('mouseless.disabled_actions', []);

        $settings = $userId ? UserSetting::forUser($userId) : null;
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
