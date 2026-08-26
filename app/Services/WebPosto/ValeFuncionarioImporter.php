<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class ValeFuncionarioImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
        $codes = collect($rows)->filter(fn ($row) => is_array($row))->pluck('funcionarioCodigo')->filter()->unique()->values();
        $employees = DB::connection('webposto')->table('funcionarios')->where('empresaCodigo', $empresa)
            ->whereIn('funcionarioCodigo', $codes)->pluck('funcionarioCodigo')
            ->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $valid = [];
        $skipped = 0;

        foreach ($rows as $row) {
            if (! is_array($row)
                || (int) ($row['empresaCodigo'] ?? 0) !== $empresa
                || ! isset($row['funcionarioCreditoCodigo'], $row['funcionarioCodigo'])
                || ! isset($employees[(string) $row['funcionarioCodigo']])) {
                $skipped++;
                continue;
            }
            $valid[] = $row;
        }

        $stored = $this->raw->import(['resultados' => $valid], $empresa, 'vales_funcionario', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;
        return $stored;
    }
}
