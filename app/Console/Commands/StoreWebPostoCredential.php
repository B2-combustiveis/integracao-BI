<?php

namespace App\Console\Commands;

use App\Models\WebPostoCredential;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class StoreWebPostoCredential extends Command
{
    protected $signature = 'webposto:credential
        {empresaCodigo : Código da empresa no WebPosto}
        {--url=https://web.qualityautomacao.com.br/ : URL base da API do WebPosto}';

    protected $description = 'Cria ou atualiza, de forma segura, a credencial do WebPosto de uma empresa';

    public function handle(): int
    {
        $empresaCodigo = filter_var($this->argument('empresaCodigo'), FILTER_VALIDATE_INT);
        $baseUrl = filter_var($this->option('url'), FILTER_VALIDATE_URL);

        if ($empresaCodigo === false || $empresaCodigo < 1) {
            $this->error('Informe um empresaCodigo inteiro e positivo.');

            return self::INVALID;
        }

        if ($baseUrl === false || ! in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            $this->error('Informe uma URL HTTP ou HTTPS válida.');

            return self::INVALID;
        }

        $empresaExiste = DB::connection('webposto')
            ->table('empresas')
            ->where('empresaCodigo', $empresaCodigo)
            ->exists();

        if (! $empresaExiste) {
            $this->error("A empresa {$empresaCodigo} não está cadastrada na base webposto.");

            return self::FAILURE;
        }

        $token = $this->secret('Token do WebPosto');

        if (! is_string($token) || trim($token) === '') {
            $this->error('O token é obrigatório.');

            return self::INVALID;
        }

        WebPostoCredential::query()->updateOrCreate(
            ['empresa_codigo' => $empresaCodigo],
            [
                'base_url' => rtrim($baseUrl, '/').'/',
                'token' => trim($token),
                'ativo' => true,
            ],
        );

        $this->info("Credencial da empresa {$empresaCodigo} salva com criptografia.");

        return self::SUCCESS;
    }
}
