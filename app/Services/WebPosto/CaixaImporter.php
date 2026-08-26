<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class CaixaImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
        $employeeCodes = collect($rows)->filter(fn ($row) => is_array($row))
            ->pluck('funcionarioCodigo')->filter()->unique()->values();
        $employees = DB::connection('webposto')->table('funcionarios')->where('empresaCodigo', $empresa)
            ->whereIn('funcionarioCodigo', $employeeCodes)->pluck('funcionarioCodigo')
            ->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $valid = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $employee = is_array($row) ? ($row['funcionarioCodigo'] ?? null) : null;
            if (! is_array($row)
                || (int) ($row['empresaCodigo'] ?? 0) !== $empresa
                || ! isset($row['caixaCodigo'])
                || ($employee !== null && ! isset($employees[(string) $employee]))) {
                $skipped++;
                continue;
            }
            $valid[] = $row;
        }
        $stored = $this->raw->import(['resultados' => $valid], $empresa, 'caixas', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;
        return $stored;
    }
}
