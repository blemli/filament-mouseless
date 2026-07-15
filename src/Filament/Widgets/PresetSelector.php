<?php

namespace Blemli\FilamentMouseless\Filament\Widgets;

use Blemli\FilamentMouseless\Facades\FilamentMouseless;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\Preset;
use Blemli\FilamentMouseless\Models\UserSetting;
use Blemli\FilamentMouseless\Support\PresetTransfer;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Widgets\Widget;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;

class PresetSelector extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    /** Sentinel option: no explicit selection — follow the UI locale's default. */
    protected const DEFAULT_OPTION = '__default';

    protected string $view = 'filament-mouseless::widgets.preset-selector';

    public ?array $data = [];

    /** Validated import payload carried from the import modal to the conflict prompt. */
    public ?array $pendingImport = null;

    /** Per-request memo for ownActivePreset() — reset on every sync. */
    private ?Preset $ownPresetMemo = null;

    private bool $ownPresetMemoLoaded = false;

    public function mount(): void
    {
        $this->syncFromSettings();
    }

    #[On('mouseless-preset-changed')]
    public function syncFromSettings(): void
    {
        $this->ownPresetMemo = null;
        $this->ownPresetMemoLoaded = false;

        $this->form->fill([
            'activePresetSlug' => UserSetting::forUser(auth()->id())->active_preset_slug ?? self::DEFAULT_OPTION,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Select::make('activePresetSlug')
                    ->hiddenLabel()
                    // Closure: evaluated at render time, so renames done earlier
                    // in the same request already show their new labels.
                    ->options(fn (): array => $this->getPresetOptions())
                    ->allowHtml()
                    ->searchable()
                    ->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(fn () => $this->persistSelection()),
            ]);
    }

    protected function persistSelection(): void
    {
        UserSetting::updateOrCreate(
            ['user_id' => auth()->id()],
            ['active_preset_slug' => $this->selectedSlug()],
        );

        FilamentMouseless::flush();
        $this->dispatch('mouseless-preset-changed');
    }

    protected function selectedSlug(): ?string
    {
        $slug = $this->data['activePresetSlug'] ?? null;

        return ($slug === self::DEFAULT_OPTION || blank($slug)) ? null : $slug;
    }

    /**
     * @return array<string, string>
     */
    public function getPresetOptions(): array
    {
        $options = [
            self::DEFAULT_OPTION => $this->optionHtml(
                __('filament-mouseless::mouseless.widget.follow_locale'),
                __('filament-mouseless::mouseless.widget.option_follow_locale'),
            ),
        ];

        foreach (FilamentMouseless::registry()->all(auth()->id()) as $slug => $preset) {
            $options[$slug] = $this->optionHtml(
                $preset['name'] ?? $slug,
                $this->optionSubtitle($preset),
            );
        }

        return $options;
    }

    protected function optionSubtitle(array $preset): string
    {
        if (filled($preset['description'] ?? null)) {
            return $preset['description'];
        }

        $source = match (true) {
            ($preset['source'] ?? null) === 'builtin' => __('filament-mouseless::mouseless.widget.option_builtin'),
            ($preset['owner_user_id'] ?? null) === auth()->id() => __('filament-mouseless::mouseless.widget.option_own'),
            default => __('filament-mouseless::mouseless.widget.option_published'),
        };

        $bindings = count(array_filter((array) ($preset['bindings'] ?? []), fn ($combo) => $combo !== null));

        return implode(' · ', array_filter([
            $source,
            strtoupper($preset['locale'] ?? ''),
            __('filament-mouseless::mouseless.widget.option_bindings', ['count' => $bindings]),
        ]));
    }

    protected function optionHtml(string $title, string $subtitle): string
    {
        return '<div class="fi-mouseless-option">'
            . '<div class="fi-mouseless-option-title">' . e($title) . '</div>'
            . '<div class="fi-mouseless-option-sub">' . e($subtitle) . '</div>'
            . '</div>';
    }

    // ------------------------------------------------------------------
    // Layout actions
    // ------------------------------------------------------------------

    public function createLayoutAction(): Action
    {
        return Action::make('createLayout')
            ->label(__('filament-mouseless::mouseless.widget.create'))
            ->icon('heroicon-m-plus')
            ->iconButton()
            ->color('gray')
            ->tooltip(__('filament-mouseless::mouseless.widget.create'))
            ->modalHeading(__('filament-mouseless::mouseless.widget.create'))
            ->schema([
                TextInput::make('name')
                    ->label(__('filament-mouseless::mouseless.widget.name'))
                    ->required()
                    ->maxLength(100)
                    ->default(fn (): string => Preset::defaultLayoutName())
                    ->rules([
                        fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (Preset::isNameTakenForUser((string) $value)) {
                                $fail(__('filament-mouseless::mouseless.widget.name_taken'));
                            }
                        },
                    ]),
                Textarea::make('description')
                    ->label(__('filament-mouseless::mouseless.widget.description'))
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data): void {
                $source = FilamentMouseless::forCurrentUser()['preset'] ?? [];

                $preset = Preset::forkFrom(
                    $source,
                    $data['name'],
                    filled($data['description'] ?? null) ? $data['description'] : null,
                );

                $this->switchTo($preset->slug);

                Notification::make()
                    ->title(__('filament-mouseless::mouseless.table.fork.done', ['name' => $preset->name]))
                    ->success()->send();
            });
    }

    public function renameLayoutAction(): Action
    {
        return Action::make('renameLayout')
            ->label(__('filament-mouseless::mouseless.widget.rename'))
            ->icon('heroicon-m-pencil-square')
            ->button()
            ->color('gray')
            ->visible(fn (): bool => $this->ownActivePreset() !== null)
            ->modalHeading(__('filament-mouseless::mouseless.widget.rename'))
            ->schema([
                TextInput::make('name')
                    ->label(__('filament-mouseless::mouseless.widget.name'))
                    ->required()
                    ->maxLength(100)
                    ->default(fn (): ?string => $this->ownActivePreset()?->name)
                    ->rules([
                        fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (Preset::isNameTakenForUser((string) $value, $this->selectedSlug())) {
                                $fail(__('filament-mouseless::mouseless.widget.name_taken'));
                            }
                        },
                    ]),
                Textarea::make('description')
                    ->label(__('filament-mouseless::mouseless.widget.description'))
                    ->rows(2)
                    ->maxLength(500)
                    ->default(fn (): ?string => $this->ownActivePreset()?->description),
            ])
            ->action(function (array $data): void {
                $preset = $this->ownActivePreset();
                if (! $preset) {
                    return;
                }

                $preset->update([
                    'name' => $data['name'],
                    'description' => filled($data['description'] ?? null) ? $data['description'] : null,
                ]);
                FilamentMouseless::flush();
                $this->dispatch('mouseless-preset-changed');

                Notification::make()
                    ->title(__('filament-mouseless::mouseless.widget.renamed'))
                    ->success()->send();
            });
    }

    public function publishLayoutAction(): Action
    {
        $isPublished = fn (): bool => (bool) $this->ownActivePreset()?->is_published;

        return Action::make('publishLayout')
            ->label(fn (): string => $isPublished()
                ? __('filament-mouseless::mouseless.widget.make_private')
                : __('filament-mouseless::mouseless.widget.publish'))
            ->icon(fn (): string => $isPublished() ? 'heroicon-m-lock-closed' : 'heroicon-m-globe-alt')
            ->button()
            ->color(fn (): string => $isPublished() ? 'warning' : 'primary')
            ->visible(fn (): bool => $this->canPublish() && $this->ownActivePreset() !== null)
            ->requiresConfirmation()
            ->modalHeading(fn (): string => $isPublished()
                ? __('filament-mouseless::mouseless.widget.make_private_heading')
                : __('filament-mouseless::mouseless.widget.publish_heading'))
            ->modalDescription(function () use ($isPublished): string {
                $name = $this->ownActivePreset()?->name ?? '';

                if ($isPublished()) {
                    return __('filament-mouseless::mouseless.widget.make_private_description', ['name' => $name]);
                }

                return config('mouseless.publishing.require_approval')
                    ? __('filament-mouseless::mouseless.widget.publish_description_approval', ['name' => $name])
                    : __('filament-mouseless::mouseless.widget.publish_description', ['name' => $name]);
            })
            ->action(function (): void {
                $preset = $this->ownActivePreset();
                if (! $preset || ! $this->canPublish()) {
                    return;
                }

                // Published layouts carry the author's full name; private ones don't.
                $authorSuffix = ' (' . (auth()->user()->name ?? '') . ')';

                if ($preset->is_published) {
                    $preset->update([
                        'is_published' => false,
                        'published_at' => null,
                        'approved_at' => null,
                        'approved_by' => null,
                        'name' => str_ends_with($preset->name, $authorSuffix)
                            ? substr($preset->name, 0, -strlen($authorSuffix))
                            : $preset->name,
                    ]);

                    Notification::make()
                        ->title(__('filament-mouseless::mouseless.widget.private_done'))
                        ->success()->send();
                } else {
                    $requiresApproval = (bool) config('mouseless.publishing.require_approval');

                    $preset->update([
                        'is_published' => true,
                        'published_at' => now(),
                        'name' => str_ends_with($preset->name, $authorSuffix)
                            ? $preset->name
                            : $preset->name . $authorSuffix,
                        ...($requiresApproval ? [] : ['approved_at' => now(), 'approved_by' => auth()->id()]),
                    ]);

                    Notification::make()
                        ->title(__($requiresApproval
                            ? 'filament-mouseless::mouseless.widget.published_pending'
                            : 'filament-mouseless::mouseless.widget.published'))
                        ->success()->send();
                }

                FilamentMouseless::flush();
                $this->dispatch('mouseless-preset-changed');
            });
    }

    public function deleteLayoutAction(): Action
    {
        return Action::make('deleteLayout')
            ->label(__('filament-mouseless::mouseless.widget.delete'))
            ->icon('heroicon-m-trash')
            ->button()
            ->color('danger')
            ->visible(fn (): bool => $this->ownActivePreset() !== null)
            ->requiresConfirmation()
            ->modalHeading(__('filament-mouseless::mouseless.table.delete_layout.heading'))
            ->modalDescription(__('filament-mouseless::mouseless.table.delete_layout.description'))
            ->action(function (): void {
                $this->ownActivePreset()?->delete();
                $this->switchTo(null);

                Notification::make()
                    ->title(__('filament-mouseless::mouseless.table.delete_layout.done'))
                    ->success()->send();
            });
    }

    // ------------------------------------------------------------------
    // Import / export
    // ------------------------------------------------------------------

    public function exportLayoutAction(): Action
    {
        return Action::make('exportLayout')
            ->label(__('filament-mouseless::mouseless.table.export.label'))
            ->icon('heroicon-m-arrow-down-tray')
            ->button()
            ->color('gray')
            ->action(function () {
                $preset = FilamentMouseless::forCurrentUser()['preset'] ?? [];
                $json = PresetTransfer::exportJson($preset);
                $slug = $preset['slug'] ?? 'mouseless';

                return response()->streamDownload(
                    function () use ($json): void {
                        echo $json;
                    },
                    "{$slug}-shortcuts.json",
                    ['Content-Type' => 'application/json'],
                );
            });
    }

    public function copyExportAction(): Action
    {
        return Action::make('copyExport')
            ->label(__('filament-mouseless::mouseless.table.export.clipboard'))
            ->icon('heroicon-m-clipboard-document')
            ->iconButton()
            ->color('gray')
            ->tooltip(__('filament-mouseless::mouseless.table.export.clipboard'))
            ->action(function (): void {
                $preset = FilamentMouseless::forCurrentUser()['preset'] ?? [];

                $this->dispatch('mouseless-copy-export', json: PresetTransfer::exportJson($preset));

                Notification::make()
                    ->title(__('filament-mouseless::mouseless.table.export.copied'))
                    ->success()->send();
            });
    }

    public function importLayoutAction(): Action
    {
        return Action::make('importLayout')
            ->label(__('filament-mouseless::mouseless.table.import.label'))
            ->icon('heroicon-m-arrow-up-tray')
            ->button()
            ->color('gray')
            ->modalHeading(__('filament-mouseless::mouseless.table.import.label'))
            ->schema([
                FileUpload::make('file')
                    ->label(__('filament-mouseless::mouseless.table.import.file'))
                    ->acceptedFileTypes(['application/json', 'text/plain'])
                    ->storeFiles(false)
                    ->previewable(false)
                    // Auto-import the moment the upload finishes — no second click
                    // on "submit" for the file path (the paste field still submits
                    // via the button). An invalid file notifies and leaves the
                    // modal open so the user can retry or paste instead.
                    ->afterStateUpdated(function ($state): void {
                        $file = is_array($state) ? Arr::first($state) : $state;

                        if (! is_object($file) || ! method_exists($file, 'get')) {
                            return; // file removed, or not a readable upload
                        }

                        if ($this->handleImportJson((string) $file->get()) === 'imported') {
                            $this->unmountAction();
                        }
                    }),
                Textarea::make('json')
                    ->label(__('filament-mouseless::mouseless.table.import.paste'))
                    ->rows(5),
            ])
            ->action(function (array $data, Action $action): void {
                $json = filled($data['file'] ?? null)
                    ? (string) $data['file']->get()
                    : (string) ($data['json'] ?? '');

                if ($this->handleImportJson($json) === 'error') {
                    $action->halt();
                }
            });
    }

    /**
     * Validate a pasted or uploaded JSON layout and act on it: import straight
     * through, hand off to the overwrite-vs-duplicate prompt, or notify on a
     * failure. Returns 'imported' | 'conflict' | 'error' so the caller decides
     * whether to close the modal (button submit vs. the upload auto-trigger).
     */
    protected function handleImportJson(string $json): string
    {
        if (blank($json)) {
            Notification::make()
                ->title(__('filament-mouseless::mouseless.table.import.empty'))
                ->danger()->send();

            return 'error';
        }

        $errors = PresetTransfer::validate($json, $payload);

        if ($errors !== []) {
            Notification::make()
                ->title(__('filament-mouseless::mouseless.table.import.invalid'))
                ->body(implode("\n", array_slice($errors, 0, 5)))
                ->danger()->send();

            return 'error';
        }

        // Same layout coming back in (matched on its Laravel id, carried in the
        // export)? Don't silently fork or clobber — hand off to a tiny confirm
        // modal that asks overwrite-vs-duplicate. A brand new layout has nothing
        // to resolve, so it imports straight away.
        if ($this->ownedPresetById($payload['id'] ?? null)) {
            $this->pendingImport = $payload;
            $this->replaceMountedAction('resolveImportConflict');

            return 'conflict';
        }

        $this->pendingImport = $payload;
        $this->completeImport(overwrite: false);

        return 'imported';
    }

    /**
     * Second step of an import that hit an existing layout: ask whether to
     * overwrite it or keep both. Duplicate is the primary (default) button so a
     * plain Enter never destroys the existing layout; overwrite is the explicit,
     * dangerous opt-in. No radio — the two buttons *are* the choice.
     */
    public function resolveImportConflictAction(): Action
    {
        return Action::make('resolveImportConflict')
            ->modalHeading(__('filament-mouseless::mouseless.table.import.conflict.heading'))
            ->modalDescription(fn (): string => __('filament-mouseless::mouseless.table.import.conflict.body', [
                'name' => (string) ($this->pendingImport['name'] ?? ''),
            ]))
            ->modalIcon('heroicon-o-document-duplicate')
            ->modalIconColor('warning')
            ->modalSubmitActionLabel(__('filament-mouseless::mouseless.table.import.conflict.duplicate'))
            ->extraModalFooterActions([
                Action::make('overwrite')
                    ->label(__('filament-mouseless::mouseless.table.import.conflict.overwrite'))
                    ->color('danger')
                    ->action(fn () => $this->completeImport(overwrite: true))
                    // Without this the footer action unmounts back to its parent
                    // (this modal), which would re-open with pendingImport already
                    // cleared — an empty-named "layout already exists" prompt.
                    ->cancelParentActions(),
            ])
            ->action(fn () => $this->completeImport(overwrite: false));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /** The user's own layout carrying this Laravel id, or null (built-ins/foreign ids never match). */
    protected function ownedPresetById(mixed $id): ?Preset
    {
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        return Preset::query()
            ->where('owner_user_id', auth()->id())
            ->whereKey((int) $id)
            ->first();
    }

    /**
     * Persist the stashed import payload, either overwriting the matched layout
     * in place or forking it into a fresh one. Shared by the straight-through
     * import and both branches of the conflict prompt.
     */
    protected function completeImport(bool $overwrite): void
    {
        $payload = $this->pendingImport;
        $this->pendingImport = null;

        if ($payload === null) {
            return;
        }

        $existing = $overwrite ? $this->ownedPresetById($payload['id'] ?? null) : null;

        if ($existing) {
            $existing->update([
                'name' => trim($payload['name']),
                'description' => $payload['description'] ?? null,
                'locale' => $payload['locale'] ?? $existing->locale,
                'version' => $payload['version'] ?? $existing->version,
                'source' => 'imported',
                'bindings' => $payload['bindings'],
                'disabled_actions' => array_values((array) ($payload['disabled_actions'] ?? [])),
            ]);

            $this->switchTo($existing->slug);

            Notification::make()
                ->title(__('filament-mouseless::mouseless.table.import.overridden', ['name' => $existing->name]))
                ->success()->send();

            return;
        }

        $preset = Preset::forkFrom(
            ['slug' => null] + $payload,
            $payload['name'],
            $payload['description'] ?? null,
            origin: 'imported',
        );

        $this->switchTo($preset->slug);

        Notification::make()
            ->title(__('filament-mouseless::mouseless.table.import.done'))
            ->success()->send();
    }

    protected function switchTo(?string $slug): void
    {
        $this->form->fill(['activePresetSlug' => $slug ?? self::DEFAULT_OPTION]);
        $this->persistSelection();
    }

    protected function canPublish(): bool
    {
        if (! FilamentMouselessPlugin::publishingEnabled()) {
            return false;
        }

        $gate = config('mouseless.publishing.gate');

        // A blank gate means publishing is open to every user.
        return blank($gate) || Gate::allows($gate);
    }

    protected function ownActivePreset(): ?Preset
    {
        if ($this->ownPresetMemoLoaded) {
            return $this->ownPresetMemo;
        }

        $this->ownPresetMemoLoaded = true;
        $slug = $this->selectedSlug();

        return $this->ownPresetMemo = $slug === null ? null : Preset::query()
            ->where('owner_user_id', auth()->id())
            ->where('slug', $slug)
            ->first();
    }
}
