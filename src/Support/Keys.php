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

    /**
     * Mousetrap/Filament combo aliases → our canonical parts. Filament's
     * ->keyBindings() use Mousetrap syntax ('mod+s', 'option+up'); the engine
     * speaks the lowercase event.key form ('cmd+s', 'alt+arrowup').
     */
    protected const MOUSETRAP_ALIASES = [
        'command' => 'cmd',
        'meta' => 'cmd',
        'super' => 'cmd',
        'control' => 'ctrl',
        'option' => 'alt',
        'return' => 'enter',
        'esc' => 'escape',
        'del' => 'delete',
        'ins' => 'insert',
        'spacebar' => 'space',
        'up' => 'arrowup',
        'down' => 'arrowdown',
        'left' => 'arrowleft',
        'right' => 'arrowright',
    ];

    public static function isMac(): bool
    {
        return static::platform() === 'mac';
    }

    /** Coarse OS bucket from the user agent — keys the reserved-combo list. */
    public static function platform(): string
    {
        $ua = request()->userAgent() ?? '';

        // Unknown/empty UA (CLI, tests, bots) buckets as non-mac, matching the
        // pre-2.0 isMac() behavior — 'mod' resolves to Ctrl there.
        return match (true) {
            str_contains($ua, 'Mac') => 'mac',
            str_contains($ua, 'Windows') => 'windows',
            default => 'linux',
        };
    }

    /**
     * The reserved (unbindable) combos for the current request's platform.
     * Config may be keyed by platform ('all'/'mac'/'windows'/'linux') or be
     * a flat list (the pre-2.0 format), which is treated as 'all'.
     *
     * @return array<int, string>
     */
    public static function reservedKeys(): array
    {
        $config = (array) config('mouseless.reserved_keys', []);

        if (array_is_list($config)) {
            return $config;
        }

        return array_values(array_unique(array_merge(
            (array) ($config['all'] ?? []),
            (array) ($config[static::platform()] ?? []),
        )));
    }

    /**
     * Translate a Mousetrap-style combo (as written in Filament's
     * ->keyBindings()) into the engine's canonical form. `mod` resolves to
     * ⌘ on macOS and Ctrl elsewhere. Returns null for empty/sequence combos.
     */
    public static function fromMousetrap(?string $combo, ?bool $mac = null): ?string
    {
        if ($combo === null || trim($combo) === '') {
            return null;
        }

        // Mousetrap sequences ("g i") are space-separated; the engine only
        // understands single chords, so skip anything with whitespace.
        if (preg_match('/\s/', trim($combo)) === 1) {
            return null;
        }

        $mac ??= static::isMac();
        $translated = [];

        foreach (explode('+', $combo) as $part) {
            $part = strtolower(trim($part));
            if ($part === '') {
                continue;
            }

            if ($part === 'mod') {
                $translated[] = $mac ? 'cmd' : 'ctrl';

                continue;
            }

            $translated[] = self::MOUSETRAP_ALIASES[$part] ?? $part;
        }

        return static::normalize(implode('+', $translated));
    }

    /**
     * Canonical combo form: lowercase, modifiers first in fixed order,
     * key last — mirrors normalize() in resources/js/index.js so server-side
     * comparisons match what the engine sees. The platform-neutral 'mod'
     * resolves to ⌘ on macOS and Ctrl everywhere else.
     */
    public static function normalize(?string $combo, ?bool $mac = null): ?string
    {
        if ($combo === null || trim($combo) === '') {
            return null;
        }

        $parts = array_map(fn (string $p): string => strtolower(trim($p)), explode('+', $combo));

        if (in_array('mod', $parts, true)) {
            $mac ??= static::isMac();
            $parts = array_map(fn (string $p): string => $p === 'mod' ? ($mac ? 'cmd' : 'ctrl') : $p, $parts);
        }

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
        $mac ??= static::isMac();
        $normalized = static::normalize($combo, $mac);
        if ($normalized === null) {
            return [];
        }
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
