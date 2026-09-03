<?php

namespace App\Services\WebPosto;

class CentroCustoImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        return $this->raw->import($payload, $empresa, 'centros_custo', $parameters);
    }
}
