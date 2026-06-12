<?php

namespace Blemli\FilamentMouseless\Support;

use Illuminate\Support\Facades\Validator;

/** JSON import/export of presets — shared by the preset-selector widget and (later) the admin preset editor. */
class PresetTransfer
{
    private const ACTION_ID_PATTERN = '/^[a-z]+(\.[a-z0-9-]+)+$/';

    public static function exportJson(array $preset): string
    {
        return json_encode([
            'name' => $preset['name'] ?? 'mouseless',
            'description' => $preset['description'] ?? null,
            'locale' => $preset['locale'] ?? app()->getLocale(),
            'version' => $preset['version'] ?? '1.0',
            'bindings' => (array) ($preset['bindings'] ?? []),
            'disabled_actions' => array_values((array) ($preset['disabled_actions'] ?? [])),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Validate an import payload. Returns a list of error strings (empty =
     * valid) and fills $data with the decoded payload.
     *
     * @return array<int, string>
     */
    public static function validate(string $json, ?array &$data): array
    {
        $data = null;

        if (strlen($json) > 65536) {
            return [__('filament-mouseless::mouseless.table.import.too_large')];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [__('filament-mouseless::mouseless.table.import.not_json')];
        }

        $validator = Validator::make($decoded, [
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'locale' => ['nullable', 'string', 'min:2', 'max:8'],
            'version' => ['nullable', 'string', 'max:20'],
            'bindings' => ['required', 'array', 'max:200'],
            'disabled_actions' => ['nullable', 'array', 'max:200'],
            'disabled_actions.*' => ['string', 'regex:' . self::ACTION_ID_PATTERN],
        ]);

        if ($validator->fails()) {
            return $validator->errors()->all();
        }

        $errors = [];
        $reserved = array_map(Keys::normalize(...), (array) config('mouseless.reserved_keys', []));
        $seen = [];

        foreach ($decoded['bindings'] as $actionId => $combo) {
            if (! is_string($actionId) || ! preg_match(self::ACTION_ID_PATTERN, $actionId)) {
                $errors[] = __('filament-mouseless::mouseless.table.import.bad_action', ['action' => (string) $actionId]);

                continue;
            }

            if ($combo === null) {
                continue; // Explicitly unbound.
            }

            if (! is_string($combo) || ! Keys::isValid($combo)) {
                $errors[] = __('filament-mouseless::mouseless.table.import.bad_combo', ['action' => $actionId]);

                continue;
            }

            $normalized = Keys::normalize($combo);

            if (in_array($normalized, $reserved, true)) {
                $errors[] = __('filament-mouseless::mouseless.profile.reserved_key', ['key' => $combo]);

                continue;
            }

            if (isset($seen[$normalized])) {
                $errors[] = __('filament-mouseless::mouseless.table.import.duplicate_combo', [
                    'key' => $combo,
                    'first' => $seen[$normalized],
                    'second' => $actionId,
                ]);

                continue;
            }

            $seen[$normalized] = $actionId;
        }

        // Protected bindings must survive the import unchanged.
        foreach (ActionMeta::PROTECTED_BINDINGS as $actionId => $requiredCombo) {
            if (
                (array_key_exists($actionId, $decoded['bindings']) && Keys::normalize($decoded['bindings'][$actionId]) !== $requiredCombo)
                || in_array($actionId, (array) ($decoded['disabled_actions'] ?? []), true)
            ) {
                $errors[] = __('filament-mouseless::mouseless.table.import.protected');
            }
        }

        if ($errors === []) {
            $data = $decoded;
        }

        return $errors;
    }
}
