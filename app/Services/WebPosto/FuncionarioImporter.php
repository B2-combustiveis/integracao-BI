<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class FuncionarioImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
        $functionCodes = collect($rows)->filter(fn ($row) => is_array($row))
            ->pluck('funcaoCodigo')->filter()->unique()->values()->all();
        $functions = DB::connection('webposto')->table('funcionario_funcoes')
            ->whereIn('funcaoCodigo', $functionCodes)->pluck('funcaoCodigo')
            ->mapWithKeys(fn ($code) => [(string) $code => true])->all();
        $valid = [];
        $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row)
                || (int) ($row['empresaCodigo'] ?? 0) !== $empresa
                || ! isset($row['funcionarioCodigo'], $row['funcaoCodigo'])
                || ! isset($functions[(string) $row['funcaoCodigo']])) {
                $skipped++;
                continue;
            }
            $valid[] = $row;
        }
        $stored = $this->raw->import(['resultados' => $valid], $empresa, 'funcionarios', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;
        return $stored;
    }
}
