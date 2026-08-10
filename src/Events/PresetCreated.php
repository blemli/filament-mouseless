<?php

namespace Blemli\FilamentMouseless\Events;

use Blemli\FilamentMouseless\Models\Preset;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A personal layout was stored — fork-on-first-edit, "create layout",
 * or an import that didn't overwrite an existing layout.
 */
class PresetCreated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Preset $preset) {}
}
