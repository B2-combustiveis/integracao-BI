<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDatabaseBearerToken;
use App\Services\WebPosto\ProdutoGrupoImporter;
use App\Services\WebPosto\WebPostoCredentialData;
use App\Services\WebPosto\WebPostoCredentialResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebPostoProdutoGruposTest extends TestCase
{
    public function test_it_fetches_and_stores_product_groups_for_the_credential_company(): void
    {
        $resolver = $this->mock(WebPostoCredentialResolver::class);
        $resolver->shouldReceive('resolve')->once()->with(4604)
            ->andReturn(new WebPostoCredentialData(1, 'https://webposto.test', 'secret-token'));
        $resolver->shouldReceive('markAsUsed')->once()->with(1);

        $importer = $this->mock(ProdutoGrupoImporter::class);
        $importer->shouldReceive('import')->once()->withArgs(fn (mixed $payload, int $empresaCodigo): bool =>
            $payload['resultados'][0]['grupoCodigo'] === 15446 && $empresaCodigo === 4604
        )->andReturn([
            'database' => 'webposto',
            'table' => 'produto_grupos',
            'sync_status' => 'synchronized',
            'received' => 1,
            'inserted' => 1,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
        ]);

        Http::fake(['*' => Http::response([
            'ultimoCodigo' => 15446,
            'resultados' => [[
                'grupoCodigo' => 15446,
                'nome' => 'COMBUSTIVEIS',
            ]],
        ])]);

        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/produto-grupos?empresa_codigo=4604')
            ->assertOk()
            ->assertJsonPath('endpoint', '/INTEGRACAO/GRUPO')
            ->assertJsonPath('empresa_codigo', 4604)
            ->assertJsonPath('records_count', 1)
            ->assertJsonPath('storage.table', 'produto_grupos');

        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://webposto.test/INTEGRACAO/GRUPO?chave=secret-token'
        );
    }

    public function test_it_requires_an_empresa_codigo(): void
    {
        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/produto-grupos')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('empresa_codigo');
    }
}
