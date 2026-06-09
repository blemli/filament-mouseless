<?php

namespace Blemli\FilamentMouseless\Filament\Pages;

use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Filament\Pages\Page;

class MyShortcuts extends Page
{
    protected static ?string $slug = 'my-shortcuts';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cursor-arrow-ripple';

    protected string $view = 'filament-mouseless::pages.my-shortcuts';

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
            // Plugin not registered on the current panel — allow access fall-through.
        }

        return true;
    }

    public function getTitle(): string
    {
        return __('filament-mouseless::mouseless.profile.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-mouseless::mouseless.profile.nav_label');
    }
}
