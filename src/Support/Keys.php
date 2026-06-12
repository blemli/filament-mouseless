<?php

namespace Blemli\FilamentMouseless\Support;

class Keys
{
    /** Mirrors the canonical modifier order of normalize() in resources/js/index.js. */
    protected const MODIFIERS = ['ctrl', 'cmd', 'meta', 'alt', 'shift'];

    protected const MAC_MODIFIER_SYMBOLS = [
        'ctrl' => '⌃',
        'cmd' => '⌘',
        'meta' => '⌘',
        'alt' => '⌥',
        'shift' => '⇧',
    ];

    protected const GENERIC_MODIFIER_LABELS = [
        'ctrl' => 'Ctrl',
        'cmd' => 'Cmd',
        'meta' => 'Cmd',
        'alt' => 'Alt',
        'shift' => 'Shift',
    ];

    protected const KEY_LABELS = [
        'space' => 'Space',
        'escape' => 'Esc',
        'arrowup' => '↑',
        'arrowdown' => '↓',
        'arrowleft' => '←',
        'arrowright' => '→',
        'enter' => 'Enter',
        'tab' => 'Tab',
        'backspace' => '⌫',
        'delete' => 'Del',
        'home' => 'Home',
        'end' => 'End',
        'pageup' => 'PgUp',
        'pagedown' => 'PgDn',
    ];

    public static function isMac(): bool
    {
        return str_contains(request()->userAgent() ?? '', 'Mac');
    }

    /**
     * Canonical combo form: lowercase, modifiers first in fixed order,
     * key last — mirrors normalize() in resources/js/index.js so server-side
     * comparisons match what the engine sees.
     */
    public static function normalize(?string $combo): ?string
    {
        if ($combo === null || trim($combo) === '') {
            return null;
        }

        $parts = array_map(fn (string $p): string => strtolower(trim($p)), explode('+', $combo));
        $mods = array_values(array_intersect(self::MODIFIERS, $parts));
        $key = '';

        foreach ($parts as $part) {
            if (! in_array($part, self::MODIFIERS, true)) {
                $key = $part;

                break;
            }
        }

        return implode('+', [...$mods, $key]);
    }

    /**
     * Modifier parts contained in a combo (canonical names, e.g. ['alt', 'shift']).
     *
     * @return array<int, string>
     */
    public static function modifiers(?string $combo): array
    {
        $normalized = static::normalize($combo);
        if ($normalized === null) {
            return [];
        }

        $parts = explode('+', $normalized);

        return array_values(array_intersect(self::MODIFIERS, $parts));
    }

    /**
     * Human-readable badge parts, platform-aware: ⌥/⌘/⌃/⇧ on macOS,
     * Alt/Cmd/Ctrl/Shift elsewhere.
     *
     * @return array<int, string>
     */
    public static function displayParts(?string $combo, ?bool $mac = null): array
    {
        $normalized = static::normalize($combo);
        if ($normalized === null) {
            return [];
        }

        $mac ??= static::isMac();
        $labels = [];

        foreach (explode('+', $normalized) as $part) {
            if (in_array($part, self::MODIFIERS, true)) {
                $labels[] = $mac
                    ? self::MAC_MODIFIER_SYMBOLS[$part]
                    : self::GENERIC_MODIFIER_LABELS[$part];

                continue;
            }

            $labels[] = static::keyLabel($part);
        }

        return $labels;
    }

    public static function display(?string $combo, ?bool $mac = null): string
    {
        return implode(' ', static::displayParts($combo, $mac));
    }

    /** True when the combo has exactly one non-modifier key we understand. */
    public static function isValid(string $combo): bool
    {
        $normalized = static::normalize($combo);
        if ($normalized === null) {
            return false;
        }

        $parts = explode('+', $normalized);
        $key = end($parts);

        if ($key === '' || in_array($key, self::MODIFIERS, true)) {
            return false;
        }

        return mb_strlen($key) === 1
            || preg_match('/^f\d{1,2}$/', $key) === 1
            || isset(self::KEY_LABELS[$key]);
    }

    protected static function keyLabel(string $key): string
    {
        if (isset(self::KEY_LABELS[$key])) {
            return self::KEY_LABELS[$key];
        }

        if (preg_match('/^f\d{1,2}$/', $key)) {
            return strtoupper($key);
        }

        return mb_strlen($key) === 1 ? mb_strtoupper($key) : ucfirst($key);
    }
}
