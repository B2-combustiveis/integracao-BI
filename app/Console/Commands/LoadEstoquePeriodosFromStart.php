<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\EstoquePeriodoImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadEstoquePeriodosFromStart extends Command
{
    protected $signature = 'webposto:load-estoque-periodos {empresa=4604} {--resume} {--pages=40}';
    protected $description = 'Padrão 2000: zera e recarrega períodos de estoque desde o cursor 1';

    public function handle(WebPostoCursorSynchronizer $sync, EstoquePeriodoImporter $importer): int
    {
        ini_set('memory_limit', '512M');
        $empresa = (int) $this->argument('empresa');
        $key = '/INTEGRACAO/ESTOQUE_PERIODO:manual-initial';
        $resume = (bool) $this->option('resume');
        if (! $resume) {
            DB::connection('webposto')->table('estoque_periodos')->where('codigoUnidadeNegocio', $empresa)->delete();
            WebPostoSyncControl::where('empresa_codigo', $empresa)->where('endpoint', $key)->delete();
        }
        $totals = $sync->synchronize(
            endpoint: '/INTEGRACAO/ESTOQUE_PERIODO',
            empresaCodigo: $empresa,
            persist: fn ($payload, $parameters) => $importer->import($payload, $empresa, $parameters),
            query: ['dataInicial' => '2000-01-01', 'dataFinal' => now()->toDateString(), 'empresaCodigo' => $empresa, 'limite' => 1000],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => ! $resume],
            initialQuery: null,
            maxPages: max(1, (int) $this->option('pages')),
            controlKey: $key,
        );
        $this->info(json_encode($totals));
        return self::SUCCESS;
    }
}
