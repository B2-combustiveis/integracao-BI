<?php

namespace App\Console\Commands;

use App\Services\Alterdata\AlterdataClient;
use App\Services\Alterdata\TipoFormaPagamentoImporter;
use Illuminate\Console\Command;

class SyncAlterdataTiposFormaPagamento extends Command
{
    protected $signature = 'alterdata:sync-tipos-forma-pagamento';

    protected $description = 'Sincroniza os tipos de forma de pagamento da API Alterdata';

    public function handle(AlterdataClient $client, TipoFormaPagamentoImporter $importer): int
    {
        $this->info('Consultando formas de pagamento no Alterdata...');
        $resultado = $importer->import($client->todos('tipos-forma-de-pagamento', ['sort' => 'id']));

        $this->table(['Recebidos', 'Válidos', 'Inseridos', 'Atualizados', 'Inalterados', 'Ignorados'], [[
            $resultado['recebidos'], $resultado['validos'], $resultado['inseridos'],
            $resultado['atualizados'], $resultado['inalterados'], $resultado['ignorados'],
        ]]);

        return self::SUCCESS;
    }
}
