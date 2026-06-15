<?php

namespace Blemli\FilamentMouseless\Filament\Concerns;

use Blemli\FilamentMouseless\Facades\FilamentMouseless;
use Blemli\FilamentMouseless\Models\Preset;
use Blemli\FilamentMouseless\Services\CustomActionRegistry;
use Blemli\FilamentMouseless\Support\ActionMeta;
use Blemli\FilamentMouseless\Support\Keys;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Reusable shortcuts table: one row per action, grouped by category, with
 * inline key recording, steal-on-collision and disable/remove/reset actions.
 * Hosts (MyShortcuts page now, the admin preset editor later) provide the
 * preset being edited via the methods below. JSON import/export lives in
 * the PresetSelector widget (see Support\PresetTransfer).
 */
trait InteractsWithShortcutsTable
{
    public ?string $recordingActionId = null;

    /** @var array{combo: string, otherActionId: string}|null */
    public ?array $pendingSteal = null;

    /** The preset being displayed/edited (registry array shape). */
    abstract public function getShortcutsPreset(): ?array;

    /** Baseline for "changed"/reset comparisons; null disables the comparison. */
    abstract public function getShortcutsParentPreset(): ?array;

    /** Read-only state: edits must first go through ensureEditableShortcutsPreset(). */
    abstract public function isShortcutsLocked(): bool;

    /** Make the preset editable (e.g. fork a built-in into a personal layout). */
    abstract public function ensureEditableShortcutsPreset(): void;

    /**
     * Persist the layout. Return false when it could not be saved (e.g. the
     * personal preset no longer exists).
     *
     * @param  array<string, ?string>  $bindings
     * @param  array<int, string>  $disabled
     */
    abstract public function persistShortcuts(array $bindings, array $disabled): bool;

    /**
     * Persist + flush the resolver memo and the table's per-request record
     * cache — the action resolved the records before mutating, so without
     * the flush the row states render stale. Returns false (with an error
     * notification) when persisting failed; callers must skip their
     * success notification then.
     */
    protected function saveShortcuts(array $bindings, array $disabled): bool
    {
        if (! $this->persistShortcuts($bindings, $disabled)) {
            Notification::make()
                ->title(__('filament-mouseless::mouseless.table.notifications.save_failed'))
                ->danger()->send();

            return false;
        }

        FilamentMouseless::flush();
        $this->flushCachedTableRecords();

        return true;
    }

    protected function isProtectedShortcut(string $actionId): bool
    {
        return ActionMeta::isProtected($actionId);
    }

