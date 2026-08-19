<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDatabaseBearerToken;
use App\Services\WebPosto\ClienteImporter;
use App\Services\WebPosto\WebPostoCredentialData;
use App\Services\WebPosto\WebPostoCredentialResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebPostoClientesTest extends TestCase
{
    public function test_it_fetches_clients_and_passes_the_company_context_to_storage(): void
    {
        $resolver = $this->mock(WebPostoCredentialResolver::class);
        $resolver->shouldReceive('resolve')->once()->with(4604)
            ->andReturn(new WebPostoCredentialData(1, 'https://webposto.test', 'secret-token'));
        $resolver->shouldReceive('markAsUsed')->once()->with(1);
        $importer = $this->mock(ClienteImporter::class);
        $importer->shouldReceive('import')->once()->withArgs(fn (mixed $payload, int $empresa): bool =>
            $payload['resultados'][0]['clienteGrupoCodigo'] === 530 && $empresa === 4604
        )->andReturn(['database' => 'webposto', 'table' => 'clientes', 'sync_status' => 'synchronized']);
        Http::fake(['*' => Http::response(['ultimoCodigo' => 481114, 'resultados' => [[
            'clienteCodigo' => 481114, 'clienteGrupoCodigo' => 530, 'razao' => 'CLIENTE TESTE',
        ]]])]);

        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/clientes?empresa_codigo=4604')
            ->assertOk()->assertJsonPath('endpoint', '/INTEGRACAO/CLIENTE')
            ->assertJsonPath('records_count', 1)->assertJsonPath('storage.table', 'clientes');
        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://webposto.test/INTEGRACAO/CLIENTE?chave=secret-token'
        );
    }

    public function test_it_requires_an_empresa_codigo(): void
    {
        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)->getJson('/api/webposto/clientes')
            ->assertUnprocessable()->assertJsonValidationErrors('empresa_codigo');
    }
}
