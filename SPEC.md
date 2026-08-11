# filament-mouseless — v0.1 Spec

A Filament v5 plugin that adds a keyboard layer to any panel: action shortcuts, navigation jumps, a `?` cheat sheet, and user-customizable presets.

## Architecture

Three layers, resolved per request into one effective binding map:

```
1. Active preset           full coverage     selected by user (or panel default)
2. Personal overrides      sparse            user's tweaks on top of (1)
3. Admin disabled-actions  mask              removes action IDs entirely
```

- Built-in presets live in PHP files under `config/mouseless/presets/`.
- User-created presets and user settings live in the DB.
- Frontend reads the resolved map from a Livewire-hydrated JSON blob.

## Action contract

Shortcuts resolve to **Filament actions by name** on the current Livewire component (e.g. `DeleteAction`, `CreateAction`). If the action isn't registered on the current page, the shortcut is a no-op and emits a toast: *"No matching action on this page."*

Action IDs are language-independent and namespaced:

```
crud.*    create, edit, delete, save, cancel, view, duplicate
list.*    search, filter, refresh, export, import, select-all, bulk-action,
          next-page, prev-page
record.*  print, history, comment, archive, restore, approve, reject, publish,
          merge
nav.*     dashboard, profile, logout, command-palette, recently-viewed,
          notifications, resource.{slug}
ui.*      help, close, next-tab, prev-tab
```

## Modifier conventions

| Layer | Modifier | Purpose |
|---|---|---|
| Action on current page | `alt+<key>` | create, delete, print, … |
| Navigation jump | `alt+shift+<key>` | go to resource, profile, … |
| List/table mode | bare key (vim) | `j`/`k`/`x`/`enter`/`/` |
| Global | unmodified symbol | `?` help, `Esc` close |

List-mode keys fire **only** when focus is not in `<input>`, `<textarea>`, or `[contenteditable]`.

## Default presets

Ship two: `english-default` and `german-default`. Full binding tables in `config/mouseless/presets/*.php`.

English highlights: `alt+c` create, `alt+e` edit, `alt+d` delete, `alt+s` save, `alt+p` print, `alt+m` merge, `alt+h` history, `?` help, `alt+shift+u` profile.

German highlights: `alt+n` (Neu), `alt+b` (Bearbeiten), `alt+l` (Löschen), `alt+s` (Speichern), `alt+d` (Drucken), `alt+v` (Verlauf), `alt+z` (Zusammenführen). Refresh uses `F5` cross-language.

## Presets — data model

```
mouseless_presets
  id, slug (unique), name, locale, version,
  owner_user_id (null for installation-published),
  tenant_id (Filament tenant it was published under; null = installation-wide),
  source ('user' | 'imported'),
  parent_slug (fork chain),
  bindings (json),
  is_published, published_at, approved_by, approved_at

mouseless_user_settings
  user_id, active_preset_slug, overrides (json)
```

Built-in presets are not stored — they merge in from PHP files at resolve time.

## Forking & overrides

Tweaks save as **sparse overrides** on the active preset (lightweight, auto-follows upstream changes). An explicit **"Fork to publish"** button copies the resolved map into a personal preset the user owns. No version pinning in v0.1 — edits to a published preset go live to its subscribers.

## Publishing (opt-in)

Two paths:

- **Export/import JSON** — always available. User downloads their preset; others import via file or URL. Schema-validated, diff shown before applying.
- **In-app publishing** — opt-in. When `mouseless.publishing.enabled` is true, users with the `publish-mouseless-preset` Gate see a "Publish" button. Optional moderation queue (`require_approval`) routes drafts through the admin page. Published presets appear in everyone's picker.

Slug uniqueness enforced on publish. Name collisions OK (`compact-by-alice`, `compact-by-bob`).

