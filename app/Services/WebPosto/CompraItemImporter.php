<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class CompraItemImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
        $db = DB::connection('webposto');
        $purchaseCodes = collect($rows)->filter(fn ($row) => is_array($row))->pluck('compraCodigo')->filter()->unique()->values()->all();
        $productCodes = collect($rows)->filter(fn ($row) => is_array($row))->pluck('produtoCodigo')->filter()->unique()->values()->all();
        $lmcCodes = collect($rows)->filter(fn ($row) => is_array($row))->pluck('produtoLmcCodigo')->filter()->unique()->values()->all();
        $purchases = $db->table('compras')->where('empresaCodigo', $empresa)->whereIn('compraCodigo', $purchaseCodes)
            ->pluck('compraCodigo')->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $products = $db->table('produtos')->where('empresaCodigo', $empresa)->whereIn('produtoCodigo', $productCodes)
            ->pluck('produtoCodigo')->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $lmcs = $db->table('produto_lmc_lmp')->where('empresaCodigo', $empresa)->whereIn('produtoLmcCodigo', $lmcCodes)
            ->pluck('produtoLmcCodigo')->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $valid = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $lmc = is_array($row) ? ($row['produtoLmcCodigo'] ?? null) : null;
            if (! is_array($row)
                || (int) ($row['empresaCodigo'] ?? 0) !== $empresa
                || ! isset($row['compraCodigo'], $row['produtoCodigo'], $row['sequencialItem'])
                || ! isset($purchases[(string) $row['compraCodigo']], $products[(string) $row['produtoCodigo']])
                || ($lmc !== null && ! isset($lmcs[(string) $lmc]))) {
                $skipped++;
                continue;
            }
            $valid[] = $row;
        }
        $stored = $this->raw->import(['resultados' => $valid], $empresa, 'compra_itens', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;
        return $stored;
    }
}
