<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationServiceCompanyRun extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'resource_results' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'heartbeat_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(IntegrationServiceRun::class, 'integration_service_run_id');
    }
}
