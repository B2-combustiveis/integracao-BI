<?php

namespace Tests\Feature;

use App\Services\WebPosto\ClienteGrupoImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ClienteGrupoImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.webposto' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('webposto');
        Schema::connection('webposto')->create('cliente_grupos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('grupoCodigo');
            $table->string('grupoCodigoExterno')->nullable();
            $table->string('descricao')->nullable();
            $table->boolean('usaLimiteLitros')->nullable();
            $table->decimal('limiteLitros', 18, 3)->nullable();
            $table->decimal('limiteLitrosDisponivel', 18, 3)->nullable();
            $table->boolean('usaLimiteReais')->nullable();
            $table->decimal('limiteReais', 15, 2)->nullable();
            $table->decimal('limiteReaisDisponivel', 15, 2)->nullable();
            $table->boolean('bloqueadoFinanceiroVencido')->nullable();
            $table->unsignedInteger('diasTolerancia')->nullable();
            $table->unsignedBigInteger('codigo')->nullable();
            $table->timestamps();
        });
    }

    public function test_it_inserts_checks_and_updates_customer_groups_by_company(): void
    {
        $importer = app(ClienteGrupoImporter::class);
        $first = $importer->import($this->payload('CORRENTISTA'), 4604);
        $second = $importer->import($this->payload('CORRENTISTA'), 4604);
        $third = $importer->import($this->payload('CORRENTISTA ATIVO'), 4604);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(1, $third['updated']);
        $this->assertDatabaseHas('cliente_grupos', [
            'empresaCodigo' => 4604,
            'grupoCodigo' => 530,
            'descricao' => 'CORRENTISTA ATIVO',
        ], 'webposto');
    }

    private function payload(string $descricao): array
    {
        return ['ultimoCodigo' => 530, 'resultados' => [[
            'grupoCodigo' => 530,
            'grupoCodigoExterno' => null,
            'descricao' => $descricao,
            'usaLimiteLitros' => false,
            'limiteLitros' => 0.0,
            'limiteLitrosDisponivel' => 0.0,
            'usaLimiteReais' => false,
            'limiteReais' => 0.0,
            'limiteReaisDisponivel' => 0.0,
            'bloqueadoFinanceiroVencido' => false,
            'diasTolerancia' => null,
            'codigo' => 530,
        ]]];
    }
}
