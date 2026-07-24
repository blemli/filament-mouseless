<?php

use Blemli\FilamentMouseless\Support\Keys;

it('merges the all bucket with the current platform', function () {
    config()->set('mouseless.reserved_keys', [
        'all' => ['Tab', 'Enter'],
        'mac' => ['cmd+w'],
        'windows' => ['ctrl+w', 'alt+f4'],
        'linux' => ['ctrl+w', 'ctrl+alt+l'],
    ]);

    // No user agent in tests → non-mac bucket (linux).
    expect(Keys::reservedKeys())
        ->toContain('Tab')
        ->toContain('ctrl+alt+l')
        ->not->toContain('cmd+w')
        ->not->toContain('alt+f4');
});

it('accepts the pre-2.0 flat list as the all bucket', function () {
    config()->set('mouseless.reserved_keys', ['Tab', 'cmd+r']);

    expect(Keys::reservedKeys())->toBe(['Tab', 'cmd+r']);
});
