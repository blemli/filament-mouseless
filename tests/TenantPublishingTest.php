<?php

use Blemli\FilamentMouseless\Filament\Widgets\PresetSelector;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Models\Preset;
use Blemli\FilamentMouseless\Models\UserSetting;
use Blemli\FilamentMouseless\Services\PresetRegistry;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Livewire\Livewire;

beforeEach(function () {
    foreach (['presets', 'user_settings', 'statistics', 'nudges'] as $table) {
        $migration = include __DIR__ . "/../database/migrations/create_mouseless_{$table}_table.php.stub";
        $migration->up();
    }

    config()->set('mouseless.publishing.enabled', true);
    config()->set('mouseless.publishing.require_approval', false);
    // The default gate isn't defined in the test app; blank = open to everyone.
    config()->set('mouseless.publishing.gate', null);
});

/** Any model with a key works as a Filament tenant — no table needed. */
function makeTenant(int $key): Model
{
    $tenant = new class extends User
    {
        protected $table = 'users';
    };
    $tenant->forceFill(['id' => $key]);
    $tenant->exists = true;

    return $tenant;
}

function actingAsTenantUser(int $id): void
{
    $user = new class extends User
    {
        protected $table = 'users';
    };
    $user->forceFill(['id' => $id]);
    $user->exists = true;

    test()->actingAs($user);
}

/** A layout some OTHER user published — visible only through publishing. */
function publishedLayout(string $slug, ?string $tenantId): Preset
{
    return Preset::create([
        'slug' => $slug,
        'name' => ucfirst($slug),
        'bindings' => [],
        'owner_user_id' => 999,
        'is_published' => true,
        'published_at' => now(),
        'approved_at' => now(),
        'tenant_id' => $tenantId,
    ]);
}

it('scopes published layouts to the current tenant when enabled', function () {
    config()->set('mouseless.publishing.scope_to_tenant', true);

    publishedLayout('from-tenant-a', '1');
    publishedLayout('from-tenant-b', '2');
    publishedLayout('installation-wide', null);

    Filament::setTenant(makeTenant(1), isQuiet: true);
    expect(app(PresetRegistry::class)->all(5))
        ->toHaveKey('from-tenant-a')
        ->toHaveKey('installation-wide')
        ->not->toHaveKey('from-tenant-b');

    // Same request, other tenant — the registry memo must not leak across.
    Filament::setTenant(makeTenant(2), isQuiet: true);
    expect(app(PresetRegistry::class)->all(5))
        ->toHaveKey('from-tenant-b')
        ->not->toHaveKey('from-tenant-a');

    // No tenant (CLI, non-tenant panel): only installation-wide layouts.
    Filament::setTenant(null);
    expect(app(PresetRegistry::class)->all(5))
        ->toHaveKey('installation-wide')
        ->not->toHaveKey('from-tenant-a')
        ->not->toHaveKey('from-tenant-b');
});

it('keeps published layouts installation-wide while scoping is off', function () {
    publishedLayout('from-tenant-a', '1');

    Filament::setTenant(makeTenant(2), isQuiet: true);

    expect(app(PresetRegistry::class)->all(5))->toHaveKey('from-tenant-a');
});

it('stamps the publishing tenant on the layout and clears it on unpublish', function () {
    $panel = Panel::make()->id('tenant-publish-panel')->plugin(
        FilamentMouselessPlugin::make()->publishable()->scopePublishingToTenant(),
    );
    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
    Filament::setTenant(makeTenant(7), isQuiet: true);

    actingAsTenantUser(25);
    $preset = Preset::forkFrom(['slug' => 'english-default', 'bindings' => []], 'Tenant Layout');
    UserSetting::updateOrCreate(['user_id' => 25], ['active_preset_slug' => $preset->slug]);

    Livewire::test(PresetSelector::class)->callAction('publishLayout');

    $preset->refresh();
    expect($preset->is_published)->toBeTrue()
        ->and($preset->tenant_id)->toBe('7');

    Livewire::test(PresetSelector::class)->callAction('publishLayout');

    $preset->refresh();
    expect($preset->is_published)->toBeFalse()
        ->and($preset->tenant_id)->toBeNull();
});
