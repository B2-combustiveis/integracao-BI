<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebPostoSyncEndpointRun extends Model
{
    protected $table = 'webposto_sync_endpoint_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function control(): BelongsTo
    {
        return $this->belongsTo(WebPostoSyncControl::class, 'webposto_sync_control_id');
    }
}
