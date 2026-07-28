<?php

use Blemli\FilamentMouseless\Facades\FilamentMouseless;
use Blemli\FilamentMouseless\Filament\Pages\MouselessSettings;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\AdminOverride;
use Blemli\FilamentMouseless\Models\Preset;
use Blemli\FilamentMouseless\Models\Statistic;
use Blemli\FilamentMouseless\Models\UserSetting;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    foreach (['statistics', 'presets', 'user_settings', 'nudges', 'admin_overrides'] as $table) {
        $migration = include __DIR__ . "/../database/migrations/create_mouseless_{$table}_table.php.stub";
        $migration->up();
    }
});

function makeAdminOverridesPanel(string $id, bool $statistics = false): void
{
    $plugin = FilamentMouselessPlugin::make()->statistics($statistics);

    $panel = Panel::make()->id($id)->plugin($plugin);

    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
}

function actingAsAdminOverridesUser(int $id = 1): void
{
    $user = new class extends User
    {
        protected $table = 'users';
    };
    $user->forceFill(['id' => $id]);
    $user->exists = true;

    test()->actingAs($user);
}

/** A full-snapshot fork of english-default, as the fork flow creates them. */
function forkEnglishDefault(int $userId, array $rebinds = []): Preset
{
    $base = include __DIR__ . '/../config/mouseless/presets/english-default.php';

    $fork = Preset::create([
        'slug' => "fork-{$userId}",
        'name' => "Fork {$userId}",
        'locale' => 'en',
        'version' => '1.0',
        'owner_user_id' => $userId,
        'source' => 'user',
        'parent_slug' => 'english-default',
        'bindings' => array_merge($base['bindings'], $rebinds),
        'disabled_actions' => [],
    ]);

    UserSetting::updateOrCreate(['user_id' => $userId], ['active_preset_slug' => $fork->slug]);

    return $fork;
}

it('stores deltas only and deletes rows equal to the default', function () {
    AdminOverride::apply('crud.edit', rebound: true, combo: 'alt+shift+9', disabled: false);

    expect(AdminOverride::current())->toHaveKey('crud.edit')
        ->and(AdminOverride::current()['crud.edit']['combo'])->toBe('alt+shift+9');

    AdminOverride::apply('crud.edit', rebound: false, combo: null, disabled: false);

    expect(AdminOverride::current())->toBe([]);
});

it('remembers the previous combo when a rebind changes again', function () {
    AdminOverride::apply('crud.edit', rebound: true, combo: 'alt+shift+9', disabled: false);
    AdminOverride::apply('crud.edit', rebound: true, combo: 'alt+shift+8', disabled: false);

    $override = AdminOverride::current()['crud.edit'];

    expect($override['combo'])->toBe('alt+shift+8')
        ->and($override['previous'])->toBe('alt+shift+9');
});

it('applies a rebind to users on the default preset', function () {
    makeAdminOverridesPanel('adm-default-panel');
    actingAsAdminOverridesUser();

    AdminOverride::apply('crud.edit', rebound: true, combo: 'alt+shift+9', disabled: false);

    expect(FilamentMouseless::resolver()->forUser(1)['bindings']['crud.edit'])->toBe('alt+shift+9');
});

it('never touches an explicit fork rebind', function () {
    makeAdminOverridesPanel('adm-explicit-panel');
    actingAsAdminOverridesUser();
    forkEnglishDefault(1, ['crud.edit' => 'alt+q']);

    AdminOverride::apply('crud.edit', rebound: true, combo: 'alt+shift+9', disabled: false);

    expect(FilamentMouseless::resolver()->forUser(1)['bindings']['crud.edit'])->toBe('alt+q')
        ->and(FilamentMouseless::resolver()->adminOverridesFor(1)['crud.edit']['state'])->toBe('explicit');
});

it('reaches untouched snapshot entries inside forks', function () {
    makeAdminOverridesPanel('adm-implicit-panel');
    actingAsAdminOverridesUser();
    forkEnglishDefault(1); // crud.edit snapshotted as alt+e, never changed

    AdminOverride::apply('crud.edit', rebound: true, combo: 'alt+shift+9', disabled: false);

    expect(FilamentMouseless::resolver()->forUser(1)['bindings']['crud.edit'])->toBe('alt+shift+9')
        ->and(FilamentMouseless::resolver()->adminOverridesFor(1)['crud.edit']['state'])->toBe('applied');
});

