<?php

namespace App\Console\Commands;

use App\Jobs\SyncWebPostoCompanyNewRecords;
use App\Jobs\SyncWebPostoNewRecords;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Services\WebPosto\WebPostoNewRecordsSyncService;
use Illuminate\Console\Command;

class SyncWebPostoNewRecordsNow extends Command
{
    protected $signature = 'webposto:sync-new-records
        {service=2 : ID do servico de integracao}
        {--queue : Enfileira a sincronizacao (um job por posto) no worker permanente}';

    protected $description = 'Sincroniza imediatamente os recursos WebPosto configurados usando cursores persistidos';

    public function handle(WebPostoNewRecordsSyncService $synchronizer): int
    {
        $serviceId = (int) $this->argument('service');

        if ($this->option('queue')) {
            SyncWebPostoNewRecords::dispatch($serviceId);
            $this->info('Sincronizacao enfileirada (um job por posto).');

            return self::SUCCESS;
        }

        // Sem --queue, o job pai só cria o run e enfileira um job por posto;
        // aqui rodamos esses jobs na hora, um a um, para manter o comando síncrono.
        $job = new SyncWebPostoNewRecords($serviceId);
        app()->call([$job, 'handle']);

        $run = IntegrationServiceRun::query()
            ->where('integration_service_id', $serviceId)
            ->latest('id')
            ->first();

        if ($run !== null) {
            IntegrationServiceCompanyRun::query()
                ->where('integration_service_run_id', $run->id)
                ->orderBy('position')
                ->each(fn (IntegrationServiceCompanyRun $companyRun) => (new SyncWebPostoCompanyNewRecords(
                    $run->id,
                    $companyRun->id,
                    $serviceId,
                ))->handle($synchronizer));
        }

        $this->info('Sincronizacao concluida.');

        return self::SUCCESS;
    }
}
