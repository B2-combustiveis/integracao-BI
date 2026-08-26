<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::connection('webposto')->statement(
            'ALTER TABLE movimentos_conta MODIFY movimentoContaCodigo BIGINT NOT NULL, ADD UNIQUE movimentos_conta_empresa_codigo_unique (empresaCodigo, movimentoContaCodigo)'
        );
    }

    public function down(): void
    {
        DB::connection('webposto')->statement(
            'ALTER TABLE movimentos_conta DROP INDEX movimentos_conta_empresa_codigo_unique, MODIFY movimentoContaCodigo BIGINT NULL'
        );
    }
};
