<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Services\ClickHouse\ClickHouseTableCatalog;
use App\Services\Integration\WebPostoReconciliationCoordinator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Job pai: um IntegrationServiceCompanyRun por TABELA do catalogo (nao por
 * empresa - o sync do ClickHouse le o webposto inteiro de uma vez por tabela,
 * nao faz uma chamada de API por posto como o sync do WebPosto).
 */
class SyncClickHouseIncremental implements ShouldBeUnique, ShouldQueue
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
        return "clickhouse-incremental-sync:{$this->serviceId}";
    }

    public function handle(ClickHouseTableCatalog $catalog): void
    {
        if (app(WebPostoReconciliationCoordinator::class)->heavyReadIsRunning()) {
            return;
        }

        // O job pai termina assim que distribui as tabelas, enquanto os jobs filhos
        // continuam trabalhando. A trava ShouldBeUnique do pai, portanto, nao evita
        // que o proximo tick crie outra execucao sobre a anterior. Serializamos pela
        // linha do servico e so criamos o relatorio quando nao ha rodada ativa.
        $run = DB::transaction(function (): ?IntegrationServiceRun {
            $service = IntegrationService::query()->lockForUpdate()->findOrFail($this->serviceId);
            $alreadyRunning = IntegrationServiceRun::query()
                ->where('integration_service_id', $service->id)
                ->whereIn('status', ['running', 'finalizing'])
                ->exists();

            if ($alreadyRunning) {
                // Mantem uma nova tentativa proxima. Quando a rodada ativa concluir,
                // seu finalizador volta a aplicar a frequencia normal de 15 minutos.
                $service->update(['next_run_at' => now()->addMinute()]);

                return null;
            }

            $run = IntegrationServiceRun::query()->create([
                'integration_service_id' => $service->id,
                'status' => 'running',
                'period_start' => today(),
                'period_end' => today(),
                'started_at' => now(),
            ]);
            $service->update(['last_started_at' => now(), 'last_error' => null]);

            return $run;
        });

        if ($run === null) {
            return;
        }

        $service = IntegrationService::query()->findOrFail($this->serviceId);

        $tables = $catalog->all();
        $position = 0;
        foreach ($tables as $table => $definition) {
            $companyRun = IntegrationServiceCompanyRun::query()->create([
                'integration_service_run_id' => $run->id,
                'empresa_codigo' => 0,
                'empresa_nome' => 'ClickHouse · '.$table,
                'block_key' => 'shared-clickhouse-'.$table,
                'position' => ++$position,
                'status' => 'pending',
                'resource_results' => [],
            ]);

            SyncClickHouseTable::dispatch($run->id, $companyRun->id, $service->id, $table)
                ->onQueue($definition['queue']);
        }
    }
}
