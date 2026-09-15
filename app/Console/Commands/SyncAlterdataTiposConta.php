<?php

namespace App\Console\Commands;

use App\Services\Alterdata\AlterdataClient;
use App\Services\Alterdata\CatalogoDescricaoImporter;
use Illuminate\Console\Command;

class SyncAlterdataTiposConta extends Command
{
    protected $signature = 'alterdata:sync-tipos-conta';

    protected $description = 'Sincroniza os tipos de conta bancária da API Alterdata';

    public function handle(AlterdataClient $client, CatalogoDescricaoImporter $importer): int
    {
        $this->info('Consultando tipos de conta no Alterdata...');
        $resultado = $importer->import('tipos_conta', $client->todos('tipo-conta', ['sort' => 'id'], 1000));

        $this->table(['Recebidos', 'Válidos', 'Inseridos', 'Atualizados', 'Inalterados', 'Ignorados'], [[
            $resultado['recebidos'], $resultado['validos'], $resultado['inseridos'],
            $resultado['atualizados'], $resultado['inalterados'], $resultado['ignorados'],
        ]]);

        return self::SUCCESS;
    }
}
