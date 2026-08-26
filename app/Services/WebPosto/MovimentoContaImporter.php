<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class MovimentoContaImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
        $accountCodes = collect($rows)->filter(fn ($row) => is_array($row))
            ->pluck('contaCodigo')->filter()->unique()->values()->all();
        $accounts = DB::connection('webposto')->table('contas_bancarias')
            ->where('empresaCodigo', $empresa)->whereIn('contaCodigo', $accountCodes)
            ->pluck('contaCodigo')->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $valid = [];
        $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)
                || (int) ($row['empresaCodigo'] ?? 0) !== $empresa
                || ! isset($row['movimentoContaCodigo'], $row['contaCodigo'])
                || ! isset($accounts[(string) $row['contaCodigo']])) {
                $skipped++;
                continue;
            }
            $valid[] = $row;
        }
        $stored = $this->raw->import(['resultados' => $valid], $empresa, 'movimentos_conta', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;
        return $stored;
    }
}
