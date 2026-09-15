<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'alterdata';

    public function up(): void
    {
        Schema::connection($this->connection)->create('departamentos', function (Blueprint $table): void {
            $table->id();
            $table->string('alterdata_id', 100)->unique();
            $table->string('empresa_alterdata_id', 100)->index();
            $table->string('externo_id', 100)->nullable()->index();
            $table->string('nome')->nullable()->index();
            $table->string('cei', 50)->nullable();
            $table->json('dados_origem');
            $table->char('dados_hash', 64);
            $table->timestamp('ultima_consulta_em')->nullable();
            $table->timestamps();

            $table->foreign('empresa_alterdata_id')->references('alterdata_id')->on('empresas');
            $table->unique(['empresa_alterdata_id', 'externo_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('departamentos');
    }
};
