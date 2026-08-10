# filament-mouseless

[![Latest Version on Packagist](https://img.shields.io/packagist/v/blemli/filament-mouseless.svg?style=flat-square)](https://packagist.org/packages/blemli/filament-mouseless)[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/blemli/filament-mouseless/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/blemli/filament-mouseless/actions?query=workflow%3Arun-tests+branch%3Amain)[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/blemli/filament-mouseless/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/blemli/filament-mouseless/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)[![Total Downloads](https://img.shields.io/packagist/dt/blemli/filament-mouseless.svg?style=flat-square)](https://packagist.org/packages/blemli/filament-mouseless)

Use filament without a mouse. [For Real](https://mouseless.problem.li). 

## Installation

You can install the package in only 2 simple steps:

1. install via composer:

```bash
composer require blemli/filament-mouseless
```

2. run the installer

```bash
php artisan mouseless:install
```

> [!TIP]
>
> In CI you can use `--no-interaction` to skip all dialogue. Run `php artisan mouseless --help` to see all toggles.



### Usage

1. Open your App on any page you like and disconnect your Mouse.
2. Now press <kbd>?</kbd> outside a textfield to show all the available shortcuts.
3. From now on use your app without the mouse :)



## Features

Dark-Mode Support, Language Adaptive: DE (**E**rstellen), EN (**N**ew), ES (**C**rear), FR (**C**réer) & IT (**N**uovo), Filament Native Style (no custom theme needed), Mobile Friendly, Hidden on Devices without Keyboard, Respects your Theme & Font & Color, Stateless mode available (without migrations), Show a Shortcuts Overlay with <kbd>?</kbd>, Convenient `mouseless:install` command, Let Users Register custom Combinations, Compatible with Filament Shield but not required, Configure everything like Icons &  Labels & Positions, Printable CheatSheat, Go to Resources with Shortcuts, Navigate Table Rows, Highlight Shortcuts in UI, Utility to rename existing actions, Create your most important Resource from anywhere, Escape to Dashboard, Teach Shortcuts to users without annoying them, Jump to any Control with a Double-Tap of Ctrl (opt-in), Laravel-Events to hook into, Reorder Rows (also in Repeaters), 

### roadmap

 Multipanel support, Multitenant Support, Filtersearch the Cheatsheet, Nudge for Cheatsheet,  ~~Tutorial on missed Shortcuts~~, ~~Statistics on avoided clicks~~, Let Users share Presets with eachother, Let Admins Moderate Shared Presets (remove unused ones &see which action is the most overwritten, most used),  `shorcuts:list` command, Detect already existing shortcuts of actions (artisan?), ~~singleton preset~~, Free Key Visualisation, ~~Fire Laravel Events~~, unattended install,  ~~vimperator mode to jump to fields (double ctrl)~~, uninstall command

### Languages

EN, DE, ES, FR, IT — each with its own initials-based default preset. Requests for more languages are welcome!

### Supported Third Party Packages

Filament Shield, more to come

## Configure

Everything works great with zero configuration, but everything is still configurable. Milk and Honey! 

### Basic Configuration

#### Publish the config

Most options below are plugin methods and need no config file. For everything that lives in `config/mouseless.php` (reserved keys, rename map, publishing/moderation, scan paths, …), publish it first:

```bash
php artisan vendor:publish --tag="filament-mouseless-config"
```

#### Remap (or disable) single shortcuts

Happy with the defaults but one key is in your way? Remap it right in the panel provider — no preset authoring needed:

```php
use Blemli\FilamentMouseless\Enums\MouselessAction;

FilamentMouselessPlugin::make()
    ->remap('crud.create', 'alt+shift+n')       // action id → new combo
    ->remap(MouselessAction::Approve, 'opt+y')  // enum if you like autocomplete
    ->remap('record.reject', null)              // null (or '') disables the shortcut
```

Or the same thing in the config file (fluent calls win over config):

```php
// config/mouseless.php
'remap' => [
    'crud.create' => 'alt+shift+n',
    'record.reject' => null,
],
```

The override rewrites the built-in defaults themselves — every language preset, the <kbd>?</kbd> overlay, the cheatsheet, hints and `/my-shortcuts` all agree. Perfect for stateless installs; on stateful ones a user who explicitly rebound the action keeps their own choice. Combos use the usual syntax (`mod` = ⌘/Ctrl per platform, and `opt`/`option` are accepted for `alt`). A disabled shortcut stays listed in the overlay as unbound — to hide the action entirely use `mouseless.disabled_actions`. <kbd>Esc</kbd> (`ui.close`) is protected and can't be remapped.

#### Stateless Mode

If you don't want users to customize their own shortcuts (and don't want to run the package migrations), turn the plugin stateless:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->stateless(),
])
```

This hides the `/my-shortcuts` page and removes its user-menu link. Shortcuts still work — everyone just rides on the configured default preset.

For unattended installs, `php artisan mouseless:install --stateless` skips the migration prompt entirely.

#### Singleton Mode

Want users to rebind shortcuts, but the whole *presets* concept is more than your app needs? Singleton mode keeps `/my-shortcuts` fully editable and hides everything preset-shaped:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->singleton(),
])
```

