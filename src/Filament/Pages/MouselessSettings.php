<?php

namespace Blemli\FilamentMouseless\Filament\Pages;

use Blemli\FilamentMouseless\Facades\FilamentMouseless;
use Blemli\FilamentMouseless\Filament\Concerns\InteractsWithShortcutsTable;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\AdminOverride;
use Blemli\FilamentMouseless\Models\Statistic;
use Blemli\FilamentMouseless\Services\CustomActionRegistry;
use Blemli\FilamentMouseless\Support\ActionMeta;
use Blemli\FilamentMouseless\Support\Keys;
use Blemli\FilamentMouseless\Support\Shield;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema as SchemaFacade;

/**
 * The SuperAdmin page: the org-wide default preset choice, the defaults
 * table (the same shortcuts table as /my-shortcuts, editing the defaults
 * for ALL users via AdminOverride deltas) and the all-users statistics.
 * Preset moderation lives on the PresetModeration subpage.
 */
class MouselessSettings extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithShortcutsTable;
    use InteractsWithTable;

    protected static ?string $slug = 'mouseless-settings';

    public const WARNED_SESSION_KEY = 'mouseless.admin_defaults_warned';

    /** @var array<string, mixed>|null Request memo — the table resolves this dozens of times per render. */
    protected ?array $effectivePresetMemo = null;

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
        return FilamentMouselessPlugin::safeGet()?->getSettingsPageLabel()
            ?? __('filament-mouseless::mouseless.admin.nav_label');
    }

    protected string $view = 'filament-mouseless::pages.settings';

    public ?array $data = [];

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public static function canAccess(): bool
    {
        return (bool) config('mouseless.admin.enabled', true)
            && static::userMayModerate()
            && Shield::userCanAccessPage(static::class);
    }

    /**
     * Gate-based authorization for everything this page exposes.
     *
     * If `mouseless.admin.gate` is set, the user must pass that Gate ability —
     * fail-closed. If it's null/empty, the page is open to any authenticated
     * panel user (the config flag is the only gate). Consuming apps SHOULD
     * register a Gate ability and point this config at it.
     */
    public static function userMayModerate(): bool
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
        $this->form->fill([
            'default_preset' => AdminOverride::defaultPresetSlug()
                ?: config('mouseless.default_preset'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $presets = collect(FilamentMouseless::registry()->all())
            ->mapWithKeys(fn ($p, $slug) => [$slug => ($p['name'] ?? $slug) . ' (' . ($p['locale'] ?? '?') . ')'])
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
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        abort_unless(static::userMayModerate(), 403);

        $payload = $this->form->getState();

        AdminOverride::setDefaultPresetSlug($payload['default_preset'] ?? null);
        FilamentMouseless::flush();

        Notification::make()
            ->title(__('filament-mouseless::mouseless.admin.saved'))
            ->success()
            ->send();
    }

    // ------------------------------------------------------------------
    // Defaults table (shared shortcuts table, editing for ALL users)
    // ------------------------------------------------------------------

    public function table(Table $table): Table
    {
        return $this->shortcutsTable($table);
    }

    public function hasDefaultsTable(): bool
    {
        return (bool) config('mouseless.admin.defaults_table', true)
            && $this->overridesTableExists();
    }

    protected function overridesTableExists(): bool
    {
        try {
            return SchemaFacade::hasTable('mouseless_admin_overrides');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The base the admin edits: the default preset users without an own
     * selection resolve — the built-in matching the admin's UI locale, then
     * the configured default. Deltas against it become AdminOverride rows.
     *
     * @return array<string, mixed>
     */
    protected function baseDefaultPreset(): array
    {
        $registry = FilamentMouseless::registry();

        $preset = $registry->builtInForLocale(app()->getLocale())
            ?? $registry->find(AdminOverride::defaultPresetSlug() ?: (string) config('mouseless.default_preset'))
            ?? collect($registry->builtIn())->first();

        return $preset ?? ['slug' => null, 'bindings' => [], 'disabled_actions' => []];
    }

    /** Base + the current admin deltas — what the table displays. */
    public function getShortcutsPreset(): ?array
    {
        if ($this->effectivePresetMemo !== null) {
            return $this->effectivePresetMemo;
        }

        $base = $this->baseDefaultPreset();
        $bindings = (array) ($base['bindings'] ?? []);
        $disabled = (array) ($base['disabled_actions'] ?? []);

        foreach (AdminOverride::current() as $actionId => $override) {
            if (AdminOverride::rebinds($override)) {
                $bindings[$actionId] = $override['combo'];
            }

            if ($override['disabled']) {
                $disabled[] = $actionId;
            }
        }

        return $this->effectivePresetMemo = [
            ...$base,
            'bindings' => $bindings,
            'disabled_actions' => array_values(array_unique($disabled)),
        ];
    }

    /** Comparison baseline = the pristine base, so "changed" means "has a delta". */
    public function getShortcutsParentPreset(): ?array
    {
        return $this->baseDefaultPreset();
    }

    public function isShortcutsLocked(): bool
    {
        return false;
    }

    public function ensureEditableShortcutsPreset(): void
    {
        // Nothing to fork — deltas are written directly by persistShortcuts().
        // Reaching this point means the all-users warning was confirmed (or
        // already acknowledged); "record" doesn't persist immediately, so the
        // flag is set here, not only in persistShortcuts().
        session()->put(self::WARNED_SESSION_KEY, true);
    }

    /**
     * Diff the edited map against the pristine base and write the deltas as
     * AdminOverride rows; entries equal to the base delete their row.
     */
    public function persistShortcuts(array $bindings, array $disabled): bool
    {
        abort_unless(static::userMayModerate(), 403);

        if (! $this->overridesTableExists()) {
            return false;
        }

        $base = $this->baseDefaultPreset();
        $baseBindings = (array) ($base['bindings'] ?? []);
        $baseDisabled = (array) ($base['disabled_actions'] ?? []);
        $customDefaults = $this->customDefaults();

        $ids = array_unique([
            ...array_keys($bindings),
            ...array_keys($baseBindings),
            ...array_keys($customDefaults),
            ...array_values($disabled),
            ...array_values($baseDisabled),
            ...array_keys(AdminOverride::current()),
        ]);

        foreach ($ids as $actionId) {
            $baseCombo = array_key_exists($actionId, $baseBindings)
                ? $baseBindings[$actionId]
                : ($customDefaults[$actionId] ?? null);

            $target = array_key_exists($actionId, $bindings) ? $bindings[$actionId] : $baseCombo;
            $rebound = Keys::normalize($target) !== Keys::normalize($baseCombo);

            // A disable delta only exists on top of an enabled base action —
            // overrides can't (and don't need to) re-enable base disables.
            $wantsDisabled = in_array($actionId, $disabled, true)
                && ! in_array($actionId, $baseDisabled, true);

            AdminOverride::apply($actionId, $rebound, $rebound ? $target : null, $wantsDisabled);
        }

        // First edit confirmed — no more "applies to all users" prompts
        // this session.
        session()->put(self::WARNED_SESSION_KEY, true);

        $this->effectivePresetMemo = null;

        return true;
    }

    // ------------------------------------------------------------------
    // All-users warning (reuses the fork-confirmation wrapper)
    // ------------------------------------------------------------------

    protected function shortcutsEditNeedsConfirmation(): bool
    {
        return ! session()->get(self::WARNED_SESSION_KEY, false);
    }

    protected function shortcutsEditConfirmHeading(): string
    {
        return __('filament-mouseless::mouseless.admin.warn.heading');
    }

    protected function shortcutsEditConfirmDescription(): string
    {
        return __('filament-mouseless::mouseless.admin.warn.description');
    }

    protected function shortcutsEditConfirmSubmitLabel(): string
    {
        return __('filament-mouseless::mouseless.admin.warn.confirm');
    }

    // ------------------------------------------------------------------
    // Table host tweaks
    // ------------------------------------------------------------------

    /**
     * The teach/invoked columns would show the admin's *personal* state —
     * meaningless on a table that edits everyone's defaults.
     */
    protected function shortcutsTeachEnabled(): bool
    {
        return false;
    }

    public function hasShortcutsStatistics(): bool
    {
        return false;
    }

    /**
     * Flag deltas whose combo is already taken in another locale's built-in
     * preset — the override applies to every locale, so it would collide
     * there ("one layer, all locales" trade-off made visible).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function decorateShortcutRows(array $rows): array
    {
        $builtIns = FilamentMouseless::registry()->builtIn();
        $baseSlug = $this->baseDefaultPreset()['slug'] ?? null;

        foreach ($rows as $index => $row) {
            if (! $row['changed'] || $row['normalized'] === null) {
                continue;
            }

            $conflicts = [];

            foreach ($builtIns as $slug => $preset) {
                if ($slug === $baseSlug) {
                    continue;
                }

                foreach ((array) ($preset['bindings'] ?? []) as $otherId => $combo) {
                    if ($otherId !== $row['id'] && Keys::normalize($combo) === $row['normalized']) {
                        $conflicts[] = ($preset['name'] ?? $slug) . ': ' . ActionMeta::label($otherId);
                    }
                }
            }

            if ($conflicts !== []) {
                $rows[$index]['cross_locale'] = $conflicts;
            }
        }

        return $rows;
    }

    /** @return array<string, string> */
    protected function customDefaults(): array
    {
        try {
            return app(CustomActionRegistry::class)->managedDefaults();
        } catch (\Throwable) {
            return [];
        }
    }

    // ------------------------------------------------------------------
    // Statistics (all users)
    // ------------------------------------------------------------------

    /**
     * The all-users statistics block: org-wide totals, the most-used
     * shortcuts, and the keyboard leaderboard. Null hides the section —
     * when the plugin doesn't track statistics, the table isn't migrated,
     * or the section is disabled via `mouseless.admin.statistics`.
     *
     * @return array{
     *     totals: array{keyboard: int, clicks: int, users: int},
     *     topActions: array<int, array{label: string, keyboard: int}>,
     *     leaderboard: array<int, array{name: string, keyboard: int}>,
     * }|null
     */
    public function getStatisticsSummary(): ?array
    {
        if (! FilamentMouselessPlugin::statisticsEnabled() || ! config('mouseless.admin.statistics', true)) {
            return null;
        }

        try {
            if (! SchemaFacade::hasTable('mouseless_statistics')) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $topActions = collect(Statistic::perActionTotalsAllUsers())
            ->sortByDesc(fn (array $t): int => $t['kb'])
            ->take(5)
            ->map(fn (array $t, string $id): array => [
                'label' => ActionMeta::label($id),
                'keyboard' => $t['kb'],
            ])
            ->values()
            ->all();

        $leaderboard = array_map(fn (array $row): array => [
            'name' => $this->statisticsUserName($row['user_id']),
            'keyboard' => $row['keyboard'],
        ], Statistic::leaderboard());

        return [
            'totals' => Statistic::totals(),
            'topActions' => $topActions,
            'leaderboard' => $leaderboard,
        ];
    }

    protected function statisticsUserName(int $userId): string
    {
        try {
            $user = Filament::auth()->getProvider()->retrieveById($userId);

            if ($user) {
                return Filament::getUserName($user);
            }
        } catch (\Throwable) {
            // Fall through to the anonymous label.
        }

        return __('filament-mouseless::mouseless.admin.stats_unknown_user', ['id' => $userId]);
    }
}
