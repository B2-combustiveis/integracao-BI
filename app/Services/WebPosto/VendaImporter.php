<?php

namespace App\Services\WebPosto;

class VendaImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null)
            ? $payload['resultados']
            : [];
        $valid = [];
        $skipped = 0;

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['vendaCodigo'])) {
                $skipped++;
                continue;
            }

            $valid[] = [...$row, 'empresaCodigo' => $empresa];
        }

        $stored = $this->raw->import(['resultados' => $valid], $empresa, 'vendas', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] += $skipped;

        return $stored;
    }
}
