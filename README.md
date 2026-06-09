# filament-mouseless

[![Latest Version on Packagist](https://img.shields.io/packagist/v/blemli/filament-mouseless.svg?style=flat-square)](https://packagist.org/packages/blemli/filament-mouseless)[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/blemli/filament-mouseless/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/blemli/filament-mouseless/actions?query=workflow%3Arun-tests+branch%3Amain)[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/blemli/filament-mouseless/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/blemli/filament-mouseless/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)[![Total Downloads](https://img.shields.io/packagist/dt/blemli/filament-mouseless.svg?style=flat-square)](https://packagist.org/packages/blemli/filament-mouseless)

Use filament without a mouse. For Real. 

## Installation

You can install the package via composer:

```bash
composer require blemli/filament-mouseless
```

then run the installer

```bash
php artisan filament-mouseless:install
```

and don't forget to register in the AdminPanelProvider:

```php
->plugins([
  // other plugins
  FilamentMouselessPlugin::make()
])
```

## Usage

Go to any page and press <kbd>?</kbd> outside a textfield to show all the available shortcuts.

## Features Overview

Dark-Mode Support, Language Adaptive: DE (**N**eu) & EN (**C**reate),  Filament Native Style (no custom theme needed), Mobile Friendly, Stateless mode available (without migrations), Show a Shortcuts Overlay with <kbd>?</kbd>, Let Users Register custom Combinations, Many Convenience Combinations (escape, go home, search), Hidden on Devices without Keyboard, 

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
