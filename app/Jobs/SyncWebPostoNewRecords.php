<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Models\WebPostoCredential;
use App\Services\WebPosto\WebPostoNewRecordsSyncService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncWebPostoNewRecords implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 7200;
    public int $uniqueFor = 7200;

    public function __construct(public readonly int $serviceId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "webposto-new-records:{$this->serviceId}";
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(WebPostoNewRecordsSyncService $synchronizer): void
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
        $companies = DB::connection('webposto')->table('webposto_credentials as credentials')
            ->leftJoin('empresas', 'empresas.empresaCodigo', '=', 'credentials.empresa_codigo')
            ->where('credentials.ativo', true)
            ->where('credentials.implantacao_status', WebPostoCredential::STATUS_SINCRONIZADO)
            ->orderBy('credentials.empresa_codigo')
            ->get([
                'credentials.empresa_codigo',
                'empresas.fantasia',
                'empresas.razao',
            ]);

        if ($companies->isEmpty()) {
            $message = 'Nenhum posto sincronizado e ativo foi encontrado.';
            $run->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            $service->update(['last_error' => $message]);
            return;
        }

        $companyRuns = $companies->values()->map(function (object $company, int $position) use ($run) {
            return IntegrationServiceCompanyRun::query()->create([
                'integration_service_run_id' => $run->id,
                'empresa_codigo' => (int) $company->empresa_codigo,
                'empresa_nome' => $company->fantasia ?: ($company->razao ?: 'Empresa '.$company->empresa_codigo),
                'position' => $position + 1,
                'status' => 'pending',
                'resource_results' => [],
            ]);
        });
        $failures = [];

        foreach ($companyRuns as $companyRun) {
            $companyTotals = ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
            $resourceResults = [];
            $companyRun->update(['status' => 'running', 'started_at' => now(), 'error' => null]);

            try {
                $results = $synchronizer->synchronize(
                    $companyRun->empresa_codigo,
                    $service->settings['resources'] ?? [],
                    $run->id,
                    function (string $state, string $resource, ?array $stored) use (
                        $companyRun,
                        &$companyTotals,
                        &$resourceResults,
                    ): void {
                        if ($state === 'completed' && $stored !== null) {
                            $resourceResults[$resource] = $stored;
                            foreach ($companyTotals as $field => $value) {
                                $companyTotals[$field] += (int) ($stored[$field] ?? 0);
                            }
                        }
                        $companyRun->update([
                            'current_resource' => $state === 'running' ? $resource : null,
                            ...$companyTotals,
                            'resource_results' => $resourceResults,
                        ]);
                    },
                    forceFullReconcile: $service->resource === 'webposto-full-reconciliation',
                );

                $companyRun->update([
                    ...$companyTotals,
                    'status' => 'success',
                    'current_resource' => null,
                    'finished_at' => now(),
                ]);
            } catch (Throwable $exception) {
                $message = mb_substr($exception->getMessage(), 0, 2000);
                $failures[] = $companyRun->empresa_nome.': '.$message;
                $companyRun->update([
                    ...$companyTotals,
                    'status' => 'failed',
                    'current_resource' => null,
                    'error' => $message,
                    'finished_at' => now(),
                ]);
            }

        }

        $status = $failures === []
            ? 'success'
            : (count($failures) === $companyRuns->count() ? 'failed' : 'partial');
        $error = $failures === [] ? null : implode("\n", $failures);
        $run->update(['status' => $status, 'error' => $error, 'finished_at' => now()]);
        $service->update([
            'last_completed_at' => now(),
            'next_run_at' => now()->addMinutes($service->frequency_minutes),
            'last_error' => $error,
        ]);
    }
}
