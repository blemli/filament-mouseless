# filament-mouseless

[![Latest Version on Packagist](https://img.shields.io/packagist/v/blemli/filament-mouseless.svg?style=flat-square)](https://packagist.org/packages/blemli/filament-mouseless)[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/blemli/filament-mouseless/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/blemli/filament-mouseless/actions?query=workflow%3Arun-tests+branch%3Amain)[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/blemli/filament-mouseless/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/blemli/filament-mouseless/actions?query=workflow%3A"Fix+PHP+code+styling"+branch%3Amain)[![Total Downloads](https://img.shields.io/packagist/dt/blemli/filament-mouseless.svg?style=flat-square)](https://packagist.org/packages/blemli/filament-mouseless)

Use filament without a mouse. For Real. 

## Installation

You can install the package in only 2-3 simple steps:

1. install via composer:

```bash
composer require blemli/filament-mouseless
```

2. run the installer

```bash
php artisan mouseless:install
```

3.  let the installer register the plugin in your panel provider — or do it yourself:

```php
->plugins([
  // other plugins
  FilamentMouselessPlugin::make()
])
```

## Usage

Open your App on any page you like and disconnect your Mouse. Now press <kbd>?</kbd> outside a textfield to show all the available shortcuts. Now useryour app without a mouse :)

## Features Overview

Dark-Mode Support, Language Adaptive: DE (**E**rstellen), EN (**N**ew), ES (**C**rear), FR (**C**réer) & IT (**N**uovo), Filament Native Style (no custom theme needed), Mobile Friendly, Respects your Theme & Font & Color, Stateless mode available (without migrations), Show a Shortcuts Overlay with <kbd>?</kbd>, Convenient `mouseless:install` command, Let Users Register custom Combinations, Hidden on Devices without Keyboard, Compatible with Filament Shield but not required, Configure everything like Icons &  Labels & Positions, Printable CheatSheat, Jump to Resources with Shortcuts, Navigate Table Rows, Highlight Shortcuts in UI, Utility to rename existing actions, Create your most important Resource from anywhere, Escape to Dashboard, Open Filters Panel, Open Column Selector, Teach Shortcuts to users, 

## Shortcuts

The layout is built from four tiers. On macOS <kbd>⌥</kbd> is Option and <kbd>⌘</kbd> is Cmd; on Windows/Linux read <kbd>⌥</kbd> as <kbd>Alt</kbd> and <kbd>⌘</kbd> as <kbd>Ctrl</kbd>.

1. **<kbd>⌘</kbd> — submit & clipboard.** Universal muscle memory, identical in every language, works even while typing in a field.
2. **<kbd>⌥</kbd> + initial — record actions.** The letter is the initial of the action's label *in your language*, following Filament's own wording — the key matches the button you see.
3. **<kbd>⌥⇧</kbd> + initial — table controls.** Everything that acts on the whole table instead of one record.
4. **<kbd>⌥⌃</kbd> — UI & chrome.** Profile, logout & co. Identical in every language.

### <kbd>⌘</kbd> Submit & clipboard — same everywhere

| Key | Action | Behavior |
| --- | --- | --- |
| <kbd>⌘</kbd><kbd>Enter</kbd> | Submit form | Save on edit pages, create on create pages |
| <kbd>⌘</kbd><kbd>S</kbd> | Save | |
| <kbd>⌘</kbd><kbd>⇧</kbd><kbd>Enter</kbd> | Create & create another | |
| <kbd>⌘</kbd><kbd>A</kbd> | Select all rows | Only in list context, never inside inputs |
| <kbd>⌘</kbd><kbd>C</kbd> | Copy record as Markdown | Only when nothing is selected — normal copying is untouched |
| <kbd>⌘</kbd><kbd>P</kbd> | Print | Cheatsheet when the overlay is open → record print action → browser print |
| <kbd>⌘</kbd><kbd>K</kbd> | Command palette | Filament's global search |

### <kbd>⌥</kbd> Record actions — language adaptive

| Action | 🇬🇧 English | 🇩🇪 Deutsch | 🇪🇸 Español | 🇫🇷 Français | 🇮🇹 Italiano |
| --- | --- | --- | --- | --- | --- |
| create | <kbd>⌥N</kbd> **N**ew | <kbd>⌥E</kbd> **E**rstellen | <kbd>⌥C</kbd> **C**rear | <kbd>⌥C</kbd> **C**réer | <kbd>⌥N</kbd> **N**uovo |
| edit | <kbd>⌥E</kbd> **E**dit | <kbd>⌥B</kbd> **B**earbeiten | <kbd>⌥E</kbd> **E**ditar | <kbd>⌥M</kbd> **M**odifier | <kbd>⌥M</kbd> **M**odifica |
| view | <kbd>⌥V</kbd> **V**iew | <kbd>⌥A</kbd> **A**nzeigen | <kbd>⌥V</kbd> **V**er | <kbd>⌥V</kbd> **V**oir | <kbd>⌥V</kbd> **V**edi |
| delete | <kbd>⌥D</kbd> **D**elete | <kbd>⌥L</kbd> **L**öschen | <kbd>⌥B</kbd> **B**orrar | <kbd>⌥S</kbd> **S**upprimer | <kbd>⌥E</kbd> **E**limina |
| force delete | <kbd>⌥F</kbd> **F**orce delete | <kbd>⌥⇧L</kbd> Endgültig löschen | <kbd>⌥F</kbd> **F**orzar borrado | <kbd>⌥⇧S</kbd> Supprimer définitivement | <kbd>⌥F</kbd> **F**orza eliminazione |
| restore | <kbd>⌥R</kbd> **R**estore | <kbd>⌥W</kbd> **W**iederherstellen | <kbd>⌥R</kbd> **R**estaurar | <kbd>⌥R</kbd> **R**estaurer | <kbd>⌥R</kbd> **R**ipristina |
| replicate | <kbd>⌥Y</kbd> cop**y** | <kbd>⌥D</kbd> **D**uplizieren | <kbd>⌥⇧R</kbd> **R**eplicar | <kbd>⌥D</kbd> **D**upliquer | <kbd>⌥D</kbd> **D**uplica |
| attach / associate | <kbd>⌥L</kbd> **L**ink | <kbd>⌥V</kbd> **V**erknüpfen | <kbd>⌥⇧V</kbd> **V**incular | <kbd>⌥L</kbd> **L**ier | <kbd>⌥C</kbd> **C**ollega |
| detach / dissociate | <kbd>⌥U</kbd> **U**nlink | <kbd>⌥T</kbd> **T**rennen | <kbd>⌥D</kbd> **D**esvincular | <kbd>⌥⇧D</kbd> **D**étacher | <kbd>⌥S</kbd> **S**collega |
| history / timeline | <kbd>⌥H</kbd> **H**istory | <kbd>⌥H</kbd> **H**istorie | <kbd>⌥H</kbd> **H**istorial | <kbd>⌥H</kbd> **H**istorique | <kbd>⌥T</kbd> **T**imeline |
| merge | <kbd>⌥M</kbd> **M**erge | <kbd>⌥Z</kbd> **Z**usammenführen | <kbd>⌥U</kbd> **U**nir | <kbd>⌥F</kbd> **F**usionner | <kbd>⌥U</kbd> **U**nisci |
| split | <kbd>⌥S</kbd> **S**plit | <kbd>⌥⇧Z</kbd> **Z**erteilen | <kbd>⌥S</kbd> **S**eparar | <kbd>⌥E</kbd> **É**clater | <kbd>⌥⇧D</kbd> **D**ividi |
| comment | <kbd>⌥C</kbd> **C**omment | <kbd>⌥K</kbd> **K**ommentieren | <kbd>⌥N</kbd> **N**ota | <kbd>⌥N</kbd> **N**oter | <kbd>⌥⇧N</kbd> **N**ota |
| approve | <kbd>⌥A</kbd> **A**pprove | <kbd>⌥G</kbd> **G**enehmigen | <kbd>⌥A</kbd> **A**probar | <kbd>⌥A</kbd> **A**pprouver | <kbd>⌥A</kbd> **A**pprova |
| reject | <kbd>⌥X</kbd> ✗ | <kbd>⌥X</kbd> ✗ | <kbd>⌥X</kbd> ✗ | <kbd>⌥X</kbd> ✗ | <kbd>⌥X</kbd> ✗ |
| archive | <kbd>⌥⇧A</kbd> | <kbd>⌥⇧A</kbd> | <kbd>⌥⇧A</kbd> | <kbd>⌥⇧A</kbd> | <kbd>⌥⇧A</kbd> |
| favorite | <kbd>⌥B</kbd> **B**ookmark | <kbd>⌥M</kbd> **M**erken | <kbd>⌥M</kbd> **M**arcar | <kbd>⌥⇧P</kbd> **P**référé | <kbd>⌥P</kbd> **P**referito |
| watch | <kbd>⌥W</kbd> **W**atch | <kbd>⌥⇧B</kbd> **B**eobachten | <kbd>⌥O</kbd> **O**bservar | <kbd>⌥O</kbd> **O**bserver | <kbd>⌥O</kbd> **O**sserva |
| lock | <kbd>⌥⇧L</kbd> **L**ock | <kbd>⌥S</kbd> **S**perren | <kbd>⌥P</kbd> **P**roteger | <kbd>⌥B</kbd> **B**loquer | <kbd>⌥B</kbd> **B**locca |
| share | <kbd>⌥⇧S</kbd> **S**hare | <kbd>⌥F</kbd> **F**reigeben | <kbd>⌥⇧S</kbd> **S**hare | <kbd>⌥P</kbd> **P**artager | <kbd>⌥I</kbd> **I**noltra |

Memorable patterns hiding in there:

- **<kbd>⌥X</kbd> = reject, everywhere.** X is the cross-out mark in every language.
- **<kbd>⌥⇧A</kbd> = archive, everywhere** — A always belongs to a native action, so Archive lives one shift away.
- **Force delete is <kbd>⌥F</kbd> where the language allows** (Force / Forzar / Forza) **and "shift + your delete key" where it doesn't** (DE <kbd>⌥⇧L</kbd>, FR <kbd>⌥⇧S</kbd>) — the *harder* delete.
- **<kbd>⌥A</kbd> = approve** everywhere except German (<kbd>⌥G</kbd> Genehmigen).
- **Watch = <kbd>⌥O</kbd>** ("observe") in Spanish, French and Italian.

### <kbd>⌥⇧</kbd> Table controls

| Control | 🇬🇧 | 🇩🇪 | 🇪🇸 | 🇫🇷 | 🇮🇹 |
| --- | --- | --- | --- | --- | --- |
| filters | <kbd>⌥⇧F</kbd> **F**ilters | <kbd>⌥⇧F</kbd> **F**ilter | <kbd>⌥⇧F</kbd> **F**iltros | <kbd>⌥⇧F</kbd> **F**iltres | <kbd>⌥⇧F</kbd> **F**iltri |
| export | <kbd>⌥⇧E</kbd> **E**xport | <kbd>⌥⇧E</kbd> **E**xportieren | <kbd>⌥⇧E</kbd> **E**xportar | <kbd>⌥⇧E</kbd> **E**xporter | <kbd>⌥⇧E</kbd> **E**sporta |
| import | <kbd>⌥⇧I</kbd> **I**mport | <kbd>⌥⇧I</kbd> **I**mport | <kbd>⌥⇧I</kbd> **I**mportar | <kbd>⌥⇧I</kbd> **I**mporter | <kbd>⌥⇧I</kbd> **I**mporta |
| sort | <kbd>⌥⇧O</kbd> **O**rder by | <kbd>⌥⇧O</kbd> **O**rdnen | <kbd>⌥⇧O</kbd> **O**rdenar | <kbd>⌥⇧T</kbd> **T**rier | <kbd>⌥⇧O</kbd> **O**rdina |
| columns | <kbd>⌥⇧C</kbd> **C**olumns | <kbd>⌥⇧S</kbd> **S**palten | <kbd>⌥⇧C</kbd> **C**olumnas | <kbd>⌥⇧C</kbd> **C**olonnes | <kbd>⌥⇧C</kbd> **C**olonne |
| bulk actions | <kbd>⌥⇧B</kbd> **B**ulk | <kbd>⌥⇧M</kbd> **M**ehrfachaktionen | <kbd>⌥⇧M</kbd> **M**asivas | <kbd>⌥⇧M</kbd> **M**ultiples | <kbd>⌥⇧M</kbd> **M**ultiple |

### <kbd>⌥⌃</kbd> UI & chrome — same everywhere

| Key | Action | Mnemonic |
| --- | --- | --- |
| <kbd>⌥⌃P</kbd> | Profile | **P**rofile |
| <kbd>⌥⌃N</kbd> | Notifications | **N**otifications |
| <kbd>⌥⌃R</kbd> | Recently viewed | **R**ecent |
| <kbd>⌥⌃Q</kbd> | Logout | **Q**uit |
| <kbd>⌥⌃G</kbd> | Language switch | **G**lobe 🌐 |

### Fixed keys — same everywhere

<kbd>/</kbd> search the table · <kbd>g</kbd> go-to palette · <kbd>j</kbd>/<kbd>k</kbd> row down/up · <kbd>Space</kbd> toggle row · <kbd>⇧J</kbd>/<kbd>⇧K</kbd> extend selection · <kbd>⌥→</kbd>/<kbd>⌥←</kbd> next/previous page · <kbd>⌥↑</kbd> dashboard · <kbd>⌥↓</kbd> next tab · <kbd>F5</kbd> refresh the table · <kbd>?</kbd> help overlay · <kbd>Esc</kbd> close/cancel

Once a row is focused, <kbd>↑</kbd>/<kbd>↓</kbd> continue the row navigation (<kbd>⇧↑</kbd>/<kbd>⇧↓</kbd> extend the selection) — outside a focused row the arrows scroll as usual. And the row cursor is remembered per list: open a record, come back (Esc or browser back), and you're on the same row.

Tabs work like browser tabs: <kbd>⌥1</kbd>–<kbd>⌥9</kbd> jump straight to the Nth tab (list filter tabs and form tabs alike), <kbd>⌥↓</kbd> cycles through them — and in ⌥-hold hint mode every tab shows its number.

### Discover as you go

Every button whose action has a shortcut gets its initial **underlined**, Windows-accelerator style. On by default — opt out with:

```php
FilamentMouselessPlugin::make()
    ->disableUnderlines()
```

There are also **⌥-hold hint badges**: while the bare <kbd>⌥</kbd> key is held outside a text field, big key badges pop over everything reachable on the current page — release to dismiss. Opt in with:

```php
FilamentMouselessPlugin::make()
    ->hints()
```

### Teach missed shortcuts

Opt-in: whenever something that has a shortcut is **mouse-clicked**, a notification points it out — *"You could have just hit ⌥E"* — with three actions: **change shortcut** (deep-links to the action's row on `/my-shortcuts`), **don't show again** (this action), and **don't show any tips** (mute everything, reversible from the shortcuts page).

```php
FilamentMouselessPlugin::make()
    ->teach()
    ->deferDays(2)   // optional: newcomers get quiet first days
```

It covers every click the engine understands: action buttons, table rows (→ the row action's key), search fields (<kbd>/</kbd>, <kbd>⌘K</kbd>), filters/columns/grouping, the paginator (<kbd>⌥→</kbd>/<kbd>⌥←</kbd>), tabs (<kbd>⌥1</kbd>–<kbd>⌥9</kbd>), sidebar links (<kbd>G</kbd>), and breadcrumbs (<kbd>Esc</kbd>). Only the browser's own chrome (its refresh button, etc.) is out of reach.

Teaching helps, never nags: **at most one tip per page view**, repeat tips for the same action **back off** — after an hour, then doubling up to once a week — and an action used via keyboard **3 times in a row counts as taught** and is never mentioned again (a mouse click resets the streak). A toggleable **Tips column** on `/my-shortcuts` shows each action's state (unused / taught / dismissed); click a taught or dismissed badge to have it taught again. State is stored per user (`mouseless_nudges` table), so it follows people across devices; stateless installs skip the feature.

### Reserved keys

Combos the browser or OS never gives up (<kbd>⌘W</kbd>, <kbd>Ctrl+T</kbd>, <kbd>Alt+F4</kbd>, …) are **prohibited**: the recorder refuses them, imports reject them, and the engine never registers them. The lists are platform-aware — publish the config and extend them per OS:

```php
// config/mouseless.php
'reserved_keys' => [
    'all'     => ['Tab', 'Enter', 'ctrl+alt+j'],  // your additions
    'mac'     => [/* … */],
    'windows' => [/* … */],
    'linux'   => [/* … */],
],
```

Prohibitions are hard by default. To let power users bind them anyway (accepted with a warning — the browser may still win):

```php
FilamentMouselessPlugin::make()
    ->onlyWarnOnProhibited()
```

### Make your own actions fit the scheme

The shortcuts bind to conventional action names (`delete`, `approve`, `history`, …). If your app says `Action::make('remove')` instead, one command renames them:

```bash
php artisan mouseless:rename-actions          # dry run: shows what would change
php artisan mouseless:rename-actions --write  # applies the renames
```

It rewrites only the name inside `::make()` and flags any other references to the old name for manual review. Common synonyms are built in (`remove`→`delete`, `copy`→`replicate`, `accept`→`approve`, …); extend the map per app via `mouseless.rename_map` in the config. Names the engine already understands as aliases (`activityLog`, `bookmark`, `subscribe`, …) are left untouched.

> [!NOTE]
>
> Shortcuts never fire while you're typing: the whole <kbd>⌥</kbd> family is suppressed inside inputs, so layout characters like <kbd>⌥G</kbd> = @ (Swiss) or <kbd>⌥L</kbd> = @ (German) keep working. AltGr combos are never interpreted as shortcuts, and keys the browser won't give up (⌘W, Ctrl+T, Alt+F4, …) are reserved per platform and can't be bound. 

### v2

Reorder Rows,  Multipanel support, Multitenant Support, ~~Tutorial on missed Shortcuts~~, ~~Statistics on avoided clicks~~, Let Users share Presets with eachother, Let Admins Moderate Shared Presets (remove unused ones &see which action is the most overwritten, most used),  `shorcuts:list` command, Detect already existing shortcuts of actions (artisan?), ~~singleton preset~~, Free Key Visualisation, Fire Laravel Events, unattended install,  vimperator mode to jump to fields, uninstall command, support advanced tables, Integrate with Spotlight to directyl run relevant actions, AskPhil, Kanban, ActivityLog (opt-in), and probably even more!

### Languages

EN, DE, ES, FR, IT — each with its own initials-based default preset. Requests for more languages are welcome!

### Supported Third Party Packages

Filament Shield

ActivityLog

Advanced Tables

Spotlight

Language Switcher?

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

//todo: --no-interaction for no prompts at all?

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

Singleton mode hides presets from *end users* only: the opt-in admin page (`->settingsPage()`) keeps its preset vocabulary, since whoever configures the default layout needs to see what they're configuring.

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
- **An all-users block** on the admin page (`->settingsPage()`): org-wide clicks avoided, active users, most-used shortcuts, and a keyboard leaderboard. Hide it via `mouseless.admin.statistics => false`.
- **Milestone congratulations** at 100, 1'000 and 10'000 lifetime shortcut uses.
- **Smarter teaching**: with `->teach()` also on, the one-nudge-per-page slot is spent on your *most-clicked* still-unlearned actions first — a one-off click no longer burns the nudge a frequent offender should have gotten. Without statistics, teach behaves exactly as before.

Statistics need the `mouseless_statistics` migration (re-publish the package migrations when upgrading); like the other per-user features, `->stateless()` panels ignore the call.

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

### Admin Page

#### Show the Admin page

The `/mouseless-settings` page (default preset, disabled actions, moderation queue) is off by default. Opt in:

```php
->plugins([
  FilamentMouselessPlugin::make()
      ->settingsPage(),
])
```

#### Custom Icon

Used by the admin page nav and the user-menu link.

```php
FilamentMouselessPlugin::make()
    ->icon('heroicon-o-cog-6-tooth')
```

#### Custom Label

```php
FilamentMouselessPlugin::make()
    ->settingsPageLabel('Keyboard')
    ->shortcutsLabel(fn () => __('app.my_shortcuts'))
```



#### Navigation Group

Defaults to a translated "System". Pass `null` to drop the group.

```php
FilamentMouselessPlugin::make()
    ->settingsPageNavigationGroup('Settings')
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

By default everyone gets shortcuts. With [Filament Shield](https://github.com/bezhanSalleh/filament-shield) installed, mouseless registers three permissions so they appear in the role-edit UI, but they're only enforced when you opt in:

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

## Tiers

59$ Single Project

199$ Unlimited Projects forever

399$ Premium Support: Implementation of any Feature (or Bugfix) within the scope of the package within 7 business days. We can do a zoom call. 

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
