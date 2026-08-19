<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IntegrationService extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'settings' => 'array', 'last_started_at' => 'datetime',
            'last_completed_at' => 'datetime', 'next_run_at' => 'datetime'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(IntegrationServiceRun::class);
    }
}
