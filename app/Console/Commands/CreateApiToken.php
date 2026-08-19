<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateApiToken extends Command
{
    protected $signature = 'api:token {name=admin : Nome de identificação do token}';
    protected $description = 'Gera um novo token ativo para a API e o painel administrativo';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        if ($name === '') return self::INVALID;
        $token = Str::random(64);
        DB::table('api_tokens')->insert(['nome' => $name, 'token' => $token, 'ativo' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->warn('Copie agora: o token não será exibido novamente.');
        $this->line($token);
        return self::SUCCESS;
    }
}
