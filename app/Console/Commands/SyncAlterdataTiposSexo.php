<?php

namespace App\Console\Commands;

use App\Services\Alterdata\AlterdataClient;
use App\Services\Alterdata\TipoSexoImporter;
use Illuminate\Console\Command;

class SyncAlterdataTiposSexo extends Command
{
    protected $signature = 'alterdata:sync-tipos-sexo';

    protected $description = 'Sincroniza o catálogo de tipos de sexo da API Alterdata';

    public function handle(AlterdataClient $client, TipoSexoImporter $importer): int
    {
        $this->info('Consultando tipos de sexo no Alterdata...');
        $resultado = $importer->import($client->todos('tipos-sexo', ['sort' => 'id']));

        $this->table(['Recebidos', 'Válidos', 'Inseridos', 'Atualizados', 'Inalterados', 'Ignorados'], [[
            $resultado['recebidos'], $resultado['validos'], $resultado['inseridos'],
            $resultado['atualizados'], $resultado['inalterados'], $resultado['ignorados'],
        ]]);

        return self::SUCCESS;
    }
}
