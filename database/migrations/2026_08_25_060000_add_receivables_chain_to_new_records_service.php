<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::connection('webposto')->statement(
            'ALTER TABLE titulos_receber ADD UNIQUE titulos_receber_empresa_codigo_unique (empresaCodigo, tituloCodigo), ADD INDEX titulos_receber_cliente_index (empresaCodigo, clienteCodigo), ADD INDEX titulos_receber_venda_index (empresaCodigo, vendaCodigo)'
        );

        DB::table('integration_services')
            ->where('slug', 'webposto-novos-fornecedores')
            ->update([
                'active' => false,
                'settings' => json_encode([
                    'strategy' => 'ultimo_codigo',
                    'resources' => [
                        'fornecedores', 'compras', 'titulos_pagar',
                        'clientes', 'vendas', 'titulos_receber',
                        'venda_itens', 'abastecimentos',
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
                    'resources' => [
                        'fornecedores', 'compras', 'titulos_pagar',
                        'vendas', 'venda_itens', 'abastecimentos',
                    ],
                ]),
                'next_run_at' => null,
                'updated_at' => now(),
            ]);

        DB::connection('webposto')->statement(
            'ALTER TABLE titulos_receber DROP INDEX titulos_receber_empresa_codigo_unique, DROP INDEX titulos_receber_cliente_index, DROP INDEX titulos_receber_venda_index'
        );
    }
};
