<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Services\WebPosto\WebPostoNewRecordsResourceCatalog;
use App\Services\WebPosto\WebPostoReconciliationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncWebPostoCompanyReconciliation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    private const MIN_TIMEOUT_SECONDS = 2400;

    private const MAX_TIMEOUT_SECONDS = 5400;

    private const ROWS_PER_MINUTE_ESTIMATE = 14000;

    private const TIMEOUT_SAFETY_FACTOR = 2;

    public int $tries = 1;

    public int $uniqueFor = 21600;

    public function __construct(
        public readonly int $runId,
        public readonly int $companyRunId,
        public readonly int $serviceId,
        public readonly array $resources = [],
        string $queue = 'default',
    ) {
        $this->onQueue($queue);
    }

    public function uniqueId(): string
    {
        return "webposto-reconciliation-company:{$this->companyRunId}";
    }

    /**
     * Timeout proporcional ao volume ja existente localmente para os recursos deste
     * job. Postos pequenos usam o piso de 40min; postos grandes (ex: Chimba, ~10x
     * maior que a media) sao limitados ao teto de 90min em vez de rodar o tempo
     * real que precisariam, pra nao segurar o worker indefinidamente (ja aconteceu
     * de rodar 14h+ e travar toda a fila). O que nao couber nesse teto fica pro
     * webposto_sync_pending_records resolver nas proximas execucoes. O watchdog de
     * heartbeat (OrphanedIntegrationRunCleaner::cleanStaleCompanyRuns) ja cobre a
     * detecao de travamento de verdade em 10min, entao esse timeout aqui e so uma
     * rede de seguranca final para reciclar o worker.
     */
    public function timeout(): int
    {
        $companyRun = IntegrationServiceCompanyRun::query()->find($this->companyRunId);
        if ($companyRun === null || $this->resources === []) {
            return self::MIN_TIMEOUT_SECONDS;
        }

        $catalog = app(WebPostoNewRecordsResourceCatalog::class);
        $volume = 0;
        foreach (array_unique($this->resources) as $resource) {
            try {
                $definition = $catalog->get($resource);
            } catch (\InvalidArgumentException) {
                continue;
            }
            $query = DB::connection('webposto')->table($definition['table']);
            if (($definition['company_scoped'] ?? true) === true) {
                $query->where($definition['company_field'] ?? 'empresaCodigo', $companyRun->empresa_codigo);
            }
            $volume += $query->count();
        }

        $estimatedSeconds = (int) ceil($volume / self::ROWS_PER_MINUTE_ESTIMATE * 60 * self::TIMEOUT_SAFETY_FACTOR);

        return min(self::MAX_TIMEOUT_SECONDS, max(self::MIN_TIMEOUT_SECONDS, $estimatedSeconds));
    }

    public function handle(WebPostoReconciliationService $synchronizer): void
    {
        $service = IntegrationService::query()->findOrFail($this->serviceId);
        $companyRun = IntegrationServiceCompanyRun::query()->findOrFail($this->companyRunId);
        $totals = ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        $resourceResults = [];
        $companyRun->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

        try {
            $results = $synchronizer->synchronize(
                (int) $companyRun->empresa_codigo,
                $this->resources !== [] ? $this->resources : ($service->settings['resources'] ?? []),
                $this->runId,
                function (string $state, string $resource, ?array $result) use ($companyRun, &$totals, &$resourceResults): void {
                    if (in_array($state, ['completed', 'failed'], true) && $result !== null) {
                        $resourceResults[$resource] = $result;
                        foreach ($totals as $field => $value) {
                            $totals[$field] += (int) ($result[$field] ?? 0);
                        }
                    }
                    $progress = $state === 'progress' && $result !== null
                        ? ['current_page' => (int) ($result['page'] ?? 0), 'current_cursor' => isset($result['cursor']) ? (int) $result['cursor'] : null, 'heartbeat_at' => $result['heartbeat_at'] ?? now()]
                        : ['current_page' => null, 'current_cursor' => null, 'heartbeat_at' => $state === 'running' ? now() : null];
                    $companyRun->update([
                        'current_resource' => in_array($state, ['running', 'progress'], true) ? $resource : null,
                        ...$progress,
                        ...$totals,
                        'resource_results' => $resourceResults,
                    ]);
                },
                $service->resource,
            );
            $totals = collect($results)->reduce(function (array $carry, array $result): array {
                foreach (array_keys($carry) as $field) {
                    $carry[$field] += (int) ($result[$field] ?? 0);
                }

                return $carry;
            }, ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0]);
            $resourceResults = $results;
            $failures = collect($results)->filter(fn (array $result): bool => ($result['status'] ?? null) === 'failed');
            $error = $failures->isEmpty() ? null : $failures
                ->map(fn (array $result, string $resource): string => $resource.': '.($result['error'] ?? 'Falha sem mensagem.'))
                ->implode("\n");
            if ($companyRun->refresh()->finished_at === null) {
                $companyRun->update([...$totals, 'status' => $error === null ? 'success' : 'partial', 'current_resource' => null, 'error' => $error, 'finished_at' => now()]);
            }
        } catch (Throwable $exception) {
            $error = $this->safeError($exception);
            if ($companyRun->refresh()->finished_at === null) {
                $companyRun->update([...$totals, 'status' => 'failed', 'current_resource' => null, 'error' => $error, 'finished_at' => now()]);
            }
        }

        $this->finalizeRunIfComplete();
    }

    public function failed(Throwable $exception): void
    {
        $companyRun = IntegrationServiceCompanyRun::query()->find($this->companyRunId);
        if ($companyRun !== null && ! in_array($companyRun->status, ['success', 'partial', 'failed'], true)) {
            $companyRun->update([
                'status' => 'failed',
                'current_resource' => null,
                'error' => $this->safeError($exception),
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

        $blockRuns = IntegrationServiceCompanyRun::query()
            ->where('integration_service_run_id', $this->runId)
            ->get();
        $failures = $blockRuns
            ->filter(fn (IntegrationServiceCompanyRun $blockRun): bool => in_array($blockRun->status, ['partial', 'failed'], true))
            ->map(fn (IntegrationServiceCompanyRun $blockRun): string => $blockRun->empresa_nome.': '.($blockRun->error ?? 'Falha sem mensagem.'));
        $status = $failures->isEmpty()
            ? 'success'
            : ($failures->count() === $blockRuns->count() ? 'failed' : 'partial');
        $error = $failures->isEmpty() ? null : $failures->implode("\n");

        IntegrationServiceRun::query()->whereKey($this->runId)->update([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
        ]);
        $service = IntegrationService::query()->find($this->serviceId);
        $service?->update([
            'last_completed_at' => now(),
            'next_run_at' => $service->active ? now()->addMinutes($service->frequency_minutes) : null,
            'last_error' => $error,
        ]);
    }
}
