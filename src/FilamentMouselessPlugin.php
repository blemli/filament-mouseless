<?php

namespace Blemli\FilamentMouseless;

use Blemli\FilamentMouseless\Enums\MouselessAction;
use Blemli\FilamentMouseless\Facades\FilamentMouseless;
use Blemli\FilamentMouseless\Filament\Pages\MouselessSettings;
use Blemli\FilamentMouseless\Filament\Pages\MyShortcuts;
use Blemli\FilamentMouseless\Filament\Pages\PresetModeration;
use Blemli\FilamentMouseless\Support\ActionMeta;
use Blemli\FilamentMouseless\Support\Keys;
use Blemli\FilamentMouseless\Support\Shield;
use Blemli\FilamentMouseless\Support\ShortcutConflicts;
use Closure;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Log;

class FilamentMouselessPlugin implements Plugin
{
    public const DEFAULT_ICON = 'heroicon-o-cursor-arrow-ripple';

    protected bool $registerSettingsPage = false;

    protected bool $renderHelpOverlay = true;

    protected bool $renderUnderlines = true;

    protected bool $renderHints = false;

    protected bool $warnOnProhibited = false;

    protected bool $teachEnabled = false;

    protected int $teachDeferDays = 0;

    protected bool $statisticsEnabled = false;

    protected bool $escapeToDashboardEnabled = false;

    protected ?string $escapeToTarget = null;

    protected bool $renderGoto = true;

    protected bool $showShortcutsOnMobile = false;

    protected bool $keyboardProbeEnabled = true;

    protected bool $stateless = false;

    protected bool $singleton = false;

    protected bool $strictPermissions = false;

    protected ?bool $publishable = null;

    protected bool $printableCheatsheet = true;

    protected string | Closure | null $icon = null;

    protected string | Closure | null $settingsPageLabel = null;

    protected string | Closure | null $settingsPageNavigationGroup = null;

    protected bool $settingsPageNavigationGroupSet = false;

    protected string | Closure | null $shortcutsLabel = null;

    protected ?string $shortcutsPosition = null;

    /** @var array<string, ?string> action id => combo (null/'' = unbind) */
    protected array $remaps = [];

    private static bool $loggedIgnoredRemap = false;

    private static bool $loggedSingletonConflict = false;

    public function getId(): string
    {
        return 'filament-mouseless';
    }

    /**
     * Disable the user-editable shortcuts UI: hides the /my-shortcuts page
     * and the user-menu link. Shortcuts still work — they just can't be
     * customized per user. Use this when you don't want to run the package
     * migrations, or when you want every user on the same preset.
     */
    public function stateless(bool $enabled = true): static
    {
        $this->stateless = $enabled;

        return $this;
    }

    public function isStateless(): bool
    {
        return $this->stateless;
    }

    /**
     * Singleton mode: users never see presets — no preset dropdown, no
     * create/rename/delete/publish layout actions, no import/export, no
     * preset name anywhere. They can still rebind (or disable) every
     * shortcut on /my-shortcuts; behind the scenes the package quietly
     * manages one personal layout per user, created on their first edit.
     *
     * Unlike ->stateless() this keeps per-user state, so the package
     * migrations are still required. Combining it with ->stateless() is
     * contradictory — stateless wins and singleton is ignored (logged once).
     */
    public function singleton(bool $enabled = true): static
    {
        $this->singleton = $enabled;

        return $this;
    }

    public function isSingleton(): bool
    {
        return $this->singleton && ! $this->stateless;
    }

    /** True when the current panel's plugin runs in singleton mode. */
    public static function singletonEnabled(): bool
    {
        return static::safeGet()?->isSingleton() ?? false;
    }

    /**
     * Override one default shortcut without authoring a whole preset — for
     * devs happy with the base layout who just want to move a key or two.
     * Takes the action id (string or {@see MouselessAction} case) and the
     * new combo; pass null (or '') to disable the shortcut entirely.
     *
     * The override rewrites every built-in preset (all locales), so it shows
     * up consistently in the overlay, cheatsheet, hints and /my-shortcuts.
     * A user who explicitly rebound the action keeps their own choice.
     * Same idea in the config file: `mouseless.remap`. Fluent calls win
     * over config entries.
     */
    public function remap(string | MouselessAction $action, ?string $combo): static
    {
        $this->remaps[$action instanceof MouselessAction ? $action->value : $action] = $combo;

        return $this;
    }

