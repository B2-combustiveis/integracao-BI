<?php

namespace Tests\Feature;

use App\Services\WebPosto\AbastecimentoImporter;
use App\Services\WebPosto\CartaoImporter;
use App\Services\WebPosto\RawResourceImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class WebPostoZeroCodeExceptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.webposto' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('webposto');
        foreach (['venda_itens', 'vendas', 'administradoras', 'centros_custo'] as $name) {
            Schema::connection('webposto')->create($name, function (Blueprint $table) use ($name): void {
                $table->unsignedBigInteger('empresaCodigo')->nullable();
                if ($name === 'venda_itens') $table->unsignedBigInteger('vendaItemCodigo')->nullable();
                if ($name === 'vendas') $table->unsignedBigInteger('vendaCodigo')->nullable();
                if ($name === 'administradoras') $table->unsignedBigInteger('administradoraCodigo')->nullable();
                if ($name === 'centros_custo') $table->unsignedBigInteger('centroCustoCodigo')->nullable();
            });
        }
    }

    public function test_afericao_with_zero_sale_item_is_accepted_but_a_regular_fueling_is_not(): void
    {
        $raw = Mockery::mock(RawResourceImporter::class);
        $raw->shouldReceive('import')->once()->withArgs(fn ($payload) => count($payload['resultados']) === 1
            && $payload['resultados'][0]['afericao'] === true
            && $payload['resultados'][0]['vendaItemCodigo'] === null)->andReturn($this->stored(1));
        $result = (new AbastecimentoImporter($raw))->import(['resultados' => [
            ['empresaCodigo' => 4604, 'abastecimentoCodigo' => 1, 'vendaItemCodigo' => 0, 'afericao' => true],
            ['empresaCodigo' => 4604, 'abastecimentoCodigo' => 2, 'vendaItemCodigo' => 0, 'afericao' => false],
        ]], 4604, []);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['missing_parent_item']);
    }

    public function test_value_exchange_card_with_zero_sale_is_accepted_but_a_regular_card_is_not(): void
    {
        DB::connection('webposto')->table('administradoras')->insert(['empresaCodigo' => 4604, 'administradoraCodigo' => 10]);
        DB::connection('webposto')->table('centros_custo')->insert(['centroCustoCodigo' => 20]);
        $raw = Mockery::mock(RawResourceImporter::class);
        $raw->shouldReceive('import')->once()->withArgs(fn ($payload) => count($payload['resultados']) === 1
            && $payload['resultados'][0]['tipoInclusao'] === 'Troca de Valores'
            && $payload['resultados'][0]['vendaCodigo'] === null)->andReturn($this->stored(1));
        $result = (new CartaoImporter($raw))->import(['resultados' => [
            ['empresaCodigo' => 4604, 'cartaoCodigo' => 1, 'vendaCodigo' => 0, 'administradoraCodigo' => 10, 'centroCustoCodigo' => 20, 'tipoInclusao' => 'Troca de Valores'],
            ['empresaCodigo' => 4604, 'cartaoCodigo' => 2, 'vendaCodigo' => 0, 'administradoraCodigo' => 10, 'centroCustoCodigo' => 20, 'tipoInclusao' => 'Venda'],
        ]], 4604);
        $this->assertSame(1, $result['skipped']);
    }

    private function stored(int $inserted): array
    {
        return ['received' => $inserted, 'inserted' => $inserted, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
    }
}
