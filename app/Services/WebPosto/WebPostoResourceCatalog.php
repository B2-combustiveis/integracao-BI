<?php

namespace App\Services\WebPosto;

use InvalidArgumentException;

class WebPostoResourceCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $dateRange = ['data_inicial' => 'required|date', 'data_final' => 'required|date|after_or_equal:data_inicial'];

        return [
            'estoque-periodos' => $this->item('/INTEGRACAO/ESTOQUE_PERIODO', 'estoque_periodos', [...$dateRange, 'empresa_webposto_codigo' => 'nullable|integer|min:1'], ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal', 'empresa_webposto_codigo' => 'empresaCodigo']),
            'lmcs' => $this->item('/INTEGRACAO/LMC', 'lmcs', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'compras' => $this->item('/INTEGRACAO/COMPRA', 'compras', [...$dateRange, 'empresa_webposto_codigo' => 'nullable|integer|min:1', 'tipo_data' => 'nullable|string|in:EMISSAO,ENTRADA'], ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal', 'empresa_webposto_codigo' => 'empresaCodigo', 'tipo_data' => 'tipoData']),
            'vendas' => $this->item('/INTEGRACAO/VENDA', 'vendas', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'vendas-por-ids' => $this->item('/INTEGRACAO/VENDA/{id_list}', 'vendas', ['id_list' => 'required|string|max:4000'], [], ['id_list']),
            'venda-itens' => $this->item('/INTEGRACAO/VENDA_ITEM', 'venda_itens', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'venda-formas-pagamento' => $this->item('/INTEGRACAO/VENDA_FORMA_PAGAMENTO', 'venda_formas_pagamento', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'abastecimentos' => $this->item('/INTEGRACAO/ABASTECIMENTO', 'abastecimentos', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'fornecedores' => $this->item('/INTEGRACAO/FORNECEDOR', 'fornecedores'),
            'funcionarios' => $this->item('/INTEGRACAO/FUNCIONARIO', 'funcionarios'),
            'funcionario-funcoes' => $this->item('/INTEGRACAO/FUNCOES', 'funcionario_funcoes'),
            'funcionario-identificadores' => $this->item('/INTEGRACAO/CONSULTAR_FUNCIONARIO_IDENTFID', 'funcionario_identificadores'),
            'contas-bancarias' => $this->item('/INTEGRACAO/CONTA', 'contas_bancarias'),
            'prazos' => $this->item('/INTEGRACAO/PRAZOS', 'prazos'),
            'formas-pagamento' => $this->item('/INTEGRACAO/FORMA_PAGAMENTO', 'formas_pagamento'),
            'administradoras' => $this->item('/INTEGRACAO/ADMINISTRADORA', 'administradoras'),
            'pdvs' => $this->item('/INTEGRACAO/PDV', 'pdvs'),
            'pis-cofins' => $this->item('/INTEGRACAO/PIS_COFINS', 'pis_cofins'),
            'icms' => $this->item('/INTEGRACAO/ICMS', 'icms'),
            'usuarios' => $this->item('/INTEGRACAO/USUARIO', 'usuarios'),
            'usuario-empresas' => $this->item('/INTEGRACAO/USUARIO_EMPRESA', 'usuario_empresas'),
            'tanques' => $this->item('/INTEGRACAO/TANQUE', 'tanques'),
            'bicos' => $this->item('/INTEGRACAO/BICO', 'bicos'),
            'bombas' => $this->item('/INTEGRACAO/BOMBA', 'bombas'),
            'titulos-receber' => $this->item('/INTEGRACAO/TITULO_RECEBER', 'titulos_receber', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'duplicatas' => $this->item('/INTEGRACAO/DUPLICATA', 'duplicatas', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'cheques' => $this->item('/INTEGRACAO/CHEQUE', 'cheques', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'cartoes' => $this->item('/INTEGRACAO/CARTAO', 'cartoes', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'cartao-remessas' => $this->item('/INTEGRACAO/CARTAO_REMESSA', 'cartao_remessas', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'titulos-pagar' => $this->item('/INTEGRACAO/TITULO_PAGAR', 'titulos_pagar', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'vales-funcionario' => $this->item('/INTEGRACAO/VALE_FUNCIONARIO', 'vales_funcionario', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'caixas' => $this->item('/INTEGRACAO/CAIXA', 'caixas', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'caixas-apresentados' => $this->item('/INTEGRACAO/CAIXA_APRESENTADO', 'caixas_apresentados', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'movimentos-conta' => $this->item('/INTEGRACAO/MOVIMENTO_CONTA', 'movimentos_conta', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'sats' => $this->item('/INTEGRACAO/SAT', 'sats', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'nfces' => $this->item('/INTEGRACAO/NFCE', 'nfces', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'nfce-xml' => $this->item('/INTEGRACAO/NFCE/{documento_codigo}/XML', 'nfce_xmls', ['documento_codigo' => 'required|integer|min:1'], [], ['documento_codigo']),
            'nfes-saida' => $this->item('/INTEGRACAO/NFE_SAIDA', 'nfes_saida', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'nfe-xml' => $this->documentItem('/INTEGRACAO/NFE/XML', 'nfe_xmls'),
            'dfe-xml' => $this->documentItem('/INTEGRACAO/DFE_XML', 'dfe_xmls'),
            'notas-servico' => $this->item('/INTEGRACAO/NOTA_SERVICO', 'notas_servico', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'compra-itens' => $this->item('/INTEGRACAO/COMPRA_ITEM', 'compra_itens', [...$dateRange, 'compra_codigo' => 'nullable|integer|min:1', 'tipo_data' => 'nullable|string|in:EMISSAO,ENTRADA'], ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal', 'compra_codigo' => 'compraCodigo', 'tipo_data' => 'tipoData']),
            'centros-custo' => $this->item('/INTEGRACAO/CENTRO_CUSTO', 'centros_custo'),
            'planos-conta-gerencial' => $this->item('/INTEGRACAO/PLANO_CONTA_GERENCIAL', 'planos_conta_gerencial'),
            'dres' => $this->item('/INTEGRACAO/DRE', 'dres', [...$dateRange, 'apuracao_caixa' => 'required|boolean'], ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal', 'apuracao_caixa' => 'apuracaoCaixa']),
            'mapas-desempenho' => $this->item('/INTEGRACAO/MAPA_DESEMPENHO', 'mapas_desempenho', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'analises-vendas-combustivel' => $this->item('/INTEGRACAO/CONSULTAR_ANALISE_VENDAS_COMBUSTIVEL', 'analises_vendas_combustivel'),
            'despesas-financeiro-rede' => $this->item('/INTEGRACAO/CONSULTAR_DESPESAS_FINANCEIRO_REDE', 'despesas_financeiro_rede', $dateRange, ['data_inicial' => 'dataInicial', 'data_final' => 'dataFinal']),
            'grupos-meta' => $this->item('/INTEGRACAO/GRUPO_META', 'grupos_meta'),
            'funcionarios-meta' => $this->item('/INTEGRACAO/FUNCIONARIO_META', 'funcionarios_meta', ['grupo_meta_codigo' => 'required|integer|min:1'], ['grupo_meta_codigo' => 'grupoMetaCodigo']),
            'produtos-meta' => $this->item('/INTEGRACAO/PRODUTO_META', 'produtos_meta', ['grupo_meta_codigo' => 'required|integer|min:1'], ['grupo_meta_codigo' => 'grupoMetaCodigo']),
            'planos-conta-contabil' => $this->item('/INTEGRACAO/PLANO_CONTA_CONTABIL', 'planos_conta_contabil'),
        ];
    }

    /** @return array<string, mixed> */
    public function get(string $resource): array
    {
        return $this->all()[$resource] ?? throw new InvalidArgumentException('Recurso WebPosto não suportado.');
    }

    /** @param array<string, string> $rules @param array<string, string> $queryMap @param list<string> $pathParameters */
    private function item(string $endpoint, string $table, array $rules = [], array $queryMap = [], array $pathParameters = []): array
    {
        return compact('endpoint', 'table', 'rules', 'queryMap', 'pathParameters');
    }

    /** @return array<string, mixed> */
    private function documentItem(string $endpoint, string $table): array
    {
        return $this->item($endpoint, $table, [
            'empresa_webposto_codigo' => 'required|integer|min:1', 'modelo_documento' => 'required|integer',
            'numero_documento' => 'required|string|max:30', 'serie_documento' => 'required|string|max:20',
        ], ['empresa_webposto_codigo' => 'empresaCodigo', 'modelo_documento' => 'modeloDocumento', 'numero_documento' => 'numeroDocumento', 'serie_documento' => 'serieDocumento']);
    }
}
