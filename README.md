# filament-mouseless

[![Latest Version on Packagist](https://img.shields.io/packagist/v/blemli/filament-mouseless.svg?style=flat-square)](https://packagist.org/packages/blemli/filament-mouseless)[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/blemli/filament-mouseless/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/blemli/filament-mouseless/actions?query=workflow%3Arun-tests+branch%3Amain)[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/blemli/filament-mouseless/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/blemli/filament-mouseless/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)[![Total Downloads](https://img.shields.io/packagist/dt/blemli/filament-mouseless.svg?style=flat-square)](https://packagist.org/packages/blemli/filament-mouseless)

Use filament without a mouse. For Real. 

## Installation

You can install the package in only three simple steps

1. install via composer:

```bash
composer require blemli/filament-mouseless
```

2. run the installer

```bash
php artisan filament-mouseless:install
```

3.  don't forget to register in the AdminPanelProvider:

```php
->plugins([
  // other plugins
  FilamentMouselessPlugin::make()
])
```

## Usage

Go to any page and press <kbd>?</kbd> outside a textfield to show all the available shortcuts.

## Features Overview

Dark-Mode Support, Language Adaptive: DE (**N**eu) & EN (**C**reate),  Filament Native Style (no custom theme needed), Mobile Friendly, Stateless mode available (without migrations), Show a Shortcuts Overlay with <kbd>?</kbd>,  Convenient `mouseless:install` command, Let Users Register custom Combinations, Covers all Filament-Functions  (escape, go home, search, logout), Hidden on Devices without Keyboard, Compatible with Filament Shield but not required, Configure Icons and Labels

## Configure

### Stateless Mode

If you don't want users to customize their own shortcuts (and don't want to run the package migrations), turn the plugin stateless:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->stateless(),
])
```

This hides the `/my-shortcuts` page and removes its user-menu link. Shortcuts still work — everyone just rides on the configured default preset.

### Hide Overlay

If for some reason you don't want the help overlay you can disable it:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->disableHelpOverlay(),
])
```

Shortcuts still work, only the overlay popup is suppressed.

### Shortcuts on Mobile

The user-menu link to "My Shortcuts" is hidden on phones and tablets by default — keyboards usually aren't a thing there. Detection is User-Agent based, so a narrow window on a real desktop browser still shows the link.

**Keyboard on a phone?** No config needed. The link is rendered server-side but CSS-hidden on mobile UAs. The first time a real hardware key is pressed, the JS engine writes `localStorage.mouseless_kbd = '1'` and adds a class to `<body>` — the link appears instantly. On the next page load, a tiny inline script reads localStorage and reapplies the class before paint, so there's no flash. Soft keyboards don't trigger it (filtered via IME signals).

To force-show the link everywhere, skipping the heuristic:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->showShortcutsOnMobile(),
])
```

The page itself stays reachable by direct URL regardless.

#### Disabling the probe

If the auto-reveal heuristic ever misbehaves, you can switch it off:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->disableProbe(),
])
```

With the probe disabled, mobile users must use `->showShortcutsOnMobile()` to see the link.

### Show the Admin page

The `/mouseless-settings` page (default preset, disabled actions, resource letters, moderation queue) is off by default. Opt in:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->settingsPage(),
])
```

### Custom Icon

For the admin page and/or the user-menu link.

```php
FilamentMouselessPlugin::make()
    ->settingsPageIcon('heroicon-o-cog-6-tooth')
    ->shortcutsIcon('heroicon-o-command-line')
```

### Custom Label

```php
FilamentMouselessPlugin::make()
    ->settingsPageLabel('Keyboard')
    ->shortcutsLabel(fn () => __('app.my_shortcuts'))
```

### Navigation Group

Defaults to a translated "System". Pass `null` to drop the group.

```php
FilamentMouselessPlugin::make()
    ->settingsPageNavigationGroup('Settings')
```

## Permission

By default everyone gets shortcuts. With [Filament Shield](https://github.com/bezhanSalleh/filament-shield) installed, mouseless registers three permissions so they appear in the role-edit UI, but they're only enforced when you opt in:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->strictPermissions(),
])
```

| Permission | Gates (strict mode only) |
| --- | --- |
| `MouselessUse` (custom) | Master switch. When denied: no link, no overlay, no boot script, no page access. |
| `View:MyShortcuts` | The per-user customization page. Requires `MouselessUse` too. |
| `View:MouselessSettings` | The admin settings page. Requires `MouselessUse` too. |

Key formatting follows Shield's `permissions.case`/`separator`. Surface `MouselessUse` in the role-edit UI by enabling the custom-permissions tab, then `shield:generate`:

```php
'shield_resource' => ['tabs' => ['custom_permissions' => true]],
```

Grant via the role UI, or in tinker:

```php
Spatie\Permission\Models\Role::firstWhere('name', 'panel_user')
    ?->givePermissionTo(['MouselessUse', 'View:MyShortcuts']);
```



### Go Home

By default <kbd>option</kbd>+<kbd>↑</kbd>  brings you home. 

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

If you still had to use your mouse for something, please report it!

Contributions are very welcome, especially translations. If you don't have time for a PR, a bugreport is fine too. Lets make this plugin stable and comprehensive together!

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Problemli GmbH](https://github.com/blemli)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
