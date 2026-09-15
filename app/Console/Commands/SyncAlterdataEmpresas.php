<?php

namespace App\Console\Commands;

use App\Services\Alterdata\AlterdataClient;
use App\Services\Alterdata\EmpresaImporter;
use Illuminate\Console\Command;

class SyncAlterdataEmpresas extends Command
{
    protected $signature = 'alterdata:sync-empresas';

    protected $description = 'Sincroniza as empresas disponíveis na API Alterdata';

    public function handle(AlterdataClient $client, EmpresaImporter $importer): int
    {
        $this->info('Consultando empresas no Alterdata...');
        $resultado = $importer->import($client->todos('empresas'));

        $this->table(['Recebidas', 'Válidas', 'Inseridas', 'Atualizadas', 'Inalteradas', 'Ignoradas'], [[
            $resultado['recebidas'],
            $resultado['validas'],
            $resultado['inseridas'],
            $resultado['atualizadas'],
            $resultado['inalteradas'],
            $resultado['ignoradas'],
        ]]);

        return self::SUCCESS;
    }
}
