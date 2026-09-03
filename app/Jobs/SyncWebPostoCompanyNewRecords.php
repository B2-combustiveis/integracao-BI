<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Services\WebPosto\WebPostoNewRecordsSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class SyncWebPostoCompanyNewRecords implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 1800;

    public function __construct(
        public readonly int $runId,
        public readonly int $companyRunId,
        public readonly int $serviceId,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "webposto-new-records-company:{$this->companyRunId}";
    }

    public function handle(WebPostoNewRecordsSyncService $synchronizer): void
    {
        $service = IntegrationService::query()->findOrFail($this->serviceId);
        $companyRun = IntegrationServiceCompanyRun::query()->findOrFail($this->companyRunId);

        $companyTotals = ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $resourceResults = [];
        $companyRun->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

        try {
            $results = $synchronizer->synchronize(
                $companyRun->empresa_codigo,
                $service->settings['resources'] ?? [],
                $this->runId,
                function (string $state, string $resource, ?array $stored) use (
                    $companyRun,
                    &$companyTotals,
                    &$resourceResults,
                ): void {
                    if (in_array($state, ['completed', 'failed'], true) && $stored !== null) {
                        $resourceResults[$resource] = $stored;
                        foreach ($companyTotals as $field => $value) {
                            $companyTotals[$field] += (int) ($stored[$field] ?? 0);
                        }
                    }
                    $pageProgress = $state === 'progress' && $stored !== null
                        ? [
                            'current_page' => (int) ($stored['page'] ?? 0),
                            'current_cursor' => isset($stored['cursor']) ? (int) $stored['cursor'] : null,
                            'heartbeat_at' => $stored['heartbeat_at'] ?? now(),
                        ]
                        : ($state === 'failed' ? [
                            'heartbeat_at' => now(),
                        ] : [
                            'current_page' => null,
                            'current_cursor' => null,
                            'heartbeat_at' => $state === 'running' ? now() : null,
                        ]);
                    $companyRun->update([
                        'current_resource' => in_array($state, ['running', 'progress'], true) ? $resource : null,
                        ...$pageProgress,
                        ...$companyTotals,
                        'resource_results' => $resourceResults,
                    ]);
                },
            );

            $resourceFailures = collect($results)
                ->filter(fn (array $result): bool => ($result['status'] ?? null) === 'failed');
            $companyError = $resourceFailures->isEmpty()
                ? null
                : $resourceFailures->map(fn (array $result, string $resource): string => $resource.': '.($result['error'] ?? 'Falha sem mensagem.')
                )->implode("\n");

            $companyRun->update([
                ...$companyTotals,
                'status' => $companyError === null ? 'success' : 'partial',
                'current_resource' => null,
                'error' => $companyError,
                'finished_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $message = $this->safeError($exception);
            $companyRun->update([
                ...$companyTotals,
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
        return mb_substr((string) preg_replace('/([?&]chave=)[^&\s]+/i', '$1SEU_TOKEN_AQUI', $exception->getMessage()), 0, 2000);
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
            ->filter(fn (IntegrationServiceCompanyRun $companyRun): bool => in_array($companyRun->status, ['partial', 'failed'], true))
            ->map(fn (IntegrationServiceCompanyRun $companyRun): string => $companyRun->empresa_nome.': '.($companyRun->error ?? 'Falha sem mensagem.'));
        $partialCompanies = $companyRuns->where('status', 'partial')->count();
        $status = $failures->isEmpty()
            ? 'success'
            : ($partialCompanies > 0 ? 'partial' : ($failures->count() === $companyRuns->count() ? 'failed' : 'partial'));
        $error = $failures->isEmpty() ? null : $failures->implode("\n");

        IntegrationServiceRun::query()->whereKey($this->runId)->update([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
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
