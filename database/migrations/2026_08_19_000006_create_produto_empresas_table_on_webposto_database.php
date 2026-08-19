<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->create('produto_empresas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('produtoCodigo');
            $table->decimal('precoVenda', 15, 6)->nullable();
            $table->decimal('precoVendaB', 15, 6)->nullable();
            $table->decimal('precoVendaC', 15, 6)->nullable();
            $table->decimal('precoCusto', 15, 6)->nullable();
            $table->decimal('estoqueQtde', 18, 6)->nullable();
            $table->decimal('estoqueMin', 18, 6)->nullable();
            $table->string('ultimaAlteracao', 50)->nullable();
            $table->string('ultimoUsuarioAlteracao')->nullable();
            $table->unsignedBigInteger('produtoLmcCodigo')->nullable()->index();
            $table->boolean('ativo')->nullable();
            $table->unsignedBigInteger('estoquePadrao')->nullable();
            $table->decimal('fatorConversao', 18, 6)->nullable();
            $table->unsignedBigInteger('codigo')->nullable()->index();
            $table->timestamps();

            $table->unique(['empresaCodigo', 'produtoCodigo'], 'uq_produto_empresa_config');
            $table->foreign(['empresaCodigo', 'produtoCodigo'], 'fk_produto_empresa_produto')
                ->references(['empresaCodigo', 'produtoCodigo'])
                ->on('produtos')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('produto_empresas');
    }
};
