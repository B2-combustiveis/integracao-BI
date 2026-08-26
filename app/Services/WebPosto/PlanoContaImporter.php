<?php

namespace App\Services\WebPosto;

class PlanoContaImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, string $table, string $key): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null)
            ? $payload['resultados']
            : (is_array($payload) && array_is_list($payload) ? $payload : []);
        $valid = [];
        $skipped = 0;
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_numeric($row[$key] ?? null)
                || (isset($row['empresaCodigo']) && (int) $row['empresaCodigo'] !== $empresa)) {
                $skipped++;
                continue;
            }
            $valid[] = [...$row, 'empresaCodigo' => $empresa];
        }
        $stored = $this->raw->import(['resultados' => $valid], $empresa, $table, []);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;
        return $stored;
    }
}
