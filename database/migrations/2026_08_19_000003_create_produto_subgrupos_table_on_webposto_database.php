<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->create('produto_subgrupos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('grupoCodigo');
            $table->unsignedBigInteger('subGrupoCodigo');
            $table->string('descricao')->nullable();
            $table->json('produtoSubGrupo2')->nullable();
            $table->timestamps();

            $table->unique(
                ['empresaCodigo', 'grupoCodigo', 'subGrupoCodigo'],
                'uq_produto_subgrupo',
            );
            $table->foreign(['empresaCodigo', 'grupoCodigo'], 'fk_subgrupo_grupo')
                ->references(['empresaCodigo', 'grupoCodigo'])
                ->on('produto_grupos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('produto_subgrupos');
    }
};
