<?php

namespace Blemli\FilamentMouseless\Filament\Pages;

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
        return auth()->check();
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