    /** @return array<string, ?string> */
    public function getRemaps(): array
    {
        return $this->remaps;
    }

    /**
     * Effective remap overrides: `mouseless.remap` config with the current
     * panel's ->remap() calls layered on top. Combos are alias-translated
     * ('opt+x' → 'alt+x', 'mod' stays platform-neutral) and empty combos
     * become null (= unbound). Protected bindings (Escape) are ignored —
     * without them users couldn't close or cancel anything.
     *
     * @return array<string, ?string>
     */
    public static function remapOverrides(): array
    {
        $merged = array_merge(
            (array) config('mouseless.remap', []),
            static::safeGet()?->getRemaps() ?? [],
        );

        $overrides = [];

        foreach ($merged as $actionId => $combo) {
            if (ActionMeta::isProtected($actionId)) {
                if (! self::$loggedIgnoredRemap) {
                    self::$loggedIgnoredRemap = true;
                    Log::warning("[filament-mouseless] Ignoring remap of protected action '{$actionId}' — its binding cannot be changed.");
                }

                continue;
            }

            $overrides[$actionId] = Keys::translateAliases($combo);
        }

        return $overrides;
    }

    /**
     * Enforce the Shield permissions (`MouselessUse`, `View:MyShortcuts`,
     * `View:MouselessSettings`). Without this call, mouseless ignores those
     * permissions even when Shield is installed — the plugin Just Works
     * out of the box. Call this once you've assigned the perms to roles.
     */
    public function strictPermissions(bool $enabled = true): static
    {
        $this->strictPermissions = $enabled;

        return $this;
    }

    public function isStrict(): bool
    {
        return $this->strictPermissions;
    }

    /**
     * Let users publish their layouts to everyone (and make them private
     * again). Equivalent to setting `mouseless.publishing.enabled`; the
     * `mouseless.publishing.gate` (when non-null) still controls who may.
     */
    public function publishable(bool $enabled = true): static
    {
        $this->publishable = $enabled;

        return $this;
    }

    public function isPublishable(): ?bool
    {
        return $this->publishable;
    }

    /** Current panel's flag when set, else the config default. */
    public static function publishingEnabled(): bool
    {
        return static::safeGet()?->isPublishable()
            ?? (bool) config('mouseless.publishing.enabled');
    }

    /**
     * Opt in to the `/mouseless-settings` admin page (default presets,
     * disabled actions, moderation queue). Off by default
     * — the plugin runs perfectly well without it. Enable it when you want
     * admins to tune mouseless from inside Filament instead of editing the
     * config file.
     */
    public function settingsPage(bool $enabled = true): static
    {
        $this->registerSettingsPage = $enabled;

        return $this;
    }

    public function helpOverlay(bool $enabled = true): static
    {
        $this->renderHelpOverlay = $enabled;

        return $this;
    }

    public function disableHelpOverlay(): static
    {
        return $this->helpOverlay(false);
    }

    /**
     * Accelerator underlines — the shortcut initial underlined in every action
     * button's label (Windows style). On by default; pass false (or call
     * disableUnderlines()) to keep button labels untouched. The ⌥-hold hint
     * badges are unaffected.
     */
    public function underlines(bool $enabled = true): static
    {
        $this->renderUnderlines = $enabled;

        return $this;
    }

    public function disableUnderlines(): static
    {
        return $this->underlines(false);
    }

    public static function underlinesEnabled(): bool
    {
        return static::safeGet()?->renderUnderlines ?? true;
    }

    /**
     * ⌥-hold hint badges — big key badges popping over every reachable target
     * while the bare Alt/Option key is held outside a text field. Opt-in:
     * call ->hints() to enable.
     */
    public function hints(bool $enabled = true): static
    {
        $this->renderHints = $enabled;

        return $this;
    }

    public static function hintsEnabled(): bool
    {
        return static::safeGet()?->renderHints ?? false;
    }

