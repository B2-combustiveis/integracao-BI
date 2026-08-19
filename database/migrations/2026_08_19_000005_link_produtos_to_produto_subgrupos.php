<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->table('produtos', function (Blueprint $table): void {
            $table->index(
                ['empresaCodigo', 'grupoCodigo', 'subGrupo1Codigo'],
                'idx_produto_subgrupo',
            );
            $table->foreign(
                ['empresaCodigo', 'grupoCodigo', 'subGrupo1Codigo'],
                'fk_produto_subgrupo',
            )
                ->references(['empresaCodigo', 'grupoCodigo', 'subGrupoCodigo'])
                ->on('produto_subgrupos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('produtos', function (Blueprint $table): void {
            $table->dropForeign('fk_produto_subgrupo');
            $table->dropIndex('idx_produto_subgrupo');
        });
    }
};
