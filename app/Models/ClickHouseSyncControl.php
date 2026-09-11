<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ClickHouseSyncControl extends Model
{
    protected $table = 'clickhouse_sync_controls';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_watermark' => 'datetime',
            'last_full_sync_at' => 'datetime',
            'last_started_at' => 'datetime',
            'last_completed_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
