<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('integration_services')->updateOrInsert(
            ['slug' => 'webposto-reconciliacao-chimba', 'empresa_codigo' => 4604],
            [
                'name' => 'Reconciliação WebPosto',
                'category' => 'atualizacao',
                'resource' => 'webposto-chimba-reconciliation',
                'frequency_minutes' => 1440,
                'lookback_days' => 1,
                'active' => false,
                'settings' => json_encode([
                    'scope' => 'chimba',
                    'empresa_codigos' => [4604],
                    'resources' => [
                        'tanques', 'bombas', 'produto_grupos', 'produto_subgrupos', 'produtos',
                        'produto_empresas', 'produto_lmc_lmp', 'bicos', 'lmcs', 'estoque_periodos',
                        'fornecedores', 'compras', 'compra_itens', 'titulos_pagar', 'cliente_grupos',
                        'clientes', 'cliente_empresas', 'formas_pagamento', 'pdvs', 'vendas',
                        'venda_formas_pagamento', 'titulos_receber', 'venda_itens', 'abastecimentos',
                        'administradoras', 'funcionario_funcoes', 'funcionarios', 'contas_bancarias',
                        'caixas', 'caixas_apresentados',
                    ],
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'next_run_at' => null,
                'last_error' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('integration_services')->where('resource', 'webposto-chimba-reconciliation')->delete();
        DB::table('webposto_sync_controls')->where('endpoint', 'like', '%:chimba-reconciliation')->delete();
    }
};
