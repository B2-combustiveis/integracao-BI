<?php

namespace App\Console\Commands;

use App\Services\Alterdata\AlterdataClient;
use App\Services\Alterdata\CatalogoDescricaoImporter;
use Illuminate\Console\Command;

class SyncAlterdataTiposChavePix extends Command
{
    protected $signature = 'alterdata:sync-tipos-chave-pix';

    protected $description = 'Sincroniza os tipos de chave PIX da API Alterdata';

    public function handle(AlterdataClient $client, CatalogoDescricaoImporter $importer): int
    {
        $this->info('Consultando tipos de chave PIX no Alterdata...');
        $resultado = $importer->import('tipos_chave_pix', $client->todos('tipo-chave-pix', ['sort' => 'id'], 1000));

        $this->table(['Recebidos', 'Válidos', 'Inseridos', 'Atualizados', 'Inalterados', 'Ignorados'], [[
            $resultado['recebidos'], $resultado['validos'], $resultado['inseridos'],
            $resultado['atualizados'], $resultado['inalterados'], $resultado['ignorados'],
        ]]);

        return self::SUCCESS;
    }
}
