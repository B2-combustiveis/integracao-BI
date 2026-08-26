<?php

namespace App\Console\Commands;

use App\Services\WebPosto\ProdutoImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LoadProdutosFromStart extends Command
{
    protected $signature = 'webposto:load-produtos {empresa=4604}';
    protected $description = 'Padrão 2000: reconcilia produtos desde o cursor 1';

    public function handle(WebPostoCursorSynchronizer $sync, ProdutoImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $codes = [];
        $totals = $sync->synchronize(
            endpoint: '/INTEGRACAO/PRODUTO',
            empresaCodigo: $empresa,
            persist: function ($payload, $parameters) use ($importer, $empresa, &$codes): array {
                $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
                foreach ($rows as $row) {
                    if (is_array($row) && is_numeric($row['produtoCodigo'] ?? null)) {
                        $codes[(int) $row['produtoCodigo']] = true;
                    }
                }
                $stored = $importer->import($payload, $empresa);
                if (($stored['missing_parent_group'] ?? 0) > 0 || ($stored['missing_parent_subgroup'] ?? 0) > 0) {
                    throw new RuntimeException('O WebPosto retornou produtos com grupo ou subgrupo inexistente.');
                }
                return $stored;
            },
            query: ['limite' => 1000],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => true],
            initialQuery: null,
            controlKey: '/INTEGRACAO/PRODUTO:manual-initial',
        );

        if ($codes === []) {
            throw new RuntimeException('O WebPosto nao retornou produtos validos.');
        }

        $stale = DB::connection('webposto')->table('produtos')->where('empresaCodigo', $empresa)
            ->whereNotIn('produtoCodigo', array_keys($codes))->pluck('produtoCodigo');
        if ($stale->isNotEmpty()) {
            $dependencies = [
                ['produto_empresas', 'empresaCodigo', 'produtoCodigo'],
                ['venda_itens', 'empresaCodigo', 'produtoCodigo'],
                ['compra_itens', 'empresaCodigo', 'produtoCodigo'],
                ['estoque_periodos', 'codigoUnidadeNegocio', 'codigoProduto'],
                ['tanques', 'empresaCodigo', 'produtoCodigo'],
                ['bicos', 'empresaCodigo', 'produtoCodigo'],
            ];
            foreach ($dependencies as [$table, $companyField, $productField]) {
                if (DB::connection('webposto')->table($table)->where($companyField, $empresa)
                    ->whereIn($productField, $stale)->exists()) {
                    throw new RuntimeException("Existem produtos ausentes no WebPosto ainda vinculados em {$table}.");
                }
            }
            $totals['deleted'] = DB::connection('webposto')->table('produtos')->where('empresaCodigo', $empresa)
                ->whereIn('produtoCodigo', $stale)->delete();
        } else {
            $totals['deleted'] = 0;
        }

        $this->info(json_encode($totals));
        return self::SUCCESS;
    }
}
