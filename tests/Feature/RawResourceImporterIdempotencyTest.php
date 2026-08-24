<?php

namespace Tests\Feature;

use App\Services\WebPosto\RawResourceImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class RawResourceImporterIdempotencyTest extends TestCase
{
    private array $originalWebPostoConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalWebPostoConnection = config('database.connections.webposto');
        config(['database.connections.webposto' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('webposto');
        Schema::connection('webposto')->create('vendas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('vendaCodigo');
            $table->unsignedBigInteger('codigo');
            $table->dateTime('dataHora')->nullable();
            $table->timestamps();
        });
        Schema::connection('webposto')->create('lmcs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('lmcCodigo');
            $table->unsignedBigInteger('produtoLmcCodigo');
            $table->dateTime('dataMovimento')->nullable();
            $table->dateTime('dataHoraAtualizacao')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('webposto');
        config(['database.connections.webposto' => $this->originalWebPostoConnection]);

        parent::tearDown();
    }

    public function test_row_without_the_complete_natural_key_is_skipped(): void
    {
        $payload = ['resultados' => [[
            'empresaCodigo' => 4604,
            'codigo' => 10,
            'dataHora' => '2026-08-21 10:00:00',
        ]]];

        $result = app(RawResourceImporter::class)->import($payload, 4604, 'vendas', []);

        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, DB::connection('webposto')->table('vendas')->count());
    }

    public function test_duplicate_lmc_rows_from_the_api_are_consolidated_by_company_and_code(): void
    {
        $row = [
            'empresaCodigo' => 4604,
            'lmcCodigo' => 2595102,
            'produtoLmcCodigo' => 4243,
            'dataMovimento' => '2021-09-27',
            'dataHoraAtualizacao' => '2021-10-27 14:30:15',
        ];
        $payload = ['resultados' => [$row, $row]];

        $first = app(RawResourceImporter::class)->import($payload, 4604, 'lmcs', []);
        $second = app(RawResourceImporter::class)->import($payload, 4604, 'lmcs', []);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $first['skipped']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, DB::connection('webposto')->table('lmcs')->count());
    }

    public function test_repeated_rows_and_reprocessed_pages_do_not_duplicate_sales(): void
    {
        $payload = ['ultimoCodigo' => 10, 'resultados' => [
            ['empresaCodigo' => 4604, 'vendaCodigo' => 10, 'codigo' => 10, 'dataHora' => '2026-08-21 10:00:00'],
            ['empresaCodigo' => 4604, 'vendaCodigo' => 10, 'codigo' => 10, 'dataHora' => '2026-08-21 10:00:00'],
        ]];
        $importer = app(RawResourceImporter::class);

        $first = $importer->import($payload, 4604, 'vendas', []);
        $second = $importer->import($payload, 4604, 'vendas', []);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(0, $second['inserted']);
        $this->assertSame(1, DB::connection('webposto')->table('vendas')->count());
    }
}
