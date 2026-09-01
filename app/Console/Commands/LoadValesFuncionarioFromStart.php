<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\ValeFuncionarioImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadValesFuncionarioFromStart extends Command
{
    protected $signature = 'webposto:load-vales-funcionario {empresa=4604} {--resume} {--pages=200}';
    protected $description = 'Padrao 2000: recarrega os vales de funcionarios desde o primeiro cursor';

    public function handle(WebPostoCursorSynchronizer $sync, ValeFuncionarioImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $key = '/INTEGRACAO/VALE_FUNCIONARIO:manual-initial';
        $resume = (bool) $this->option('resume');
        if (! $resume) {
            DB::connection('webposto')->table('vales_funcionario')->where('empresaCodigo', $empresa)->delete();
            WebPostoSyncControl::query()->where('empresa_codigo', $empresa)->where('endpoint', $key)->delete();
        }
        $totals = $sync->synchronize(
            endpoint: '/INTEGRACAO/VALE_FUNCIONARIO',
            empresaCodigo: $empresa,
            persist: fn ($payload, $parameters) => $importer->import($payload, $empresa, $parameters),
            query: ['dataInicial' => '2000-01-01', 'dataFinal' => now()->toDateString(), 'limite' => 1000, 'empresaCodigo' => $empresa],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => ! $resume],
            maxPages: max(1, (int) $this->option('pages')),
            controlKey: $key,
        );
        $this->info(json_encode($totals));
        return self::SUCCESS;
    }
}
