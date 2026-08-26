<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('webposto')->table('vales_funcionario', function (Blueprint $table): void {
            $table->text('funcionarioReferencia')->nullable()->after('funcionarioCodigo');
            $table->unique(['empresaCodigo', 'funcionarioCreditoCodigo'], 'vales_funcionario_empresa_credito_unique');
        });

        DB::connection('webposto')->statement(
            'ALTER TABLE vales_funcionario MODIFY funcionarioCreditoCodigo BIGINT NOT NULL, MODIFY empresaCodigo BIGINT NOT NULL, MODIFY funcionarioCodigo BIGINT NOT NULL, ADD CONSTRAINT vales_funcionario_funcionario_fk FOREIGN KEY (empresaCodigo, funcionarioCodigo) REFERENCES funcionarios (empresaCodigo, funcionarioCodigo) ON DELETE RESTRICT'
        );
    }

    public function down(): void
    {
        DB::connection('webposto')->statement('ALTER TABLE vales_funcionario DROP FOREIGN KEY vales_funcionario_funcionario_fk');
        Schema::connection('webposto')->table('vales_funcionario', function (Blueprint $table): void {
            $table->dropUnique('vales_funcionario_empresa_credito_unique');
            $table->dropColumn('funcionarioReferencia');
        });
    }
};
