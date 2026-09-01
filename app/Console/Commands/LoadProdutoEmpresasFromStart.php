<?php

namespace App\Console\Commands;

use App\Services\WebPosto\ProdutoEmpresaImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LoadProdutoEmpresasFromStart extends Command
{
    protected $signature = 'webposto:load-produto-empresas {empresa=4604}';
    protected $description = 'Padrão 2000: reconcilia produtos por empresa desde o cursor 1';

    public function handle(WebPostoCursorSynchronizer $sync, ProdutoEmpresaImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $codes = [];
        $totals = $sync->synchronize(
            endpoint: '/INTEGRACAO/PRODUTO_EMPRESA',
            empresaCodigo: $empresa,
            persist: function ($payload, $parameters) use ($importer, $empresa, &$codes): array {
                $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
                $companyRows = collect($rows)->filter(fn ($row) => is_array($row)
                        && (int) ($row['empresaCodigo'] ?? 0) === $empresa)
                    ->values()->all();
                foreach ($companyRows as $row) {
                    if (is_numeric($row['produtoCodigo'] ?? null)) $codes[(int) $row['produtoCodigo']] = true;
                }
                $stored = $importer->import(['resultados' => $companyRows]);
                $stored['received'] = count($rows);
                $stored['skipped'] += count($rows) - count($companyRows);
                if (($stored['missing_parent_product'] ?? 0) > 0) {
                    throw new RuntimeException('O WebPosto retornou configuracoes sem produto-pai cadastrado.');
                }
                return $stored;
            },
            query: ['limite' => 1000, 'empresaCodigo' => $empresa],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => true],
            initialQuery: null,
            controlKey: '/INTEGRACAO/PRODUTO_EMPRESA:manual-initial',
        );
        if ($codes === []) throw new RuntimeException('O WebPosto nao retornou produtos por empresa validos.');
        $totals['deleted'] = DB::connection('webposto')->table('produto_empresas')->where('empresaCodigo', $empresa)
            ->whereNotIn('produtoCodigo', array_keys($codes))->delete();
        $this->info(json_encode($totals));
        return self::SUCCESS;
    }
}
