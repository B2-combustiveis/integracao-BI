<?php

namespace App\Services\WebPosto;

use App\Models\WebPostoCredential;
use RuntimeException;

class WebPostoCredentialResolver
{
    public function resolve(int $empresaCodigo): WebPostoCredentialData
    {
        $credential = WebPostoCredential::query()
            ->where('empresa_codigo', $empresaCodigo)
            ->where('ativo', true)
            ->first();

        if ($credential === null) {
            throw new RuntimeException("Não há credencial ativa do WebPosto para a empresa {$empresaCodigo}.");
        }

        return new WebPostoCredentialData(
            id: $credential->getKey(),
            baseUrl: $credential->base_url,
            token: $credential->token,
        );
    }

    public function markAsUsed(int $credentialId): void
    {
        WebPostoCredential::query()
            ->whereKey($credentialId)
            ->update(['ultimo_uso_em' => now()]);
    }
}
