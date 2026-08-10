<?php

namespace Blemli\FilamentMouseless\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * The user switched their active layout. A null slug means "no explicit
 * selection — follow the UI locale's built-in default".
 */
class PresetActivated
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly ?string $previousSlug,
        public readonly ?string $slug,
    ) {}
}
