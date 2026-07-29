<?php

use Blemli\FilamentMouseless\Filament\Pages\MyShortcuts;
use Blemli\FilamentMouseless\Filament\Widgets\StatisticsOverview;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Livewire\StatisticsFlush;
use Blemli\FilamentMouseless\Models\Nudge;
use Blemli\FilamentMouseless\Models\Statistic;
use Blemli\FilamentMouseless\MouselessWidget;
use Blemli\FilamentMouseless\Support\ScriptData;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;

beforeEach(function () {
    // The package ships its migrations as publishable stubs, which the test
    // migrator doesn't pick up — run them by hand (the preset tables too:
    // the widget's untapped list resolves the user's bindings; nudges for
    // the teach click-priority list).
    foreach (['statistics', 'presets', 'user_settings', 'nudges'] as $table) {
        $migration = include __DIR__ . "/../database/migrations/create_mouseless_{$table}_table.php.stub";
        $migration->up();
    }
});

function makeStatsPanel(string $id, bool $statistics = true, bool $stateless = false): void
{
    $plugin = FilamentMouselessPlugin::make()->statistics($statistics);
    if ($stateless) {
        $plugin->stateless();
    }

    $panel = Panel::make()->id($id)->plugin($plugin);

    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
}

function actingAsStatsUser(int $id = 1): void
{
    $user = new class extends User
    {
        protected $table = 'users';
    };
    $user->forceFill(['id' => $id]);
    $user->exists = true;

    test()->actingAs($user);
}

it('is off by default and enabled fluently', function () {
    makeStatsPanel('stats-off-panel', statistics: false);
    expect(FilamentMouselessPlugin::statisticsEnabled())->toBeFalse();

    makeStatsPanel('stats-on-panel');
    expect(FilamentMouselessPlugin::statisticsEnabled())->toBeTrue();
});

it('is ignored in stateless mode', function () {
    makeStatsPanel('stats-stateless-panel', statistics: true, stateless: true);

    expect(FilamentMouselessPlugin::statisticsEnabled())->toBeFalse();
});

it('merges flushes into one daily row and keeps the sums', function () {
    Statistic::record(1, ['crud.create' => ['kb' => 3, 'click' => 1]]);
    Statistic::record(1, ['crud.create' => ['kb' => 2], 'nav.goto' => ['click' => 4]]);

    $row = Statistic::query()->where('user_id', 1)->get();
    expect($row)->toHaveCount(1)
        ->and($row->first()->counters)->toBe([
            'crud.create' => ['kb' => 5, 'click' => 1],
            'nav.goto' => ['kb' => 0, 'click' => 4],
        ])
        ->and($row->first()->keyboard_count)->toBe(5)
        ->and($row->first()->click_count)->toBe(5);
});

it('sums per-action totals across days', function () {
    Statistic::create([
        'user_id' => 1,
        'date' => today()->subDay(),
        'counters' => ['crud.edit' => ['kb' => 7, 'click' => 2]],
        'keyboard_count' => 7,
        'click_count' => 2,
    ]);
    Statistic::record(1, ['crud.edit' => ['kb' => 3]]);
    Statistic::record(2, ['crud.edit' => ['kb' => 100]]); // someone else

    expect(Statistic::perActionTotals(1))->toBe(['crud.edit' => ['kb' => 10, 'click' => 2]])
        ->and(Statistic::lifetimeKeyboardCount(1))->toBe(10);
});

it('reports milestones exactly when a flush crosses them', function () {
    Statistic::create([
        'user_id' => 1,
        'date' => today()->subDay(),
        'counters' => ['a' => ['kb' => 98, 'click' => 0]],
        'keyboard_count' => 98,
        'click_count' => 0,
    ]);

    expect(Statistic::record(1, ['a' => ['kb' => 1]]))->toBe([])       // 99: not yet
        ->and(Statistic::record(1, ['a' => ['kb' => 1]]))->toBe([100]) // 100: crossed
        ->and(Statistic::record(1, ['a' => ['kb' => 1]]))->toBe([]);   // 101: only once
});

