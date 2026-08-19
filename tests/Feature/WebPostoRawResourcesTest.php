<?php

namespace Tests\Feature;

use App\Services\WebPosto\RawResourceImporter;
use App\Services\WebPosto\WebPostoClient;
use App\Http\Middleware\EnsureDatabaseBearerToken;
use Illuminate\Http\Client\Response;
use Mockery\MockInterface;
use Tests\TestCase;

class WebPostoRawResourcesTest extends TestCase
{
    public function test_it_maps_parameters_and_stores_a_raw_resource(): void
    {
        $this->mock(WebPostoClient::class, function (MockInterface $mock): void {
            $response = new Response(new \GuzzleHttp\Psr7\Response(200, ['Content-Type' => 'application/json'], '{"resultados":[{"fornecedorCodigo":10,"nome":"Teste"}]}'));
            $mock->shouldReceive('get')->once()->with('/INTEGRACAO/FORNECEDOR', 4604, [])->andReturn([
                'response' => $response, 'duration_ms' => 1.2,
                'payload' => ['resultados' => [['fornecedorCodigo' => 10, 'nome' => 'Teste']]],
            ]);
        });
        $this->mock(RawResourceImporter::class, function (MockInterface $mock): void {
            $mock->shouldReceive('import')->once()->withArgs(fn ($payload, $empresa, $table, $parameters) =>
                $payload['resultados'][0]['fornecedorCodigo'] === 10 && $empresa === 4604
                && $table === 'fornecedores' && $parameters === []
            )->andReturn(['table' => 'fornecedores', 'inserted' => 1]);
        });

        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/fornecedores?empresa_codigo=4604')
            ->assertOk()->assertJsonPath('storage.table', 'fornecedores')->assertJsonPath('storage.inserted', 1);
    }

    public function test_it_validates_period_parameters_for_transactional_resources(): void
    {
        $this->withoutMiddleware(EnsureDatabaseBearerToken::class)
            ->getJson('/api/webposto/vendas?empresa_codigo=4604')
            ->assertUnprocessable()->assertJsonValidationErrors(['data_inicial', 'data_final']);
    }

    public function test_catalog_and_migration_have_the_same_tables(): void
    {
        $catalog = app(\App\Services\WebPosto\WebPostoResourceCatalog::class)->all();
        foreach (array_unique(array_column($catalog, 'table')) as $table) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::connection('webposto')->hasTable($table), $table);
            foreach (['payload', 'record_hash', 'request_parameters', 'credencialEmpresaCodigo'] as $technical) {
                $this->assertFalse(\Illuminate\Support\Facades\Schema::connection('webposto')->hasColumn($table, $technical), "{$table}.{$technical}");
            }
        }
    }

    public function test_natural_keys_use_the_webposto_business_identifier(): void
    {
        $resolver = app(\App\Services\WebPosto\RawNaturalKeyResolver::class);
        $this->assertSame(
            ['empresaCodigo' => 4604, 'fornecedorCodigo' => 15],
            $resolver->criteria('fornecedores', ['empresaCodigo' => 4604, 'fornecedorCodigo' => 15, 'razao' => 'Teste']),
        );
    }
}
