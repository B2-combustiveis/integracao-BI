<?php

namespace App\Console\Commands;

use App\Services\Alterdata\AlterdataClient;
use App\Services\Alterdata\FuncionarioImporter;
use Illuminate\Console\Command;

class SyncAlterdataFuncionarios extends Command
{
    protected $signature = 'alterdata:sync-funcionarios';

    protected $description = 'Sincroniza os funcionários disponíveis na API Alterdata';

    public function handle(AlterdataClient $client, FuncionarioImporter $importer): int
    {
        $this->info('Consultando funcionários no Alterdata...');
        $funcionarios = $client->todos(
            'funcionarios',
            ['sort' => 'id'],
            100,
            [
                'empresa', 'departamento', 'sexo', 'estadocivil', 'formadepagamento',
                'nacionalidade', 'naturalidade', 'estado', 'tipoDeConta', 'tipoDeChavePix',
            ],
        );
        $resultado = $importer->import($funcionarios);

        $this->table(['Recebidos', 'Válidos', 'Inseridos', 'Atualizados', 'Inalterados', 'Ignorados'], [[
            $resultado['recebidos'], $resultado['validos'], $resultado['inseridos'],
            $resultado['atualizados'], $resultado['inalterados'], $resultado['ignorados'],
        ]]);

        return self::SUCCESS;
    }
}