it('reports every milestone a single large flush jumps over', function () {
    expect(Statistic::record(1, ['a' => ['kb' => 1500]]))->toBe([100, 1000]);
});

it('fills quiet days with zeros in the daily series', function () {
    Statistic::record(1, ['a' => ['kb' => 4, 'click' => 2]]);

    $series = Statistic::dailyCounts(1, 7);

    expect($series)->toHaveCount(7)
        ->and($series[today()->toDateString()])->toBe(['kb' => 4, 'click' => 2])
        ->and($series[today()->subDays(3)->toDateString()])->toBe(['kb' => 0, 'click' => 0]);
});

it('plots the weekly keyboard share and skips quiet weeks', function () {
    makeStatsPanel('stats-trend-panel');
    actingAsStatsUser(12);

    // Three weeks back: 50 % share. This week: 100 %. Quiet weeks between.
    Statistic::create([
        'user_id' => 12,
        'date' => today()->subDays(21),
        'counters' => [],
        'keyboard_count' => 5,
        'click_count' => 5,
    ]);
    Statistic::record(12, ['a' => ['kb' => 4]]);

    $widget = new StatisticsOverview;
    $points = explode(' ', $widget->trendPoints());

    expect($widget->hasTrend())->toBeTrue()
        ->and($points)->toHaveCount(2)          // quiet weeks contribute no points
        ->and($points[0])->toEndWith(',16')     // 50 % share → mid-scale
        ->and(end($points))->toEndWith(',2');   // 100 % share → top of the viewbox
});

it('needs two active weeks before showing a trend', function () {
    makeStatsPanel('stats-trend-single-panel');
    actingAsStatsUser(13);

    Statistic::record(13, ['a' => ['kb' => 30, 'click' => 10]]);

    expect((new StatisticsOverview)->hasTrend())->toBeFalse();
});

it('shows the dashboard widget only while statistics are live', function () {
    makeStatsPanel('stats-canview-panel');
    actingAsStatsUser(14);
    expect(MouselessWidget::canView())->toBeTrue();

    makeStatsPanel('stats-canview-off-panel', statistics: false);
    expect(MouselessWidget::canView())->toBeFalse();
});

it('aggregates org totals and the leaderboard', function () {
    Statistic::record(1, ['a' => ['kb' => 10, 'click' => 2]]);
    Statistic::record(2, ['a' => ['kb' => 30]]);
    Statistic::record(3, ['b' => ['kb' => 20]]);

    expect(Statistic::totals())->toBe(['keyboard' => 60, 'clicks' => 2, 'users' => 3])
        ->and(Statistic::leaderboard(2))->toBe([
            ['user_id' => 2, 'keyboard' => 30],
            ['user_id' => 3, 'keyboard' => 20],
        ]);
});

it('persists flushed events for the authenticated user', function () {
    makeStatsPanel('stats-flush-panel');
    actingAsStatsUser(7);

    Livewire::test(StatisticsFlush::class)
        ->dispatch('mouseless-stats-flush', events: ['crud.create' => ['kb' => 2, 'click' => 1]]);

    expect(Statistic::perActionTotals(7))->toBe(['crud.create' => ['kb' => 2, 'click' => 1]]);
});

it('ignores flushes from guests', function () {
    makeStatsPanel('stats-guest-panel');

    Livewire::test(StatisticsFlush::class)
        ->dispatch('mouseless-stats-flush', events: ['crud.create' => ['kb' => 2]]);

    expect(Statistic::query()->count())->toBe(0);
});

