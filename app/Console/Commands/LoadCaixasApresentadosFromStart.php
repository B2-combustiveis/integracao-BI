<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\CaixaApresentadoImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadCaixasApresentadosFromStart extends Command
{
    protected $signature = 'webposto:load-caixas-apresentados {empresa=4604} {--resume} {--pages=200}';
    protected $description = 'Padrao 2000: reconcilia caixas apresentados desde o cursor 1';

    public function handle(WebPostoCursorSynchronizer $sync, CaixaApresentadoImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $key = '/INTEGRACAO/CAIXA_APRESENTADO:manual-initial';
        $resume = (bool) $this->option('resume');
        if (! $resume) {
            WebPostoSyncControl::query()->where('empresa_codigo', $empresa)->where('endpoint', $key)->delete();
        }
        $seen = [];
        $totals = $sync->synchronize(
            endpoint: '/INTEGRACAO/CAIXA_APRESENTADO',
            empresaCodigo: $empresa,
            persist: function ($payload, $parameters) use ($importer, $empresa, &$seen): array {
                $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
                foreach ($rows as $row) {
                    if (is_array($row) && is_numeric($row['caixaCodigo'] ?? null)) {
                        $seen[(int) $row['caixaCodigo']] = true;
                    }
                }
                return $importer->import($payload, $empresa, $parameters);
            },
            query: ['dataInicial' => '2000-01-01', 'dataFinal' => now()->toDateString(), 'limite' => 1000],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => ! $resume],
            maxPages: max(1, (int) $this->option('pages')),
            controlKey: $key,
        );
        $deleted = 0;
        if (! $resume && $seen !== []) {
            $deleted = DB::connection('webposto')->table('caixas_apresentados')
                ->where('empresaCodigo', $empresa)->whereNotIn('caixaCodigo', array_keys($seen))->delete();
        }
        $this->info(json_encode([...$totals, 'deleted' => $deleted]));

        return self::SUCCESS;
    }
}
