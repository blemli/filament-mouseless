<?php

use Blemli\FilamentMouseless\Models\Nudge;

beforeEach(function () {
    // The package ships its migrations as publishable stubs, which the test
    // migrator doesn't pick up — run this one by hand.
    $migration = include __DIR__ . '/../../database/migrations/create_mouseless_nudges_table.php.stub';
    $migration->up();
});

it('backs off doubling from an hour to a week', function () {
    expect(Nudge::backoffHours(0))->toBe(0)
        ->and(Nudge::backoffHours(1))->toBe(1)
        ->and(Nudge::backoffHours(2))->toBe(2)
        ->and(Nudge::backoffHours(3))->toBe(4)
        ->and(Nudge::backoffHours(4))->toBe(8)
        ->and(Nudge::backoffHours(8))->toBe(128)
        ->and(Nudge::backoffHours(9))->toBe(168)
        ->and(Nudge::backoffHours(1000))->toBe(168); // no overflow, capped at a week
});

it('marks an action taught after three keyboard uses in a row', function () {
    Nudge::recordUsed(1, 'crud.edit');
    Nudge::recordUsed(1, 'crud.edit');
    expect(Nudge::statesFor(1)['crud.edit']['learned'])->toBeFalse();

    Nudge::recordUsed(1, 'crud.edit');
    expect(Nudge::statesFor(1)['crud.edit'])->toMatchArray(['learned' => true, 'streak' => 3]);
});

it('breaks the streak when the mouse is used instead', function () {
    Nudge::recordUsed(1, 'crud.edit');
    Nudge::recordUsed(1, 'crud.edit');
    Nudge::breakStreak(1, 'crud.edit');
    Nudge::recordUsed(1, 'crud.edit');

    // 2 uses + click + 1 use = streak 1, not taught.
    expect(Nudge::statesFor(1)['crud.edit'])->toMatchArray(['learned' => false, 'streak' => 1]);
});

it('showing a nudge starts the backoff and resets the streak', function () {
    Nudge::recordUsed(2, 'crud.create');
    Nudge::recordShown(2, 'crud.create');

    $state = Nudge::statesFor(2)['crud.create'];
    expect($state['shown'])->toBe(1)
        ->and($state['streak'])->toBe(0)
        ->and($state['nextAt'])->toBeGreaterThan(now()->getTimestampMs());
});

it('mutes and unmutes all teaching via the sentinel row', function () {
    expect(Nudge::isMuted(3))->toBeFalse();

    Nudge::muteAll(3);
    expect(Nudge::isMuted(3))->toBeTrue()
        ->and(Nudge::statesFor(3))->toBe([]); // the sentinel never leaks into action states

    Nudge::clear(3, Nudge::MUTE_ALL);
    expect(Nudge::isMuted(3))->toBeFalse();
});

it('clearing a nudge makes the action teachable again', function () {
    Nudge::dismiss(4, 'record.share');
    expect(Nudge::statesFor(4)['record.share']['dismissed'])->toBeTrue();

    Nudge::clear(4, 'record.share');
    expect(Nudge::statesFor(4))->not->toHaveKey('record.share');
});
