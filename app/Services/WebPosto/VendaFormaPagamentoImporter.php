<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class VendaFormaPagamentoImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null)
            ? $payload['resultados']
            : [];
        $saleCodes = collect($rows)->filter(fn ($row) => is_array($row))
            ->pluck('vendaCodigo')->filter()->unique()->values()->all();
        $sales = DB::connection('webposto')->table('vendas')
            ->where('empresaCodigo', $empresa)->whereIn('vendaCodigo', $saleCodes)
            ->pluck('vendaCodigo')->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $valid = [];
        $skipped = 0;

        foreach ($rows as $row) {
            if (! is_array($row)
                || (int) ($row['empresaCodigo'] ?? 0) !== $empresa
                || ! isset($row['codigo'], $row['vendaCodigo'])
                || ! isset($sales[(string) $row['vendaCodigo']])) {
                $skipped++;
                continue;
            }

            $valid[] = $row;
        }

        $stored = $this->raw->import(['resultados' => $valid], $empresa, 'venda_formas_pagamento', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;

        return $stored;
    }
}
