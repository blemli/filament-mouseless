<?php

namespace Blemli\FilamentMouseless\Filament\Pages;

use Blemli\FilamentMouseless\Facades\FilamentMouseless;
use Blemli\FilamentMouseless\Models\Preset;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Gate;

class MouselessSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'mouseless-settings';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cursor-arrow-ripple';

    protected static string | \UnitEnum | null $navigationGroup = null;

    public static function getNavigationGroup(): ?string
    {
        return __('filament-mouseless::mouseless.admin.nav_group');
    }

    public static function getNavigationLabel(): string
    {
        return __('filament-mouseless::mouseless.admin.nav_label');
    }

    protected string $view = 'filament-mouseless::pages.settings';

    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        return (bool) config('mouseless.admin.enabled', true)
            && static::userMayModerate();
    }

    public static function canAccess(): bool
    {
        return (bool) config('mouseless.admin.enabled', true)
            && static::userMayModerate();
    }

    /**
     * Gate-based authorization for everything this page exposes.
     *
     * If `mouseless.admin.gate` is set, the user must pass that Gate ability —
     * fail-closed. If it's null/empty, the page is open to any authenticated
     * panel user (the config flag is the only gate). Consuming apps SHOULD
     * register a Gate ability and point this config at it.
     */
    protected static function userMayModerate(): bool
    {
        $ability = config('mouseless.admin.gate');

        if (! $ability) {
            return true;
        }

        return Gate::allows($ability);
    }

    public function getTitle(): string
    {
        return __('filament-mouseless::mouseless.admin.title');
    }

    public function mount(): void
    {
        $resourceLetters = collect(config('mouseless.resource_letters', []))
            ->map(fn ($letter, $class) => ['resource' => $class, 'letter' => $letter])
            ->values()
            ->all();

        $this->form->fill([
            'default_preset' => config('mouseless.default_preset'),
            'disabled_actions' => config('mouseless.disabled_actions', []),
            'resource_letters' => $resourceLetters,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function getResourceOptions(): array
    {
        $panel = Filament::getCurrentPanel();
        if (! $panel) {
            return [];
        }

        $options = [];
        foreach ($panel->getResources() as $resourceClass) {
            $options[$resourceClass] = $this->resourceLabel($resourceClass);
        }
        asort($options);

        return $options;
    }

    /**
     * Best-effort translated, pluralized label for a Filament resource. Falls
     * back gracefully if any of the resource's label methods aren't defined.
     */
    protected function resourceLabel(string $resourceClass): string
    {
        foreach (['getNavigationLabel', 'getPluralModelLabel', 'getModelLabel'] as $method) {
            if (! method_exists($resourceClass, $method)) {
                continue;
            }

            try {
                $label = $resourceClass::{$method}();
                if (is_string($label) && $label !== '') {
                    return ucfirst($label);
                }
            } catch (\Throwable) {
                // Resource may require a panel/container context we don't have — skip.
            }
        }

        return class_basename($resourceClass);
    }

    public function form(Schema $schema): Schema
    {
        $presets = collect(FilamentMouseless::registry()->all())
            ->mapWithKeys(fn ($p, $slug) => [$slug => ($p['name'] ?? $slug) . ' (' . ($p['locale'] ?? '?') . ')'])
            ->all();

        $actions = collect(FilamentMouseless::registry()->builtIn())
            ->flatMap(fn ($p) => array_keys($p['bindings'] ?? []))
            ->unique()
            ->sort()
            ->mapWithKeys(fn ($id) => [$id => __('filament-mouseless::mouseless.action.' . $id)])
            ->all();

        return $schema
            ->components([
                Section::make(__('filament-mouseless::mouseless.admin.default_preset'))
                    ->visible((bool) config('mouseless.admin.default_preset', true))
                    ->components([
                        Select::make('default_preset')
                            ->label(__('filament-mouseless::mouseless.admin.default_preset'))
                            ->hiddenLabel()
                            ->options($presets)
                            ->searchable()
                            ->required(),
                    ]),

                Section::make(__('filament-mouseless::mouseless.admin.disabled_actions'))
                    ->visible((bool) config('mouseless.admin.disabled_actions', true))
                    ->components([
                        CheckboxList::make('disabled_actions')
                            ->label(__('filament-mouseless::mouseless.admin.disabled_actions'))
                            ->hiddenLabel()
                            ->options($actions)
                            ->columns(2)
                            ->searchable(),
                    ]),

                Section::make(__('filament-mouseless::mouseless.admin.resource_letters'))
                    ->visible((bool) config('mouseless.admin.resource_letters', true))
                    ->components([
                        Repeater::make('resource_letters')
                            ->label(__('filament-mouseless::mouseless.admin.resource_letters'))
                            ->hiddenLabel()
                            ->schema([
                                Select::make('resource')
                                    ->label(__('filament-mouseless::mouseless.admin.resource_class'))
                                    ->options(function (Get $get) {
                                        $all = $get('../../resource_letters') ?? [];
                                        $current = $get('resource');
                                        $usedElsewhere = collect($all)
                                            ->pluck('resource')
                                            ->filter()
                                            ->reject(fn ($r) => $r === $current)
                                            ->all();

                                        return collect($this->getResourceOptions())
                                            ->reject(fn ($label, $class) => in_array($class, $usedElsewhere, true))
                                            ->all();
                                    })
                                    ->live()
                                    ->searchable()
                                    ->required(),
                                TextInput::make('letter')
                                    ->label(__('filament-mouseless::mouseless.admin.letter'))
                                    ->maxLength(1)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->reorderable(false)
                            ->addActionLabel(__('filament-mouseless::mouseless.admin.add_override')),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::userMayModerate(), 403);

        $payload = $this->form->getState();

        // Flatten repeater rows back to class => letter for storage / consumer code.
        $payload['resource_letters'] = collect($payload['resource_letters'] ?? [])
            ->filter(fn ($row) => ! empty($row['resource']) && ! empty($row['letter']))
            ->mapWithKeys(fn ($row) => [$row['resource'] => $row['letter']])
            ->all();

        cache()->forever('mouseless.admin_overlay', $payload);

        Notification::make()
            ->title(__('filament-mouseless::mouseless.admin.saved'))
            ->success()
            ->send();
    }

    public function getModerationQueue(): array
    {
        if (! config('mouseless.publishing.enabled')) {
            return [];
        }
        if (! config('mouseless.publishing.require_approval')) {
            return [];
        }
        if (! config('mouseless.admin.moderation_queue', true)) {
            return [];
        }

        return Preset::query()
            ->where('is_published', true)
            ->whereNull('approved_at')
            ->get()
            ->all();
    }

    public function approve(int $presetId): void
    {
        abort_unless(static::userMayModerate(), 403);

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
        abort_unless(static::userMayModerate(), 403);

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
