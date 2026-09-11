<?php

namespace App\Console\Commands;

use App\Jobs\SyncClickHouseIncremental;
use App\Jobs\SyncClickHouseTable;
use App\Models\IntegrationService;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Services\ClickHouse\ClickHouseTableSynchronizer;
use Illuminate\Console\Command;

class SyncClickHouseNow extends Command
{
    protected $signature = 'webposto:sync-clickhouse-now
        {--queue : Enfileira a sincronizacao (um job por tabela) no worker permanente}';

    protected $description = 'Sincroniza imediatamente as tabelas do ClickHouse usando os watermarks persistidos';

    public function handle(ClickHouseTableSynchronizer $synchronizer): int
    {
        $serviceId = (int) IntegrationService::query()->where('resource', 'clickhouse-incremental-sync')->value('id');
        if ($serviceId === 0) {
            $this->error('Servico clickhouse-incremental-sync nao encontrado. Rode as migrations.');

            return self::FAILURE;
        }

        if ($this->option('queue')) {
            SyncClickHouseIncremental::dispatch($serviceId);
            $this->info('Sincronizacao enfileirada (um job por tabela).');

            return self::SUCCESS;
        }

        $job = new SyncClickHouseIncremental($serviceId);
        app()->call([$job, 'handle']);

        $run = IntegrationServiceRun::query()
            ->where('integration_service_id', $serviceId)
            ->latest('id')
            ->first();

        if ($run !== null) {
            IntegrationServiceCompanyRun::query()
                ->where('integration_service_run_id', $run->id)
                ->orderBy('position')
                ->each(function (IntegrationServiceCompanyRun $companyRun) use ($run, $serviceId, $synchronizer): void {
                    $table = str_replace('shared-clickhouse-', '', (string) $companyRun->block_key);
                    (new SyncClickHouseTable($run->id, $companyRun->id, $serviceId, $table))->handle($synchronizer);
                });
        }

        $this->info('Sincronizacao concluida.');

        return self::SUCCESS;
    }
}
