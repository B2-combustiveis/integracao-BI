<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class CompraImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
        $supplierCodes = collect($rows)->filter(fn ($row) => is_array($row))
            ->pluck('fornecedorCodigo')->filter()->unique()->values()->all();
        $suppliers = DB::connection('webposto')->table('fornecedores')
            ->where('empresaCodigo', $empresa)->whereIn('fornecedorCodigo', $supplierCodes)
            ->pluck('fornecedorCodigo')->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $valid = [];
        $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)
                || (int) ($row['empresaCodigo'] ?? 0) !== $empresa
                || ! isset($row['compraCodigo'], $row['fornecedorCodigo'])
                || ! isset($suppliers[(string) $row['fornecedorCodigo']])) {
                $skipped++;
                continue;
            }
            $valid[] = $row;
        }
        $stored = $this->raw->import(['resultados' => $valid], $empresa, 'compras', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;
        return $stored;
    }
}
