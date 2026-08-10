<?php

namespace Blemli\FilamentMouseless\Events;

use Blemli\FilamentMouseless\Models\Preset;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** A personal layout was deleted. */
class PresetDeleted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Preset $preset) {}
}
