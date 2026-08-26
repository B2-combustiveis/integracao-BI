<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class LmcImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
        $productCodes = collect($rows)->filter(fn ($row) => is_array($row))
            ->pluck('produtoLmcCodigo')->filter()->unique()->values();
        $products = DB::connection('webposto')->table('produto_lmc_lmp')->where('empresaCodigo', $empresa)
            ->whereIn('produtoLmcCodigo', $productCodes)->pluck('produtoLmcCodigo')
            ->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $valid = [];
        $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)
                || (int) ($row['empresaCodigo'] ?? 0) !== $empresa
                || ! isset($row['lmcCodigo'], $row['produtoLmcCodigo'])
                || ! isset($products[(string) $row['produtoLmcCodigo']])) {
                $skipped++;
                continue;
            }
            $valid[] = $row;
        }
        $stored = $this->raw->import(['resultados' => $valid], $empresa, 'lmcs', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;
        return $stored;
    }
}
