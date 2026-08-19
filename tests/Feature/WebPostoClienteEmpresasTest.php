<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDatabaseBearerToken;
use App\Services\WebPosto\ClienteEmpresaImporter;
use App\Services\WebPosto\WebPostoCredentialData;
use App\Services\WebPosto\WebPostoCredentialResolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebPostoClienteEmpresasTest extends TestCase
{
    public function test_it_fetches_the_real_client_company_relationship(): void
    {
        $resolver = $this->mock(WebPostoCredentialResolver::class);
        $resolver->shouldReceive('resolve')->once()->with(4604)->andReturn(new WebPostoCredentialData(1, 'https://webposto.test', 'token'));
        $resolver->shouldReceive('markAsUsed')->once()->with(1);
        $importer = $this->mock(ClienteEmpresaImporter::class);
        $importer->shouldReceive('import')->once()->andReturn(['table' => 'cliente_empresas']);
        Http::fake(['*' => Http::response(['ultimoCodigo' => 1, 'resultados' => [[
            'empresaCodigo' => 4604, 'clienteCodigo' => 481112, 'ativoInativo' => true, 'usaPrazo' => true, 'codigo' => 1,
        ]]])]);
        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/cliente-empresas?empresa_codigo=4604')->assertOk()
            ->assertJsonPath('endpoint', '/INTEGRACAO/CLIENTE_EMPRESA')->assertJsonPath('storage.table', 'cliente_empresas');
    }

    public function test_it_requires_an_empresa_codigo(): void
    {
        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)->getJson('/api/webposto/cliente-empresas')
            ->assertUnprocessable()->assertJsonValidationErrors('empresa_codigo');
    }
}
