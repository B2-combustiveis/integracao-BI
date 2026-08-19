<?php

namespace Tests\Feature;

use App\Services\WebPosto\ProdutoGrupoImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProdutoGrupoImporterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.webposto' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::purge('webposto');

        Schema::connection('webposto')->create('produto_grupos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('grupoCodigo');
            $table->string('nome')->nullable();
            $table->string('ultimoUsuarioAlteracao')->nullable();
            $table->string('grupoCodigoExterno')->nullable();
            $table->string('codigoTributoIcms')->nullable();
            $table->string('codigoTributoPisCofins')->nullable();
            $table->string('descricaoTributoIcms')->nullable();
            $table->string('descricaoTributoPisCofins')->nullable();
            $table->string('tipoGrupo')->nullable();
            $table->unsignedBigInteger('codigo')->nullable();
            $table->timestamps();
            $table->unique(['empresaCodigo', 'grupoCodigo']);
        });
    }

    public function test_it_inserts_checks_and_updates_groups_inside_the_company_scope(): void
    {
        $importer = app(ProdutoGrupoImporter::class);

        $first = $importer->import($this->payload('COMBUSTIVEIS'), 4604);
        $second = $importer->import($this->payload('COMBUSTIVEIS'), 4604);
        $third = $importer->import($this->payload('COMBUSTÍVEIS'), 4604);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(1, $third['updated']);
        $this->assertDatabaseHas('produto_grupos', [
            'empresaCodigo' => 4604,
            'grupoCodigo' => 15446,
            'nome' => 'COMBUSTÍVEIS',
            'tipoGrupo' => 'Pista',
        ], 'webposto');
    }

    private function payload(string $nome): array
    {
        return ['resultados' => [[
            'grupoCodigo' => 15446,
            'nome' => $nome,
            'ultimoUsuarioAlteracao' => 'PYLAR',
            'grupoCodigoExterno' => null,
            'codigoTributoIcms' => null,
            'codigoTributoPisCofins' => null,
            'descricaoTributoIcms' => null,
            'descricaoTributoPisCofins' => null,
            'tipoGrupo' => 'Pista',
            'codigo' => 15446,
        ]]];
    }
}