it('keeps a key the user actively used before the change', function () {
    makeAdminOverridesPanel('adm-kept-panel', statistics: true);
    actingAsAdminOverridesUser();

    Statistic::create([
        'user_id' => 1,
        'date' => today()->subDays(3),
        'counters' => ['crud.edit' => ['kb' => 5, 'click' => 0]],
        'keyboard_count' => 5,
        'click_count' => 0,
    ]);

    AdminOverride::apply('crud.edit', rebound: true, combo: 'alt+shift+9', disabled: false);

    $decision = FilamentMouseless::resolver()->adminOverridesFor(1)['crud.edit'];

    expect(FilamentMouseless::resolver()->forUser(1)['bindings']['crud.edit'])->toBe('alt+e')
        ->and($decision['state'])->toBe('kept')
        ->and($decision['default'])->toBe('alt+shift+9');
});

it('keeps the previous override combo for users who adopted it', function () {
    makeAdminOverridesPanel('adm-prev-panel', statistics: true);
    actingAsAdminOverridesUser();

    AdminOverride::apply('crud.edit', rebound: true, combo: 'alt+shift+8', disabled: false);
    DB::table('mouseless_admin_overrides')->where('action_id', 'crud.edit')->update([
        'created_at' => now()->subDays(10),
        'combo_set_at' => now(),
        'previous_combo' => 'alt+shift+9',
    ]);

    // First used 5 days ago — after the first override, before today's change:
    // the key under their fingers was the previous override combo.
    Statistic::create([
        'user_id' => 1,
        'date' => today()->subDays(5),
        'counters' => ['crud.edit' => ['kb' => 2, 'click' => 0]],
        'keyboard_count' => 2,
        'click_count' => 0,
    ]);

    expect(FilamentMouseless::resolver()->forUser(1)['bindings']['crud.edit'])->toBe('alt+shift+9');
});

it('applies the new default to users who only used it afterwards', function () {
    makeAdminOverridesPanel('adm-after-panel', statistics: true);
    actingAsAdminOverridesUser();

    AdminOverride::apply('crud.edit', rebound: true, combo: 'alt+shift+8', disabled: false);
    DB::table('mouseless_admin_overrides')->where('action_id', 'crud.edit')->update([
        'created_at' => now()->subDays(10),
        'combo_set_at' => now()->subDays(10),
    ]);

    Statistic::create([
        'user_id' => 1,
        'date' => today()->subDay(),
        'counters' => ['crud.edit' => ['kb' => 2, 'click' => 0]],
        'keyboard_count' => 2,
        'click_count' => 0,
    ]);

    expect(FilamentMouseless::resolver()->forUser(1)['bindings']['crud.edit'])->toBe('alt+shift+8');
});

it('applies to everyone when statistics are off', function () {
    makeAdminOverridesPanel('adm-nostats-panel', statistics: false);
    actingAsAdminOverridesUser();

    Statistic::create([
        'user_id' => 1,
        'date' => today()->subDays(3),
        'counters' => ['crud.edit' => ['kb' => 5, 'click' => 0]],
        'keyboard_count' => 5,
        'click_count' => 0,
    ]);

    AdminOverride::apply('crud.edit', rebound: true, combo: 'alt+shift+9', disabled: false);

    expect(FilamentMouseless::resolver()->forUser(1)['bindings']['crud.edit'])->toBe('alt+shift+9');
});

it('hard-disables an action for fork users too', function () {
    makeAdminOverridesPanel('adm-disable-panel');
    actingAsAdminOverridesUser();
    forkEnglishDefault(1, ['crud.edit' => 'alt+q']);

    AdminOverride::apply('crud.edit', rebound: false, combo: null, disabled: true);

    $resolved = FilamentMouseless::resolver()->forUser(1);

    expect($resolved['disabled'])->toContain('crud.edit')
        ->and($resolved['bindings'])->not->toHaveKey('crud.edit');
});

