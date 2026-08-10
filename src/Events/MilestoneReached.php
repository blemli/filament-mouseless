<?php

namespace Blemli\FilamentMouseless\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A statistics flush pushed the user's lifetime keyboard count across a
 * configured milestone (see ->statistics(milestones: [...])). One event
 * per milestone crossed; the in-app congratulation fires independently.
 */
class MilestoneReached
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly int $milestone,
        public readonly int $lifetimeKeyboardCount,
    ) {}
}
