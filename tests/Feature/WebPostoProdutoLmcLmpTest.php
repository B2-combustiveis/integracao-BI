<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDatabaseBearerToken;
use App\Services\WebPosto\ProdutoLmcLmpImporter;
use App\Services\WebPosto\WebPostoCredentialData;
use App\Services\WebPosto\WebPostoCredentialResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebPostoProdutoLmcLmpTest extends TestCase
{
    public function test_it_fetches_lmc_lmp_products_using_only_the_resolved_key(): void
    {
        $resolver = $this->mock(WebPostoCredentialResolver::class);
        $resolver->shouldReceive('resolve')->once()->with(4604)
            ->andReturn(new WebPostoCredentialData(1, 'https://webposto.test', 'secret-token'));
        $resolver->shouldReceive('markAsUsed')->once()->with(1);

        $importer = $this->mock(ProdutoLmcLmpImporter::class);
        $importer->shouldReceive('import')->once()->withArgs(fn (mixed $payload, int $empresaCodigo): bool =>
            $payload[0]['produtoLmcCodigo'] === 4238 && $empresaCodigo === 4604
        )->andReturn([
            'database' => 'webposto',
            'table' => 'produto_lmc_lmp',
            'sync_status' => 'synchronized',
            'received' => 1,
            'inserted' => 1,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
        ]);

        Http::fake(['*' => Http::response([[
            'produtoLmcCodigo' => 4238,
            'sequencia' => 1,
            'descricao' => 'GASOLINA C COMUM',
            'tipoCombustivel' => 'G',
            'geraLmcLmp' => 'S',
        ]])]);

        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/produto-lmc-lmp?empresa_codigo=4604')
            ->assertOk()
            ->assertJsonPath('endpoint', '/INTEGRACAO/PRODUTO_LMC_LMP')
            ->assertJsonPath('records_count', 1)
            ->assertJsonPath('storage.table', 'produto_lmc_lmp');

        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://webposto.test/INTEGRACAO/PRODUTO_LMC_LMP?chave=secret-token'
        );
    }

    public function test_it_requires_an_empresa_codigo(): void
    {
        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/produto-lmc-lmp')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('empresa_codigo');
    }
}