Users see just the shortcuts table — no preset dropdown, no create/rename/delete/publish layout buttons, no import/export, no "locked preset" banner, and the <kbd>?</kbd> overlay drops its "source: …" footer. Rebinding, disabling and resetting shortcuts work exactly as before; the first edit silently creates one managed personal layout per user in the background (no fork confirmation, no notification), and later edits keep writing to it.

Because that per-user layout lives in the database, singleton mode still needs the package migrations — it sits between the default mode (full preset UI) and `->stateless()` (no customization at all). Combining `->singleton()` with `->stateless()` is contradictory; stateless wins and a warning is logged.

#### Statistics on avoided clicks

Every shortcut fired is a click you didn't make. Opt in to counting them:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->statistics(),
])
```

The engine counts two things per action: keyboard invocations (each one = a click avoided) and mouse clicks on targets that *have* a shortcut — the two together give every action a keyboard/mouse ratio. Counts are buffered client-side and flushed in small batches; storage is one row per user per day with a JSON counter map (no per-action rows), so the table stays tiny.

What you get:

- **A statistics card** on `/my-shortcuts` (below the preset selector; in singleton mode it stands alone): lifetime "clicks avoided" headline, an 8-week keyboard-share sparkline (ratio of keys to clicks, so a quiet week doesn't read as a relapse), and *untapped actions* — the shortcuts you still mostly click, with their key combos as a nudge.
- **The same card as a dashboard widget** — register it like any Filament widget; it hides itself while statistics aren't tracked:

  ```php
  use Blemli\FilamentMouseless\MouselessWidget;
  
  ->widgets([MouselessWidget::class])
  ```

  If your dashboard page overrides `getWidgets()`, panel-level registration won't reach it — add `MouselessWidget::class` to that list instead.
- **An "Invoked" table column**, hidden by default — toggle it on and sort descending for your personal most-used-shortcuts ranking.
- **Milestone congratulations** at 100, 1'000 and 10'000 lifetime shortcut uses.
- **Smarter teaching**: with `->teach()` also on, the one-nudge-per-page slot is spent on your *most-clicked* still-unlearned actions first — a one-off click no longer burns the nudge a frequent offender should have gotten. Without statistics, teach behaves exactly as before.

Statistics need the `mouseless_statistics` migration. like the other per-user features, `->stateless()` panels ignore the call.

### Overlay

#### Hide Overlay

If for some obscure reason you don't want the help overlay, you can disable it:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->disableHelpOverlay(),
])
```

Shortcuts still work, only the overlay popup is suppressed. How will your users know? 

#### Change the overlay Shortcut

If you like the Overlay but with a different shortcut you can change it:
//todo: 

#### Printable cheatsheet

The help overlay doubles as a printable cheatsheet: a print button in its footer (or Cmd/Ctrl+P while it's open) prints just the shortcut list, headed by your app's name.

A slogan under the app name comes from `config/app.php` when present:

```php
'slogan' => 'Ship faster, click less',
```

Turn printing off entirely (no print button, no print styles):

```php
FilamentMouselessPlugin::make()
    ->disableCheatsheetPrinting()
```

### Look & Feel

#### Custom Icon

Used by the my-shortcuts page nav and the user-menu link.

```php
FilamentMouselessPlugin::make()
    ->icon('heroicon-o-cog-6-tooth')
```

#### Custom Label

```php
FilamentMouselessPlugin::make()
    ->shortcutsLabel(fn () => __('app.my_shortcuts'))
```

### User Presets

#### Personal layouts

Users customize shortcuts by creating their own layout — built-in presets are read-only, the first change forks them automatically. If you don't want per-user layouts at all, run the plugin in stateless mode (see above).

#### Let users publish layouts

Off by default. Enable it per panel:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->publishable(),
])
```

Published layouts appear in every user's layout dropdown (with the author's name appended). Moderation and a permission gate are configurable via `mouseless.publishing` in the config.

### Custom Actions

Your own Filament actions can join the keyboard layer too. Use Filament's native `->keyBindings()` as usual:

```php
use Filament\Actions\Action;

Action::make('approve')
    ->keyBindings(['mod+shift+a'])
    ->action(fn () => /* … */);
