<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->create('cliente_grupos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('grupoCodigo');
            $table->string('grupoCodigoExterno', 100)->nullable();
            $table->string('descricao')->nullable();
            $table->boolean('usaLimiteLitros')->nullable();
            $table->decimal('limiteLitros', 18, 3)->nullable();
            $table->decimal('limiteLitrosDisponivel', 18, 3)->nullable();
            $table->boolean('usaLimiteReais')->nullable();
            $table->decimal('limiteReais', 15, 2)->nullable();
            $table->decimal('limiteReaisDisponivel', 15, 2)->nullable();
            $table->boolean('bloqueadoFinanceiroVencido')->nullable();
            $table->unsignedInteger('diasTolerancia')->nullable();
            $table->unsignedBigInteger('codigo')->nullable()->index();
            $table->timestamps();

            $table->unique(['empresaCodigo', 'grupoCodigo'], 'uq_empresa_cliente_grupo');
            $table->foreign('empresaCodigo', 'fk_cliente_grupo_empresa')
                ->references('empresaCodigo')
                ->on('empresas')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('cliente_grupos');
    }
};
