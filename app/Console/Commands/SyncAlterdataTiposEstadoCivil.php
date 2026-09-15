<?php

namespace App\Console\Commands;

use App\Services\Alterdata\AlterdataClient;
use App\Services\Alterdata\TipoEstadoCivilImporter;
use Illuminate\Console\Command;

class SyncAlterdataTiposEstadoCivil extends Command
{
    protected $signature = 'alterdata:sync-tipos-estado-civil';

    protected $description = 'Sincroniza os tipos de estado civil da API Alterdata';

    public function handle(AlterdataClient $client, TipoEstadoCivilImporter $importer): int
    {
        $this->info('Consultando tipos de estado civil no Alterdata...');
        $resultado = $importer->import($client->todos('tipos-estado-civil', ['sort' => 'id']));

        $this->table(['Recebidos', 'Válidos', 'Inseridos', 'Atualizados', 'Inalterados', 'Ignorados'], [[
            $resultado['recebidos'], $resultado['validos'], $resultado['inseridos'],
            $resultado['atualizados'], $resultado['inalterados'], $resultado['ignorados'],
        ]]);

        return self::SUCCESS;
    }
}
