<?php

namespace App\Console\Commands;

use App\Services\Alterdata\AlterdataClient;
use App\Services\Alterdata\PaisImporter;
use Illuminate\Console\Command;

class SyncAlterdataPaises extends Command
{
    protected $signature = 'alterdata:sync-paises';

    protected $description = 'Sincroniza o catálogo de países da API Alterdata';

    public function handle(AlterdataClient $client, PaisImporter $importer): int
    {
        $this->info('Consultando países no Alterdata...');
        // Este endpoint ignora page[offset] quando o limite é 100. Como o catálogo
        // é pequeno, requisitamos tudo de uma vez para não repetir a primeira página.
        $resultado = $importer->import($client->todos('paises', ['sort' => 'id'], 1000));

        $this->table(['Recebidos', 'Válidos', 'Inseridos', 'Atualizados', 'Inalterados', 'Ignorados'], [[
            $resultado['recebidos'], $resultado['validos'], $resultado['inseridos'],
            $resultado['atualizados'], $resultado['inalterados'], $resultado['ignorados'],
        ]]);

        return self::SUCCESS;
    }
}
