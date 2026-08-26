<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class TituloPagarImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null)
            ? $payload['resultados']
            : [];
        $supplierCodes = collect($rows)->filter(fn ($row) => is_array($row))
            ->pluck('fornecedorCodigo')->filter()->unique()->values()->all();
        $purchaseCodes = collect($rows)->filter(fn ($row) => is_array($row))
            ->pluck('notaEntradaCodigo')->filter()->unique()->values()->all();
        $suppliers = DB::connection('webposto')->table('fornecedores')
            ->where('empresaCodigo', $empresa)->whereIn('fornecedorCodigo', $supplierCodes)
            ->pluck('fornecedorCodigo')->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $purchases = DB::connection('webposto')->table('compras')
            ->where('empresaCodigo', $empresa)->whereIn('compraCodigo', $purchaseCodes)
            ->pluck('compraCodigo')->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $valid = [];
        $skipped = 0;

        foreach ($rows as $row) {
            $purchaseCode = is_array($row) ? ($row['notaEntradaCodigo'] ?? null) : null;
            if (! is_array($row)
                || (int) ($row['empresaCodigo'] ?? 0) !== $empresa
                || ! isset($row['tituloPagarCodigo'], $row['fornecedorCodigo'])
                || ! isset($suppliers[(string) $row['fornecedorCodigo']])
                || ($purchaseCode !== null && ! isset($purchases[(string) $purchaseCode]))) {
                $skipped++;
                continue;
            }

            $valid[] = $row;
        }

        $stored = $this->raw->import(['resultados' => $valid], $empresa, 'titulos_pagar', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;

        return $stored;
    }
}
