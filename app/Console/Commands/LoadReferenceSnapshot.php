<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\FormaPagamentoImporter;
use App\Services\WebPosto\PdvImporter;
use App\Services\WebPosto\WebPostoClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LoadReferenceSnapshot extends Command
{
    protected $signature = 'webposto:load-reference-snapshot {resource : formas_pagamento ou pdvs} {empresa=4604}';
    protected $description = 'Padrão 2000: recarrega uma tabela cadastral de referência';

    public function handle(WebPostoClient $client): int
    {
        $resource = (string) $this->argument('resource');
        $empresa = (int) $this->argument('empresa');
        $definition = match ($resource) {
            'formas_pagamento' => ['/INTEGRACAO/FORMA_PAGAMENTO', FormaPagamentoImporter::class],
            'pdvs' => ['/INTEGRACAO/PDV', PdvImporter::class],
            default => throw new RuntimeException('Recurso de referência não suportado.'),
        };
        [$endpoint, $importer] = $definition;
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
            $rows = is_array($result['payload']) && is_array($result['payload']['resultados'] ?? null)
                ? $result['payload']['resultados'] : [];
            if ($rows === []) {
                throw new RuntimeException('O WebPosto não retornou registros válidos para '.$resource.'.');
            }
            $stored = DB::connection('webposto')->transaction(function () use (
                $resource, $empresa, $importer, $result
            ): array {
                DB::connection('webposto')->table($resource)->where('empresaCodigo', $empresa)->delete();

                return app($importer)->import($result['payload'], $empresa);
            });
            $key = $resource === 'formas_pagamento' ? 'formaPagamentoCodigo' : 'pdvCodigo';
            $control->update([
                'status' => 'ok',
                'last_code' => (int) (DB::connection('webposto')->table($resource)
                    ->where('empresaCodigo', $empresa)->max($key) ?? 0),
                'last_completed_at' => now(),
                'consecutive_failures' => 0,
                'last_error' => null,
                'metadata' => ['mode' => 'snapshot', 'records' => $stored['inserted'] ?? 0],
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
