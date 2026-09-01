<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\ClienteGrupoImporter;
use App\Services\WebPosto\WebPostoClient;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

class LoadClienteGruposSnapshot extends Command
{
    protected $signature = 'webposto:load-cliente-grupos {empresa=4604}';
    protected $description = 'Padrão 2000: reconcilia o snapshot completo de grupos de clientes';

    public function handle(WebPostoClient $client, ClienteGrupoImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $endpoint = '/INTEGRACAO/GRUPO_CLIENTE';
        $controlKey = $endpoint.':manual-initial';
        $control = WebPostoSyncControl::query()->updateOrCreate(
            ['empresa_codigo' => $empresa, 'endpoint' => $controlKey],
            ['strategy' => 'C', 'last_code' => 0, 'metadata' => ['mode' => 'snapshot']],
        );
        $control->update(['status' => 'running', 'last_started_at' => now(), 'last_error' => null]);

        try {
            $result = $client->get($endpoint, $empresa, ['empresaCodigo' => $empresa]);
            if (! $result['response']->successful()) {
                throw new RuntimeException('WebPosto respondeu HTTP '.$result['response']->status().' em '.$endpoint.'.');
            }
            $stored = $importer->import($result['payload'], $empresa);
            if (($stored['received'] ?? 0) > 0 && ($stored['inserted'] + $stored['updated'] + $stored['unchanged']) === 0) {
                throw new RuntimeException('O WebPosto não retornou grupos de clientes válidos.');
            }
            $control->update([
                'status' => 'ok',
                'last_code' => (int) (\DB::connection('webposto')->table('cliente_grupos')
                    ->where('empresaCodigo', $empresa)->max('grupoCodigo') ?? 0),
                'last_completed_at' => now(),
                'consecutive_failures' => 0,
                'last_error' => null,
                'metadata' => ['mode' => 'snapshot', 'records' => $stored['received'] ?? 0],
            ]);
            $this->info(json_encode($stored));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $control->update([
                'status' => 'error',
                'last_completed_at' => now(),
                'consecutive_failures' => $control->consecutive_failures + 1,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            throw $exception;
        }
    }
}
