<?php

namespace Blemli\FilamentMouseless\Events;

use Blemli\FilamentMouseless\Models\Preset;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A previously shared layout was made private again. */
class PresetUnpublished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Preset $preset) {}
}
