<?php

namespace Tests\Feature;

use App\Services\WebPosto\ProdutoLmcLmpImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProdutoLmcLmpImporterTest extends TestCase
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

        Schema::connection('webposto')->create('produto_lmc_lmp', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('produtoLmcCodigo');
            $table->unsignedInteger('sequencia')->nullable();
            $table->string('descricao')->nullable();
            $table->string('tipoCombustivel')->nullable();
            $table->string('geraLmcLmp')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_inserts_checks_and_updates_lmc_lmp_products_by_company(): void
    {
        $importer = app(ProdutoLmcLmpImporter::class);

        $first = $importer->import($this->payload('GASOLINA C COMUM'), 4604);
        $second = $importer->import($this->payload('GASOLINA C COMUM'), 4604);
        $third = $importer->import($this->payload('GASOLINA COMUM'), 4604);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(1, $third['updated']);
        $this->assertDatabaseHas('produto_lmc_lmp', [
            'empresaCodigo' => 4604,
            'produtoLmcCodigo' => 4238,
            'descricao' => 'GASOLINA COMUM',
            'geraLmcLmp' => 'S',
        ], 'webposto');
    }

    private function payload(string $descricao): array
    {
        return [[
            'produtoLmcCodigo' => 4238,
            'sequencia' => 1,
            'descricao' => $descricao,
            'tipoCombustivel' => 'G',
            'geraLmcLmp' => 'S',
        ]];
    }
}
