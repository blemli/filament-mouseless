<?php

namespace Blemli\FilamentMouseless\Livewire;

use Blemli\FilamentMouseless\Facades\FilamentMouseless;
use Blemli\FilamentMouseless\Models\Preset;
use Blemli\FilamentMouseless\Models\UserSetting;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Component;

class ShortcutsProfileTab extends Component
{
    public ?string $activePresetSlug = null;

    public array $overrides = [];

    public ?string $recordingActionId = null;

    public string $importJson = '';

    public function mount(): void
    {
        $userId = auth()->id();
        if (! $userId) {
            return;
        }

        $settings = UserSetting::forUser($userId);
        $this->activePresetSlug = $settings->active_preset_slug ?? config('mouseless.default_preset');
        $this->overrides = $settings->overrides ?? [];
    }

    public function updatedActivePresetSlug(): void
    {
        $this->persist();
    }

    public function startRecording(string $actionId): void
    {
        $this->recordingActionId = $actionId;
    }

    public function cancelRecording(): void
    {
        $this->recordingActionId = null;
    }

    public function recordKey(string $key): void
    {
        if (! $this->recordingActionId) {
            return;
        }

        $reserved = (array) config('mouseless.reserved_keys', []);
        if (in_array($key, $reserved, true)) {
            Notification::make()
                ->title(__('filament-mouseless::mouseless.profile.reserved_key', ['key' => $key]))
                ->danger()->send();

            return;
        }

        $resolved = FilamentMouseless::forCurrentUser();
        $collision = collect($resolved['bindings'])
            ->reject(fn ($v, $id) => $id === $this->recordingActionId)
            ->search($key);

        if ($collision !== false) {
            Notification::make()
                ->title(__('filament-mouseless::mouseless.profile.collision', [
                    'action' => $collision,
                    'key' => $key,
                ]))
                ->danger()->send();

            return;
        }

        $this->overrides[$this->recordingActionId] = $key;
        $this->recordingActionId = null;
        $this->persist();

        Notification::make()
            ->title(__('filament-mouseless::mouseless.profile.binding_saved'))
            ->success()->send();
    }

    public function clearOverride(string $actionId): void
    {
        unset($this->overrides[$actionId]);
        $this->persist();
    }

    public function exportJson(): string
    {
        $resolved = FilamentMouseless::forCurrentUser();

        return json_encode([
            'name' => ($resolved['preset']['name'] ?? 'export') . ' (export)',
            'locale' => $resolved['preset']['locale'] ?? 'en',
            'bindings' => $resolved['bindings'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function importPreset(): void
    {
        $data = json_decode($this->importJson, true);
        if (! is_array($data) || ! isset($data['bindings']) || ! is_array($data['bindings'])) {
            Notification::make()
                ->title(__('filament-mouseless::mouseless.profile.import_invalid'))
                ->danger()->send();

            return;
        }

        $slug = Str::slug(($data['name'] ?? 'imported') . '-' . Str::random(6));

        Preset::create([
            'slug' => $slug,
            'name' => $data['name'] ?? __('filament-mouseless::mouseless.profile.imported'),
            'locale' => $data['locale'] ?? 'en',
            'version' => $data['version'] ?? '1.0',
            'owner_user_id' => auth()->id(),
            'source' => 'imported',
            'bindings' => $data['bindings'],
        ]);

        $this->activePresetSlug = $slug;
        $this->overrides = [];
        $this->importJson = '';
        $this->persist();

        Notification::make()
            ->title(__('filament-mouseless::mouseless.profile.import_ok'))
            ->success()->send();
    }

    public function forkToPublish(): void
    {
        if (! config('mouseless.publishing.enabled')) {
            return;
        }
        if (! Gate::allows(config('mouseless.publishing.gate'))) {
            return;
        }

        $resolved = FilamentMouseless::forCurrentUser();
        $base = $resolved['preset'];

        $slug = Str::slug(($base['slug'] ?? 'preset') . '-by-' . auth()->id() . '-' . Str::random(4));

        Preset::create([
            'slug' => $slug,
            'name' => ($base['name'] ?? 'Preset') . ' (fork)',
            'locale' => $base['locale'] ?? 'en',
            'version' => '1.0',
            'owner_user_id' => auth()->id(),
            'source' => 'user',
            'parent_slug' => $base['slug'] ?? null,
            'bindings' => $resolved['bindings'],
            'is_published' => false,
        ]);

        $this->activePresetSlug = $slug;
        $this->overrides = [];
        $this->persist();

        Notification::make()
            ->title(__('filament-mouseless::mouseless.profile.fork_ok'))
            ->success()->send();
    }

    public function publish(): void
    {
        if (! config('mouseless.publishing.enabled')) {
            return;
        }
        if (! Gate::allows(config('mouseless.publishing.gate'))) {
            return;
        }

        $preset = Preset::query()
            ->where('owner_user_id', auth()->id())
            ->where('slug', $this->activePresetSlug)
            ->first();
        if (! $preset) {
            return;
        }

        $preset->is_published = true;
        $preset->published_at = now();
        if (! config('mouseless.publishing.require_approval')) {
            $preset->approved_at = now();
            $preset->approved_by = auth()->id();
        }
        $preset->save();

        Notification::make()
            ->title(__('filament-mouseless::mouseless.profile.published'))
            ->success()->send();
    }

    public function unpublish(): void
    {
        $preset = Preset::query()
            ->where('owner_user_id', auth()->id())
            ->where('slug', $this->activePresetSlug)
            ->first();
        if (! $preset) {
            return;
        }

        $preset->is_published = false;
        $preset->published_at = null;
        $preset->approved_at = null;
        $preset->save();

        Notification::make()
            ->title(__('filament-mouseless::mouseless.profile.unpublished'))
            ->success()->send();
    }

    protected function persist(): void
    {
        $userId = auth()->id();
        if (! $userId) {
            return;
        }

        UserSetting::updateOrCreate(
            ['user_id' => $userId],
            [
                'active_preset_slug' => $this->activePresetSlug,
                'overrides' => $this->overrides,
            ],
        );
    }

    public function render()
    {
        $registry = FilamentMouseless::registry();
        $presets = $registry->all(auth()->id());

        $resolved = FilamentMouseless::forCurrentUser();

        $canPublish = config('mouseless.publishing.enabled')
            && Gate::allows(config('mouseless.publishing.gate'));

        return view('filament-mouseless::livewire.shortcuts-tab', [
            'presets' => $presets,
            'bindings' => $resolved['bindings'],
            'preset' => $resolved['preset'],
            'canPublish' => $canPublish,
        ]);
    }
}
