<?php

use Blemli\FilamentMouseless\Filament\Concerns\InteractsWithShortcutsTable;
use Blemli\FilamentMouseless\Filament\Pages\MyShortcuts;
use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Livewire\HelpOverlay;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;

function makeCurrentPanelWithPlugin(string $id, FilamentMouselessPlugin $plugin): void
{
    $panel = Panel::make()->id($id)->plugin($plugin);

    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
}

/** Minimal shortcuts-table host: always locked, so the fork prompt would apply. */
function makeLockedShortcutsTableHost(): object
{
    return new class
    {
        use InteractsWithShortcutsTable {
            configureShortcutsForkConfirmation as public;
        }

        public function getShortcutsPreset(): ?array
        {
            return ['bindings' => []];
        }

        public function getShortcutsParentPreset(): ?array
        {
            return null;
        }

        public function isShortcutsLocked(): bool
        {
            return true;
        }

        public function ensureEditableShortcutsPreset(): void {}

        public function persistShortcuts(array $bindings, array $disabled): bool
        {
            return true;
        }
    };
}

it('is on by default', function () {
    expect(FilamentMouselessPlugin::make()->isSingleton())->toBeTrue();
});

it('stays off for panels without the plugin', function () {
    expect(FilamentMouselessPlugin::singletonEnabled())->toBeFalse();
});

it('disables singleton mode via presets()', function () {
    expect(FilamentMouselessPlugin::make()->presets()->isSingleton())->toBeFalse()
        ->and(FilamentMouselessPlugin::make()->presets()->presets(false)->isSingleton())->toBeTrue();
});

it('lets stateless win over singleton mode', function () {
    $plugin = FilamentMouselessPlugin::make()->stateless();

    expect($plugin->isSingleton())->toBeFalse()
        ->and($plugin->isStateless())->toBeTrue();
});

it('warns once when presets and stateless are combined', function () {
    // The one-shot flag is process-global — reset it so this test doesn't
    // depend on being the first registration in the PHPUnit process.
    (new ReflectionProperty(FilamentMouselessPlugin::class, 'loggedPresetsConflict'))->setValue(null, false);

    Log::shouldReceive('warning')
        ->once()
        ->withArgs(fn (string $message): bool => str_contains($message, 'presets'));

    $plugin = FilamentMouselessPlugin::make()->presets()->stateless();
    $plugin->register(Panel::make()->id('conflict-a'));
    // Second registration must not log again (one-shot flag).
    $plugin->register(Panel::make()->id('conflict-b'));
});

it('does not warn when stateless rides on the singleton default', function () {
    (new ReflectionProperty(FilamentMouselessPlugin::class, 'loggedPresetsConflict'))->setValue(null, false);

    Log::shouldReceive('warning')->never();

    FilamentMouselessPlugin::make()->stateless()->register(Panel::make()->id('stateless-default'));
});

it('reports singleton mode for the current panel', function () {
    makeCurrentPanelWithPlugin('singleton-panel', FilamentMouselessPlugin::make());

    expect(FilamentMouselessPlugin::singletonEnabled())->toBeTrue()
        ->and((new MyShortcuts)->isSingletonMode())->toBeTrue();
});

it('keeps the shortcuts page accessible in singleton mode', function () {
    makeCurrentPanelWithPlugin('singleton-access-panel', FilamentMouselessPlugin::make());

    $user = new class extends User
    {
        protected $table = 'users';
    };
    $user->forceFill(['id' => 1]);
    $user->exists = true;

    $this->actingAs($user);

    expect(MyShortcuts::canAccess())->toBeTrue();
});

it('skips the fork confirmation modal in singleton mode', function () {
    makeCurrentPanelWithPlugin('singleton-fork-panel', FilamentMouselessPlugin::make());

    $action = makeLockedShortcutsTableHost()->configureShortcutsForkConfirmation(Action::make('record'));

    expect($action->isConfirmationRequired())->toBeFalse()
        ->and($action->getModalDescription())->toBeNull();
});

it('keeps the fork confirmation modal outside singleton mode', function () {
    makeCurrentPanelWithPlugin('default-fork-panel', FilamentMouselessPlugin::make()->presets());

    $action = makeLockedShortcutsTableHost()->configureShortcutsForkConfirmation(Action::make('record'));

    expect($action->isConfirmationRequired())->toBeTrue();
});

it('hides the preset source from the help overlay in singleton mode', function () {
    makeCurrentPanelWithPlugin('singleton-overlay-panel', FilamentMouselessPlugin::make());

    Livewire::test(HelpOverlay::class)->assertViewHas('preset', null);
});

it('keeps the preset source in the help overlay outside singleton mode', function () {
    makeCurrentPanelWithPlugin('default-overlay-panel', FilamentMouselessPlugin::make()->presets());

    Livewire::test(HelpOverlay::class)->assertViewHas(
        'preset',
        fn (?array $preset): bool => ($preset['slug'] ?? null) === 'english-default',
    );
});
