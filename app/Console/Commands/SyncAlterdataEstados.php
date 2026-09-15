<?php

namespace App\Console\Commands;

use App\Services\Alterdata\AlterdataClient;
use App\Services\Alterdata\EstadoImporter;
use Illuminate\Console\Command;

class SyncAlterdataEstados extends Command
{
    protected $signature = 'alterdata:sync-estados';

    protected $description = 'Sincroniza o catálogo de estados da API Alterdata';

    public function handle(AlterdataClient $client, EstadoImporter $importer): int
    {
        $this->info('Consultando estados no Alterdata...');
        $resultado = $importer->import($client->todos('estados', ['sort' => 'id'], 1000));

        $this->table(['Recebidos', 'Válidos', 'Inseridos', 'Atualizados', 'Inalterados', 'Ignorados'], [[
            $resultado['recebidos'], $resultado['validos'], $resultado['inseridos'],
            $resultado['atualizados'], $resultado['inalterados'], $resultado['ignorados'],
        ]]);

        return self::SUCCESS;
    }
}
