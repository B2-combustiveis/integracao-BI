<?php

namespace App\Services\Integration;

use App\Jobs\SyncClickHouseIncremental;
use App\Jobs\SyncAlterdata;
use App\Jobs\SyncWebPostoDatabase;
use App\Jobs\SyncWebPostoNewRecords;
use App\Jobs\SyncWebPostoReconciliation;
use App\Models\IntegrationService;
use Illuminate\Support\Facades\DB;

class IntegrationServiceDispatcher
{
    public function dispatchDue(): int
    {
        $ids = IntegrationService::query()->where('active', true)
            ->where(fn ($query) => $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', now()))
            ->orderByRaw("CASE WHEN resource IN ('webposto-chimba-reconciliation', 'webposto-b2-reconciliation') THEN 0 ELSE 1 END")
            ->pluck('id');
        $dispatched = 0;
        foreach ($ids as $id) {
            $dispatched += $this->dispatch((int) $id) ? 1 : 0;
        }

        return $dispatched;
    }

    public function dispatch(int $serviceId): bool
    {
        return DB::transaction(function () use ($serviceId): bool {
            $service = IntegrationService::query()->lockForUpdate()->findOrFail($serviceId);
            $coordinator = app(WebPostoReconciliationCoordinator::class);
            if ($service->resource === 'webposto-new-records' && $coordinator->newRecordsAreSuspended()) {
                return false;
            }
            if ($coordinator->isReconciliationResource($service->resource)) {
                $coordinator->pauseNewRecordsForReconciliation($service->resource);
                if ($coordinator->hasRunningNewRecords()) {
                    // Mantem o acionamento vencido para o scheduler tentar novamente
                    // assim que a execucao atual de Novos Dados terminar.
                    $service->update(['next_run_at' => now()]);

                    return false;
                }
            }

            $service->update(['next_run_at' => app(IntegrationServiceSchedule::class)->nextRunAt($service)]);
            match ($service->resource) {
                'webposto-database-changes' => SyncWebPostoDatabase::dispatch($service->id),
                'webposto-new-records' => SyncWebPostoNewRecords::dispatch($service->id),
                'webposto-chimba-reconciliation' => SyncWebPostoReconciliation::dispatch($service->id)->onQueue(SyncWebPostoReconciliation::CHIMBA_QUEUE),
                'webposto-b2-reconciliation' => SyncWebPostoReconciliation::dispatch($service->id),
                'clickhouse-incremental-sync' => SyncClickHouseIncremental::dispatch($service->id),
                'alterdata-sync' => SyncAlterdata::dispatch($service->id),
                default => throw new \InvalidArgumentException("Recurso {$service->resource} não possui job."),
            };

            return true;
        });
    }
}
