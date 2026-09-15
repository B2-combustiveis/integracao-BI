<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'alterdata';

    public function up(): void
    {
        Schema::connection($this->connection)->table('funcionarios', function (Blueprint $table): void {
            $table->string('tipo_conta_id', 100)->nullable()->after('forma_pagamento_descricao')->index();
            $table->string('tipo_conta_descricao', 100)->nullable()->after('tipo_conta_id');
            $table->string('tipo_chave_pix_id', 100)->nullable()->after('tipo_conta_descricao')->index();
            $table->string('tipo_chave_pix_descricao', 100)->nullable()->after('tipo_chave_pix_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('funcionarios', function (Blueprint $table): void {
            $table->dropColumn([
                'tipo_conta_id', 'tipo_conta_descricao',
                'tipo_chave_pix_id', 'tipo_chave_pix_descricao',
            ]);
        });
    }
};
