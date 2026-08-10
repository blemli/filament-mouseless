<?php

namespace Blemli\FilamentMouseless\Events;

use Blemli\FilamentMouseless\Models\Preset;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A layout was imported from a JSON export. $replacedExisting tells whether
 * it overwrote one of the user's layouts in place (no PresetCreated then)
 * or was stored as a new one (PresetCreated fires too).
 */
class PresetImported
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Preset $preset,
        public readonly bool $replacedExisting,
    ) {}
}
