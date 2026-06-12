<?php

namespace Blemli\FilamentMouseless\Filament\Pages;

use Blemli\FilamentMouseless\Facades\FilamentMouseless;
use Blemli\FilamentMouseless\Filament\Concerns\InteractsWithShortcutsTable;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\Preset;
use Blemli\FilamentMouseless\Models\UserSetting;
use Blemli\FilamentMouseless\Support\Shield;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\On;

class MyShortcuts extends Page implements HasTable
{
    use InteractsWithShortcutsTable;
    use InteractsWithTable;

    protected static ?string $slug = 'my-shortcuts';

    protected string $view = 'filament-mouseless::pages.my-shortcuts';

    public static function getNavigationIcon(): string | \BackedEnum | Htmlable | null
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

    public function table(Table $table): Table
    {
        return $this->shortcutsTable($table);
    }

    // ------------------------------------------------------------------
    // Shortcuts-table host implementation
    // ------------------------------------------------------------------

    public function getShortcutsPreset(): ?array
    {
        return FilamentMouseless::forCurrentUser()['preset'];
    }

    /**
     * Comparison baseline: the preset's parent for personal layouts; the
     * preset itself for built-ins/published ones (locked → nothing "changed").
     */
    public function getShortcutsParentPreset(): ?array
    {
        $preset = $this->getShortcutsPreset();
        if ($preset === null) {
            return null;
        }

        if ($this->isShortcutsLocked()) {
            return $preset;
        }

        return FilamentMouseless::registry()->find($preset['parent_slug'] ?? null, auth()->id())
            ?? $this->localeDefaultPreset()
            ?? $preset;
    }

    public function isShortcutsLocked(): bool
    {
        $preset = $this->getShortcutsPreset();

        return ($preset['owner_user_id'] ?? null) !== auth()->id();
    }

    /**
     * Fork the active (locked) preset into a personal layout
     * („Stephans Layout") and switch the user to it.
     */
    public function ensureEditableShortcutsPreset(): void
    {
        if (! $this->isShortcutsLocked()) {
            return;
        }

        $source = $this->getShortcutsPreset() ?? ['bindings' => []];
        $name = $this->shortcutsForkName();

        $preset = Preset::forkFrom($source, $name);

        $this->setActivePresetSlug($preset->slug);

        Notification::make()
            ->title(__('filament-mouseless::mouseless.table.fork.done', ['name' => $name]))
            ->success()->send();
    }

    public function persistShortcuts(array $bindings, array $disabled): bool
    {
        $preset = $this->ownPresetModel();
        if (! $preset) {
            return false;
        }

        $preset->update([
            'bindings' => $bindings,
            'disabled_actions' => array_values($disabled),
        ]);

        return true;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function ownPresetModel(): ?Preset
    {
        $preset = $this->getShortcutsPreset();
        if (($preset['owner_user_id'] ?? null) !== auth()->id()) {
            return null;
        }

        return Preset::query()
            ->where('owner_user_id', auth()->id())
            ->where('slug', $preset['slug'])
            ->first();
    }

    protected function setActivePresetSlug(?string $slug): void
    {
        UserSetting::updateOrCreate(
            ['user_id' => auth()->id()],
            ['active_preset_slug' => $slug],
        );

        FilamentMouseless::flush();
        $this->dispatch('mouseless-preset-changed');
    }

    /** Re-render (fresh records, banner, actions) when the widget switches presets. */
    #[On('mouseless-preset-changed')]
    public function onPresetChanged(): void
    {
        $this->cancelRecording();
        $this->flushCachedTableRecords();
    }

    protected function localeDefaultPreset(): ?array
    {
        return FilamentMouseless::registry()->builtInForLocale(app()->getLocale())
            ?? FilamentMouseless::registry()->find(config('mouseless.default_preset'));
    }

}
