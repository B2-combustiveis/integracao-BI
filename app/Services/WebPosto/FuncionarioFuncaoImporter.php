<?php

namespace App\Services\WebPosto;

class FuncionarioFuncaoImporter
{
    public function __construct(private readonly RawResourceImporter $raw) {}

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        return $this->raw->import($payload, $empresa, 'funcionario_funcoes', $parameters);
    }
}
