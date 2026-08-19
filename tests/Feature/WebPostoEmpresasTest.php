<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDatabaseBearerToken;
use App\Services\Bi\EmpresaBiSynchronizer;
use App\Services\WebPosto\EmpresaImporter;
use App\Services\WebPosto\WebPostoCredentialData;
use App\Services\WebPosto\WebPostoCredentialResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebPostoEmpresasTest extends TestCase
{
    public function test_it_returns_webposto_companies_with_request_metadata(): void
    {
        $this->mockImporter();

        $this->mockCredential();

        Http::fake([
            'https://webposto.test/INTEGRACAO/EMPRESAS*' => Http::response([
                ['codigo' => 1, 'nome' => 'Empresa Teste'],
            ]),
        ]);

        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/empresas?empresa_codigo=4604')
            ->assertOk()
            ->assertJson([
                'status' => true,
                'service' => 'webposto',
                'endpoint' => '/INTEGRACAO/EMPRESAS',
                'empresa_codigo' => 4604,
                'upstream' => [
                    'http_status' => 200,
                    'successful' => true,
                ],
                'records_count' => 1,
                'data' => [
                    ['codigo' => 1, 'nome' => 'Empresa Teste'],
                ],
            ]);

        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://webposto.test/INTEGRACAO/EMPRESAS?chave=secret-token'
        );
    }

    public function test_it_counts_records_inside_webposto_resultados_envelope(): void
    {
        $this->mockImporter();

        $this->mockCredential();

        Http::fake([
            '*' => Http::response([
                'ultimoCodigo' => 4604,
                'resultados' => [
                    ['codigo' => 4604, 'fantasia' => 'POSTO CHIMBA'],
                ],
            ]),
        ]);

        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/empresas?empresa_codigo=4604')
            ->assertOk()
            ->assertJsonPath('records_count', 1);
    }

    public function test_it_requires_an_empresa_codigo(): void
    {
        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/empresas')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('empresa_codigo');
    }

    private function mockCredential(): void
    {
        $resolver = $this->mock(WebPostoCredentialResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->with(4604)
            ->andReturn(new WebPostoCredentialData(1, 'https://webposto.test', 'secret-token'));
        $resolver->shouldReceive('markAsUsed')->once()->with(1);
    }

    private function mockImporter(): void
    {
        $importer = $this->mock(EmpresaImporter::class);
        $importer->shouldReceive('import')->once()->andReturn([
            'database' => 'webposto',
            'table' => 'empresas',
            'sync_status' => 'synchronized',
            'received' => 1,
            'inserted' => 1,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
        ]);

        $bi = $this->mock(EmpresaBiSynchronizer::class);
        $bi->shouldReceive('sync')->once()->andReturn([
            'database' => 'bi',
            'table' => 'dim_empresas',
            'sync_status' => 'synchronized',
            'received' => 1,
            'inserted' => 1,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
        ]);
    }
}
