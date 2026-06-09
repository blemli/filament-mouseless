<?php

namespace Blemli\FilamentMouseless\Models;

use Illuminate\Database\Eloquent\Model;

class Preset extends Model
{
    protected $table = 'mouseless_presets';

    protected $guarded = [];

    protected $casts = [
        'bindings' => 'array',
        'panels' => 'array',
        'is_published' => 'bool',
        'published_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

    public function isApproved(): bool
    {
        return $this->is_published && $this->approved_at !== null;
    }
}
