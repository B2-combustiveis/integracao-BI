<?php

namespace App\Services\WebPosto;

class RawNaturalKeyResolver
{
    private const KEYS = [
        'estoque_periodos' => ['codigo'], 'lmcs' => ['lmcCodigo'], 'compras' => ['compraCodigo'],
        'vendas' => ['vendaCodigo'], 'venda_itens' => ['vendaItemCodigo'],
        'venda_formas_pagamento' => ['codigo'], 'abastecimentos' => ['abastecimentoCodigo'],
        'fornecedores' => ['fornecedorCodigo'], 'funcionarios' => ['funcionarioCodigo'],
        'funcionario_funcoes' => ['funcaoCodigo'], 'funcionario_identificadores' => ['codigo'],
        'contas_bancarias' => ['contaCodigo'], 'prazos' => ['prazoCodigo'],
        'formas_pagamento' => ['formaPagamentoCodigo'], 'administradoras' => ['administradoraCodigo'],
        'pdvs' => ['pdvCodigo'], 'pis_cofins' => ['produtoPisCofinsCodigo'],
        'icms' => ['produtoIcmsCodigo'], 'usuarios' => ['usuarioCodigo'],
        'usuario_empresas' => ['usuarioCodigo'], 'tanques' => ['tanqueCodigo'],
        'bicos' => ['bicoCodigo'], 'bombas' => ['bombaCodigo'],
        'titulos_receber' => ['tituloCodigo'], 'duplicatas' => ['duplicataCodigo'],
        'cheques' => ['chequeCodigo'], 'cartoes' => ['cartaoCodigo'],
        'cartao_remessas' => ['cartaoRemessaCodigo'], 'titulos_pagar' => ['tituloPagarCodigo'],
        'vales_funcionario' => ['funcionarioCreditoCodigo'], 'caixas' => ['caixaCodigo'],
        'caixas_apresentados' => ['caixaCodigo'], 'movimentos_conta' => ['movimentoContaCodigo'],
        'sats' => ['codigo'], 'nfces' => ['nfceCodigo'], 'nfes_saida' => ['notaCodigo'],
        'notas_servico' => ['nfseCodigo'], 'compra_itens' => ['codigo'],
        'centros_custo' => ['centroCustoCodigo'], 'planos_conta_gerencial' => ['planoContaCodigo'],
        'mapas_desempenho' => ['funcionarioCodigo', 'produtoCodigo', 'grupoNome'],
        'despesas_financeiro_rede' => ['data', 'planoContaGerencialCodigo', 'descricaoDocumento'],
        'grupos_meta' => ['grupoMetaCodigo'], 'funcionarios_meta' => ['codigo'],
        'produtos_meta' => ['codigo'], 'planos_conta_contabil' => ['planoContaContabilCodigo'],
    ];

    /** @param array<string, mixed> $mapped @return array<string, mixed> */
    public function criteria(string $table, array $mapped): array
    {
        $fields = self::KEYS[$table] ?? [];
        if (array_key_exists('empresaCodigo', $mapped) && $mapped['empresaCodigo'] !== null) {
            array_unshift($fields, 'empresaCodigo');
        }
        $criteria = [];
        foreach (array_unique($fields) as $field) {
            if (array_key_exists($field, $mapped) && $mapped[$field] !== null) $criteria[$field] = $mapped[$field];
        }
        if ($criteria !== []) return $criteria;

        return array_filter($mapped, fn (mixed $value): bool => $value !== null);
    }
}
