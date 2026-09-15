<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'alterdata';

    public function up(): void
    {
        Schema::connection($this->connection)->create('funcionarios', function (Blueprint $table): void {
            $table->id();
            $table->string('alterdata_id', 100)->unique();
            $table->string('empresa_alterdata_id', 100)->index();
            $table->string('externo_id', 100)->nullable()->index();
            $table->string('codigo', 50)->nullable()->index();
            $table->string('nome')->nullable()->index();
            $table->string('status', 50)->nullable()->index();
            $table->string('cpf', 20)->nullable()->index();
            $table->string('pis', 20)->nullable();
            $table->string('matricula_esocial', 100)->nullable();
            $table->date('nascimento')->nullable();
            $table->date('admissao')->nullable();
            $table->date('demissao')->nullable();
            $table->date('afastamento')->nullable();
            $table->date('retorno')->nullable();
            $table->dateTime('data_atualizacao', 6)->nullable()->index();
            $table->decimal('salario_base', 15, 2)->nullable();
            $table->string('nome_funcao')->nullable();
            $table->string('departamento_id', 100)->nullable()->index();
            $table->string('departamento_nome')->nullable();
            $table->string('sexo_id', 100)->nullable();
            $table->string('sexo_descricao', 100)->nullable();
            $table->string('forma_pagamento_id', 100)->nullable();
            $table->string('forma_pagamento_descricao', 100)->nullable();
            $table->string('email')->nullable();
            $table->string('telefone', 50)->nullable();
            $table->string('telefone_celular', 50)->nullable();
            $table->string('cidade', 150)->nullable();
            $table->string('bairro', 150)->nullable();
            $table->string('rua')->nullable();
            $table->string('numero', 50)->nullable();
            $table->string('complemento')->nullable();
            $table->string('cep', 20)->nullable();
            $table->json('dados_origem');
            $table->char('dados_hash', 64);
            $table->timestamp('ultima_consulta_em')->nullable();
            $table->timestamps();

            $table->foreign('empresa_alterdata_id')->references('alterdata_id')->on('empresas');
            $table->unique(['empresa_alterdata_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('funcionarios');
    }
};
