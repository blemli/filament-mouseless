<?php

use Blemli\FilamentMouseless\FilamentMouselessPlugin;
use Blemli\FilamentMouseless\Support\ScriptData;
use Filament\Facades\Filament;
use Filament\Panel;

function makeJumpPanel(string $id, ?bool $jump = null): void
{
    $plugin = FilamentMouselessPlugin::make();
    if ($jump !== null) {
        $plugin->jump($jump);
    }

    $panel = Panel::make()->id($id)->plugin($plugin);

    Filament::registerPanel($panel);
    Filament::setCurrentPanel($panel);
}

it('is off by default: null payload', function () {
    makeJumpPanel('jump-default-panel');

    expect(FilamentMouselessPlugin::jumpEnabled())->toBeFalse()
        ->and((new ScriptData([]))->jsonSerialize()['jump'])->toBeNull();
});

it('ships chord, timeout and strings when enabled', function () {
    makeJumpPanel('jump-on-panel', jump: true);

    $payload = (new ScriptData([]))->jsonSerialize()['jump'];

    expect(FilamentMouselessPlugin::jumpEnabled())->toBeTrue()
        ->and($payload['chord'])->toBe('ctrl,ctrl')
        ->and($payload['timeoutMs'])->toBe(350)
        ->and($payload['strings']['no_targets'])->toBeString()->not->toBe('')
        ->and($payload['strings']['move_hint'])->toBeString()->not->toBe('');
});

it('reflects config overrides for chord and timeout', function () {
    config()->set('mouseless.jump.chord', 'alt,alt');
    config()->set('mouseless.jump.timeout_ms', 500);

    makeJumpPanel('jump-config-panel', jump: true);

    $payload = (new ScriptData([]))->jsonSerialize()['jump'];

    expect($payload['chord'])->toBe('alt,alt')
        ->and($payload['timeoutMs'])->toBe(500);
});

it('can be disabled explicitly', function () {
    makeJumpPanel('jump-off-panel', jump: false);

    expect((new ScriptData([]))->jsonSerialize()['jump'])->toBeNull();
});

it('lists the chord in the help overlay only when enabled', function () {
    makeJumpPanel('jump-overlay-off-panel', jump: false);
    Livewire\Livewire::test(Blemli\FilamentMouseless\Livewire\HelpOverlay::class)
        ->assertDontSee('2×');

    makeJumpPanel('jump-overlay-on-panel', jump: true);
    Livewire\Livewire::test(Blemli\FilamentMouseless\Livewire\HelpOverlay::class)
        ->assertSee('2×')
        ->assertSee(__('filament-mouseless::mouseless.action.ui.jump'));
});
