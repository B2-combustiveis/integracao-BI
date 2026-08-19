<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->create('clientes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('clienteCodigo');
            $table->string('clienteReferencia', 100)->nullable();
            $table->string('razao')->nullable();
            $table->string('fantasia')->nullable();
            $table->string('cnpjCpf', 30)->nullable()->index();
            $table->date('dataCadastro')->nullable();
            $table->string('cidade', 150)->nullable();
            $table->unsignedBigInteger('codigoCidade')->nullable();
            $table->string('bairro')->nullable();
            $table->string('numero', 50)->nullable();
            $table->string('logradouro')->nullable();
            $table->string('tipoLogradouro', 80)->nullable();
            $table->string('uf', 2)->nullable();
            $table->boolean('usaLimiteLitros')->nullable();
            $table->decimal('limiteLitros', 18, 3)->nullable();
            $table->boolean('usaLimiteReais')->nullable();
            $table->decimal('limiteReais', 15, 2)->nullable();
            $table->boolean('bloqueado')->nullable();
            $table->string('ultimoUsuarioAlteracao')->nullable();
            $table->string('dataHoraAtualizacao', 50)->nullable();
            $table->unsignedBigInteger('clienteGrupoCodigo')->nullable();
            $table->string('clienteCodigoExterno', 100)->nullable()->index();
            $table->string('telefone', 50)->nullable();
            $table->string('celular', 50)->nullable();
            $table->string('outroTelefone', 50)->nullable();
            $table->text('observacoes')->nullable();
            $table->json('centroCustoVeiculo')->nullable();
            $table->json('clienteContato')->nullable();
            $table->string('website')->nullable();
            $table->string('complemento')->nullable();
            $table->string('cep', 20)->nullable();
            $table->string('pais', 100)->nullable();
            $table->string('inscricaoEstadual', 50)->nullable();
            $table->string('inscricaoMunicipal', 50)->nullable();
            $table->string('rg', 50)->nullable();
            $table->json('frota')->nullable();
            $table->json('faturamento')->nullable();
            $table->json('limitesBloqueios')->nullable();
            $table->unsignedBigInteger('codigo')->nullable()->index();
            $table->timestamps();

            $table->unique(['empresaCodigo', 'clienteCodigo'], 'uq_empresa_cliente');
            $table->foreign('empresaCodigo', 'fk_cliente_empresa')
                ->references('empresaCodigo')->on('empresas')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign(['empresaCodigo', 'clienteGrupoCodigo'], 'fk_cliente_grupo')
                ->references(['empresaCodigo', 'grupoCodigo'])->on('cliente_grupos')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('clientes');
    }
};