it('sanitizes garbage out of a flush payload', function () {
    makeStatsPanel('stats-garbage-panel');
    actingAsStatsUser(8);

    Livewire::test(StatisticsFlush::class)
        ->dispatch('mouseless-stats-flush', events: [
            'crud.create' => ['kb' => -5, 'click' => 999999],   // negative dropped, huge capped
            'bad id with spaces!' => ['kb' => 3],               // invalid id dropped
            'nav.goto' => 'not-an-array',                        // wrong shape dropped
            'ui.help' => ['kb' => 0, 'click' => 0],              // empty dropped
        ]);

    expect(Statistic::perActionTotals(8))->toBe([
        'crud.create' => ['kb' => 0, 'click' => Statistic::MAX_EVENTS_PER_FLUSH],
    ]);
});

it('congratulates when a flush crosses a milestone', function () {
    makeStatsPanel('stats-milestone-panel');
    actingAsStatsUser(9);

    Statistic::create([
        'user_id' => 9,
        'date' => today()->subDay(),
        'counters' => ['a' => ['kb' => 99, 'click' => 0]],
        'keyboard_count' => 99,
        'click_count' => 0,
    ]);

    Livewire::test(StatisticsFlush::class)
        ->dispatch('mouseless-stats-flush', events: ['a' => ['kb' => 1]])
        ->assertNotified();
});

it('exposes the invoked column and stats aside only when tracking is live', function () {
    makeStatsPanel('stats-page-panel');
    actingAsStatsUser(10);
    expect((new MyShortcuts)->hasShortcutsStatistics())->toBeTrue();

    makeStatsPanel('stats-page-off-panel', statistics: false);
    expect((new MyShortcuts)->hasShortcutsStatistics())->toBeFalse();
});

it('ranks the most-clicked teachable actions for the teach layer', function () {
    $panel = Panel::make()->id('stats-teach-panel')
        ->plugin(FilamentMouselessPlugin::make()->statistics()->teach());
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
    actingAsStatsUser(15);

    Statistic::record(15, [
        'crud.create' => ['click' => 9], // most clicked, but already taught
        'nav.goto' => ['click' => 7],
        'crud.edit' => ['click' => 5],
        'ui.help' => ['kb' => 3],        // keyboard-only — never a click offender
    ]);
    foreach (range(1, 3) as $i) {
        Nudge::recordUsed(15, 'crud.create');
    }

    $teach = (new ScriptData([]))->jsonSerialize()['teach'];

    expect($teach['clickPriority'])->toBe(['nav.goto', 'crud.edit']);
});

it('sends no click priority when statistics are off — teach falls back to today', function () {
    $panel = Panel::make()->id('teach-only-panel')
        ->plugin(FilamentMouselessPlugin::make()->teach());
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
    actingAsStatsUser(16);

    $teach = (new ScriptData([]))->jsonSerialize()['teach'];

    expect($teach['clickPriority'])->toBe([]);
});

it('lists bound actions the user clicks more than they key as untapped', function () {
    makeStatsPanel('stats-widget-panel');
    actingAsStatsUser(11);

    Statistic::record(11, [
        'crud.create' => ['kb' => 1, 'click' => 6],  // bound + clicked more → untapped
        'crud.edit' => ['kb' => 9, 'click' => 2],    // keyed more → not untapped
        'custom.ghost' => ['kb' => 0, 'click' => 5], // no binding → not untapped
    ]);

    $widget = new StatisticsOverview;
    $untapped = $widget->untappedActions();

    expect($widget->clicksAvoided())->toBe(10)
        ->and($untapped)->toHaveCount(1)
        ->and($untapped[0]['clicks'])->toBe(6)
        ->and($untapped[0]['kb'])->toBe(1)
        ->and($untapped[0]['combo'])->not->toBe('');
});

it('honors custom milestones configured on the plugin', function () {
    $panel = Panel::make()->id('custom-milestones')->plugin(FilamentMouselessPlugin::make()->statistics(milestones: [25]));
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);

    expect(Statistic::record(1, ['a' => ['kb' => 24]]))->toBe([])
        ->and(Statistic::record(1, ['a' => ['kb' => 1]]))->toBe([25])
        ->and(Statistic::record(1, ['a' => ['kb' => 200]]))->toBe([]);
});
