<?php

namespace App\Console\Commands;

use App\Models\IntegrationServiceRun;
use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use App\Services\WebPosto\WebPostoNewRecordsResourceCatalog;
use App\Services\WebPosto\WebPostoPendingRecordService;
use Illuminate\Console\Command;

class RecoverWebPostoPendingRecords extends Command
{
    protected $signature = 'webposto:recover-pending
        {empresa=4604}
        {resources?* : Recursos a reconciliar}';

    protected $description = 'Reconcilia registros ausentes sem limpar tabelas nem alterar registros existentes';

    public function handle(
        WebPostoCursorSynchronizer $synchronizer,
        WebPostoNewRecordsResourceCatalog $catalog,
        WebPostoPendingRecordService $pendingRecords,
    ): int {
        $empresa = (int) $this->argument('empresa');
        $resources = $this->argument('resources') ?: ['venda_itens', 'abastecimentos', 'cartoes'];
        $runId = IntegrationServiceRun::query()
            ->whereHas('service', fn ($query) => $query->where('resource', 'webposto-new-records')
                ->where('empresa_codigo', $empresa))
            ->latest('id')->value('id');

        if ($runId === null) {
            $this->error('Nenhuma execucao da empresa foi encontrada para vincular as pendencias.');
            return self::FAILURE;
        }

        foreach ($resources as $resource) {
            $definition = $catalog->get($resource);
            $baseline = WebPostoSyncControl::query()
                ->where('empresa_codigo', $empresa)
                ->where('endpoint', $definition['endpoint'].':manual-initial')
                ->value('last_code');

            if ($baseline === null) {
                $this->warn($resource.': cursor da carga inicial nao encontrado.');
                continue;
            }

            $query = [...$definition['query'], 'limite' => $definition['limit']];
            if (isset($definition['query_company_field'])) {
                $query[$definition['query_company_field']] = $empresa;
            }

            $totals = $synchronizer->synchronize(
                endpoint: $definition['endpoint'],
                empresaCodigo: $empresa,
                persist: function (mixed $payload, array $parameters) use (
                    $definition,
                    $empresa,
                    $runId,
                    $resource,
                    $pendingRecords,
                ): array {
                    $rows = collect(is_array($payload) && is_array($payload['resultados'] ?? null)
                        ? $payload['resultados'] : [])
                        ->filter(fn ($row) => is_array($row))
                        ->values()->all();
                    $pendingRecords->storeMissing($definition, $empresa, $runId, $resource, $rows, $parameters);

                    return ['received' => count($rows), 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
                },
                query: $query,
                cursor: [
                    'initial_value' => (int) $baseline,
                    'prefer_initial_value' => true,
                    ...($definition['cursor'] ?? []),
                ],
                controlKey: $definition['endpoint'].':pending-recovery',
            );

            $pending = \Illuminate\Support\Facades\DB::table('webposto_sync_pending_records')
                ->where('empresa_codigo', $empresa)->where('resource', $resource)
                ->where('status', 'pending')->count();
            $this->line($resource.": {$totals['received']} conferidos; {$pending} pendentes.");
        }

        return self::SUCCESS;
    }
}