    /**
     * Teach missed shortcuts: whenever the user MOUSE-clicks something that
     * has a keyboard shortcut, a notification points it out ("you could have
     * just hit ⌥E") with actions to change the shortcut, silence that action,
     * or mute teaching entirely. At most one nudge per page view, and repeat
     * nudges for the same action back off — hourly, then daily, then weekly —
     * until the user either uses the shortcut once (learned: never nudged
     * again) or dismisses it. Opt-in; needs the package migrations (per-user
     * state), so ->stateless() panels ignore it.
     */
    public function teach(bool $enabled = true): static
    {
        $this->teachEnabled = $enabled;

        return $this;
    }

    public static function teachingEnabled(): bool
    {
        $plugin = static::safeGet();

        return ($plugin?->teachEnabled ?? false) && ! ($plugin?->isStateless() ?? false);
    }

    /**
     * Track avoided clicks: every keyboard invocation of an action counts as
     * one click avoided, and mouse clicks on targets that HAVE a shortcut are
     * counted too — giving each action a shortcut/click ratio. Powers the
     * statistics widget on /my-shortcuts (clicks avoided, trend, untapped
     * actions), the hidden "Invoked" table column, the all-users block on the
     * admin settings page, and the 100 / 1'000 / 10'000 milestone
     * congratulations. Opt-in; needs the mouseless_statistics migration, so
     * ->stateless() panels ignore it.
     */
    public function statistics(bool $enabled = true): static
    {
        $this->statisticsEnabled = $enabled;

        return $this;
    }

    public static function statisticsEnabled(): bool
    {
        $plugin = static::safeGet();

        return ($plugin?->statisticsEnabled ?? false) && ! ($plugin?->isStateless() ?? false);
    }

    /**
     * Grace period for newcomers: no teach notifications during a user's
     * first X days (measured from their account's created_at). Someone still
     * finding the buttons shouldn't be told to skip them.
     */
    public function deferDays(int $days): static
    {
        $this->teachDeferDays = max(0, $days);

        return $this;
    }

    public static function teachDeferredDays(): int
    {
        return static::safeGet()?->teachDeferDays ?? 0;
    }

    /**
     * Reserved (browser/OS) combos are hard-blocked by default: the recorder
     * refuses them, imports reject them, and the engine never registers them.
     * Call this to downgrade the block to a warning — the combo is accepted
     * and registered at the user's own risk; the browser may still win.
     */
    public function onlyWarnOnProhibited(bool $enabled = true): static
    {
        $this->warnOnProhibited = $enabled;

        return $this;
    }

    public static function prohibitionsAreSoft(): bool
    {
        return static::safeGet()?->warnOnProhibited ?? false;
    }

    /**
     * Escape as an exit: once there is nothing left to close (no overlay, no
     * selection, no record page, no focus), another Esc leaves for the
     * dashboard — unless the list carries filter/search/tab/group state the
     * user would lose. Pass a URL or a Filament page class to escape to that
     * page instead of the dashboard.
     */
    public function escapeToDashboard(?string $target = null): static
    {
        $this->escapeToDashboardEnabled = true;
        $this->escapeToTarget = $target;

        return $this;
    }

    /** The resolved escape-target URL, or null when the feature is off. */
    public static function escapeTarget(): ?string
    {
        $plugin = static::safeGet();
        if (! $plugin?->escapeToDashboardEnabled) {
            return null;
        }

        $target = $plugin->escapeToTarget;

        if ($target === null) {
            try {
                return Filament::getHomeUrl() ?: '/';
            } catch (\Throwable) {
                return '/';
            }
        }

        // A Filament page class resolves to its panel URL.
        if (class_exists($target) && method_exists($target, 'getUrl')) {
            try {
                return $target::getUrl();
            } catch (\Throwable) {
                return null;
            }
        }

        return $target;
    }

    /**
     * The "go to" palette — a leader-key overlay (default `g`) that jumps to
     * any navigation target by typing its name (JetBrains-style camel-hump
     * chords: "Product Categories" → type `pc`). On by default; the leader key
     * itself is the `nav.goto` binding, so users rebind it on /my-shortcuts
     * like any other shortcut. Pass false to drop the overlay entirely.
     */
    public function goto(bool $enabled = true): static
    {
        $this->renderGoto = $enabled;

        return $this;
    }