    public function shortcutsTable(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, ?array $filters, array $sort): array => $this->shortcutRecords($search, $filters, $sort))
            ->paginated(false)
            ->deferFilters(false)
            ->deferColumnManager(false)
            ->defaultGroup(
                Group::make('category')
                    ->label(__('filament-mouseless::mouseless.table.columns.category'))
                    ->getKeyFromRecordUsing(fn (array $record): string => $record['category'])
                    ->getTitleFromRecordUsing(fn (array $record): string => ActionMeta::categoryLabel($record['category']))
                    ->titlePrefixedWithLabel(false)
                    ->collapsible()
            )
            ->columns([
                TextColumn::make('label')
                    ->label(__('filament-mouseless::mouseless.table.columns.action'))
                    ->icon(fn (array $record): string => $record['icon'])
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label(__('filament-mouseless::mouseless.table.columns.category'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => ActionMeta::categoryLabel($state))
                    ->toggleable(isToggledHiddenByDefault: true),
                ViewColumn::make('combo')
                    ->label(__('filament-mouseless::mouseless.table.columns.key'))
                    ->view('filament-mouseless::tables.columns.key-combo')
                    ->searchable(),
                TextColumn::make('default')
                    ->label(__('filament-mouseless::mouseless.table.columns.default'))
                    ->formatStateUsing(fn (?string $state): string => Keys::display($state))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('changed')
                    ->label(__('filament-mouseless::mouseless.table.columns.status'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state
                        ? __('filament-mouseless::mouseless.table.status.changed')
                        : __('filament-mouseless::mouseless.table.status.default'))
                    ->color(fn (bool $state): string => $state ? 'warning' : 'gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('changed')
                    ->label(__('filament-mouseless::mouseless.table.filters.changed'))
                    ->trueLabel(__('filament-mouseless::mouseless.table.status.changed'))
                    ->falseLabel(__('filament-mouseless::mouseless.table.status.default')),
                TernaryFilter::make('disabled')
                    ->label(__('filament-mouseless::mouseless.table.filters.disabled'))
                    ->trueLabel(__('filament-mouseless::mouseless.table.filters.disabled_true'))
                    ->falseLabel(__('filament-mouseless::mouseless.table.filters.disabled_false')),
                TernaryFilter::make('unbound')
                    ->label(__('filament-mouseless::mouseless.table.filters.unbound'))
                    ->trueLabel(__('filament-mouseless::mouseless.table.filters.unbound_true'))
                    ->falseLabel(__('filament-mouseless::mouseless.table.filters.unbound_false')),
                SelectFilter::make('category')
                    ->label(__('filament-mouseless::mouseless.table.columns.category'))
                    ->multiple()
                    ->options(array_combine(
                        ActionMeta::CATEGORY_ORDER,
                        array_map(ActionMeta::categoryLabel(...), ActionMeta::CATEGORY_ORDER),
                    )),
                SelectFilter::make('keys')
                    ->label(__('filament-mouseless::mouseless.table.filters.keys'))
                    ->multiple()
                    ->options($this->shortcutsModifierOptions()),
            ])
            ->recordActions([
                $this->configureShortcutsForkConfirmation(
                    Action::make('record')
                        ->label(fn (array $record): string => $record['combo'] === null
                            ? __('filament-mouseless::mouseless.table.actions.record_unbound')
                            : __('filament-mouseless::mouseless.table.actions.record'))
                        ->icon('heroicon-m-key')
                        ->visible(fn (array $record): bool => ! $record['disabled'] && ! ($record['readonly'] ?? false) && ! $this->isProtectedShortcut($record['id']))
                        ->action(function (array $record): void {
                            $this->ensureEditableShortcutsPreset();
                            $this->startRecording($record['id']);
                        }),
                ),
                $this->configureShortcutsForkConfirmation(
                    Action::make('removeShortcut')
                        ->label(__('filament-mouseless::mouseless.table.actions.remove'))
                        ->icon('heroicon-m-minus-circle')
                        ->color('gray')
                        ->visible(fn (array $record): bool => $record['combo'] !== null && ! $record['disabled'] && ! ($record['readonly'] ?? false) && ! $this->isProtectedShortcut($record['id']))
                        ->action(fn (array $record) => $this->removeShortcut($record['id'])),
                ),
                Action::make('resetShortcut')
                    ->label(__('filament-mouseless::mouseless.table.actions.reset'))
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (array $record): bool => $record['changed'] && ! ($record['readonly'] ?? false))
                    ->action(fn (array $record) => $this->resetShortcut($record['id'])),
                $this->configureShortcutsForkConfirmation(
                    Action::make('toggleShortcutDisabled')
                        ->visible(fn (array $record): bool => ! ($record['readonly'] ?? false) && ! $this->isProtectedShortcut($record['id']))
                        ->label(fn (array $record): string => $record['disabled']
                            ? __('filament-mouseless::mouseless.table.actions.enable')
                            : __('filament-mouseless::mouseless.table.actions.disable'))
                        ->icon(fn (array $record): string => $record['disabled'] ? 'heroicon-m-play' : 'heroicon-m-no-symbol')
                        ->color(fn (array $record): string => $record['disabled'] ? 'success' : 'danger')
                        ->action(fn (array $record) => $this->toggleShortcutDisabled($record['id'])),
                ),
            ])
            ->recordClasses(fn (array $record): ?string => $record['disabled'] ? 'fi-mouseless-row-disabled' : null)
            ->toolbarActions([
                Action::make('resetAllShortcuts')
                    ->label(__('filament-mouseless::mouseless.table.reset_all.label'))
                    ->icon('heroicon-m-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (): bool => ! $this->isShortcutsLocked() && $this->shortcutsHaveChanges())
                    ->requiresConfirmation()
                    ->modalHeading(__('filament-mouseless::mouseless.table.reset_all.heading'))
                    ->modalDescription(__('filament-mouseless::mouseless.table.reset_all.description'))
                    ->action(fn () => $this->resetAllShortcuts()),
                Action::make('searchByKey')
                    ->label(__('filament-mouseless::mouseless.table.key_search.label'))
                    ->icon('heroicon-m-key')
                    ->iconButton()
                    ->color('gray')
                    ->tooltip(__('filament-mouseless::mouseless.table.key_search.label'))
                    ->extraAttributes(['class' => 'fi-mouseless-key-search-btn'])
                    ->modalHeading(__('filament-mouseless::mouseless.table.key_search.label'))
                    ->modalDescription(__('filament-mouseless::mouseless.table.key_search.hint'))
                    ->modalContent(view('filament-mouseless::modals.key-search'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('filament-mouseless::mouseless.table.recording.cancel')),
                BulkActionGroup::make([
                    $this->configureShortcutsForkConfirmation(
                        BulkAction::make('disableSelected')
                            ->label(__('filament-mouseless::mouseless.table.bulk.disable'))
                            ->icon('heroicon-m-no-symbol')
                            ->color('danger')
                            ->action(fn (Collection $records) => $this->bulkSetShortcutsDisabled($records, true))
                            ->deselectRecordsAfterCompletion(),
                    ),
                    $this->configureShortcutsForkConfirmation(
                        BulkAction::make('enableSelected')
                            ->label(__('filament-mouseless::mouseless.table.bulk.enable'))
                            ->icon('heroicon-m-play')
                            ->visible(fn (): bool => (array) ($this->getShortcutsPreset()['disabled_actions'] ?? []) !== [])
                            ->action(fn (Collection $records) => $this->bulkSetShortcutsDisabled($records, false))
                            ->deselectRecordsAfterCompletion(),
                    ),
                    $this->configureShortcutsForkConfirmation(
                        BulkAction::make('removeSelected')
                            ->label(__('filament-mouseless::mouseless.table.bulk.remove'))
                            ->icon('heroicon-m-minus-circle')
                            ->action(fn (Collection $records) => $this->bulkRemoveShortcuts($records))
                            ->deselectRecordsAfterCompletion(),
                    ),
                    BulkAction::make('resetSelected')
                        ->label(__('filament-mouseless::mouseless.table.bulk.reset'))
                        ->icon('heroicon-m-arrow-uturn-left')
                        ->visible(fn (): bool => ! $this->isShortcutsLocked())
                        ->action(fn (Collection $records) => $this->bulkResetShortcuts($records))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * Wraps modifying row actions: on a locked (not owned) preset, ask first —
     * confirming forks into a personal layout, then the action proceeds.
     */
    protected function configureShortcutsForkConfirmation(Action $action): Action
    {
        // Everything must stay conditional: a non-null modal heading/description
        // alone makes Filament open a modal even without requiresConfirmation.
        return $action
            ->requiresConfirmation(fn (): bool => $this->isShortcutsLocked())
            ->modalHeading(fn (): ?string => $this->isShortcutsLocked()
                ? __('filament-mouseless::mouseless.table.fork.heading')
                : null)
            ->modalDescription(fn (): ?string => $this->isShortcutsLocked()
                ? __('filament-mouseless::mouseless.table.fork.description', ['name' => $this->shortcutsForkName()])
                : null)
            ->modalSubmitActionLabel(fn (): ?string => $this->isShortcutsLocked()
                ? __('filament-mouseless::mouseless.table.fork.confirm')
                : null);
    }

    /** Proposed name for the auto-created personal layout. */
    public function shortcutsForkName(): string
    {
        return Preset::defaultLayoutName();
    }

    // ------------------------------------------------------------------
    // Rows
    // ------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function shortcutRecords(?string $search, ?array $filters, array $sort): array
    {
        $rows = $this->shortcutRows();

        if (filled($search)) {
            $needle = mb_strtolower(trim($search));
            $comboNeedle = Keys::normalize($search) ?? $needle;

            $rows = array_filter($rows, fn (array $r): bool => str_contains(mb_strtolower($r['label']), $needle)
                || ($r['normalized'] !== null && str_contains($r['normalized'], $comboNeedle)));
        }

        $changed = $filters['changed']['value'] ?? null;
        if ($changed !== null && $changed !== '') {
            $want = filter_var($changed, FILTER_VALIDATE_BOOL);
            $rows = array_filter($rows, fn (array $r): bool => $r['changed'] === $want);
        }

        $disabled = $filters['disabled']['value'] ?? null;
        if ($disabled !== null && $disabled !== '') {
            $want = filter_var($disabled, FILTER_VALIDATE_BOOL);
            $rows = array_filter($rows, fn (array $r): bool => $r['disabled'] === $want);
        }

        $unbound = $filters['unbound']['value'] ?? null;
        if ($unbound !== null && $unbound !== '') {
            $want = filter_var($unbound, FILTER_VALIDATE_BOOL);
            $rows = array_filter($rows, fn (array $r): bool => ($r['combo'] === null) === $want);
        }

        $categories = array_filter((array) ($filters['category']['values'] ?? []));
        if ($categories !== []) {
            $rows = array_filter($rows, fn (array $r): bool => in_array($r['category'], $categories, true));
        }

        $modifiers = array_filter((array) ($filters['keys']['values'] ?? []));
        if ($modifiers !== []) {
            $rows = array_filter($rows, function (array $r) use ($modifiers): bool {
                if ($r['normalized'] === null) {
                    return false;
                }

                $rowModifiers = Keys::modifiers($r['combo']);

                foreach ($modifiers as $modifier) {
                    if ($modifier === 'none' ? $rowModifiers === [] : in_array($modifier, $rowModifiers, true)) {
                        return true;
                    }
                }

                return false;
            });
        }

        // Category order always wins so the table's groups stay contiguous;
        // the requested sort applies within each group.
        [$sortColumn, $sortDirection] = $sort + [null, null];
        $rows = array_values($rows);

        usort($rows, function (array $a, array $b) use ($sortColumn, $sortDirection): int {
            $byCategory = ActionMeta::categoryOrder($a['category']) <=> ActionMeta::categoryOrder($b['category']);
            if ($byCategory !== 0) {
                return $byCategory;
            }

            $cmp = strcasecmp($a['label'], $b['label']);

            return ($sortColumn === 'label' && $sortDirection === 'desc') ? -$cmp : $cmp;
        });

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function shortcutRows(): array
    {
        $preset = $this->getShortcutsPreset() ?? [];
        $parent = $this->getShortcutsParentPreset();

        $bindings = (array) ($preset['bindings'] ?? []);
        $disabled = (array) ($preset['disabled_actions'] ?? []);
        $parentBindings = (array) ($parent['bindings'] ?? []);
        $parentDisabled = (array) ($parent['disabled_actions'] ?? []);

        // Include disabled-only ids: a disabled action without any binding
        // entry must still get a row, or it could never be re-enabled. Custom
        // ids are handled separately (their default is the code-defined combo,
        // not a parent-preset value) — drop them here to avoid duplicate rows.
        $ids = array_values(array_filter(
            array_unique([...array_keys($bindings), ...array_keys($parentBindings), ...array_values($disabled)]),
            fn (string $id): bool => ! str_starts_with($id, 'custom.'),
        ));

        $rows = array_map(
            fn (string $id): array => $this->coreShortcutRow($id, $bindings, $disabled, $parent, $parentBindings, $parentDisabled),
            $ids,
        );

        $rows = [...$rows, ...$this->customShortcutRows($bindings, $disabled)];

        // Duplicate detection runs across every effective combo (core + custom)
        // so a preset key colliding with a code-defined one is flagged too.
        $comboCounts = [];
        foreach ($rows as $row) {
            if ($row['disabled'] || $row['normalized'] === null) {
                continue;
            }

            $comboCounts[$row['normalized']] = ($comboCounts[$row['normalized']] ?? 0) + 1;
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['duplicate'] = ! $row['disabled']
                && $row['normalized'] !== null
                && ($comboCounts[$row['normalized']] ?? 0) > 1;
        }

        return $rows;
    }

    /**
     * @param  array<string, ?string>  $bindings
     * @param  array<int, string>  $disabled
     * @param  array<string, mixed>|null  $parent
     * @param  array<string, ?string>  $parentBindings
     * @param  array<int, string>  $parentDisabled
     * @return array<string, mixed>
     */
    protected function coreShortcutRow(string $id, array $bindings, array $disabled, ?array $parent, array $parentBindings, array $parentDisabled): array
    {
        $combo = $bindings[$id] ?? null;
        $normalized = Keys::normalize($combo);
        $isDisabled = in_array($id, $disabled, true);
        $parentCombo = $parentBindings[$id] ?? null;

        $changed = $parent !== null && (
            $normalized !== Keys::normalize($parentCombo)
            || $isDisabled !== in_array($id, $parentDisabled, true)
        );

        return [
            '__key' => $id,
            'id' => $id,
            'label' => ActionMeta::label($id),
            'category' => ActionMeta::category($id),
            'icon' => ActionMeta::icon($id),
            'combo' => $combo,
            'normalized' => $normalized,
            'default' => $parentCombo,
            'changed' => $changed,
            'disabled' => $isDisabled,
            'duplicate' => false,
            'readonly' => false,
        ];
    }

    /**
     * Rows for the host app's custom actions. Managed actions behave like core
     * rows — their "default" is the code-defined combo, and a preset entry
     * overrides it. Read-only actions (page without the MouselessKeyBindings
     * trait) are shown for reference but cannot be rebound.
     *
     * @param  array<string, ?string>  $bindings
     * @param  array<int, string>  $disabled
     * @return array<int, array<string, mixed>>
     */
    protected function customShortcutRows(array $bindings, array $disabled): array
    {
        try {
            $actions = app(CustomActionRegistry::class)->all();
        } catch (\Throwable) {
            return [];
        }

        $rows = [];

        foreach ($actions as $id => $meta) {
            $codeCombo = Keys::normalize($meta['combos'][0] ?? null);
            $label = (is_string($meta['label'] ?? null) && $meta['label'] !== '')
                ? $meta['label']
                : ActionMeta::label($id);

            if (! ($meta['managed'] ?? false)) {
                $rows[] = [
                    '__key' => $id,
                    'id' => $id,
                    'label' => $label,
                    'category' => 'custom',
                    'icon' => ActionMeta::icon($id),
                    'combo' => $codeCombo,
                    'normalized' => $codeCombo,
                    'default' => $codeCombo,
                    'changed' => false,
                    'disabled' => false,
                    'duplicate' => false,
                    'readonly' => true,
                ];

                continue;
            }

            $hasOverride = array_key_exists($id, $bindings);
            $combo = $hasOverride ? $bindings[$id] : $codeCombo;
            $normalized = Keys::normalize($combo);
            $isDisabled = in_array($id, $disabled, true);
            $changed = ($hasOverride && $normalized !== $codeCombo) || $isDisabled;

            $rows[] = [
                '__key' => $id,
                'id' => $id,
                'label' => $label,
                'category' => 'custom',
                'icon' => ActionMeta::icon($id),
                'combo' => $combo,
                'normalized' => $normalized,
                'default' => $codeCombo,
                'changed' => $changed,
                'disabled' => $isDisabled,
                'duplicate' => false,
                'readonly' => false,
            ];
        }

        return $rows;
    }

    /** Called by the key-search modal once a combo was captured. */
    public function applyKeySearch(string $combo): void
    {
        $this->tableSearch = Keys::normalize($combo) ?? '';
        $this->resetPage();
        $this->unmountAction();
    }

    public function shortcutsHaveChanges(): bool
    {
        foreach ($this->shortcutRows() as $row) {
            if ($row['changed']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    protected function shortcutsModifierOptions(): array
    {
        $mac = Keys::isMac();

        return [
            'ctrl' => $mac ? '⌃ Control' : 'Ctrl',
            'cmd' => $mac ? '⌘ Command' : 'Cmd',
            'alt' => $mac ? '⌥ Option' : 'Alt',
            'shift' => $mac ? '⇧ Shift' : 'Shift',
            'none' => __('filament-mouseless::mouseless.table.filters.no_modifier'),
        ];
    }

    // ------------------------------------------------------------------
    // Recording
    // ------------------------------------------------------------------

    public function startRecording(string $actionId): void
    {
        $this->pendingSteal = null;
        $this->recordingActionId = $actionId;
    }

    public function cancelRecording(): void
    {
        $this->recordingActionId = null;
        $this->pendingSteal = null;
    }

    public function recordKey(string $key): void
    {
        if (! $this->recordingActionId) {
            return;
        }

        $combo = Keys::normalize($key);
        if ($combo === null) {
            return;
        }

        // Escape always cancels — it belongs to ui.close and is never recordable.
        if ($combo === 'escape') {
            $this->cancelRecording();

            return;
        }

        // Reject modifier-only artifacts like "shift+" — keep recording active.
        if (! Keys::isValid($combo)) {
            return;
        }

        $reserved = array_map(Keys::normalize(...), (array) config('mouseless.reserved_keys', []));
        if (in_array($combo, $reserved, true)) {
            Notification::make()
                ->title(__('filament-mouseless::mouseless.profile.reserved_key', ['key' => Keys::display($combo)]))
                ->danger()->send();

            return; // Keep recording so the user can try another combo.
        }

        $preset = $this->getShortcutsPreset() ?? [];

        // Disabled actions still hold their key — stealing unbinds them, so
        // re-enabling later can never create a silent duplicate combo.
        foreach ((array) ($preset['bindings'] ?? []) as $id => $existing) {
            if ($id === $this->recordingActionId) {
                continue;
            }

            if (Keys::normalize($existing) === $combo) {
                if ($this->isProtectedShortcut($id)) {
                    Notification::make()
                        ->title(__('filament-mouseless::mouseless.table.steal.protected', [
                            'key' => Keys::display($combo),
                            'action' => ActionMeta::label($id),
                        ]))
                        ->danger()->send();

                    return; // Recording stays active — try another combo.
                }

                $this->pendingSteal = ['combo' => $combo, 'otherActionId' => $id];

                return;
            }
        }

        $this->applyRecordedKey($combo);
    }

    /** Assign the colliding combo here and unbind the action that held it. */
    public function confirmSteal(): void
    {
        if (! $this->pendingSteal || ! $this->recordingActionId) {
            return;
        }

        $combo = $this->pendingSteal['combo'];
        $otherActionId = $this->pendingSteal['otherActionId'];

        $this->ensureEditableShortcutsPreset();

        $preset = $this->getShortcutsPreset() ?? [];
        $bindings = (array) ($preset['bindings'] ?? []);
        $bindings[$otherActionId] = null;
        $bindings[$this->recordingActionId] = $combo;

        if (! $this->saveShortcuts($bindings, (array) ($preset['disabled_actions'] ?? []))) {
            return;
        }

        $this->cancelRecording();

        Notification::make()
            ->title(__('filament-mouseless::mouseless.table.steal.done', [
                'action' => ActionMeta::label($otherActionId),
            ]))
            ->success()->send();
    }

    protected function applyRecordedKey(string $combo): void
    {
        $this->ensureEditableShortcutsPreset();

        $preset = $this->getShortcutsPreset() ?? [];
        $bindings = (array) ($preset['bindings'] ?? []);
        $bindings[$this->recordingActionId] = $combo;

        if (! $this->saveShortcuts($bindings, (array) ($preset['disabled_actions'] ?? []))) {
            return;
        }

        $this->cancelRecording();

        Notification::make()
            ->title(__('filament-mouseless::mouseless.profile.binding_saved'))
            ->success()->send();
    }

    // ------------------------------------------------------------------
    // Row mutations
    // ------------------------------------------------------------------

    /** Unbind the key without disabling the action. */
    public function removeShortcut(string $actionId): void
    {
        if ($this->isProtectedShortcut($actionId)) {
            return;
        }

        $this->ensureEditableShortcutsPreset();

        $preset = $this->getShortcutsPreset() ?? [];
        $bindings = (array) ($preset['bindings'] ?? []);
        $bindings[$actionId] = null;

        if (! $this->saveShortcuts($bindings, (array) ($preset['disabled_actions'] ?? []))) {
            return;
        }

        Notification::make()
            ->title(__('filament-mouseless::mouseless.table.notifications.removed'))
            ->success()->send();
    }

    public function resetShortcut(string $actionId): void
    {
        // Resetting only restores parent values — never worth forking a layout.
        if ($this->isShortcutsLocked()) {
            return;
        }

        $preset = $this->getShortcutsPreset() ?? [];
        $parent = $this->getShortcutsParentPreset() ?? [];

        $bindings = (array) ($preset['bindings'] ?? []);
        $parentBindings = (array) ($parent['bindings'] ?? []);

        if (array_key_exists($actionId, $parentBindings)) {
            $bindings[$actionId] = $parentBindings[$actionId];
        } else {
            unset($bindings[$actionId]);
        }

        $disabled = (array) ($preset['disabled_actions'] ?? []);
        $parentDisabled = (array) ($parent['disabled_actions'] ?? []);
        $disabled = in_array($actionId, $parentDisabled, true)
            ? array_values(array_unique([...$disabled, $actionId]))
            : array_values(array_diff($disabled, [$actionId]));

        if (! $this->saveShortcuts($bindings, $disabled)) {
            return;
        }

        Notification::make()
            ->title(__('filament-mouseless::mouseless.table.notifications.reset'))
            ->success()->send();
    }

    public function toggleShortcutDisabled(string $actionId): void
    {
        if ($this->isProtectedShortcut($actionId)) {
            return;
        }

        $this->ensureEditableShortcutsPreset();

        $preset = $this->getShortcutsPreset() ?? [];
        $disabled = (array) ($preset['disabled_actions'] ?? []);

        $isDisabled = in_array($actionId, $disabled, true);
        $disabled = $isDisabled
            ? array_values(array_diff($disabled, [$actionId]))
            : [...$disabled, $actionId];

        if (! $this->saveShortcuts((array) ($preset['bindings'] ?? []), $disabled)) {
            return;
        }

        Notification::make()
            ->title($isDisabled
                ? __('filament-mouseless::mouseless.table.notifications.enabled')
                : __('filament-mouseless::mouseless.table.notifications.disabled'))
            ->success()->send();
    }

    public function bulkSetShortcutsDisabled(Collection $records, bool $disable): void
    {
        $this->ensureEditableShortcutsPreset();

        $preset = $this->getShortcutsPreset() ?? [];
        $disabled = (array) ($preset['disabled_actions'] ?? []);
        $ids = $records
            ->map(fn (array $record): string => $record['id'])
            ->reject(fn (string $id): bool => $disable && $this->isProtectedShortcut($id))
            ->values()
            ->all();

        $disabled = $disable
            ? array_values(array_unique([...$disabled, ...$ids]))
            : array_values(array_diff($disabled, $ids));

        if (! $this->saveShortcuts((array) ($preset['bindings'] ?? []), $disabled)) {
            return;
        }

        Notification::make()
            ->title(__($disable
                ? 'filament-mouseless::mouseless.table.bulk.disabled'
                : 'filament-mouseless::mouseless.table.bulk.enabled', ['count' => count($ids)]))
            ->success()->send();
    }

    public function bulkRemoveShortcuts(Collection $records): void
    {
        $this->ensureEditableShortcutsPreset();

        $preset = $this->getShortcutsPreset() ?? [];
        $bindings = (array) ($preset['bindings'] ?? []);

        foreach ($records as $record) {
            if ($this->isProtectedShortcut($record['id'])) {
                continue;
            }

            $bindings[$record['id']] = null;
        }

        if (! $this->saveShortcuts($bindings, (array) ($preset['disabled_actions'] ?? []))) {
            return;
        }

        Notification::make()
            ->title(__('filament-mouseless::mouseless.table.bulk.removed', ['count' => $records->count()]))
            ->success()->send();
    }

    public function bulkResetShortcuts(Collection $records): void
    {
        if ($this->isShortcutsLocked()) {
            return;
        }

        $preset = $this->getShortcutsPreset() ?? [];
        $parent = $this->getShortcutsParentPreset() ?? [];

        $bindings = (array) ($preset['bindings'] ?? []);
        $disabled = (array) ($preset['disabled_actions'] ?? []);
        $parentBindings = (array) ($parent['bindings'] ?? []);
        $parentDisabled = (array) ($parent['disabled_actions'] ?? []);

        foreach ($records as $record) {
            $id = $record['id'];

            if (array_key_exists($id, $parentBindings)) {
                $bindings[$id] = $parentBindings[$id];
            } else {
                unset($bindings[$id]);
            }

            $disabled = in_array($id, $parentDisabled, true)
                ? array_values(array_unique([...$disabled, $id]))
                : array_values(array_diff($disabled, [$id]));
        }

        if (! $this->saveShortcuts($bindings, $disabled)) {
            return;
        }

        Notification::make()
            ->title(__('filament-mouseless::mouseless.table.bulk.reset_done', ['count' => $records->count()]))
            ->success()->send();
    }

    public function resetAllShortcuts(): void
    {
        $parent = $this->getShortcutsParentPreset();
        if ($parent === null || $this->isShortcutsLocked()) {
            return;
        }

        $saved = $this->saveShortcuts(
            (array) ($parent['bindings'] ?? []),
            (array) ($parent['disabled_actions'] ?? []),
        );

        if (! $saved) {
            return;
        }

        Notification::make()
            ->title(__('filament-mouseless::mouseless.table.reset_all.done'))
            ->success()->send();
    }
}
