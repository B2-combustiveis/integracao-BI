<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebPostoSyncControl extends Model
{
    protected $table = 'webposto_sync_controls';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_change_sync_at' => 'datetime', 'last_full_sync_at' => 'datetime',
            'last_started_at' => 'datetime', 'last_completed_at' => 'datetime', 'metadata' => 'array'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WebPostoSyncEndpointRun::class);
    }
}
