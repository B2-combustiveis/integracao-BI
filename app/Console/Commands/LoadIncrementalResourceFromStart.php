<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\ClienteEmpresaImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use App\Services\WebPosto\WebPostoNewRecordsResourceCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadIncrementalResourceFromStart extends Command
{
    protected $signature = 'webposto:load-incremental-resource {resource} {empresa=4604} {--resume} {--pages=40} {--data-inicial=} {--data-final=} {--append}';
    protected $description = 'Recarrega um recurso incremental desde o cursor inicial';

    public function handle(
        WebPostoCursorSynchronizer $sync,
        WebPostoNewRecordsResourceCatalog $catalog,
    ): int {
        $resource = (string) $this->argument('resource');
        $empresa = (int) $this->argument('empresa');
        $definition = $resource === 'cliente_empresas'
            ? [
                'endpoint' => '/INTEGRACAO/CLIENTE_EMPRESA', 'table' => 'cliente_empresas',
                'key' => 'codigo', 'limit' => 200, 'query' => [],
                'query_company_field' => 'empresaCodigo',
                'importer' => ClienteEmpresaImporter::class,
            ]
            : $catalog->get($resource);
        $resume = (bool) $this->option('resume');
        $append = (bool) $this->option('append');
        $dataInicial = trim((string) $this->option('data-inicial'));
        $dataFinal = trim((string) $this->option('data-final'));
        $window = $dataInicial !== '' ? ':'.$dataInicial.':'.($dataFinal ?: now()->toDateString()) : '';
        $controlKey = $definition['endpoint'].':manual-initial'.($append ? $window : '');

        if (! $resume && ! $append) {
            DB::connection('webposto')->table($definition['table'])
                ->where('empresaCodigo', $empresa)->delete();
        }
        if (! $resume) {
            WebPostoSyncControl::query()->where('empresa_codigo', $empresa)
                ->where('endpoint', $controlKey)->delete();
            $this->info(($append ? 'Acumulando em ' : $definition['table'].' limpa para a empresa. ').'Iniciando no cursor 1.');
        }

        $query = [...$definition['query'], 'limite' => $definition['limit']];
        if ($dataInicial !== '' && array_key_exists('dataInicial', $query)) {
            $query['dataInicial'] = $dataInicial;
        }
        if ($dataFinal !== '' && array_key_exists('dataFinal', $query)) {
            $query['dataFinal'] = $dataFinal;
        }
        if (isset($definition['query_company_field'])) {
            $query[$definition['query_company_field']] = $empresa;
        }

        $totals = $sync->synchronize(
            endpoint: $definition['endpoint'],
            empresaCodigo: $empresa,
            persist: fn (mixed $payload, array $parameters): array => app($definition['importer'])
                ->import($payload, $empresa, $parameters),
            query: $query,
            cursor: ['initial_value' => 1, 'prefer_initial_value' => ! $resume],
            initialQuery: null,
            maxPages: max(1, (int) $this->option('pages')),
            controlKey: $controlKey,
        );

        $this->table(['Páginas', 'Recebidos', 'Inseridos', 'Iguais', 'Ignorados'], [[
            $totals['pages'], $totals['received'], $totals['inserted'],
            $totals['unchanged'], $totals['skipped'],
        ]]);

        return self::SUCCESS;
    }
}
