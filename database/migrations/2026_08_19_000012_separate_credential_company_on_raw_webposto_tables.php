<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    private const TABLES = [
        'estoque_periodos', 'lmcs', 'compras', 'vendas', 'venda_itens', 'venda_formas_pagamento',
        'abastecimentos', 'fornecedores', 'funcionarios', 'funcionario_funcoes', 'funcionario_identificadores',
        'contas_bancarias', 'prazos', 'formas_pagamento', 'administradoras', 'pdvs', 'pis_cofins',
        'icms', 'usuarios', 'usuario_empresas', 'tanques', 'bicos', 'bombas', 'titulos_receber',
        'duplicatas', 'cheques', 'cartoes', 'cartao_remessas', 'titulos_pagar', 'vales_funcionario',
        'caixas', 'caixas_apresentados', 'movimentos_conta', 'sats', 'nfces', 'nfce_xmls',
        'nfes_saida', 'nfe_xmls', 'dfe_xmls', 'notas_servico', 'compra_itens', 'centros_custo',
        'planos_conta_gerencial', 'dres', 'mapas_desempenho', 'analises_vendas_combustivel',
        'despesas_financeiro_rede', 'grupos_meta', 'funcionarios_meta', 'produtos_meta',
        'planos_conta_contabil',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::connection($this->connection)->table($tableName, function (Blueprint $table): void {
                $table->renameColumn('empresaCodigo', 'credencialEmpresaCodigo');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::connection($this->connection)->table($tableName, function (Blueprint $table): void {
                $table->renameColumn('credencialEmpresaCodigo', 'empresaCodigo');
            });
        }
    }
};
