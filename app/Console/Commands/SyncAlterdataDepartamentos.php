<?php

namespace App\Console\Commands;

use App\Services\Alterdata\AlterdataClient;
use App\Services\Alterdata\DepartamentoImporter;
use Illuminate\Console\Command;

class SyncAlterdataDepartamentos extends Command
{
    protected $signature = 'alterdata:sync-departamentos';

    protected $description = 'Sincroniza os departamentos e seus vínculos na API Alterdata';

    public function handle(AlterdataClient $client, DepartamentoImporter $importer): int
    {
        $this->info('Consultando departamentos no Alterdata...');
        $resultado = $importer->import($client->todos(
            'departamentos',
            ['sort' => 'id'],
            100,
            ['empresa'],
        ));

        $this->table(['Recebidos', 'Válidos', 'Inseridos', 'Atualizados', 'Inalterados', 'Ignorados'], [[
            $resultado['recebidos'], $resultado['validos'], $resultado['inseridos'],
            $resultado['atualizados'], $resultado['inalterados'], $resultado['ignorados'],
        ]]);

        return self::SUCCESS;
    }
}
