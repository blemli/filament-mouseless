<?php

namespace Blemli\FilamentMouseless\Filament\Pages;

use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\Preset;
use Blemli\FilamentMouseless\Support\Shield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Moderation subpage of the SuperAdmin area: published presets awaiting
 * approval. Only registered when publishing (with approval) is enabled.
 */
class PresetModeration extends Page
{
    protected static ?string $slug = 'mouseless-settings/moderation';

    protected string $view = 'filament-mouseless::pages.moderation';

    public static function getNavigationIcon(): string | \BackedEnum | Htmlable | null
    {
        return FilamentMouselessPlugin::safeGet()?->getIcon()
            ?? FilamentMouselessPlugin::DEFAULT_ICON;
    }

    public static function getNavigationGroup(): ?string
    {
        return FilamentMouselessPlugin::safeGet()?->getSettingsPageNavigationGroup()
            ?? __('filament-mouseless::mouseless.admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-mouseless::mouseless.admin.moderation_nav_label');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return (bool) config('mouseless.admin.enabled', true)
            && (bool) config('mouseless.publishing.enabled')
            && (bool) config('mouseless.publishing.require_approval')
            && (bool) config('mouseless.admin.moderation_queue', true)
            && MouselessSettings::userMayModerate()
            && Shield::userCanAccessPage(MouselessSettings::class);
    }

    public function getTitle(): string
    {
        return __('filament-mouseless::mouseless.admin.moderation_queue');
    }

    /** @return array<int, Preset> */
    public function getModerationQueue(): array
    {
        return Preset::query()
            ->where('is_published', true)
            ->whereNull('approved_at')
            ->get()
            ->all();
    }

    public function approve(int $presetId): void
    {
        abort_unless(MouselessSettings::userMayModerate(), 403);

        $preset = Preset::findOrFail($presetId);
        $preset->approved_by = auth()->id();
        $preset->approved_at = now();
        $preset->save();

        Notification::make()
            ->title(__('filament-mouseless::mouseless.admin.preset_approved', ['name' => $preset->name]))
            ->success()
            ->send();
    }

    public function reject(int $presetId): void
    {
        abort_unless(MouselessSettings::userMayModerate(), 403);

        $preset = Preset::findOrFail($presetId);
        $preset->is_published = false;
        $preset->published_at = null;
        $preset->save();

        Notification::make()
            ->title(__('filament-mouseless::mouseless.admin.preset_rejected'))
            ->warning()
            ->send();
    }
}
