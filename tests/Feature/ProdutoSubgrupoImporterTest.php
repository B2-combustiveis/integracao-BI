<?php

namespace Tests\Feature;

use App\Services\WebPosto\ProdutoSubgrupoImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProdutoSubgrupoImporterTest extends TestCase
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
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('grupoCodigo');
            $table->unique(['empresaCodigo', 'grupoCodigo']);
        });
        Schema::connection('webposto')->create('produto_subgrupos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('grupoCodigo');
            $table->unsignedBigInteger('subGrupoCodigo');
            $table->string('descricao')->nullable();
            $table->json('produtoSubGrupo2')->nullable();
            $table->timestamps();
            $table->unique(['empresaCodigo', 'grupoCodigo', 'subGrupoCodigo']);
        });

        DB::connection('webposto')->table('produto_grupos')->insert([
            'empresaCodigo' => 4604,
            'grupoCodigo' => 15449,
        ]);
    }

    public function test_it_inserts_checks_and_updates_subgroups_linked_to_their_group(): void
    {
        $importer = app(ProdutoSubgrupoImporter::class);

        $first = $importer->import($this->payload('AR'), 4604);
        $second = $importer->import($this->payload('AR'), 4604);
        $third = $importer->import($this->payload('AR CONDICIONADO'), 4604);

        $this->assertSame(1, $first['inserted']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertSame(1, $third['updated']);
        $this->assertDatabaseHas('produto_subgrupos', [
            'empresaCodigo' => 4604,
            'grupoCodigo' => 15449,
            'subGrupoCodigo' => 5,
            'descricao' => 'AR CONDICIONADO',
            'produtoSubGrupo2' => '[]',
        ], 'webposto');
    }

    public function test_it_skips_a_subgroup_when_its_parent_group_is_missing(): void
    {
        $result = app(ProdutoSubgrupoImporter::class)->import([[
            'subGrupoCodigo' => 99,
            'descricao' => 'SEM GRUPO',
            'grupoCodigo' => 99999,
            'produtoSubGrupo2' => [],
        ]], 4604);

        $this->assertSame(1, $result['missing_parent_group']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, DB::connection('webposto')->table('produto_subgrupos')->count());
    }

    private function payload(string $descricao): array
    {
        return [[
            'subGrupoCodigo' => 5,
            'descricao' => $descricao,
            'grupoCodigo' => 15449,
            'produtoSubGrupo2' => [],
        ]];
    }
}
