<?php

namespace Blemli\FilamentMouseless\Models;

use Illuminate\Database\Eloquent\Model;

class UserSetting extends Model
{
    protected $table = 'mouseless_user_settings';

    protected $guarded = [];

    protected $casts = [
        'overrides' => 'array',
    ];

    public static function forUser(int $userId): self
    {
        return static::firstOrCreate(
            ['user_id' => $userId],
            ['active_preset_slug' => null, 'overrides' => []],
        );
    }
}
