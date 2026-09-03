<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $service = DB::table('integration_services')
            ->where('resource', 'webposto-chimba-reconciliation')
            ->first();
        if ($service === null) {
            return;
        }

        $settings = json_decode($service->settings ?? '{}', true) ?: [];
        $settings['worker_blocks'] = [
            [
                'name' => 'Cadastros e estoque',
                'resources' => [
                    'tanques', 'bombas', 'produto_grupos', 'produto_subgrupos', 'produtos',
                    'produto_empresas', 'produto_lmc_lmp', 'bicos', 'lmcs', 'estoque_periodos',
                    'fornecedores', 'compras', 'compra_itens', 'titulos_pagar',
                ],
            ],
            [
                'name' => 'Vendas e financeiro',
                'resources' => [
                    'cliente_grupos', 'clientes', 'cliente_empresas', 'formas_pagamento', 'pdvs',
                    'administradoras', 'centros_custo', 'vendas', 'cartoes',
                    'venda_formas_pagamento', 'titulos_receber',
                ],
            ],
            [
                'name' => 'Itens de venda',
                'resources' => ['venda_itens'],
            ],
            [
                'name' => 'Abastecimentos e fechamento',
                'resources' => [
                    'abastecimentos', 'funcionario_funcoes', 'funcionarios', 'vales_funcionario',
                    'contas_bancarias', 'movimentos_conta', 'caixas', 'caixas_apresentados',
                ],
            ],
        ];

        DB::table('integration_services')->where('id', $service->id)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'active' => false,
            'next_run_at' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $service = DB::table('integration_services')
            ->where('resource', 'webposto-chimba-reconciliation')
            ->first();
        if ($service === null) {
            return;
        }
        $settings = json_decode($service->settings ?? '{}', true) ?: [];
        unset($settings['worker_blocks']);
        DB::table('integration_services')->where('id', $service->id)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
};
