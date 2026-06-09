<?php

namespace Blemli\FilamentMouseless\Filament\Pages;

use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Support\Shield;
use Filament\Pages\Page;

class MyShortcuts extends Page
{
    protected static ?string $slug = 'my-shortcuts';

    protected string $view = 'filament-mouseless::pages.my-shortcuts';

    public static function getNavigationIcon(): string|\BackedEnum|\Illuminate\Contracts\Support\Htmlable|null
    {
        return FilamentMouselessPlugin::safeGet()?->getIcon()
            ?? FilamentMouselessPlugin::DEFAULT_ICON;
    }

    public static function shouldRegisterNavigation(): bool
    {
        // Hidden from sidebar — reached via the user-menu entry.
        return false;
    }

    public static function canAccess(): bool
    {
        if (! auth()->check()) {
            return false;
        }

        try {
            if (FilamentMouselessPlugin::get()->isStateless()) {
                return false;
            }
        } catch (\Throwable) {
            // Plugin not registered on this panel — fall through to Shield check.
        }

        return Shield::userCanAccessPage(static::class);
    }

    public function getTitle(): string
    {
        return __('filament-mouseless::mouseless.profile.title');
    }

    public static function getNavigationLabel(): string
    {
        return FilamentMouselessPlugin::safeGet()?->getShortcutsLabel()
            ?? __('filament-mouseless::mouseless.profile.nav_label');
    }
}
