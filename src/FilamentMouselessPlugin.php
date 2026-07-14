<?php

namespace Blemli\FilamentMouseless;

use Blemli\FilamentMouseless\Filament\Pages\MouselessSettings;
use Blemli\FilamentMouseless\Filament\Pages\MyShortcuts;
use Blemli\FilamentMouseless\Support\Shield;
use Closure;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Blade;

class FilamentMouselessPlugin implements Plugin
{
    public const DEFAULT_ICON = 'heroicon-o-cursor-arrow-ripple';

    protected bool $registerSettingsPage = false;

    protected bool $renderHelpOverlay = true;

    protected bool $renderGoto = true;

    protected bool $showShortcutsOnMobile = false;

    protected bool $keyboardProbeEnabled = true;

    protected bool $stateless = false;

    protected bool $strictPermissions = false;

    protected ?bool $publishable = null;

    protected bool $printableCheatsheet = true;

    protected string | Closure | null $icon = null;

    protected string | Closure | null $settingsPageLabel = null;

    protected string | Closure | null $settingsPageNavigationGroup = null;

    protected bool $settingsPageNavigationGroupSet = false;

    protected string | Closure | null $shortcutsLabel = null;

    protected ?string $shortcutsPosition = null;

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
        $pages = [];

        if (! $this->stateless) {
            $pages[] = MyShortcuts::class;
        }

        if ($this->registerSettingsPage && config('mouseless.admin.enabled', true)) {
            $pages[] = MouselessSettings::class;
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
                ->visible(fn (): bool => Shield::userMayUse())
                ->extraAttributes(['class' => 'fi-mouseless-shortcuts-link']),
        ]);
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
