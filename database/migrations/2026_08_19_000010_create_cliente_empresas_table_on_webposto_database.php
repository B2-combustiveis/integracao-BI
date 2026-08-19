<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->create('cliente_empresas', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('clienteCodigo');
            $table->boolean('ativoInativo')->nullable();
            $table->boolean('usaPrazo')->nullable();
            $table->unsignedBigInteger('codigo')->nullable()->index();
            $table->timestamps();

            $table->unique(['empresaCodigo', 'clienteCodigo'], 'uq_cliente_empresa');
            $table->foreign('empresaCodigo', 'fk_cliente_empresa_empresa')
                ->references('empresaCodigo')->on('empresas')->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreign(['empresaCodigo', 'clienteCodigo'], 'fk_cliente_empresa_cliente')
                ->references(['empresaCodigo', 'clienteCodigo'])->on('clientes')
                ->cascadeOnUpdate()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('cliente_empresas');
    }
};
