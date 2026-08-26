<?php

namespace Tests\Feature;

use App\Services\WebPosto\EstoquePeriodoImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EstoquePeriodoImporterTest extends TestCase
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
        Schema::connection('webposto')->create('estoque_periodos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('codigoUnidadeNegocio');
            $table->unsignedBigInteger('codigo');
            $table->unsignedBigInteger('codigoProduto');
            $table->timestamps();
            $table->unique(['codigoUnidadeNegocio', 'codigo']);
        });
        DB::connection('webposto')->table('produtos')->insert([
            'empresaCodigo' => 4604,
            'produtoCodigo' => 1103666,
        ]);
    }

    public function test_it_only_imports_stock_with_a_valid_product_from_the_requested_unit(): void
    {
        $result = app(EstoquePeriodoImporter::class)->import(['resultados' => [
            ['codigoUnidadeNegocio' => 4604, 'codigo' => 100, 'codigoProduto' => 1103666],
            ['codigoUnidadeNegocio' => 4604, 'codigo' => 101, 'codigoProduto' => 9999],
            ['codigoUnidadeNegocio' => 9999, 'codigo' => 102, 'codigoProduto' => 1103666],
        ]], 4604);

        $this->assertSame(3, $result['received']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(2, $result['skipped']);
        $this->assertDatabaseHas('estoque_periodos', [
            'codigoUnidadeNegocio' => 4604,
            'codigo' => 100,
            'codigoProduto' => 1103666,
        ], 'webposto');
        $this->assertSame(1, DB::connection('webposto')->table('estoque_periodos')->count());
    }
}
