<?php

// config for Blemli/FilamentMouseless
return [

    /*
    |--------------------------------------------------------------------------
    | SuperAdmin page
    |--------------------------------------------------------------------------
    | Hide the entire page (and all its sections) by setting enabled => false.
    | When the page is enabled, individual sections can be toggled too.
    */
    'admin' => [
        'enabled' => true,
        'default_preset' => true,
        'disabled_actions' => true,
        'moderation_queue' => true,

        // Authorization gate for the SuperAdmin page (view + save + approve + reject).
        // RECOMMENDED: define a Gate ability in your AuthServiceProvider and set the
        // string here — e.g. 'moderate-mouseless-presets'. Leaving this null opens
        // the page to any authenticated panel user, which is fine for single-admin
        // installs but unsafe when multiple roles can reach the panel.
        'gate' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Publishing (opt-in)
    |--------------------------------------------------------------------------
    | Off by default. When enabled, users with the configured Gate can publish
    | their personal presets into the shared picker. Optional approval queue
    | routes drafts through the SuperAdmin page.
    */
    'publishing' => [
        'enabled' => false,
        'require_approval' => true,
        'gate' => 'publish-mouseless-preset',
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */
    'default_preset' => 'english-default',

    // Action IDs hidden entirely from the help overlay and never bound.
    'disabled_actions' => [],

    /*
    |--------------------------------------------------------------------------
    | Remap single shortcuts
    |--------------------------------------------------------------------------
    | Override individual default shortcuts without authoring a whole preset —
    | the override applies to every built-in preset (all locales). Use null to
    | disable a shortcut (it stays listed in the overlay as unbound; use
    | 'disabled_actions' above to hide it entirely). Combos accept the usual
    | syntax plus aliases: 'mod' = ⌘/Ctrl per platform, 'opt'/'option' = alt.
    | ->remap() calls on the plugin win over entries here.
    |
    | 'remap' => [
    |     'crud.create' => 'alt+shift+n',
    |     'record.reject' => null, // disable
    | ],
    */
    'remap' => [],

    /*
    |--------------------------------------------------------------------------
    | Reserved keys (unbindable)
    |--------------------------------------------------------------------------
    | Combos the browser or OS wins regardless of preventDefault — the recorder
    | refuses them and the engine never registers them. Keyed by platform
    | (detected from the user agent); 'all' applies everywhere. A flat array
    | (the pre-2.0 format) is still accepted and treated as 'all'.
    |
    | Do NOT add keys the package itself uses (Escape → ui.close, ? → ui.help)
    | — they'd be silently dropped at binding-registration time and the
    | corresponding actions would never fire.
    */
    'reserved_keys' => [
        'all' => ['Tab', 'Enter'],
        'mac' => [
            'cmd+q', 'cmd+w', 'cmd+t', 'cmd+n', 'cmd+m', 'cmd+h',
            'cmd+shift+w', 'cmd+shift+t', 'cmd+shift+n',
        ],
        'windows' => [
            'ctrl+w', 'ctrl+t', 'ctrl+n', 'ctrl+f4',
            'ctrl+shift+w', 'ctrl+shift+t', 'ctrl+shift+n',
            'alt+f4', 'alt+tab', 'alt+space',
        ],
        'linux' => [
            'ctrl+w', 'ctrl+t', 'ctrl+n',
            'ctrl+shift+w', 'ctrl+shift+t', 'ctrl+shift+n',
            'alt+f4', 'alt+tab', 'alt+space',
            // Window-manager grabs (GNOME/KDE): terminal, lock screen.
            'ctrl+alt+t', 'ctrl+alt+l',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | List/table mode
    |--------------------------------------------------------------------------
    | Bare-letter shortcuts (j, k, x, /, …) are suppressed when focus is in
    | any of these selectors so users can type freely in inputs.
    */
    'list_mode' => [
        'ignore_in' => ['input', 'textarea', '[contenteditable]'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug
    |--------------------------------------------------------------------------
    | When true, the JS engine logs every keypress + matched action to the
    | browser console — handy for diagnosing why a binding isn't firing.
    */
    'debug' => env('MOUSELESS_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Built-in preset discovery
    |--------------------------------------------------------------------------
    | Path containing PHP files that return preset arrays. Each filename
    | (without extension) becomes the preset slug.
    */
    'presets_path' => __DIR__ . '/mouseless/presets',

    /*
    |--------------------------------------------------------------------------
    | Custom action discovery
    |--------------------------------------------------------------------------
    | Your own Filament actions that carry ->keyBindings() are surfaced in the
    | help overlay and the my-shortcuts page. `php artisan mouseless:scan`
    | writes the manifest below — it is developer-editable, and manual entries
    | (those with 'scanned' => false) are preserved across re-scans.
    |
    | Add the MouselessKeyBindings trait to a page so the package takes over its
    | actions' key handling (users can then rebind them). Set
    | manage_all_keybindings => true to apply that takeover to every page.
    */
    'custom_actions_path' => config_path('mouseless/actions.php'),

    // Directories scanned by `mouseless:scan` for ->keyBindings() calls.
    'scan_paths' => [
        app_path('Filament'),
    ],

    'manage_all_keybindings' => false,
];
