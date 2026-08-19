<?php

namespace App\Console\Commands;

use App\Services\WebPosto\RawResourceImporter;
use App\Services\WebPosto\WebPostoClient;
use App\Services\WebPosto\WebPostoResourceCatalog;
use Illuminate\Console\Command;
use Throwable;

class SyncWebPostoCatalog extends Command
{
    protected $signature = 'webposto:sync-catalog
        {empresa : Código da empresa cuja credencial será usada}
        {--data-inicial=2024-02-01}
        {--data-final=2024-02-01}
        {--grupo-meta-codigo=482}';

    protected $description = 'Consulta e armazena os recursos brutos mapeados da collection WebPosto';

    public function handle(WebPostoResourceCatalog $catalog, WebPostoClient $client, RawResourceImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $common = [
            'data_inicial' => (string) $this->option('data-inicial'),
            'data_final' => (string) $this->option('data-final'),
            'empresa_webposto_codigo' => $empresa,
            'tipo_data' => 'EMISSAO',
            'apuracao_caixa' => true,
            'grupo_meta_codigo' => (int) $this->option('grupo-meta-codigo'),
            'compra_codigo' => 1772726,
            'documento_codigo' => 150199947,
            'modelo_documento' => 55,
            'numero_documento' => '000000068',
            'serie_documento' => '31',
        ];

        foreach ($catalog->all() as $resource => $definition) {
            if ($resource === 'vendas-por-ids') {
                $this->line("SKIP {$resource}: exige uma lista de vendas conhecida");
                continue;
            }
            $endpoint = $definition['endpoint'];
            foreach ($definition['pathParameters'] as $parameter) {
                $endpoint = str_replace('{'.$parameter.'}', rawurlencode((string) ($common[$parameter] ?? '')), $endpoint);
            }
            $query = [];
            foreach ($definition['queryMap'] as $local => $upstream) {
                if (array_key_exists($local, $common)) $query[$upstream] = $common[$local];
            }

            try {
                $result = $client->get($endpoint, $empresa, $query);
                if (! $result['response']->successful()) {
                    $this->error("FAIL {$resource}: HTTP {$result['response']->status()}");
                    continue;
                }
                $stored = $importer->import($result['payload'], $empresa, $definition['table'], $query);
                $this->info("OK {$resource}: recebidos={$stored['received']} inseridos={$stored['inserted']} existentes={$stored['unchanged']}");
            } catch (Throwable $exception) {
                $this->error("FAIL {$resource}: {$exception->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
