<?php

namespace App\Services\Integration;

use App\Jobs\SyncFinancialReceivables;
use App\Jobs\SyncWebPostoDatabase;
use App\Jobs\SyncWebPostoNewRecords;
use App\Models\IntegrationService;
use Illuminate\Support\Facades\DB;

class IntegrationServiceDispatcher
{
    public function dispatchDue(): int
    {
        $ids = IntegrationService::query()->where('active', true)
            ->where(fn ($query) => $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->pluck('id');
        foreach ($ids as $id) $this->dispatch((int) $id);
        return $ids->count();
    }

    public function dispatch(int $serviceId): void
    {
        DB::transaction(function () use ($serviceId): void {
            $service = IntegrationService::query()->lockForUpdate()->findOrFail($serviceId);
            $service->update(['next_run_at' => now()->addMinutes($service->frequency_minutes)]);
            match ($service->resource) {
                'titulos-receber' => SyncFinancialReceivables::dispatch($service->id),
                'webposto-database-changes' => SyncWebPostoDatabase::dispatch($service->id),
                'webposto-new-records' => SyncWebPostoNewRecords::dispatch($service->id),
                default => throw new \InvalidArgumentException("Recurso {$service->resource} não possui job."),
            };
        });
    }
}
