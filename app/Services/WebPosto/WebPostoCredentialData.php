<?php

namespace App\Services\WebPosto;

readonly class WebPostoCredentialData
{
    public function __construct(
        public int $id,
        public string $baseUrl,
        public string $token,
    ) {
    }
}
