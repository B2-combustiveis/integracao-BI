<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDatabaseBearerToken;
use App\Services\WebPosto\ClienteGrupoImporter;
use App\Services\WebPosto\WebPostoCredentialData;
use App\Services\WebPosto\WebPostoCredentialResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebPostoClienteGruposTest extends TestCase
{
    public function test_it_fetches_customer_groups_using_the_company_credential(): void
    {
        $resolver = $this->mock(WebPostoCredentialResolver::class);
        $resolver->shouldReceive('resolve')->once()->with(4604)
            ->andReturn(new WebPostoCredentialData(1, 'https://webposto.test', 'secret-token'));
        $resolver->shouldReceive('markAsUsed')->once()->with(1);
        $importer = $this->mock(ClienteGrupoImporter::class);
        $importer->shouldReceive('import')->once()->withArgs(fn (mixed $payload, int $empresa): bool =>
            $payload['resultados'][0]['grupoCodigo'] === 530 && $empresa === 4604
        )->andReturn(['database' => 'webposto', 'table' => 'cliente_grupos', 'sync_status' => 'synchronized']);

        Http::fake(['*' => Http::response(['ultimoCodigo' => 530, 'resultados' => [[
            'grupoCodigo' => 530,
            'descricao' => 'CORRENTISTA',
        ]]])]);

        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/cliente-grupos?empresa_codigo=4604')
            ->assertOk()
            ->assertJsonPath('endpoint', '/INTEGRACAO/GRUPO_CLIENTE')
            ->assertJsonPath('records_count', 1)
            ->assertJsonPath('storage.table', 'cliente_grupos');

        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://webposto.test/INTEGRACAO/GRUPO_CLIENTE?chave=secret-token'
        );
    }

    public function test_it_requires_an_empresa_codigo(): void
    {
        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/cliente-grupos')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('empresa_codigo');
    }
}
