<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->create('produto_lmc_lmp', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('produtoLmcCodigo');
            $table->unsignedInteger('sequencia')->nullable();
            $table->string('descricao')->nullable();
            $table->string('tipoCombustivel', 10)->nullable();
            $table->string('geraLmcLmp', 1)->nullable();
            $table->timestamps();

            $table->unique(['empresaCodigo', 'produtoLmcCodigo'], 'uq_empresa_produto_lmc');
            $table->foreign('empresaCodigo', 'fk_produto_lmc_empresa')
                ->references('empresaCodigo')
                ->on('empresas')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('produto_lmc_lmp');
    }
};
