<?php

use Blemli\FilamentMouseless\Events\MilestoneReached;
use Blemli\FilamentMouseless\Events\PresetActivated;
use Blemli\FilamentMouseless\Events\PresetCreated;
use Blemli\FilamentMouseless\Events\PresetDeleted;
use Blemli\FilamentMouseless\Events\PresetImported;
use Blemli\FilamentMouseless\Events\PresetPublished;
use Blemli\FilamentMouseless\Events\PresetUnpublished;
use Blemli\FilamentMouseless\Events\ShortcutsChanged;
use Blemli\FilamentMouseless\Filament\Concerns\InteractsWithShortcutsTable;
use Blemli\FilamentMouseless\Filament\Widgets\PresetSelector;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Livewire\StatisticsFlush;
use Blemli\FilamentMouseless\Models\Preset;
use Blemli\FilamentMouseless\Models\Statistic;
use Blemli\FilamentMouseless\Models\UserSetting;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    foreach (['presets', 'user_settings', 'statistics', 'nudges'] as $table) {
        $migration = include __DIR__ . "/../database/migrations/create_mouseless_{$table}_table.php.stub";
        $migration->up();
    }
});

function makeEventsPanel(string $id, bool $publishable = false): void
{
    $plugin = FilamentMouselessPlugin::make()->statistics();
    if ($publishable) {
        $plugin->publishable();
    }

    $panel = Panel::make()->id($id)->plugin($plugin);

    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
}

function actingAsEventsUser(int $id): void
{
    $user = new class extends User
    {
        protected $table = 'users';
    };
    $user->forceFill(['id' => $id]);
    $user->exists = true;

    test()->actingAs($user);
}

/** Minimal shortcuts-table host whose current layout is $preset. */
function makeEventsTableHost(array $preset): object
{
    return new class($preset)
    {
        use InteractsWithShortcutsTable {
            saveShortcuts as public;
        }

        public function __construct(private array $currentPreset) {}

        public function getShortcutsPreset(): ?array
        {
            return $this->currentPreset;
        }

        public function getShortcutsParentPreset(): ?array
        {
            return null;
        }

        public function isShortcutsLocked(): bool
        {
            return false;
        }

        public function ensureEditableShortcutsPreset(): void {}

        public function persistShortcuts(array $bindings, array $disabled): bool
        {
            return true;
        }

        public function flushCachedTableRecords(): void {}
    };
}

it('fires ShortcutsChanged with the diff when a layout is saved', function () {
    actingAsEventsUser(21);
    Event::fake([ShortcutsChanged::class]);

    $host = makeEventsTableHost([
        'slug' => 'my-layout',
        'bindings' => ['crud.create' => 'alt+n', 'crud.save' => 'alt+s'],
        'disabled_actions' => [],
    ]);

    $host->saveShortcuts(['crud.create' => 'alt+m', 'crud.save' => 'alt+s'], ['nav.goto']);

    Event::assertDispatched(ShortcutsChanged::class, function (ShortcutsChanged $event): bool {
        return $event->userId === 21
            && $event->presetSlug === 'my-layout'
            && $event->oldBindings['crud.create'] === 'alt+n'
            && $event->newBindings['crud.create'] === 'alt+m'
            && $event->changedActionIds() === ['crud.create', 'nav.goto'];
    });
});

it('fires PresetCreated when a layout is stored and PresetDeleted when it goes', function () {
    actingAsEventsUser(22);
    Event::fake([PresetCreated::class, PresetDeleted::class]);

    $preset = Preset::forkFrom(
        ['slug' => 'english-default', 'bindings' => ['crud.create' => 'alt+n']],
        'My Layout',
    );

    Event::assertDispatched(PresetCreated::class, fn (PresetCreated $event): bool => $event->preset->is($preset));

    $preset->delete();

    Event::assertDispatched(PresetDeleted::class, fn (PresetDeleted $event): bool => $event->preset->is($preset));
});

it('fires PresetActivated only when the active layout actually changes', function () {
    makeEventsPanel('events-activate-panel');
    actingAsEventsUser(23);

    $preset = Preset::forkFrom(['slug' => 'english-default', 'bindings' => []], 'Switch Target');

    Event::fake([PresetActivated::class]);

    Livewire::test(PresetSelector::class)
        ->set('data.activePresetSlug', $preset->slug);

    Event::assertDispatched(PresetActivated::class, function (PresetActivated $event) use ($preset): bool {
        return $event->userId === 23
            && $event->previousSlug === null
            && $event->slug === $preset->slug;
    });

    // Re-selecting the already active layout stays silent.
    Event::fake([PresetActivated::class]);

    Livewire::test(PresetSelector::class)
        ->set('data.activePresetSlug', $preset->slug);

    Event::assertNotDispatched(PresetActivated::class);
});

it('fires PresetPublished and PresetUnpublished when sharing is toggled', function () {
    makeEventsPanel('events-publish-panel', publishable: true);
    actingAsEventsUser(25);
    // The default gate isn't defined in the test app; blank = open to everyone.
    config()->set('mouseless.publishing.gate', null);

    $preset = Preset::forkFrom(['slug' => 'english-default', 'bindings' => []], 'Shared Layout');
    UserSetting::updateOrCreate(['user_id' => 25], ['active_preset_slug' => $preset->slug]);

    Event::fake([PresetPublished::class, PresetUnpublished::class]);

    Livewire::test(PresetSelector::class)->callAction('publishLayout');

    Event::assertDispatched(PresetPublished::class, fn (PresetPublished $event): bool => $event->preset->is($preset));

    Livewire::test(PresetSelector::class)->callAction('publishLayout');

    Event::assertDispatched(PresetUnpublished::class, fn (PresetUnpublished $event): bool => $event->preset->is($preset));
});

it('fires PresetImported when an import lands as a new layout', function () {
    makeEventsPanel('events-import-panel');
    actingAsEventsUser(26);

    Event::fake([PresetImported::class]);

    Livewire::test(PresetSelector::class)
        ->set('pendingImport', ['name' => 'Imported Layout', 'bindings' => ['crud.create' => 'alt+i']])
        ->callAction('resolveImportConflict');

    Event::assertDispatched(PresetImported::class, function (PresetImported $event): bool {
        return $event->preset->name === 'Imported Layout'
            && $event->replacedExisting === false;
    });
});

it('fires MilestoneReached when a flush crosses a milestone', function () {
    makeEventsPanel('events-milestone-panel');
    actingAsEventsUser(24);
    Event::fake([MilestoneReached::class]);

    Statistic::create([
        'user_id' => 24,
        'date' => today()->subDay(),
        'counters' => ['a' => ['kb' => 99, 'click' => 0]],
        'keyboard_count' => 99,
        'click_count' => 0,
    ]);

    Livewire::test(StatisticsFlush::class)
        ->dispatch('mouseless-stats-flush', events: ['a' => ['kb' => 1]]);

    Event::assertDispatched(MilestoneReached::class, function (MilestoneReached $event): bool {
        return $event->userId === 24
            && $event->milestone === 100
            && $event->lifetimeKeyboardCount === 100;
    });
});
