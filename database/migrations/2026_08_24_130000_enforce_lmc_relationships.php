<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        $connection = DB::connection($this->connection);
        $connection->statement('DELETE FROM lmcs WHERE empresaCodigo IS NULL OR lmcCodigo IS NULL OR produtoLmcCodigo IS NULL');
        $connection->statement(
            'DELETE older FROM lmcs older JOIN lmcs newer ON newer.empresaCodigo = older.empresaCodigo AND newer.lmcCodigo = older.lmcCodigo AND (newer.dataHoraAtualizacao > older.dataHoraAtualizacao OR (newer.dataHoraAtualizacao = older.dataHoraAtualizacao AND newer.id > older.id))'
        );
        $connection->statement(
            'DELETE child FROM lmcs child LEFT JOIN produto_lmc_lmp parent ON parent.empresaCodigo = child.empresaCodigo AND parent.produtoLmcCodigo = child.produtoLmcCodigo WHERE parent.id IS NULL'
        );
        $connection->statement('ALTER TABLE lmcs MODIFY empresaCodigo BIGINT UNSIGNED NOT NULL, MODIFY lmcCodigo BIGINT UNSIGNED NOT NULL, MODIFY produtoLmcCodigo BIGINT UNSIGNED NOT NULL');

        Schema::connection($this->connection)->table('lmcs', function (Blueprint $table): void {
            $table->unique(['empresaCodigo', 'lmcCodigo'], 'uq_lmc_empresa_codigo');
            $table->index(['empresaCodigo', 'dataHoraAtualizacao', 'lmcCodigo'], 'idx_lmc_empresa_atualizacao_codigo');
            $table->foreign(['empresaCodigo', 'produtoLmcCodigo'], 'fk_lmc_produto_lmc')
                ->references(['empresaCodigo', 'produtoLmcCodigo'])->on('produto_lmc_lmp')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('lmcs', function (Blueprint $table): void {
            $table->dropForeign('fk_lmc_produto_lmc');
            $table->dropIndex('idx_lmc_empresa_atualizacao_codigo');
            $table->dropUnique('uq_lmc_empresa_codigo');
        });
        DB::connection($this->connection)->statement('ALTER TABLE lmcs MODIFY empresaCodigo BIGINT NULL, MODIFY lmcCodigo BIGINT NULL, MODIFY produtoLmcCodigo BIGINT NULL');
    }
};
