<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::connection('webposto')->statement(
            'ALTER TABLE estoque_periodos MODIFY codigo BIGINT NOT NULL, MODIFY codigoProduto BIGINT UNSIGNED NOT NULL, MODIFY codigoUnidadeNegocio BIGINT UNSIGNED NOT NULL, ADD UNIQUE estoque_periodos_empresa_codigo_unique (codigoUnidadeNegocio, codigo), ADD CONSTRAINT estoque_periodos_produto_fk FOREIGN KEY (codigoUnidadeNegocio, codigoProduto) REFERENCES produtos (empresaCodigo, produtoCodigo) ON DELETE RESTRICT'
        );
    }

    public function down(): void
    {
        DB::connection('webposto')->statement(
            'ALTER TABLE estoque_periodos DROP FOREIGN KEY estoque_periodos_produto_fk, DROP INDEX estoque_periodos_empresa_codigo_unique, MODIFY codigo BIGINT NULL, MODIFY codigoProduto BIGINT NULL, MODIFY codigoUnidadeNegocio BIGINT NULL'
        );
    }
};
