<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'alterdata';

    public function up(): void
    {
        Schema::connection($this->connection)->create('paises', function (Blueprint $table): void {
            $table->id();
            $table->string('alterdata_id', 100)->unique();
            $table->string('sigla', 10)->nullable();
            $table->string('nome', 150);
            $table->json('dados_origem');
            $table->char('dados_hash', 64);
            $table->timestamp('ultima_consulta_em')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('paises');
    }
};