```

Mouseless discovers these automatically and lists them in the <kbd>?</kbd> overlay and the `/my-shortcuts` page, under a **Custom actions** group. Actions that aren't available on the current page are greyed out.

#### Let users rebind them

By default a discovered action is **read-only**: it keeps Filament's own key handling and shows up for reference only. Add the `MouselessKeyBindings` trait to the page (or resource page) that renders the action, and Mouseless takes over its key handling — now users can rebind or disable it from `/my-shortcuts`:

```php
use Blemli\FilamentMouseless\Filament\Concerns\MouselessKeyBindings;

class EditOrder extends EditRecord
{
    use MouselessKeyBindings;

    // Optional: keep specific actions on their native binding.
    public function mouselessExcludedActions(): array
    {
        return ['print'];
    }
}
```

You keep writing `->keyBindings()` exactly the same way — the trait only changes *who* handles the key. To apply the takeover everywhere without touching each page, set `mouseless.manage_all_keybindings` to `true`.

> A page without the trait that uses `->keyBindings()` logs a one-time hint pointing you here, so read-only actions never go unnoticed.

#### The scan command

Discovery at render time only sees the page you're on. To make every custom action show up everywhere (including greyed-out on other pages), run:

```bash
php artisan mouseless:scan
```

It statically scans `mouseless.scan_paths` (defaults to `app/Filament`) for `->keyBindings()` calls and writes a manifest to `config/mouseless/actions.php`. `mouseless:install` runs it for you once.

The manifest is yours to edit. Entries with `'scanned' => true` are rewritten on every scan; add your own with `'scanned' => false` (e.g. for actions defined with dynamic key bindings the scanner can't read) and they're preserved:

```php
// config/mouseless/actions.php
return [
    'custom.export-pdf' => [
        'label' => 'Export PDF',
        'keyBindings' => ['mod+e'],
        'managed' => true,
        'source' => null,
        'scanned' => false, // hand-added — never touched by the scanner
    ],
];
```

You can also restrict shortcuts for specific users, using permissions:

//todo: treat missing 'scanned' as false

### Permissions

By default everyone gets shortcuts. With [Filament Shield](https://github.com/bezhanSalleh/filament-shield) installed, mouseless registers two permissions so they appear in the role-edit UI, but they're only enforced when you opt in:

//todo: shouldn't they also not show up if strictPermissions isn't enabled?

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

Key formatting follows Shield's `permissions.case`/`separator`. Surface `MouselessUse` in the role-edit UI by enabling the custom-permissions tab, then `shield:generate`:

```php
'shield_resource' => ['tabs' => ['custom_permissions' => true]],
```

Grant via the role UI, or in tinker:

```php
Spatie\Permission\Models\Role::firstWhere('name', 'panel_user')
    ?->givePermissionTo(['MouselessUse', 'View:MyShortcuts']);
```

> [!IMPORTANT]
>
> The Installer will remind you to activate ->strictPermissions() if it detects shield. However if you install shield after mouseless you have to remember yourself.



### Miscellanious

The configurations never end...

#### Shortcuts on Mobile

The user-menu link to "My Shortcuts" is hidden on phones and tablets by default — keyboards usually aren't a thing there. Detection is User-Agent based, so a narrow window on a real desktop browser still shows the link.

**Keyboard on a phone?** No config needed. Mouseless automagically detects hardware keyboards (including bluetooth ones) and shows the my-shortcuts page.

To force-show the link everywhere, skipping the heuristic:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->showShortcutsOnMobile(),
])
```

The page itself stays reachable by direct URL regardless.

> [!NOTE]
>
> The link is rendered server-side but CSS-hidden on mobile UAs. The first time a real hardware key is pressed, the JS engine writes `localStorage.mouseless_kbd = '1'` and adds a class to `<body>` — the link appears instantly. On the next page load, a tiny inline script reads localStorage and reapplies the class before paint, so there's no flash. Soft keyboards don't trigger it (filtered via IME signals).



##### Disabling the probe

If the auto-reveal heuristic ever misbehaves, you can switch it off:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->disableProbe(),
])
```

With the probe disabled, mobile users must use `->showShortcutsOnMobile()` to see the link.

#### Position in the User Menu

The "My Shortcuts" link normally sits with the other user-menu items, right above logout. To pin it to the profile entry instead, hand it a render hook:

```php
use Filament\View\PanelsRenderHook;

FilamentMouselessPlugin::make()
    ->shortcutsPosition(PanelsRenderHook::USER_MENU_PROFILE_AFTER)
