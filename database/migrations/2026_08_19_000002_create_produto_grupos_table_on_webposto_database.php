<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->create('produto_grupos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('grupoCodigo');
            $table->string('nome')->nullable();
            $table->string('ultimoUsuarioAlteracao')->nullable();
            $table->string('grupoCodigoExterno', 100)->nullable();
            $table->string('codigoTributoIcms', 100)->nullable();
            $table->string('codigoTributoPisCofins', 100)->nullable();
            $table->string('descricaoTributoIcms')->nullable();
            $table->string('descricaoTributoPisCofins')->nullable();
            $table->string('tipoGrupo', 100)->nullable();
            $table->unsignedBigInteger('codigo')->nullable()->index();
            $table->timestamps();

            $table->unique(['empresaCodigo', 'grupoCodigo']);
            $table->foreign('empresaCodigo')
                ->references('empresaCodigo')
                ->on('empresas')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('produto_grupos');
    }
};
