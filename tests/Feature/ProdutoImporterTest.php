<?php

namespace Tests\Feature;

use App\Services\WebPosto\ProdutoImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProdutoImporterTest extends TestCase
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

        Schema::connection('webposto')->create('produto_grupos', function (Blueprint $table): void {
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('grupoCodigo');
        });
        Schema::connection('webposto')->create('produtos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('produtoCodigo');
            foreach (['nome', 'referenciaCodigo', 'tipoCombustivel', 'unidadeCompra', 'unidadeVenda', 'produtoCodigoExterno', 'tipoProduto', 'descricaoFabricante', 'registraInventario', 'ncm', 'cest', 'codigoAnp'] as $column) {
                $table->string($column)->nullable();
            }
            foreach (['grupoCodigo', 'produtoLmcCodigo', 'subGrupo1Codigo', 'subGrupo2Codigo', 'subGrupo3Codigo', 'codigo'] as $column) {
                $table->unsignedBigInteger($column)->nullable();
            }
            foreach (['tributacaoAdRem', 'misturaBioCombustivel', 'percUfOrigemScanc'] as $column) {
                $table->decimal($column, 15, 6)->nullable();
            }
            $table->boolean('combustivel')->nullable();
            $table->json('produtoCodigoBarra')->nullable();
            $table->boolean('ativo')->nullable();
            $table->timestamps();
        });
        Schema::connection('webposto')->create('produto_subgrupos', function (Blueprint $table): void {
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('grupoCodigo');
            $table->unsignedBigInteger('subGrupoCodigo');
        });

        DB::connection('webposto')->table('produto_grupos')->insert([
            'empresaCodigo' => 4604,
            'grupoCodigo' => 15446,
        ]);
        DB::connection('webposto')->table('produto_subgrupos')->insert([
            'empresaCodigo' => 4604,
            'grupoCodigo' => 15446,
            'subGrupoCodigo' => 5,
        ]);
    }

    public function test_it_inserts_checks_and_updates_products(): void
    {
        $importer = app(ProdutoImporter::class);

        $first = $importer->import($this->payload('GASOLINA C COMUM'), 4604);
        DB::connection('webposto')->table('produtos')->update([
            'produtoCodigoBarra' => '[{"codigoBarra": "7890000000000"}]',
        ]);
        $second = $importer->import($this->payload('GASOLINA C COMUM'), 4604);
        $third = $importer->import($this->payload('GASOLINA COMUM'), 4604);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(1, $third['updated']);
        $this->assertDatabaseHas('produtos', [
            'empresaCodigo' => 4604,
            'produtoCodigo' => 1103666,
            'grupoCodigo' => 15446,
            'subGrupo1Codigo' => 5,
            'nome' => 'GASOLINA COMUM',
            'produtoCodigoBarra' => '[{"codigoBarra":"7890000000000"}]',
        ], 'webposto');
    }

    public function test_it_skips_a_product_with_an_unknown_subgroup(): void
    {
        $payload = $this->payload('PRODUTO SEM SUBGRUPO');
        $payload['resultados'][0]['subGrupo1Codigo'] = 999;

        $result = app(ProdutoImporter::class)->import($payload, 4604);

        $this->assertSame(1, $result['missing_parent_subgroup']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, DB::connection('webposto')->table('produtos')->count());
    }

    private function payload(string $nome): array
    {
        return ['ultimoCodigo' => 1103666, 'resultados' => [[
            'produtoCodigo' => 1103666,
            'nome' => $nome,
            'referenciaCodigo' => '000001',
            'grupoCodigo' => 15446,
            'combustivel' => true,
            'produtoLmcCodigo' => 4238,
            'tipoCombustivel' => 'GASOLINA',
            'unidadeCompra' => 'L',
            'unidadeVenda' => 'L',
            'subGrupo1Codigo' => 5,
            'subGrupo2Codigo' => null,
            'subGrupo3Codigo' => null,
            'produtoCodigoExterno' => null,
            'tributacaoAdRem' => 1.57,
            'tipoProduto' => 'C',
            'descricaoFabricante' => null,
            'registraInventario' => 'S',
            'ncm' => '27101259',
            'cest' => '0600201',
            'misturaBioCombustivel' => 27.0,
            'codigoAnp' => '320102001',
            'percUfOrigemScanc' => 0.0,
            'produtoCodigoBarra' => [['codigoBarra' => '7890000000000']],
            'codigo' => 1103666,
            'ativo' => null,
        ]]];
    }
}
