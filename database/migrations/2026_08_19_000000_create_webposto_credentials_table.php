<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->create('webposto_credentials', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresa_codigo')->unique();
            $table->string('base_url');
            $table->text('token');
            $table->boolean('ativo')->default(true);
            $table->timestamp('ultimo_uso_em')->nullable();
            $table->timestamps();

            $table->foreign('empresa_codigo')
                ->references('empresaCodigo')
                ->on('empresas')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('webposto_credentials');
    }
};
