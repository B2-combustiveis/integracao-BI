<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->create('empresas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo')->unique();
            $table->string('cnpj', 20)->nullable();
            $table->string('razao')->nullable();
            $table->string('fantasia')->nullable();
            $table->string('tipoLogradouro', 80)->nullable();
            $table->string('logradouro')->nullable();
            $table->string('endereco')->nullable();
            $table->string('bairro')->nullable();
            $table->string('numero', 50)->nullable();
            $table->string('cep', 12)->nullable();
            $table->string('cidade', 150)->nullable();
            $table->string('estado', 2)->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('ultimoUsuarioAlteracao')->nullable();
            $table->json('centroCustoPrincipal')->nullable();
            $table->string('empresaCodigoExterno', 100)->nullable();
            $table->string('sigla', 50)->nullable();
            $table->string('tipoImposto', 20)->nullable();
            $table->unsignedBigInteger('codigo')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('empresas');
    }
};
