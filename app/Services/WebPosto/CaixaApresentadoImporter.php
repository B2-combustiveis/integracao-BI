<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class CaixaApresentadoImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null)
            ? $payload['resultados'] : [];
        $codes = collect($rows)->filter(fn ($row) => is_array($row)
                && (int) ($row['empresaCodigo'] ?? 0) === $empresa
                && is_numeric($row['caixaCodigo'] ?? null))
            ->pluck('caixaCodigo')->map(fn ($code): int => (int) $code)->unique();
        $parents = DB::connection('webposto')->table('caixas')
            ->where('empresaCodigo', $empresa)->whereIn('caixaCodigo', $codes)
            ->pluck('caixaCodigo')->mapWithKeys(fn ($code): array => [(string) $code => true])->all();
        $valid = collect($rows)->filter(fn ($row): bool => is_array($row)
                && (int) ($row['empresaCodigo'] ?? 0) === $empresa
                && is_numeric($row['caixaCodigo'] ?? null)
                && isset($parents[(string) $row['caixaCodigo']]))
            ->unique(fn (array $row): string => $empresa.'-'.(int) $row['caixaCodigo'])->values();
        $stored = $this->raw->import(
            ['resultados' => $valid->all()],
            $empresa,
            'caixas_apresentados',
            $parameters,
        );
        $stored['received'] = count($rows);
        $stored['skipped'] = (int) ($stored['skipped'] ?? 0) + count($rows) - $valid->count();
        $stored['missing_parent_caixa'] = $codes->count() - count($parents);

        return $stored;
    }
}
