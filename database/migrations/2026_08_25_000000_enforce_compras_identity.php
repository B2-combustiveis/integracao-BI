<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::connection('webposto')->statement(
            'ALTER TABLE compras MODIFY empresaCodigo BIGINT NOT NULL, MODIFY compraCodigo BIGINT NOT NULL, MODIFY fornecedorCodigo BIGINT NOT NULL, ADD UNIQUE compras_empresa_codigo_unique (empresaCodigo, compraCodigo), ADD INDEX compras_fornecedor_index (empresaCodigo, fornecedorCodigo)'
        );
    }

    public function down(): void
    {
        DB::connection('webposto')->statement(
            'ALTER TABLE compras DROP INDEX compras_empresa_codigo_unique, DROP INDEX compras_fornecedor_index, MODIFY empresaCodigo BIGINT NULL, MODIFY compraCodigo BIGINT NULL, MODIFY fornecedorCodigo BIGINT NULL'
        );
    }
};
