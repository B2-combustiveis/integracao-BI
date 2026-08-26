<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::connection('webposto')->statement(
            'ALTER TABLE titulos_pagar ADD UNIQUE titulos_pagar_empresa_codigo_unique (empresaCodigo, tituloPagarCodigo), ADD INDEX titulos_pagar_fornecedor_index (empresaCodigo, fornecedorCodigo), ADD INDEX titulos_pagar_compra_index (empresaCodigo, notaEntradaCodigo)'
        );

        DB::table('integration_services')
            ->where('slug', 'webposto-novos-fornecedores')
            ->update([
                'active' => false,
                'settings' => json_encode([
                    'strategy' => 'ultimo_codigo',
                    'resources' => [
                        'fornecedores', 'compras', 'titulos_pagar',
                        'vendas', 'venda_itens', 'abastecimentos',
                    ],
                ]),
                'next_run_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('integration_services')
            ->where('slug', 'webposto-novos-fornecedores')
            ->update([
                'active' => false,
                'settings' => json_encode([
                    'strategy' => 'ultimo_codigo',
                    'resources' => ['fornecedores', 'vendas', 'venda_itens', 'abastecimentos'],
                ]),
                'next_run_at' => null,
                'updated_at' => now(),
            ]);

        DB::connection('webposto')->statement(
            'ALTER TABLE titulos_pagar DROP INDEX titulos_pagar_empresa_codigo_unique, DROP INDEX titulos_pagar_fornecedor_index, DROP INDEX titulos_pagar_compra_index'
        );
    }
};
