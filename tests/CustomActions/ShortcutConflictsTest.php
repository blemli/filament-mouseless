<?php

use Blemli\FilamentMouseless\Services\CustomActionRegistry;
use Blemli\FilamentMouseless\Support\ShortcutConflicts;

it('reports no duplicates when every combo is unique', function () {
    $count = ShortcutConflicts::duplicateCount([
        'bindings' => ['list.search' => 'alt+f', 'record.view' => 'alt+a'],
        'disabled_actions' => [],
    ]);

    expect($count)->toBe(0);
});

it('counts both actions that share a combo', function () {
    $count = ShortcutConflicts::duplicateCount([
        'bindings' => ['list.search' => 'alt+b', 'record.view' => 'alt+b'],
        'disabled_actions' => [],
    ]);

    expect($count)->toBe(2);
});

it('flags a binding colliding with a read-only custom action code combo', function () {
    // Read-only customs never reach the resolved binding map, but they still
    // clash at dispatch time — the badge must count them like the table does.
    app(CustomActionRegistry::class)->registerRuntime('custom.export', [
        'label' => 'Export',
        'keyBindings' => ['alt+e'],
        'managed' => false,
    ]);

    $count = ShortcutConflicts::duplicateCount([
        'bindings' => ['list.search' => 'alt+e'],
        'disabled_actions' => [],
    ]);

    expect($count)->toBe(2);
});

it('excludes disabled actions from the duplicate count', function () {
    $count = ShortcutConflicts::duplicateCount([
        'bindings' => ['list.search' => 'alt+b', 'record.view' => 'alt+b'],
        'disabled_actions' => ['record.view'],
    ]);

    expect($count)->toBe(0);
});

it('lets a preset override of a custom action win over its code combo', function () {
    app(CustomActionRegistry::class)->registerRuntime('custom.approve', [
        'label' => 'Approve',
        'keyBindings' => ['alt+a'],
        'managed' => true,
    ]);

    // Override moves the custom off alt+a, so nothing collides with the core
    // action still on alt+a.
    $count = ShortcutConflicts::duplicateCount([
        'bindings' => ['record.view' => 'alt+a', 'custom.approve' => 'alt+x'],
        'disabled_actions' => [],
    ]);

    expect($count)->toBe(0);
});