it('drops the weaker claim when a kept key collides with a reassigned one', function () {
    makeAdminOverridesPanel('adm-conflict-panel', statistics: true);
    actingAsAdminOverridesUser();

    // The user actively uses crud.edit on its default alt+e …
    Statistic::create([
        'user_id' => 1,
        'date' => today()->subDays(3),
        'counters' => ['crud.edit' => ['kb' => 9, 'click' => 0]],
        'keyboard_count' => 9,
        'click_count' => 0,
    ]);

    // … then the admin moves crud.edit away and hands alt+e to crud.view.
    AdminOverride::apply('crud.edit', rebound: true, combo: 'alt+shift+9', disabled: false);
    AdminOverride::apply('crud.view', rebound: true, combo: 'alt+e', disabled: false);

    $resolved = FilamentMouseless::resolver()->forUser(1);
    $decisions = FilamentMouseless::resolver()->adminOverridesFor(1);

    expect($resolved['bindings']['crud.edit'])->toBe('alt+e')      // kept
        ->and($resolved['bindings'])->not->toHaveKey('crud.view')  // dropped
        ->and($decisions['crud.view']['state'])->toBe('conflict');

    // A user without that muscle memory gets both new defaults.
    $fresh = FilamentMouseless::resolver()->forUser(2);
    expect($fresh['bindings']['crud.edit'])->toBe('alt+shift+9')
        ->and($fresh['bindings']['crud.view'])->toBe('alt+e');
});

it('prefers the admin-chosen default preset over the config fallback', function () {
    makeAdminOverridesPanel('adm-slug-panel');
    actingAsAdminOverridesUser();

    // No built-in matches this locale, so resolution falls to the default slug.
    app()->setLocale('xx');
    AdminOverride::setDefaultPresetSlug('german-default');

    expect(FilamentMouseless::resolver()->forUser(1)['preset']['slug'])->toBe('german-default');
});

it('writes table edits as deltas and clears reverted ones', function () {
    makeAdminOverridesPanel('adm-page-panel');
    actingAsAdminOverridesUser();

    $page = new MouselessSettings;
    $base = $page->getShortcutsPreset();

    $edited = $base['bindings'];
    $edited['crud.edit'] = 'alt+shift+9';

    expect($page->persistShortcuts($edited, ['crud.view']))->toBeTrue();

    $overrides = AdminOverride::current();
    expect($overrides['crud.edit']['combo'])->toBe('alt+shift+9')
        ->and($overrides['crud.view']['disabled'])->toBeTrue()
        ->and($overrides)->toHaveCount(2)
        ->and(session()->get(MouselessSettings::WARNED_SESSION_KEY))->toBeTrue();

    // Reverting to the base values deletes the rows again.
    expect($page->persistShortcuts($base['bindings'], []))->toBeTrue()
        ->and(AdminOverride::current())->toBe([]);
});

it('shows the admin deltas as changed rows on the settings page', function () {
    makeAdminOverridesPanel('adm-rows-panel');
    actingAsAdminOverridesUser();

    AdminOverride::apply('crud.edit', rebound: true, combo: 'alt+shift+9', disabled: false);

    $page = new MouselessSettings;
    $preset = $page->getShortcutsPreset();

    expect($preset['bindings']['crud.edit'])->toBe('alt+shift+9')
        ->and($page->getShortcutsParentPreset()['bindings']['crud.edit'])->toBe('alt+e')
        ->and($page->isShortcutsLocked())->toBeFalse();
});

it('gates the moderation subpage on publishing approval', function () {
    makeAdminOverridesPanel('adm-mod-panel');
    actingAsAdminOverridesUser();

    config()->set('mouseless.publishing.enabled', false);
    expect(\Blemli\FilamentMouseless\Filament\Pages\PresetModeration::canAccess())->toBeFalse();

    config()->set('mouseless.publishing.enabled', true);
    config()->set('mouseless.publishing.require_approval', true);
    expect(\Blemli\FilamentMouseless\Filament\Pages\PresetModeration::canAccess())->toBeTrue();
});
