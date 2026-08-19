<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDatabaseBearerToken;
use App\Services\WebPosto\ProdutoSubgrupoImporter;
use App\Services\WebPosto\WebPostoCredentialData;
use App\Services\WebPosto\WebPostoCredentialResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebPostoProdutoSubgruposTest extends TestCase
{
    public function test_it_fetches_and_stores_product_subgroups(): void
    {
        $resolver = $this->mock(WebPostoCredentialResolver::class);
        $resolver->shouldReceive('resolve')->once()->with(4604)
            ->andReturn(new WebPostoCredentialData(1, 'https://webposto.test', 'secret-token'));
        $resolver->shouldReceive('markAsUsed')->once()->with(1);

        $importer = $this->mock(ProdutoSubgrupoImporter::class);
        $importer->shouldReceive('import')->once()->withArgs(fn (mixed $payload, int $empresaCodigo): bool =>
            $payload[0]['subGrupoCodigo'] === 5 && $empresaCodigo === 4604
        )->andReturn([
            'database' => 'webposto',
            'table' => 'produto_subgrupos',
            'sync_status' => 'synchronized',
            'received' => 1,
            'inserted' => 1,
            'updated' => 0,
            'unchanged' => 0,
            'missing_parent_group' => 0,
            'skipped' => 0,
        ]);

        Http::fake(['*' => Http::response([[
            'subGrupoCodigo' => 5,
            'descricao' => 'AR',
            'grupoCodigo' => 15449,
            'produtoSubGrupo2' => [],
        ]])]);

        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/produto-subgrupos?empresa_codigo=4604')
            ->assertOk()
            ->assertJsonPath('endpoint', '/INTEGRACAO/CONSULTAR_SUB_GRUPO_REDE')
            ->assertJsonPath('records_count', 1)
            ->assertJsonPath('storage.table', 'produto_subgrupos');

        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://webposto.test/INTEGRACAO/CONSULTAR_SUB_GRUPO_REDE?chave=secret-token'
        );
    }

    public function test_it_requires_an_empresa_codigo(): void
    {
        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/produto-subgrupos')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('empresa_codigo');
    }
}
