<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::connection('webposto')->statement(
            'ALTER TABLE venda_formas_pagamento ADD UNIQUE venda_pagamentos_empresa_codigo_unique (empresaCodigo, codigo), ADD INDEX venda_pagamentos_venda_index (empresaCodigo, vendaCodigo)'
        );

        DB::table('integration_services')
            ->where('slug', 'webposto-novos-fornecedores')
            ->update([
                'active' => false,
                'settings' => json_encode([
                    'strategy' => 'ultimo_codigo',
                    'resources' => [
                        'fornecedores', 'compras', 'titulos_pagar',
                        'clientes', 'vendas', 'venda_formas_pagamento',
                        'titulos_receber', 'venda_itens', 'abastecimentos',
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
                        'clientes', 'vendas', 'titulos_receber',
                        'venda_itens', 'abastecimentos',
                    ],
                ]),
                'next_run_at' => null,
                'updated_at' => now(),
            ]);

        DB::connection('webposto')->statement(
            'ALTER TABLE venda_formas_pagamento DROP INDEX venda_pagamentos_empresa_codigo_unique, DROP INDEX venda_pagamentos_venda_index'
        );
    }
};
