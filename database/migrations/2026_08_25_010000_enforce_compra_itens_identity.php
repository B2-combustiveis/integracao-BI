<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::connection('webposto')->statement(
            'ALTER TABLE compra_itens MODIFY empresaCodigo BIGINT NOT NULL, MODIFY compraCodigo BIGINT NOT NULL, MODIFY produtoCodigo BIGINT NOT NULL, MODIFY sequencialItem BIGINT NOT NULL, ADD UNIQUE compra_itens_empresa_compra_sequencial_unique (empresaCodigo, compraCodigo, sequencialItem), ADD INDEX compra_itens_produto_index (empresaCodigo, produtoCodigo), ADD INDEX compra_itens_produto_lmc_index (empresaCodigo, produtoLmcCodigo)'
        );
    }

    public function down(): void
    {
        DB::connection('webposto')->statement(
            'ALTER TABLE compra_itens DROP INDEX compra_itens_empresa_compra_sequencial_unique, DROP INDEX compra_itens_produto_index, DROP INDEX compra_itens_produto_lmc_index, MODIFY empresaCodigo BIGINT NULL, MODIFY compraCodigo BIGINT NULL, MODIFY produtoCodigo BIGINT NULL, MODIFY sequencialItem BIGINT NULL'
        );
    }
};
