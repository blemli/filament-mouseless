<?php

use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\UserSetting;
use Blemli\FilamentMouseless\Services\ActionDiscovery;
use Blemli\FilamentMouseless\Services\BindingResolver;
use Blemli\FilamentMouseless\Services\CustomActionRegistry;
use Blemli\FilamentMouseless\Services\PresetRegistry;
use Blemli\FilamentMouseless\Support\Keys;
use Blemli\FilamentMouseless\Support\PanelAuth;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;

function makeMouselessPanel(string $id, FilamentMouselessPlugin $plugin): Panel
{
    $panel = Panel::make()->id($id)->plugin($plugin);
    Filament::registerPanel($panel);

    return $panel;
}

/** Switch panels the way a new request would: fresh package services. */
function onPanel(Panel $panel): void
{
    Filament::setCurrentPanel($panel);
    foreach ([PresetRegistry::class, BindingResolver::class, CustomActionRegistry::class, ActionDiscovery::class] as $service) {
        app()->forgetInstance($service);
    }
}

it('resolves each panel\'s own fluent config', function () {
    // No built-in preset for this locale, so the panel default decides.
    app()->setLocale('xx');

    $admin = makeMouselessPanel('mp-admin', FilamentMouselessPlugin::make()
        ->defaultPreset('german-default')
        ->remap('crud.create', 'alt+shift+x')
        ->disabledActions(['crud.delete'])
        ->jump(chord: 'alt,alt', timeoutMs: 500)
        ->reservedKeys(['ctrl+q'])
        ->listModeIgnoreIn(['input']));

    $customer = makeMouselessPanel('mp-customer', FilamentMouselessPlugin::make());

    onPanel($admin);
    $resolved = app(BindingResolver::class)->forUser(null);
    expect($resolved['preset']['slug'])->toBe('german-default')
        ->and($resolved['bindings']['crud.create'])->toBe('alt+shift+x')
        ->and($resolved['bindings'])->not->toHaveKey('crud.delete')
        ->and(FilamentMouselessPlugin::jumpChord())->toBe('alt,alt')
        ->and(FilamentMouselessPlugin::jumpTimeoutMs())->toBe(500)
        ->and(Keys::reservedKeys())->toBe(['ctrl+q'])
        ->and(FilamentMouselessPlugin::listModeIgnoreSelectors())->toBe(['input']);

    onPanel($customer);
    $resolved = app(BindingResolver::class)->forUser(null);
    expect($resolved['preset']['slug'])->toBe(config('mouseless.default_preset'))
        ->and($resolved['bindings']['crud.create'])->not->toBe('alt+shift+x')
        ->and($resolved['bindings'])->toHaveKey('crud.delete')
        ->and(FilamentMouselessPlugin::jumpChord())->toBe(config('mouseless.jump.chord'))
        ->and(FilamentMouselessPlugin::listModeIgnoreSelectors())->toBe(config('mouseless.list_mode.ignore_in'));
});

it('shares the user\'s preset choice across panels', function () {
    foreach (['presets', 'user_settings'] as $table) {
        $migration = include __DIR__ . "/../database/migrations/create_mouseless_{$table}_table.php.stub";
        $migration->up();
    }

    $a = makeMouselessPanel('mp-share-a', FilamentMouselessPlugin::make());
    $b = makeMouselessPanel('mp-share-b', FilamentMouselessPlugin::make());

    // The user picks a preset "on panel A"…
    onPanel($a);
    UserSetting::query()->updateOrCreate(['user_id' => 1], ['active_preset_slug' => 'german-default']);

    // …and panel B follows: one row per user, no per-panel copy.
    onPanel($b);
    $resolved = app(BindingResolver::class)->forUser(1);
    expect($resolved['preset']['slug'])->toBe('german-default')
        ->and(UserSetting::query()->where('user_id', 1)->count())->toBe(1);
});

it('resolves the user through the panel\'s own auth guard', function () {
    config()->set('auth.guards.second', ['driver' => 'session', 'provider' => 'users']);

    $panel = makeMouselessPanel('mp-guard', FilamentMouselessPlugin::make());
    $panel->authGuard('second');
    onPanel($panel);

    $user = new class extends User
    {
        protected $table = 'users';
    };
    $user->forceFill(['id' => 7]);
    $user->exists = true;

    // Log into the panel's guard directly — actingAs() would also make
    // 'second' the app default via shouldUse() and hide the difference.
    auth()->guard('second')->setUser($user);

    // The default guard sees nobody — only the panel guard knows the user.
    expect(auth()->id())->toBeNull()
        ->and(PanelAuth::id())->toBe(7)
        ->and(PanelAuth::check())->toBeTrue();
});
