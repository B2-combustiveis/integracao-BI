<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Models\WebPostoCredential;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class SyncWebPostoNewRecords implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

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

    public function handle(): void
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
            ->whereIn('credentials.base', [
                WebPostoCredential::BASE_B1,
                WebPostoCredential::BASE_B2,
            ])
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

        foreach ($companyRuns as $companyRun) {
            SyncWebPostoCompanyNewRecords::dispatch($run->id, $companyRun->id, $service->id);
        }
    }
}
