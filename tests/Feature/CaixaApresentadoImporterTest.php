<?php

namespace Tests\Feature;

use App\Services\WebPosto\CaixaApresentadoImporter;
use App\Services\WebPosto\RawResourceImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class CaixaApresentadoImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.webposto' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('webposto');
        Schema::connection('webposto')->create('caixas', function (Blueprint $table): void {
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('caixaCodigo');
        });
        DB::connection('webposto')->table('caixas')->insert([
            'empresaCodigo' => 4604,
            'caixaCodigo' => 100,
        ]);
    }

    public function test_it_only_accepts_presented_cash_registers_with_a_parent_from_the_company(): void
    {
        $raw = Mockery::mock(RawResourceImporter::class);
        $raw->shouldReceive('import')->once()->withArgs(fn ($payload, $empresa, $table) =>
            $empresa === 4604 && $table === 'caixas_apresentados'
            && array_column($payload['resultados'], 'caixaCodigo') === [100]
        )->andReturn(['received' => 1, 'inserted' => 1, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0]);
        $result = (new CaixaApresentadoImporter($raw))->import(['resultados' => [
            ['empresaCodigo' => 4604, 'caixaCodigo' => 100],
            ['empresaCodigo' => 4604, 'caixaCodigo' => 999],
            ['empresaCodigo' => 9001, 'caixaCodigo' => 100],
        ]], 4604);

        $this->assertSame(3, $result['received']);
        $this->assertSame(2, $result['skipped']);
        $this->assertSame(1, $result['missing_parent_caixa']);
    }
}
