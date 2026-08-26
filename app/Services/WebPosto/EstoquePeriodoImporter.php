<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class EstoquePeriodoImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
        $productCodes = collect($rows)->filter(fn ($row) => is_array($row))
            ->pluck('codigoProduto')->filter()->unique()->values()->all();
        $products = DB::connection('webposto')->table('produtos')
            ->where('empresaCodigo', $empresa)->whereIn('produtoCodigo', $productCodes)
            ->pluck('produtoCodigo')->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $valid = [];
        $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)
                || (int) ($row['codigoUnidadeNegocio'] ?? 0) !== $empresa
                || ! isset($row['codigo'], $row['codigoProduto'])
                || ! isset($products[(string) $row['codigoProduto']])) {
                $skipped++;
                continue;
            }
            $valid[] = $row;
        }
        $stored = $this->raw->import(['resultados' => $valid], $empresa, 'estoque_periodos', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;
        return $stored;
    }
}
