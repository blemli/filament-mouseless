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
        'resource_letters' => true,
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

    // Override the auto-picked alt+shift+<letter> for a resource.
    //   \App\Filament\Resources\Products::class => 'r',
    'resource_letters' => [],

    /*
    |--------------------------------------------------------------------------
    | Reserved keys (unbindable)
    |--------------------------------------------------------------------------
    | Keys we leave to the browser. Do NOT add keys the package itself uses
    | (Escape → ui.close, ? → ui.help) — they'd be silently dropped at
    | binding-registration time and the corresponding actions would never fire.
    */
    'reserved_keys' => [
        'Tab', 'Enter',
        'cmd+r', 'cmd+w', 'cmd+t', 'cmd+l',
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
];
