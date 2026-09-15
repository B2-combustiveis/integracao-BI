<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Services\Alterdata\AlterdataSynchronizationService;
use App\Services\Integration\IntegrationServiceSchedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncAlterdata implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 900;
    public int $uniqueFor = 1200;

    public function __construct(public readonly int $serviceId)
    {
        $this->onQueue('alterdata');
    }

    public function uniqueId(): string
    {
        return "alterdata-sync:{$this->serviceId}";
    }

    public function handle(AlterdataSynchronizationService $synchronizer): void
    {
        [$run, $block] = DB::transaction(function (): array {
            $service = IntegrationService::query()->lockForUpdate()->findOrFail($this->serviceId);
            $activeRun = IntegrationServiceRun::query()
                ->where('integration_service_id', $service->id)
                ->whereIn('status', ['running', 'finalizing'])
                ->exists();
            if ($activeRun) {
                return [null, null];
            }

            $run = IntegrationServiceRun::query()->create([
                'integration_service_id' => $service->id,
                'status' => 'running',
                'period_start' => today(),
                'period_end' => today(),
                'started_at' => now(),
            ]);
            $block = IntegrationServiceCompanyRun::query()->create([
                'integration_service_run_id' => $run->id,
                'empresa_codigo' => 0,
                'empresa_nome' => 'Alterdata',
                'block_key' => 'shared-alterdata',
                'position' => 1,
                'status' => 'running',
                'current_resource' => 'iniciando',
                'heartbeat_at' => now(),
                'started_at' => now(),
                'resource_results' => [],
            ]);
            $service->update(['last_started_at' => now(), 'last_error' => null]);

            return [$run, $block];
        });

        if ($run === null || $block === null) {
            return;
        }

        try {
            $result = $synchronizer->sync(fn (string $resource) => $block->update([
                'current_resource' => $resource,
                'heartbeat_at' => now(),
            ]));
            $block->update([
                ...$result['totals'],
                'status' => 'success',
                'current_resource' => null,
                'heartbeat_at' => now(),
                'resource_results' => $result['results'],
                'finished_at' => now(),
            ]);
            $this->finish($run, $result['totals'], null);
        } catch (Throwable $exception) {
            $error = mb_substr($exception->getMessage(), 0, 2000);
            $block->update([
                'status' => 'failed',
                'current_resource' => null,
                'heartbeat_at' => now(),
                'error' => $error,
                'finished_at' => now(),
            ]);
            $this->finish($run, [], $error);
        }
    }

    private function finish(IntegrationServiceRun $run, array $totals, ?string $error): void
    {
        $run->update([
            'status' => $error === null ? 'success' : 'failed',
            'received' => (int) ($totals['received'] ?? 0),
            'inserted' => (int) ($totals['inserted'] ?? 0),
            'updated' => (int) ($totals['updated'] ?? 0),
            'unchanged' => (int) ($totals['unchanged'] ?? 0),
            'skipped' => (int) ($totals['skipped'] ?? 0),
            'error' => $error,
            'finished_at' => now(),
        ]);
        $service = IntegrationService::query()->find($this->serviceId);
        $service?->update([
            'last_completed_at' => now(),
            'next_run_at' => $service->active ? app(IntegrationServiceSchedule::class)->nextRunAt($service) : null,
            'last_error' => $error,
        ]);
    }
}
