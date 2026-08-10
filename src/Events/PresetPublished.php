<?php

namespace Blemli\FilamentMouseless\Events;

use Blemli\FilamentMouseless\Models\Preset;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A layout was shared with other users. When publishing requires approval,
 * the preset is not approved yet — check $preset->isApproved().
 */
class PresetPublished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly Preset $preset) {}
}
