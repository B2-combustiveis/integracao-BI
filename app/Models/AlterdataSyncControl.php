<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlterdataSyncControl extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_watermark' => 'datetime',
            'last_full_sync_at' => 'datetime',
        ];
    }
}
