<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureDatabaseBearerToken;
use App\Services\WebPosto\ProdutoImporter;
use App\Services\WebPosto\WebPostoCredentialData;
use App\Services\WebPosto\WebPostoCredentialResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebPostoProdutosTest extends TestCase
{
    public function test_it_sends_the_requested_limit_and_stores_products(): void
    {
        $resolver = $this->mock(WebPostoCredentialResolver::class);
        $resolver->shouldReceive('resolve')->once()->with(4604)
            ->andReturn(new WebPostoCredentialData(1, 'https://webposto.test', 'secret-token'));
        $resolver->shouldReceive('markAsUsed')->once()->with(1);

        $importer = $this->mock(ProdutoImporter::class);
        $importer->shouldReceive('import')->once()->withArgs(fn (mixed $payload, int $empresaCodigo): bool =>
            $payload['resultados'][0]['produtoCodigo'] === 1103666 && $empresaCodigo === 4604
        )->andReturn([
            'database' => 'webposto',
            'table' => 'produtos',
            'sync_status' => 'synchronized',
            'received' => 1,
            'inserted' => 1,
            'updated' => 0,
            'unchanged' => 0,
            'missing_parent_group' => 0,
            'missing_parent_subgroup' => 0,
            'skipped' => 0,
        ]);

        Http::fake(['*' => Http::response([
            'ultimoCodigo' => 1103666,
            'resultados' => [[
                'produtoCodigo' => 1103666,
                'grupoCodigo' => 15446,
                'nome' => 'GASOLINA C COMUM',
            ]],
        ])]);

        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/produtos?empresa_codigo=4604&limite=1000')
            ->assertOk()
            ->assertJsonPath('endpoint', '/INTEGRACAO/PRODUTO')
            ->assertJsonPath('limite', 1000)
            ->assertJsonPath('ultimo_codigo', 1103666)
            ->assertJsonPath('storage.table', 'produtos');

        Http::assertSent(fn (Request $request): bool =>
            $request->url() === 'https://webposto.test/INTEGRACAO/PRODUTO?chave=secret-token&limite=1000'
        );
    }

    public function test_it_validates_the_limit(): void
    {
        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/produtos?empresa_codigo=4604&limite=1001')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('limite');
    }
}
