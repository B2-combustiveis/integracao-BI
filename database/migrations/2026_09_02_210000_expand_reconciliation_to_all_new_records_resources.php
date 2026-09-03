<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const RESOURCES = [
        'tanques', 'bombas', 'produto_grupos', 'produto_subgrupos', 'produtos',
        'produto_empresas', 'produto_lmc_lmp', 'bicos', 'lmcs', 'estoque_periodos',
        'fornecedores', 'compras', 'compra_itens', 'titulos_pagar', 'cliente_grupos',
        'clientes', 'cliente_empresas', 'formas_pagamento', 'pdvs', 'vendas',
        'venda_formas_pagamento', 'titulos_receber', 'venda_itens', 'abastecimentos',
        'administradoras', 'funcionario_funcoes', 'funcionarios', 'contas_bancarias',
        'caixas', 'caixas_apresentados',
    ];

    public function up(): void
    {
        $service = DB::table('integration_services')
            ->where('resource', 'webposto-chimba-reconciliation')
            ->first();
        if ($service === null) {
            return;
        }

        $settings = json_decode($service->settings ?? '{}', true) ?: [];
        $settings['resources'] = self::RESOURCES;
        DB::table('integration_services')->where('id', $service->id)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'active' => false,
            'next_run_at' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // A expansão será validada no piloto antes de qualquer redução de escopo.
    }
};
