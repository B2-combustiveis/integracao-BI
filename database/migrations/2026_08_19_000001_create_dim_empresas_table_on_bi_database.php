<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'bi';

    public function up(): void
    {
        Schema::connection($this->connection)->create('dim_empresas', function (Blueprint $table): void {
            $table->id('empresa_id');
            $table->unsignedBigInteger('empresa_codigo')->unique();
            $table->char('cnpj', 14)->nullable()->index();
            $table->string('razao_social')->nullable();
            $table->string('nome_fantasia')->nullable();
            $table->string('logradouro')->nullable();
            $table->string('numero', 50)->nullable();
            $table->string('bairro')->nullable();
            $table->string('cep', 8)->nullable();
            $table->string('cidade', 150)->nullable();
            $table->char('estado', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('codigo_externo', 100)->nullable();
            $table->string('sigla', 50)->nullable();
            $table->boolean('ativo')->default(true);
            $table->timestamp('sincronizado_em');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('dim_empresas');
    }
};
