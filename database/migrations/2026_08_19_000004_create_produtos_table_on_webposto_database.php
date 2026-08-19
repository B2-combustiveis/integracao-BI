<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->create('produtos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('produtoCodigo');
            $table->string('nome')->nullable();
            $table->string('referenciaCodigo', 100)->nullable();
            $table->unsignedBigInteger('grupoCodigo');
            $table->boolean('combustivel')->nullable();
            $table->unsignedBigInteger('produtoLmcCodigo')->nullable()->index();
            $table->string('tipoCombustivel', 100)->nullable();
            $table->string('unidadeCompra', 10)->nullable();
            $table->string('unidadeVenda', 10)->nullable();
            $table->unsignedBigInteger('subGrupo1Codigo')->nullable()->index();
            $table->unsignedBigInteger('subGrupo2Codigo')->nullable()->index();
            $table->unsignedBigInteger('subGrupo3Codigo')->nullable()->index();
            $table->string('produtoCodigoExterno', 100)->nullable()->index();
            $table->decimal('tributacaoAdRem', 15, 6)->nullable();
            $table->string('tipoProduto', 10)->nullable();
            $table->string('descricaoFabricante')->nullable();
            $table->string('registraInventario', 10)->nullable();
            $table->string('ncm', 20)->nullable();
            $table->string('cest', 20)->nullable();
            $table->decimal('misturaBioCombustivel', 15, 6)->nullable();
            $table->string('codigoAnp', 30)->nullable();
            $table->decimal('percUfOrigemScanc', 15, 6)->nullable();
            $table->json('produtoCodigoBarra')->nullable();
            $table->unsignedBigInteger('codigo')->nullable()->index();
            $table->boolean('ativo')->nullable();
            $table->timestamps();

            $table->unique(['empresaCodigo', 'produtoCodigo'], 'uq_produto_empresa');
            $table->foreign(['empresaCodigo', 'grupoCodigo'], 'fk_produto_grupo')
                ->references(['empresaCodigo', 'grupoCodigo'])
                ->on('produto_grupos')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('produtos');
    }
};
