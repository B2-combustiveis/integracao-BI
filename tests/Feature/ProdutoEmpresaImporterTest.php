<?php

namespace Tests\Feature;

use App\Services\WebPosto\ProdutoEmpresaImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProdutoEmpresaImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.webposto' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('webposto');

        Schema::connection('webposto')->create('produtos', function (Blueprint $table): void {
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('produtoCodigo');
        });
        Schema::connection('webposto')->create('produto_empresas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('produtoCodigo');
            foreach (['precoVenda', 'precoVendaB', 'precoVendaC', 'precoCusto', 'estoqueQtde', 'estoqueMin', 'fatorConversao'] as $column) {
                $table->decimal($column, 18, 6)->nullable();
            }
            $table->string('ultimaAlteracao')->nullable();
            $table->string('ultimoUsuarioAlteracao')->nullable();
            $table->unsignedBigInteger('produtoLmcCodigo')->nullable();
            $table->boolean('ativo')->nullable();
            $table->unsignedBigInteger('estoquePadrao')->nullable();
            $table->unsignedBigInteger('codigo')->nullable();
            $table->timestamps();
        });

        DB::connection('webposto')->table('produtos')->insert([
            'empresaCodigo' => 4604,
            'produtoCodigo' => 1103666,
        ]);
    }

    public function test_it_inserts_checks_and_updates_company_product_data(): void
    {
        $importer = app(ProdutoEmpresaImporter::class);

        $first = $importer->import($this->payload(5.89));
        $second = $importer->import($this->payload(5.89));
        $third = $importer->import($this->payload(6.09));

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(1, $third['updated']);
        $this->assertDatabaseHas('produto_empresas', [
            'empresaCodigo' => 4604,
            'produtoCodigo' => 1103666,
            'precoVenda' => 6.09,
            'estoqueQtde' => 18622.729,
            'ativo' => true,
        ], 'webposto');
    }

    public function test_it_skips_configuration_for_an_unknown_product(): void
    {
        $payload = $this->payload(5.89);
        $payload['resultados'][0]['produtoCodigo'] = 999999;

        $result = app(ProdutoEmpresaImporter::class)->import($payload);

        $this->assertSame(1, $result['missing_parent_product']);
        $this->assertSame(1, $result['skipped']);
    }

    private function payload(float $precoVenda): array
    {
        return ['ultimoCodigo' => 1103666, 'resultados' => [[
            'empresaCodigo' => 4604,
            'produtoCodigo' => 1103666,
            'precoVenda' => $precoVenda,
            'precoVendaB' => 6.89,
            'precoVendaC' => 0.0,
            'precoCusto' => 5.541452,
            'estoqueQtde' => 18622.729,
            'estoqueMin' => 0.0,
            'ultimaAlteracao' => '2026-08-08T11:24:39.452-03:00',
            'ultimoUsuarioAlteracao' => 'FLAVIA',
            'produtoLmcCodigo' => 4238,
            'ativo' => true,
            'estoquePadrao' => 1,
            'fatorConversao' => null,
            'codigo' => 1103666,
        ]]];
    }
}
