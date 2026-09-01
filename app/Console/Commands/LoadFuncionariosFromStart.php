<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\FuncionarioImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadFuncionariosFromStart extends Command
{
    protected $signature = 'webposto:load-funcionarios {empresa=4604} {--resume} {--pages=40}';
    protected $description = 'Padrão 2000: zera e recarrega funcionários desde o cursor 1';

    public function handle(WebPostoCursorSynchronizer $sync, FuncionarioImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $key = '/INTEGRACAO/FUNCIONARIO:manual-initial';
        $resume = (bool) $this->option('resume');
        if (! $resume) {
            DB::connection('webposto')->table('funcionarios')->where('empresaCodigo', $empresa)->delete();
            WebPostoSyncControl::where('empresa_codigo', $empresa)->where('endpoint', $key)->delete();
        }
        $totals = $sync->synchronize(
            endpoint: '/INTEGRACAO/FUNCIONARIO',
            empresaCodigo: $empresa,
            persist: fn ($payload, $parameters) => $importer->import($payload, $empresa, $parameters),
            query: ['dataInicial' => '2000-01-01', 'dataFinal' => now()->toDateString(), 'limite' => 1000, 'empresaCodigo' => $empresa],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => ! $resume],
            initialQuery: null,
            maxPages: max(1, (int) $this->option('pages')),
            controlKey: $key,
        );
        $this->info(json_encode($totals));
        return self::SUCCESS;
    }
}