**Multi-tenancy:** published layouts are installation-wide by default. `->scopePublishingToTenant()` (or `mouseless.publishing.scope_to_tenant`) keeps them inside the Filament tenant they were published from — publish stamps the current tenant on the layout, the picker filters by the viewer's tenant, tenant-less layouts stay global. Everything else is tenancy-safe by construction: all per-user state (presets, statistics, nudges, settings) follows the user across tenants, and URLs come from tenant-aware Filament helpers. Caveat for later: the admin statistics helpers (`Statistic::totals()`, `perActionTotalsAllUsers()`, `leaderboard()`) aggregate installation-wide — an admin widget built on them must filter by tenant membership itself.

## SuperAdmin page (System nav group)

Disablable via `mouseless.admin.enabled`. When on:

- Panel default preset
- Disabled action IDs
- Resource-letter overrides (for initial collisions)
- Moderation queue (only when publishing enabled)

## User profile tab "Shortcuts"

- Active preset picker (built-ins + personal + installation-published)
- Recording mode: press a combo to bind it; collisions are **rejected** with "remove `crud.save` from `alt+s` first"
- Fork to publish / Publish (if enabled) / Unpublish
- Export as JSON / Import from JSON or URL

Preset picker is a flat list with a locale badge per row (no grouping).

## `?` overlay

Modal listing the effective binding map, grouped by namespace. Footer shows source: *"english-default + 3 personal overrides"*. Actions whose Filament counterpart isn't on the current page are **greyed out**. Actions in `mouseless.disabled_actions` are **hidden completely**.

## Focus & context

- "Current record" = the record on edit/view pages, or the j/k-focused row on list pages.
- No focused record + shortcut needs one → no-op + toast.
- Context-aware shortcuts (e.g. `record.merge`) are always bound; they just no-op + toast when their action isn't on the page.

## Go-to palette

Leader key `nav.goto` (default `g`) opens a type-to-filter overlay over the current panel's navigation tree (`$panel->getNavigation()` — already auth-filtered, grouped, labelled, icon'd, with resolved URLs). Matching is JetBrains-style: each item gets a camelCase **chord** — the first letter of every word, grown left-to-right only when an earlier item already claimed that chord (so "Product Categories" → `pc`, a clashing "Post Categories" → `poc`). Typing the chord (or any label prefix) filters; a unique match auto-commits, ties resolve with ↑/↓/Enter. The chord letters are highlighted in each row to teach muscle memory. Read-only: auto-generated, never stored in presets.

## Reserved keys

Unbindable, configurable via `mouseless.reserved_keys`. Defaults: `Escape`, `Tab`, `Enter`, `cmd+r`, `cmd+w`, `cmd+t`, `cmd+l`, `?`.

## Localization

Plugin-emitted strings ship in `resources/lang/{en,de}/mouseless.php`, follow `app()->getLocale()`. Covers toasts, `?` overlay labels, admin/profile UI, recording-flow validation. A preset's `locale` field describes its mnemonics, not the UI language — these are independent.

## Config shape

```php
return [
    'admin' => [
        'enabled'           => true,
        'default_preset'    => true,
        'disabled_actions'  => true,
        'moderation_queue'  => true,
    ],
    'publishing' => [
        'enabled'          => false,
        'require_approval' => true,
        'gate'             => 'publish-mouseless-preset',
    ],
    'default_preset'   => 'english-default',
    'disabled_actions' => [],
    'reserved_keys'    => ['Escape', 'Tab', 'Enter', 'cmd+r', 'cmd+w', 'cmd+t', 'cmd+l', '?'],
    'list_mode' => [
        'ignore_in' => ['input', 'textarea', '[contenteditable]'],
    ],
];
```



## 

## Out of scope for v0.1

- Preset versioning / update-pinning
- Cross-app preset registry
- Multi-layer presets (base + add-on)
- Vim-style chord mode for resource navigation (deferred — first-wins is enough)
- `vim-list` add-on preset
- allow third partie plugins to register shortcuts
- 