```

`USER_MENU_PROFILE_AFTER` places the link directly below the profile entry, `USER_MENU_PROFILE_BEFORE` directly above it. Mobile hiding and Shield permissions apply as usual.

### Go Home

By default <kbd>⌥</kbd>+<kbd>↑</kbd> brings you home.

Prefer Escape as the exit? Opt in — once nothing is left to close (overlay, selection, record page, focus), another <kbd>Esc</kbd> leaves for the dashboard. Lists with active filters, search, grouping or a switched tab are never abandoned.

```php
FilamentMouselessPlugin::make()
    ->escapeToDashboard()                       // Esc cascades to the dashboard
    ->escapeToDashboard('/admin/orders')        // …or to a URL
    ->escapeToDashboard(OrdersOverview::class)  // …or to a Filament page
```

### Jump Mode

Vimperator-style: double-tap <kbd>Ctrl</kbd> and every clickable control in the page content gets a small letter badge — inputs, buttons, links, tabs, dropdown triggers. Type the label to focus the field or click the control. No more memorizing a shortcut for every corner of a filter panel.

```php
FilamentMouselessPlugin::make()->jump()
```

- Labels are **sticky**: each one is hashed from the control's stable identity (its Livewire state path, action, or URL — never its on-screen text or position), so a control keeps its letter across reloads, page changes and language switches. When two controls hash to the same letter, that letter becomes a prefix and both get two-character labels.
- Badges cover the page content and the topbar (user menu, notifications, language switcher) — never the sidebar or breadcrumbs (the <kbd>g</kbd> palette already covers navigation). With a modal open, only the modal's controls are labeled.
- **Selected table rows** — and the row your <kbd>j</kbd>/<kbd>k</kbd> cursor is on — additionally expose their cells: the selection checkbox, clickable cells, and copyable cells (activating one copies the value). All other rows stay quiet.
- <kbd>Esc</kbd>, a second double-tap, a click, or scrolling closes the mode. Backspace un-types. Keystrokes never leak into the page while badges are up.
- Densely clickable custom regions (marker maps, canvases — anything with its own keyboard navigation) can opt out: put `data-mouseless-jump="ignore"` on the container and nothing inside it gets a badge.
- **Reordering without dragging**: committing the label on a drag handle (repeater items, key-value rows, reorderable tables) grabs the row instead of clicking — <kbd>↑</kbd>/<kbd>↓</kbd> (or <kbd>k</kbd>/<kbd>j</kbd>) move it, <kbd>Enter</kbd> or <kbd>Esc</kbd> drops it.

The chord is configurable — a double-tap of any single modifier:

```php
// config/mouseless.php
'jump' => [
    'chord' => 'ctrl,ctrl',   // or 'alt,alt', 'shift,shift', 'meta,meta'
    'timeout_ms' => 350,      // max gap between the two taps
],
```

A general chord system (arbitrary key sequences for any action) may come later.

### Events

The package fires plain Laravel events whenever something is *persisted* — hook them for audit logs, analytics, cache busting or a Slack cheer. Nothing fires during shortcut *resolution* (that happens on every request and would be pure noise).

All events live in `Blemli\FilamentMouseless\Events`:

| Event | Fired when | Payload |
| --- | --- | --- |
| `ShortcutsChanged` | A user edits their layout in the shortcuts table (rebind, remove, reset, enable/disable — single, bulk or per-category) | `userId`, `presetSlug`, old/new `bindings` & `disabled`, `changedActionIds()` helper |
| `PresetCreated` | A personal layout is stored (fork-on-first-edit, "create layout", import-as-new) | `preset` |
| `PresetDeleted` | A personal layout is deleted | `preset` |
| `PresetActivated` | The user switches their active layout (`null` slug = follow the UI locale's default) | `userId`, `previousSlug`, `slug` |
| `PresetPublished` / `PresetUnpublished` | A layout is shared with other users / made private again | `preset` |
| `PresetImported` | A layout is imported from JSON — `replacedExisting` tells overwrite from create | `preset`, `replacedExisting` |
| `MilestoneReached` | A statistics flush crosses a lifetime-keyboard-count milestone (see `->statistics(milestones: [...])`) | `userId`, `milestone`, `lifetimeKeyboardCount` |

Listen to them like any Laravel event, e.g. in your `AppServiceProvider`:

```php
use Blemli\FilamentMouseless\Events\ShortcutsChanged;
use Illuminate\Support\Facades\Event;

Event::listen(function (ShortcutsChanged $event) {
    logger()->info('Shortcuts changed', [
        'user' => $event->userId,
        'actions' => $event->changedActionIds(),
    ]);
});
```

## Tiers

59$ Single Project

199$ Unlimited Projects forever

399$ Premium Support: Implementation of any Feature (or Bugfix), or Language addition, within the scope of the package within 7 business days. We can do a video call. 

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

If you still had to use your mouse for something, please report it! We are very thankfull for bugreports.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Problemli GmbH](https://github.com/blemli)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
