<?php

namespace Tests\Feature;

use App\Services\WebPosto\LmcImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LmcImporterTest extends TestCase
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
        });
        Schema::connection('webposto')->create('lmcs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('lmcCodigo');
            $table->unsignedBigInteger('produtoLmcCodigo');
            $table->timestamps();
            $table->unique(['empresaCodigo', 'lmcCodigo']);
        });
        DB::connection('webposto')->table('produto_lmc_lmp')->insert([
            'empresaCodigo' => 4604,
            'produtoLmcCodigo' => 4238,
        ]);
    }

    public function test_it_only_imports_lmcs_with_a_valid_product_from_the_requested_company(): void
    {
        $result = app(LmcImporter::class)->import(['resultados' => [
            ['empresaCodigo' => 4604, 'lmcCodigo' => 100, 'produtoLmcCodigo' => 4238],
            ['empresaCodigo' => 4604, 'lmcCodigo' => 101, 'produtoLmcCodigo' => 9999],
            ['empresaCodigo' => 9999, 'lmcCodigo' => 102, 'produtoLmcCodigo' => 4238],
        ]], 4604);

        $this->assertSame(3, $result['received']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(2, $result['skipped']);
        $this->assertDatabaseHas('lmcs', [
            'empresaCodigo' => 4604,
            'lmcCodigo' => 100,
            'produtoLmcCodigo' => 4238,
        ], 'webposto');
        $this->assertSame(1, DB::connection('webposto')->table('lmcs')->count());
    }
}
