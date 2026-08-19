<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDatabaseBearerToken;
use App\Services\WebPosto\ProdutoEmpresaImporter;
use App\Services\WebPosto\WebPostoCredentialData;
use App\Services\WebPosto\WebPostoCredentialResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebPostoProdutoEmpresasTest extends TestCase
{
    public function test_it_fetches_company_product_data_with_the_requested_limit(): void
    {
        $resolver = $this->mock(WebPostoCredentialResolver::class);
        $resolver->shouldReceive('resolve')->once()->with(4604)
            ->andReturn(new WebPostoCredentialData(1, 'https://webposto.test', 'secret-token'));
        $resolver->shouldReceive('markAsUsed')->once()->with(1);

        $importer = $this->mock(ProdutoEmpresaImporter::class);
        $importer->shouldReceive('import')->once()->andReturn([
            'database' => 'webposto',
            'table' => 'produto_empresas',
            'sync_status' => 'synchronized',
            'received' => 1,
            'inserted' => 1,
            'updated' => 0,
            'unchanged' => 0,
            'missing_parent_product' => 0,
            'skipped' => 0,
        ]);

        Http::fake(['*' => Http::response([
            'ultimoCodigo' => 1103666,
            'resultados' => [[
                'empresaCodigo' => 4604,
                'produtoCodigo' => 1103666,
            ]],
        ])]);

        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/produto-empresas?empresa_codigo=4604&limite=2000')
            ->assertOk()
            ->assertJsonPath('endpoint', '/INTEGRACAO/PRODUTO_EMPRESA')
            ->assertJsonPath('limite', 2000)
            ->assertJsonPath('storage.table', 'produto_empresas');

        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://webposto.test/INTEGRACAO/PRODUTO_EMPRESA?chave=secret-token&limite=2000'
        );
    }

    public function test_it_rejects_a_limit_above_2000(): void
    {
        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/produto-empresas?empresa_codigo=4604&limite=2001')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limite');
    }
}
