<?php

namespace Blemli\FilamentMouseless\Filament\Concerns;

use Blemli\FilamentMouseless\Events\ShortcutsChanged;
use Blemli\FilamentMouseless\Facades\FilamentMouseless;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\Nudge;
use Blemli\FilamentMouseless\Models\Preset;
use Blemli\FilamentMouseless\Models\Statistic;
use Blemli\FilamentMouseless\Services\CustomActionRegistry;
use Blemli\FilamentMouseless\Support\ActionMeta;
use Blemli\FilamentMouseless\Support\Keys;
use Blemli\FilamentMouseless\Support\PanelAuth;
use Blemli\FilamentMouseless\Support\ShortcutConflicts;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\HtmlString;

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
        // Pre-mutation snapshot for the event diff — the resolver memo is
        // only flushed below, so this still sees the old layout.
        $old = $this->getShortcutsPreset() ?? [];

        if (! $this->persistShortcuts($bindings, $disabled)) {
            Notification::make()
                ->title(__('filament-mouseless::mouseless.table.notifications.save_failed'))
                ->danger()->send();

            return false;
        }

        FilamentMouseless::flush();
        $this->flushCachedTableRecords();

        ShortcutsChanged::dispatch(
            (int) PanelAuth::id(),
            (string) ($old['slug'] ?? ''),
            (array) ($old['bindings'] ?? []),
            $bindings,
            array_values((array) ($old['disabled_actions'] ?? [])),
            array_values($disabled),
        );

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
            ->reorderableColumns()
            ->defaultGroup(
                Group::make('category')
                    ->label(__('filament-mouseless::mouseless.table.columns.category'))
                    ->getKeyFromRecordUsing(fn (array $record): string => $record['category'])
                    ->getTitleFromRecordUsing(fn (array $record): string => ActionMeta::categoryLabel($record['category']))
                    ->getDescriptionFromRecordUsing(fn (array $record): ?HtmlString => $this->shortcutsGroupActionsHtml($record['category']))
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
                // Teach state per action (plugin ->teach()): has the nudge
                // taught this shortcut yet? Clicking a taught/dismissed badge
                // clears the state so teaching starts over.
                ...($this->shortcutsTeachEnabled() ? [
                    TextColumn::make('teach')
                        ->label(__('filament-mouseless::mouseless.table.columns.teach'))
                        ->badge()
                        ->formatStateUsing(fn (string $state, array $record): string => __("filament-mouseless::mouseless.teach.state.{$state}")
                            . ($state === 'unused' && ($record['teach_streak'] ?? 0) > 0
                                ? ' ' . $record['teach_streak'] . '/' . Nudge::TAUGHT_AFTER
                                : ''))
                        ->color(fn (string $state): string => match ($state) {
                            'taught' => 'success',
                            'dismissed' => 'warning',
                            default => 'gray',
                        })
                        ->tooltip(fn (array $record): ?string => in_array($record['teach'] ?? 'unused', ['taught', 'dismissed'], true)
                            ? __('filament-mouseless::mouseless.teach.clear_tooltip')
                            : null)
                        ->action(fn (array $record) => $this->clearShortcutTeach($record))
                        ->toggleable(isToggledHiddenByDefault: true),
                ] : []),
                // Lifetime keyboard invocations per action (plugin
                // ->statistics()). Hidden by default; toggle it on and sort
                // descending for a "my most used shortcuts" view.
                ...($this->hasShortcutsStatistics() ? [
                    TextColumn::make('invoked')
                        ->label(__('filament-mouseless::mouseless.table.columns.invoked'))
                        ->numeric()
                        ->alignEnd()
                        ->sortable()
                        ->toggleable(isToggledHiddenByDefault: true),
                ] : []),
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
                // Recording happens in a modal (like the key search), so no
                // fork-confirmation wrapper: on a locked preset the modal
                // description carries the fork notice instead, and the fork
                // itself happens at save time (applyRecordedKey/confirmSteal).
                Action::make('record')
                    ->label(fn (array $record): string => $record['combo'] === null
                        ? __('filament-mouseless::mouseless.table.actions.record_unbound')
                        : __('filament-mouseless::mouseless.table.actions.record'))
                    ->icon('heroicon-m-key')
                    ->visible(fn (array $record): bool => ! $record['disabled'] && ! ($record['readonly'] ?? false) && ! $this->isProtectedShortcut($record['id']))
                    // Mounting resets any stale steal prompt from an abandoned
                    // recording — every open starts at "press a key…".
                    ->mountUsing(fn (array $record) => $this->startRecording($record['id']))
                    ->modalHeading(fn (array $record): string => __('filament-mouseless::mouseless.table.recording.heading', ['action' => $record['label']]))
                    // Key icon + warning tint: the twin "press a key" modal
                    // (search-by-key) is info-blue with a magnifier, so the
                    // two stay tellable at a glance.
                    ->modalIcon('heroicon-o-key')
                    ->modalIconColor('warning')
                    ->modalDescription(fn (): string => $this->isShortcutsLocked() && ! FilamentMouselessPlugin::singletonEnabled()
                        ? __('filament-mouseless::mouseless.table.fork.description', ['name' => $this->shortcutsForkName()])
                        : __('filament-mouseless::mouseless.table.recording.hint'))
                    // Closure, not view(): pendingSteal must be re-read on every
                    // re-render so the steal prompt appears inside the modal.
                    ->modalContent(fn (): View => view('filament-mouseless::modals.key-record', ['steal' => $this->pendingSteal]))
                    // Filament's default is 4xl — near-fullscreen on a laptop;
                    // xl keeps most action names on one heading line.
                    ->modalWidth(Width::ExtraLarge)
                    ->slideOver(false)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('filament-mouseless::mouseless.table.recording.cancel')),
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
                // "Never notify" has to be reversible — this is its way back.
                Action::make('unmuteTeach')
                    ->label(__('filament-mouseless::mouseless.teach.unmute'))
                    ->icon('heroicon-m-bell')
                    ->color('gray')
                    ->visible(fn (): bool => $this->shortcutsTeachEnabled() && Nudge::isMuted((int) PanelAuth::id()))
                    ->action(function (): void {
                        Nudge::clear((int) PanelAuth::id(), Nudge::MUTE_ALL);

                        Notification::make()
                            ->title(__('filament-mouseless::mouseless.teach.unmuted'))
                            ->success()
                            ->send();
                    }),
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
                // Declared last so it is the toolbar's final child: the CSS
                // overlap that pulls it into the search box assumes nothing
                // renders to its right (the bulk-actions trigger would).
                Action::make('searchByKey')
                    ->label(__('filament-mouseless::mouseless.table.key_search.label'))
                    ->icon('heroicon-m-key')
                    ->iconButton()
                    ->color('gray')
                    ->tooltip(__('filament-mouseless::mouseless.table.key_search.label'))
                    ->extraAttributes(['class' => 'fi-mouseless-key-search-btn'])
                    ->modalHeading(__('filament-mouseless::mouseless.table.key_search.label'))
                    // Counterpart to the record modal's warning-yellow key
                    // icon — see the record action above.
                    ->modalIcon('heroicon-o-magnifying-glass')
                    ->modalIconColor('info')
                    ->modalDescription(__('filament-mouseless::mouseless.table.key_search.hint'))
                    ->modalContent(view('filament-mouseless::modals.key-search'))
                    // Same width as the record modal — they are twins.
                    ->modalWidth(Width::ExtraLarge)
                    ->slideOver(false)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('filament-mouseless::mouseless.table.recording.cancel')),
            ]);
    }

    /**
     * Whether the statistics layer is live for this user — gates the
     * "Invoked" column here and the widget/aside on the my-shortcuts page
     * (public: blade templates check it too).
     */
    public function hasShortcutsStatistics(): bool
    {
        if (! FilamentMouselessPlugin::statisticsEnabled() || ! PanelAuth::check()) {
            return false;
        }

        try {
            return Schema::hasTable('mouseless_statistics');
        } catch (\Throwable) {
            return false;
        }
    }

    /** Whether the teach column (and its states) should render at all. */
    protected function shortcutsTeachEnabled(): bool
    {
        if (! FilamentMouselessPlugin::teachingEnabled() || ! PanelAuth::check()) {
            return false;
        }

        try {
            return Schema::hasTable('mouseless_nudges');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Clicking a taught/dismissed teach badge forgets that state (streak,
     * backoff and all) — the action gets nudged again like a fresh one.
     */
    public function clearShortcutTeach(array $record): void
    {
        if (! $this->shortcutsTeachEnabled() || ($record['teach'] ?? 'unused') === 'unused') {
            return;
        }

        Nudge::clear((int) PanelAuth::id(), $record['id']);
        // The click resolved the records before the delete — flush, or the
        // badge renders its stale state until the next full refresh.
        $this->flushCachedTableRecords();

        Notification::make()
            ->title(__('filament-mouseless::mouseless.teach.cleared'))
            ->success()
            ->send();
    }

    /**
     * Wraps modifying row actions: on a locked (not owned) preset, ask first —
     * confirming forks into a personal layout, then the action proceeds.
     * Singleton mode skips the prompt: the fork is invisible plumbing there,
     * so the edit must feel like a plain in-place change.
     */
    protected function configureShortcutsForkConfirmation(Action $action): Action
    {
        $needsPrompt = fn (): bool => $this->isShortcutsLocked()
            && ! FilamentMouselessPlugin::singletonEnabled();

        // Everything must stay conditional: a non-null modal heading/description
        // alone makes Filament open a modal even without requiresConfirmation.
        return $action
            ->requiresConfirmation($needsPrompt)
            ->modalHeading(fn (): ?string => $needsPrompt()
                ? __('filament-mouseless::mouseless.table.fork.heading')
                : null)
            ->modalDescription(fn (): ?string => $needsPrompt()
                ? __('filament-mouseless::mouseless.table.fork.description', ['name' => $this->shortcutsForkName()])
                : null)
            ->modalSubmitActionLabel(fn (): ?string => $needsPrompt()
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
            // Second try with spaces as separators: "?search=alt+e" URLs
            // arrive with the + already decoded to a space.
            $comboNeedle = Keys::normalize($search)
                ?? Keys::normalize(str_replace(' ', '+', trim($search)))
                ?? $needle;

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

            if ($sortColumn === 'invoked') {
                $cmp = ($a['invoked'] ?? 0) <=> ($b['invoked'] ?? 0);
                if ($cmp !== 0) {
                    return $sortDirection === 'desc' ? -$cmp : $cmp;
                }
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

        if ($this->shortcutsTeachEnabled()) {
            $states = Nudge::statesFor((int) PanelAuth::id());

            foreach ($rows as &$row) {
                $state = $states[$row['id']] ?? null;
                $row['teach'] = match (true) {
                    (bool) ($state['dismissed'] ?? false) => 'dismissed',
                    (bool) ($state['learned'] ?? false) => 'taught',
                    default => 'unused',
                };
                $row['teach_streak'] = (int) ($state['streak'] ?? 0);
            }
            unset($row);
        }

        if ($this->hasShortcutsStatistics()) {
            $invocations = Statistic::perActionTotals((int) PanelAuth::id());

            foreach ($rows as &$row) {
                $row['invoked'] = (int) ($invocations[$row['id']]['kb'] ?? 0);
            }
            unset($row);
        }

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

    /**
     * Dismiss the steal prompt but stay in recording mode, so a collision isn't
     * a dead end: the cell drops back to "press a key…" and the user can try a
     * different combo without restarting.
     */
    public function retryRecording(): void
    {
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

        $reserved = array_map(Keys::normalize(...), Keys::reservedKeys());
        if (in_array($combo, $reserved, true)) {
            if (! FilamentMouselessPlugin::prohibitionsAreSoft()) {
                Notification::make()
                    ->title(__('filament-mouseless::mouseless.profile.reserved_key', ['key' => Keys::display($combo)]))
                    ->danger()->send();

                return; // Keep recording so the user can try another combo.
            }

            // ->onlyWarnOnProhibited(): accept the combo, but say why it may
            // stay dead — the browser/OS usually wins these keys.
            Notification::make()
                ->title(__('filament-mouseless::mouseless.profile.reserved_key_warning', ['key' => Keys::display($combo)]))
                ->warning()->send();
        }

        $preset = $this->getShortcutsPreset() ?? [];
        $presetBindings = (array) ($preset['bindings'] ?? []);

        // Disabled actions still hold their key — stealing unbinds them, so
        // re-enabling later can never create a silent duplicate combo.
        foreach ($presetBindings as $id => $existing) {
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

        // Custom actions on their code-defined combo aren't in the preset, so
        // the loop above misses them. Their combo lives in code and can't be
        // stolen (like a protected binding) — refuse and let the user pick
        // another, instead of silently creating a duplicate. Overridden customs
        // already sit in $presetBindings and were handled above.
        foreach (ShortcutConflicts::customCombos() as $id => $custom) {
            if ($id === $this->recordingActionId || array_key_exists($id, $presetBindings)) {
                continue;
            }

            if ($custom['combo'] === $combo) {
                Notification::make()
                    ->title(__('filament-mouseless::mouseless.table.steal.protected', [
                        'key' => Keys::display($combo),
                        'action' => $custom['label'],
                    ]))
                    ->danger()->send();

                return; // Recording stays active — try another combo.
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
        $this->unmountAction();

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
        $this->unmountAction();

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
        // Acting on a row ends any in-progress recording — otherwise the cell
        // stays stuck in "press a key…"/steal mode after the row changed.
        $this->cancelRecording();

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
        $this->cancelRecording();

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
        $this->cancelRecording();

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

    // ------------------------------------------------------------------
    // Group (category) actions
    // ------------------------------------------------------------------

    /**
     * Buttons injected into a category's group-header row: disable/enable the
     * whole group and reset it to the parent preset. Returns null when nothing
     * applies (the header then shows no description). Rendered raw through the
     * Group description slot — see resources/views/tables/group-actions.blade.php.
     */
    protected function shortcutsGroupActionsHtml(string $category): ?HtmlString
    {
        $rows = array_values(array_filter(
            $this->shortcutRows(),
            fn (array $row): bool => $row['category'] === $category,
        ));

        if ($rows === []) {
            return null;
        }

        $buttons = [];

        // Reset mirrors the per-row reset: offered only on an editable preset
        // that actually diverges from its parent somewhere in this group.
        $hasChanges = false;
        foreach ($rows as $row) {
            if ($row['changed']) {
                $hasChanges = true;

                break;
            }
        }

        if (! $this->isShortcutsLocked() && $hasChanges) {
            $buttons[] = [
                'method' => 'resetCategory',
                'label' => __('filament-mouseless::mouseless.table.group.reset'),
                'icon' => 'heroicon-m-arrow-uturn-left',
                'color' => 'gray',
            ];
        }

        // Disable/enable only touches non-protected, editable rows.
        $toggleable = array_filter(
            $rows,
            fn (array $row): bool => ! ($row['readonly'] ?? false) && ! $this->isProtectedShortcut($row['id']),
        );

        if ($toggleable !== []) {
            $allDisabled = true;
            foreach ($toggleable as $row) {
                if (! $row['disabled']) {
                    $allDisabled = false;

                    break;
                }
            }

            $buttons[] = $allDisabled
                ? [
                    'method' => 'enableCategory',
                    'label' => __('filament-mouseless::mouseless.table.group.enable'),
                    'icon' => 'heroicon-m-play',
                    'color' => 'success',
                ]
                : [
                    'method' => 'disableCategory',
                    'label' => __('filament-mouseless::mouseless.table.group.disable'),
                    'icon' => 'heroicon-m-no-symbol',
                    'color' => 'danger',
                ];
        }

        if ($buttons === []) {
            return null;
        }

        return new HtmlString(
            view('filament-mouseless::tables.group-actions', [
                'category' => $category,
                'buttons' => $buttons,
            ])->render(),
        );
    }

    /**
     * @return array<int, string> Every action ID belonging to the category.
     */
    protected function categoryIds(string $category): array
    {
        return array_values(array_map(
            fn (array $row): string => $row['id'],
            array_filter($this->shortcutRows(), fn (array $row): bool => $row['category'] === $category),
        ));
    }

    /**
     * @return array<int, string> Non-protected, editable action IDs in the category.
     */
    protected function toggleableCategoryIds(string $category): array
    {
        return array_values(array_map(
            fn (array $row): string => $row['id'],
            array_filter(
                $this->shortcutRows(),
                fn (array $row): bool => $row['category'] === $category
                    && ! ($row['readonly'] ?? false)
                    && ! $this->isProtectedShortcut($row['id']),
            ),
        ));
    }

    public function disableCategory(string $category): void
    {
        $this->cancelRecording();

        $preset = $this->getShortcutsPreset() ?? [];
        $ids = array_values(array_diff(
            $this->toggleableCategoryIds($category),
            (array) ($preset['disabled_actions'] ?? []),
        ));

        if ($ids === []) {
            return;
        }

        // Fork first (on a locked preset), then re-read so we mutate the copy.
        $this->ensureEditableShortcutsPreset();

        $preset = $this->getShortcutsPreset() ?? [];
        $disabled = array_values(array_unique([...(array) ($preset['disabled_actions'] ?? []), ...$ids]));

        if (! $this->saveShortcuts((array) ($preset['bindings'] ?? []), $disabled)) {
            return;
        }

        Notification::make()
            ->title(__('filament-mouseless::mouseless.table.group.disabled', [
                'count' => count($ids),
                'group' => ActionMeta::categoryLabel($category),
            ]))
            ->success()->send();
    }

    public function enableCategory(string $category): void
    {
        $this->cancelRecording();

        $preset = $this->getShortcutsPreset() ?? [];
        $ids = array_values(array_intersect(
            $this->toggleableCategoryIds($category),
            (array) ($preset['disabled_actions'] ?? []),
        ));

        if ($ids === []) {
            return;
        }

        $this->ensureEditableShortcutsPreset();

        $preset = $this->getShortcutsPreset() ?? [];
        $disabled = array_values(array_diff((array) ($preset['disabled_actions'] ?? []), $ids));

        if (! $this->saveShortcuts((array) ($preset['bindings'] ?? []), $disabled)) {
            return;
        }

        Notification::make()
            ->title(__('filament-mouseless::mouseless.table.group.enabled', [
                'count' => count($ids),
                'group' => ActionMeta::categoryLabel($category),
            ]))
            ->success()->send();
    }

    public function resetCategory(string $category): void
    {
        $this->cancelRecording();

        if ($this->isShortcutsLocked()) {
            return;
        }

        $preset = $this->getShortcutsPreset() ?? [];
        $parent = $this->getShortcutsParentPreset() ?? [];

        $bindings = (array) ($preset['bindings'] ?? []);
        $disabled = (array) ($preset['disabled_actions'] ?? []);
        $parentBindings = (array) ($parent['bindings'] ?? []);
        $parentDisabled = (array) ($parent['disabled_actions'] ?? []);

        $count = 0;
        foreach ($this->categoryIds($category) as $id) {
            $before = [$bindings[$id] ?? null, in_array($id, $disabled, true)];

            if (array_key_exists($id, $parentBindings)) {
                $bindings[$id] = $parentBindings[$id];
            } else {
                unset($bindings[$id]);
            }

            $disabled = in_array($id, $parentDisabled, true)
                ? array_values(array_unique([...$disabled, $id]))
                : array_values(array_diff($disabled, [$id]));

            if ($before !== [$bindings[$id] ?? null, in_array($id, $disabled, true)]) {
                $count++;
            }
        }

        if ($count === 0) {
            return;
        }

        if (! $this->saveShortcuts($bindings, $disabled)) {
            return;
        }

        Notification::make()
            ->title(__('filament-mouseless::mouseless.table.group.reset_done', [
                'count' => $count,
                'group' => ActionMeta::categoryLabel($category),
            ]))
            ->success()->send();
    }
}
