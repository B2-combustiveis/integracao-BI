<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationServiceRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(IntegrationService::class, 'integration_service_id');
    }
}
