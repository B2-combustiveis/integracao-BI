<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class TituloReceberImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null)
            ? $payload['resultados']
            : [];
        $clientCodes = collect($rows)->filter(fn ($row) => is_array($row))
            ->pluck('clienteCodigo')->filter()->unique()->values()->all();
        $clients = DB::connection('webposto')->table('clientes')
            ->where('empresaCodigo', $empresa)->whereIn('clienteCodigo', $clientCodes)
            ->pluck('clienteCodigo')->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $valid = [];
        $skipped = 0;

        foreach ($rows as $row) {
            if (! is_array($row)
                || (int) ($row['empresaCodigo'] ?? 0) !== $empresa
                || ! isset($row['tituloCodigo'], $row['clienteCodigo'])
                || ! isset($clients[(string) $row['clienteCodigo']])) {
                $skipped++;
                continue;
            }

            $valid[] = $row;
        }

        $stored = $this->raw->import(['resultados' => $valid], $empresa, 'titulos_receber', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;

        return $stored;
    }
}
