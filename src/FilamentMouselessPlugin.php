<?php

namespace Blemli\FilamentMouseless;

use Blemli\FilamentMouseless\Filament\Pages\MouselessSettings;
use Blemli\FilamentMouseless\Filament\Pages\MyShortcuts;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Illuminate\Support\Facades\Blade;

class FilamentMouselessPlugin implements Plugin
{
    protected bool $registerAdminPage = true;

    protected bool $renderHelpOverlay = true;

    protected bool $showShortcutsOnMobile = false;

    protected bool $keyboardProbeEnabled = true;

    protected bool $stateless = false;

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

    public function adminPage(bool $enabled = true): static
    {
        $this->registerAdminPage = $enabled;

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

    public function register(Panel $panel): void
    {
        $pages = [];

        if (! $this->stateless) {
            $pages[] = MyShortcuts::class;
        }

        if ($this->registerAdminPage && config('mouseless.admin.enabled', true)) {
            $pages[] = MouselessSettings::class;
        }

        $panel->pages($pages);

        if ($this->stateless) {
            return;
        }

        // Always render the link; CSS hides it on mobile when no keyboard has
        // been detected. The fi-mouseless-shortcuts-link class is the CSS hook.
        $panel->userMenuItems([
            Action::make('mouseless-shortcuts')
                ->label(fn () => __('filament-mouseless::mouseless.profile.nav_label'))
                ->icon('heroicon-o-cursor-arrow-ripple')
                ->url(fn () => MyShortcuts::getUrl())
                ->extraAttributes(['class' => 'fi-mouseless-shortcuts-link']),
        ]);
    }

    public function boot(Panel $panel): void
    {
        if ($this->renderHelpOverlay) {
            FilamentView::registerRenderHook(
                'panels::body.end',
                fn (): string => Blade::render('@livewire(\Blemli\FilamentMouseless\Livewire\HelpOverlay::class)'),
            );
        }

        FilamentView::registerRenderHook(
            'panels::body.start',
            fn (): string => $this->renderBootScript(),
        );
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
}
