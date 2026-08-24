<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        DB::connection($this->connection)->statement(
            'DELETE older FROM caixas older JOIN caixas newer ON newer.empresaCodigo = older.empresaCodigo AND newer.caixaCodigo = older.caixaCodigo AND newer.id > older.id'
        );
        DB::connection($this->connection)->statement(
            'DELETE older FROM caixas_apresentados older JOIN caixas_apresentados newer ON newer.empresaCodigo = older.empresaCodigo AND newer.caixaCodigo = older.caixaCodigo AND newer.id > older.id'
        );
        DB::connection($this->connection)->statement(
            'DELETE child FROM caixas_apresentados child LEFT JOIN caixas parent ON parent.empresaCodigo = child.empresaCodigo AND parent.caixaCodigo = child.caixaCodigo WHERE parent.id IS NULL'
        );

        Schema::connection($this->connection)->table('caixas', function (Blueprint $table): void {
            $table->unique(['empresaCodigo', 'caixaCodigo'], 'uq_caixa_empresa_codigo');
        });
        Schema::connection($this->connection)->table('caixas_apresentados', function (Blueprint $table): void {
            $table->unique(['empresaCodigo', 'caixaCodigo'], 'uq_caixa_apresentado_empresa_codigo');
            $table->foreign(['empresaCodigo', 'caixaCodigo'], 'fk_caixa_apresentado_caixa')
                ->references(['empresaCodigo', 'caixaCodigo'])->on('caixas')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('caixas_apresentados', function (Blueprint $table): void {
            $table->dropForeign('fk_caixa_apresentado_caixa');
            $table->dropUnique('uq_caixa_apresentado_empresa_codigo');
        });
        Schema::connection($this->connection)->table('caixas', function (Blueprint $table): void {
            $table->dropUnique('uq_caixa_empresa_codigo');
        });
    }
};
