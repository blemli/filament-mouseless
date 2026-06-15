<?php

use Blemli\FilamentMouseless\Services\BindingResolver;
use Blemli\FilamentMouseless\Services\CustomActionRegistry;

it('merges managed custom defaults into the binding map', function () {
    app(CustomActionRegistry::class)->registerRuntime('custom.approve', [
        'label' => 'Approve',
        'keyBindings' => ['mod+shift+a'],
        'managed' => true,
    ]);

    $resolved = app(BindingResolver::class)->forUser(null);

    expect($resolved['bindings'])->toHaveKey('custom.approve')
        ->and($resolved['bindings']['custom.approve'])->toBe('ctrl+shift+a');
});

it('never adds read-only custom actions to the binding map', function () {
    app(CustomActionRegistry::class)->registerRuntime('custom.export', [
        'label' => 'Export',
        'keyBindings' => ['mod+e'],
        'managed' => false,
    ]);

    $resolved = app(BindingResolver::class)->forUser(null);

    expect($resolved['bindings'])->not->toHaveKey('custom.export');
});

it('honours disabled_actions for custom actions', function () {
    config()->set('mouseless.disabled_actions', ['custom.approve']);

    app(CustomActionRegistry::class)->registerRuntime('custom.approve', [
        'label' => 'Approve',
        'keyBindings' => ['mod+shift+a'],
        'managed' => true,
    ]);

    $resolved = app(BindingResolver::class)->forUser(null);

    expect($resolved['bindings'])->not->toHaveKey('custom.approve');
});
