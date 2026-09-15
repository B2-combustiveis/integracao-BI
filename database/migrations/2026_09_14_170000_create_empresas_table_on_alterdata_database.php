<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'alterdata';

    public function up(): void
    {
        Schema::connection($this->connection)->create('empresas', function (Blueprint $table): void {
            $table->id();
            $table->string('alterdata_id', 100)->unique();
            $table->string('externo_id', 100)->nullable()->index();
            $table->string('nome')->nullable();
            $table->boolean('ativa')->nullable()->index();
            $table->string('cpf_cnpj', 32)->nullable()->index();
            $table->string('cpf_cnpj_alfanumerico', 32)->nullable();
            $table->string('tipo_movimento_permitido', 100)->nullable();
            $table->text('endereco')->nullable();
            $table->boolean('is_cpf')->nullable();
            $table->boolean('controla_transferencia_tomadores')->nullable();
            $table->json('dados_origem');
            $table->timestamp('ultima_consulta_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('empresas');
    }
};
