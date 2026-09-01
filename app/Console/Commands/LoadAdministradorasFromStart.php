<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\AdministradoraImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadAdministradorasFromStart extends Command
{
    protected $signature = 'webposto:load-administradoras {empresa=4604} {--resume} {--pages=40}';
    protected $description = 'Padrão 2000: zera e recarrega administradoras desde o cursor 1';

    public function handle(WebPostoCursorSynchronizer $sync, AdministradoraImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $key = '/INTEGRACAO/ADMINISTRADORA:manual-initial';
        $resume = (bool) $this->option('resume');

        if (! $resume) {
            DB::connection('webposto')->table('administradoras')->where('empresaCodigo', $empresa)->delete();
            WebPostoSyncControl::query()->where('empresa_codigo', $empresa)->where('endpoint', $key)->delete();
        }

        $totals = $sync->synchronize(
            endpoint: '/INTEGRACAO/ADMINISTRADORA',
            empresaCodigo: $empresa,
            persist: fn ($payload, $parameters) => $importer->import($payload, $empresa, $parameters),
            query: ['limite' => 1000, 'empresaCodigo' => $empresa],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => ! $resume],
            initialQuery: null,
            maxPages: max(1, (int) $this->option('pages')),
            controlKey: $key,
        );

        $this->info(json_encode($totals));

        return self::SUCCESS;
    }
}
