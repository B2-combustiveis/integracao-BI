<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebPostoInitialSyncRun extends Model
{
    protected $table = 'webposto_initial_sync_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'completed_resources' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
