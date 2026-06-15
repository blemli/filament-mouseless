<?php

use Blemli\FilamentMouseless\Support\Keys;

it('translates mousetrap modifiers to the engine form', function () {
    expect(Keys::fromMousetrap('command+s', mac: true))->toBe('cmd+s')
        ->and(Keys::fromMousetrap('option+up', mac: false))->toBe('alt+arrowup')
        ->and(Keys::fromMousetrap('control+shift+a', mac: false))->toBe('ctrl+shift+a')
        ->and(Keys::fromMousetrap('return', mac: false))->toBe('enter');
});

it('resolves mod to cmd on mac and ctrl elsewhere', function () {
    expect(Keys::fromMousetrap('mod+s', mac: true))->toBe('cmd+s')
        ->and(Keys::fromMousetrap('mod+s', mac: false))->toBe('ctrl+s');
});

it('returns null for empty or sequence combos', function () {
    expect(Keys::fromMousetrap(null))->toBeNull()
        ->and(Keys::fromMousetrap(''))->toBeNull()
        ->and(Keys::fromMousetrap('g i'))->toBeNull();
});
