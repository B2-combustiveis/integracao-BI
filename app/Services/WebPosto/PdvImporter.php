<?php

namespace App\Services\WebPosto;

class PdvImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        return $this->raw->import(
            $this->scoped($payload, $empresa),
            $empresa,
            'pdvs',
            $parameters,
        );
    }

    private function scoped(mixed $payload, int $empresa): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null)
            ? $payload['resultados'] : [];

        return ['resultados' => collect($rows)
            ->filter(fn ($row): bool => is_array($row)
                && (! isset($row['empresaCodigo']) || (int) $row['empresaCodigo'] === $empresa))
            ->map(fn (array $row): array => ['empresaCodigo' => $empresa, ...$row])
            ->values()->all()];
    }
}
