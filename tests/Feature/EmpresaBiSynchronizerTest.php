<?php

namespace Tests\Feature;

use App\Services\Bi\EmpresaBiSynchronizer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmpresaBiSynchronizerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.bi' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('bi');

        Schema::connection('bi')->create('dim_empresas', function (Blueprint $table): void {
            $table->id('empresa_id');
            $table->unsignedBigInteger('empresa_codigo')->unique();
            $table->string('cnpj')->nullable();
            $table->string('razao_social')->nullable();
            $table->string('nome_fantasia')->nullable();
            $table->string('logradouro')->nullable();
            $table->string('numero')->nullable();
            $table->string('bairro')->nullable();
            $table->string('cep')->nullable();
            $table->string('cidade')->nullable();
            $table->string('estado')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('codigo_externo')->nullable();
            $table->string('sigla')->nullable();
            $table->boolean('ativo');
            $table->timestamp('sincronizado_em');
            $table->timestamps();
        });
    }

    public function test_it_normalizes_inserts_checks_and_updates_bi_companies(): void
    {
        $service = app(EmpresaBiSynchronizer::class);
        $payload = $this->payload('  Posto   Chimba  ');

        $first = $service->sync($payload);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame('synchronized', $first['sync_status']);
        $this->assertDatabaseHas('dim_empresas', [
            'empresa_codigo' => 4604,
            'cnpj' => '04309086000190',
            'nome_fantasia' => 'Posto Chimba',
            'cep' => '80000000',
            'estado' => 'PR',
        ], 'bi');

        $second = $service->sync($payload);

        $this->assertSame(1, $second['unchanged']);
        $this->assertSame('already_synchronized', $second['sync_status']);

        $third = $service->sync($this->payload('Posto Chimba Atualizado'));

        $this->assertSame(1, $third['updated']);
        $this->assertDatabaseHas('dim_empresas', [
            'empresa_codigo' => 4604,
            'nome_fantasia' => 'Posto Chimba Atualizado',
        ], 'bi');
    }

    private function payload(string $fantasia): array
    {
        return ['resultados' => [[
            'empresaCodigo' => 4604,
            'cnpj' => '04.309.086/0001-90',
            'razao' => '  POSTO CHIMBA LTDA ',
            'fantasia' => $fantasia,
            'logradouro' => ' Rua   Principal ',
            'numero' => '100',
            'bairro' => 'Centro',
            'cep' => '80000-000',
            'cidade' => 'Curitiba',
            'estado' => 'pr',
            'latitude' => '-25.4284',
            'longitude' => '-49.2733',
            'empresaCodigoExterno' => 'EXT-4604',
            'sigla' => 'pc',
        ]]];
    }
}
