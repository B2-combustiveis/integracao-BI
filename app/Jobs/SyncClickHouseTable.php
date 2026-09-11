<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Services\ClickHouse\ClickHouseTableSynchronizer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncClickHouseTable implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $runId,
        public readonly int $companyRunId,
        public readonly int $serviceId,
        public readonly string $table,
        string $queue = 'default',
    ) {
        $this->onQueue($queue);
    }

    public function uniqueId(): string
    {
        return "clickhouse-sync-table:{$this->table}";
    }

    public function handle(ClickHouseTableSynchronizer $synchronizer): void
    {
        $companyRun = IntegrationServiceCompanyRun::query()->findOrFail($this->companyRunId);
        $parentIsActive = IntegrationServiceRun::query()
            ->whereKey($this->runId)
            ->where('status', 'running')
            ->exists();
        if ($companyRun->status !== 'pending' || ! $parentIsActive) {
            return;
        }

        $companyRun->update(['status' => 'running', 'started_at' => now(), 'current_resource' => $this->table, 'error' => null]);

        try {
            $result = $synchronizer->syncIncremental($this->table);
            $companyRun->update([
                ...$result,
                'status' => 'success',
                'current_resource' => null,
                'heartbeat_at' => now(),
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $message = $this->safeError($exception);
            $companyRun->update([
                'status' => 'failed',
                'current_resource' => null,
                'error' => $message,
                'finished_at' => now(),
            ]);
        }

        $this->finalizeRunIfComplete();
    }

    private function safeError(Throwable $exception): string
    {
        return mb_substr($exception->getMessage(), 0, 2000);
    }

    private function finalizeRunIfComplete(): void
    {
        $stillWorking = IntegrationServiceCompanyRun::query()
            ->where('integration_service_run_id', $this->runId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
        if ($stillWorking) {
            return;
        }

        $claimed = IntegrationServiceRun::query()
            ->whereKey($this->runId)
            ->where('status', 'running')
            ->update(['status' => 'finalizing']);
        if ($claimed === 0) {
            return;
        }

        $companyRuns = IntegrationServiceCompanyRun::query()
            ->where('integration_service_run_id', $this->runId)
            ->get();
        $failures = $companyRuns
            ->filter(fn (IntegrationServiceCompanyRun $companyRun): bool => $companyRun->status === 'failed')
            ->map(fn (IntegrationServiceCompanyRun $companyRun): string => $companyRun->empresa_nome.': '.($companyRun->error ?? 'Falha sem mensagem.'));
        $status = $failures->isEmpty()
            ? 'success'
            : ($failures->count() === $companyRuns->count() ? 'failed' : 'partial');
        $error = $failures->isEmpty() ? null : $failures->implode("\n");

        IntegrationServiceRun::query()->whereKey($this->runId)->update([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
            'received' => $companyRuns->sum('received'),
            'inserted' => $companyRuns->sum('inserted'),
            'updated' => $companyRuns->sum('updated'),
            'unchanged' => $companyRuns->sum('unchanged'),
            'skipped' => $companyRuns->sum('skipped'),
        ]);

        $service = IntegrationService::query()->find($this->serviceId);
        if ($service !== null) {
            $service->update([
                'last_completed_at' => now(),
                'next_run_at' => now()->addMinutes($service->frequency_minutes),
                'last_error' => $error,
            ]);
        }
    }
}
