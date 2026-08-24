<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Services\WebPosto\WebPostoModifiedRecordsSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncWebPostoModifiedRecords implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 7200;
    public int $uniqueFor = 7200;

    public function __construct(public readonly int $serviceId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "webposto-modified-records:{$this->serviceId}";
    }

    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(WebPostoModifiedRecordsSyncService $synchronizer): void
    {
        $service = IntegrationService::query()->findOrFail($this->serviceId);
        $run = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'period_start' => today(),
            'period_end' => today(),
            'started_at' => now(),
        ]);
        $service->update(['last_started_at' => now(), 'last_error' => null]);
        $totals = ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        try {
            $results = $synchronizer->synchronizeAll(
                $service->empresa_codigo,
                $run->id,
            );
            foreach ($results as $stored) {
                foreach ($totals as $field => $value) {
                    $totals[$field] += (int) ($stored[$field] ?? 0);
                }
            }

            $run->update([...$totals, 'status' => 'success', 'finished_at' => now()]);
            $service->update([
                'last_completed_at' => now(),
                'next_run_at' => now()->addMinutes($service->frequency_minutes),
                'last_error' => null,
            ]);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            $run->update([...$totals, 'status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            $service->update([
                'last_error' => $message,
                'next_run_at' => now()->addMinutes($service->frequency_minutes),
            ]);

            throw $exception;
        }
    }
}