    public function disableGoto(): static
    {
        return $this->goto(false);
    }

    public function isGotoEnabled(): bool
    {
        return $this->renderGoto;
    }

    /**
     * The help overlay doubles as a printable cheatsheet (print button in
     * the footer + print stylesheet). Pass false to disable both.
     */
    public function printableCheatsheet(bool $enabled = true): static
    {
        $this->printableCheatsheet = $enabled;

        return $this;
    }

    public function disableCheatsheetPrinting(): static
    {
        return $this->printableCheatsheet(false);
    }

    public function isCheatsheetPrintable(): bool
    {
        return $this->printableCheatsheet;
    }

    public function showShortcutsOnMobile(bool $enabled = true): static
    {
        $this->showShortcutsOnMobile = $enabled;

        return $this;
    }

    public function disableProbe(): static
    {
        $this->keyboardProbeEnabled = false;

        return $this;
    }

    /** Icon shared by the admin settings page nav AND the user-menu link. */
    public function icon(string | Closure $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /** Sidebar label for the admin settings page. */
    public function settingsPageLabel(string | Closure $label): static
    {
        $this->settingsPageLabel = $label;

        return $this;
    }

    /**
     * Sidebar navigation group for the admin settings page. Pass `null` to
     * remove the group entirely; never calling this leaves the translated
     * "System" default in place.
     */
    public function settingsPageNavigationGroup(string | Closure | null $group): static
    {
        $this->settingsPageNavigationGroup = $group;
        $this->settingsPageNavigationGroupSet = true;

        return $this;
    }

    /** Label for the user-menu link AND the my-shortcuts page navigation. */
    public function shortcutsLabel(string | Closure $label): static
    {
        $this->shortcutsLabel = $label;

        return $this;
    }

    /**
     * Pin the my-shortcuts link to a render hook instead of listing it with
     * the other user-menu items (where it sorts in above logout). Meant for
     * PanelsRenderHook::USER_MENU_PROFILE_BEFORE / USER_MENU_PROFILE_AFTER,
     * which place it directly above/below the profile entry.
     */
    public function shortcutsPosition(?string $renderHook): static
    {
        $this->shortcutsPosition = $renderHook;

        return $this;
    }

    public function getIcon(): string
    {
        return $this->evaluate($this->icon) ?? self::DEFAULT_ICON;
    }

    public function getSettingsPageLabel(): string
    {
        return $this->evaluate($this->settingsPageLabel)
            ?? __('filament-mouseless::mouseless.admin.nav_label');
    }

    public function getSettingsPageNavigationGroup(): ?string
    {
        if (! $this->settingsPageNavigationGroupSet) {
            return __('filament-mouseless::mouseless.admin.nav_group');
        }

        return $this->evaluate($this->settingsPageNavigationGroup);
    }

    public function getShortcutsLabel(): string
    {
        return $this->evaluate($this->shortcutsLabel)
            ?? __('filament-mouseless::mouseless.profile.nav_label');
    }

    protected function evaluate(string | Closure | null $value): ?string
    {
        if ($value instanceof Closure) {
            $value = $value();
        }

        return $value === '' ? null : $value;
    }

    public function register(Panel $panel): void
    {
        if ($this->singleton && $this->stateless && ! self::$loggedSingletonConflict) {
            self::$loggedSingletonConflict = true;
            Log::warning('[filament-mouseless] ->singleton() and ->stateless() are mutually exclusive — stateless wins, singleton mode is ignored. Remove one of the two calls.');
        }

        $pages = [];

        if (! $this->stateless) {
            $pages[] = MyShortcuts::class;
        }

        if ($this->registerSettingsPage && config('mouseless.admin.enabled', true)) {
            $pages[] = MouselessSettings::class;
            $pages[] = PresetModeration::class;
        }

        $panel->pages($pages);

        if ($this->stateless || $this->shortcutsPosition !== null) {
            return;
        }

        // Link is always rendered; CSS hides it on mobile when no keyboard has
        // been detected. Shield's `MouselessUse` also hides it when denied.
        $panel->userMenuItems([
            Action::make('mouseless-shortcuts')
                ->label(fn (): string => $this->getShortcutsLabel())
                ->icon(fn (): string => $this->getIcon())
                ->url(fn () => MyShortcuts::getUrl())
                ->badge(fn (): ?string => $this->shortcutsDuplicateBadge())
                ->badgeColor('danger')
                ->visible(fn (): bool => Shield::userMayUse())
                ->extraAttributes(['class' => 'fi-mouseless-shortcuts-link']),
        ]);
    }

    /**
     * Badge for the shortcuts menu item: the number of actions whose key combo
     * collides with another (the same rows the table flags "Mehrfach belegt"),
     * or null when there are none. Runs on every user-menu render, so it stays
     * cheap and swallows failures rather than breaking the panel chrome.
     */
    public function shortcutsDuplicateBadge(): ?string
    {
        if ($this->stateless || ! Shield::userMayUse()) {
            return null;
        }

        try {
            $count = ShortcutConflicts::duplicateCount(FilamentMouseless::forCurrentUser()['preset'] ?? null);
        } catch (\Throwable) {
            return null;
        }

        return $count > 0 ? (string) $count : null;
    }

    public function boot(Panel $panel): void
    {
        if ($this->renderHelpOverlay) {
            FilamentView::registerRenderHook(
                'panels::body.end',
                fn (): string => Shield::userMayUse()
                    ? Blade::render('@livewire(\Blemli\FilamentMouseless\Livewire\HelpOverlay::class)')
                    : '',
            );
        }

        if ($this->renderGoto) {
            FilamentView::registerRenderHook(
                'panels::body.end',
                fn (): string => Shield::userMayUse()
                    ? Blade::render('@livewire(\Blemli\FilamentMouseless\Livewire\GotoPalette::class)')
                    : '',
            );
        }

        if ($this->teachEnabled && ! $this->stateless) {
            FilamentView::registerRenderHook(
                'panels::body.end',
                fn (): string => Shield::userMayUse()
                    ? Blade::render('@livewire(\Blemli\FilamentMouseless\Livewire\TeachNudges::class)')
                    : '',
            );
        }

        if ($this->statisticsEnabled && ! $this->stateless) {
            FilamentView::registerRenderHook(
                'panels::body.end',
                fn (): string => Shield::userMayUse()
                    ? Blade::render('@livewire(\Blemli\FilamentMouseless\Livewire\StatisticsFlush::class)')
                    : '',
            );
        }

        FilamentView::registerRenderHook(
            'panels::body.start',
            fn (): string => Shield::userMayUse() ? $this->renderBootScript() : '',
        );

        if (! $this->stateless && $this->shortcutsPosition !== null) {
            FilamentView::registerRenderHook(
                $this->shortcutsPosition,
                fn (): string => Shield::userMayUse()
                    ? view('filament-mouseless::components.user-menu-item', [
                        'label' => $this->getShortcutsLabel(),
                        'icon' => $this->getIcon(),
                        'url' => MyShortcuts::getUrl(),
                        'badge' => $this->shortcutsDuplicateBadge(),
                    ])->render()
                    : '',
            );
        }
    }

    /**
     * Tiny synchronous script emitted at the very top of <body>. It runs before
     * the topbar paints, so the user-menu's mouseless link is hidden or shown
     * without a flash. All state is client-side: navigator.userAgent for mobile
     * detection, localStorage for "this browser has a keyboard" persistence.
     */
    protected function renderBootScript(): string
    {
        $alwaysShow = $this->showShortcutsOnMobile ? 'true' : 'false';
        $probeDisabled = $this->keyboardProbeEnabled ? 'false' : 'true';

        return <<<HTML
<script>(function(){
    var b=document.body;if(!b)return;
    try{if(localStorage.getItem('mouseless_kbd')==='1')b.classList.add('fi-mouseless-kbd');}catch(e){}
    if({$alwaysShow}){b.classList.add('fi-mouseless-kbd');}
    else if(/Mobile|Android|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent||'')){b.classList.add('fi-mouseless-mobile');}
    if({$probeDisabled})window.mouselessKeyboardProbeDisabled=true;
})();</script>
HTML;
    }

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    /** Like {@see get()} but returns null when the plugin isn't registered. */
    public static function safeGet(): ?static
    {
        try {
            return static::get();
        } catch (\Throwable) {
            return null;
        }
    }
}
