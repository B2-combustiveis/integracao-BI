<?php

namespace App\Services\WebPosto;

class WebPostoNewRecordsResourceCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return [
            'produto_grupos' => [
                'endpoint' => '/INTEGRACAO/GRUPO',
                'table' => 'produto_grupos',
                'key' => 'grupoCodigo',
                'natural_keys' => ['grupoCodigo'],
                'mode' => 'snapshot_new',
                'query' => [],
                'importer' => ProdutoGrupoImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'produto_subgrupos' => [
                'endpoint' => '/INTEGRACAO/CONSULTAR_SUB_GRUPO_REDE',
                'table' => 'produto_subgrupos',
                'key' => 'subGrupoCodigo',
                'natural_keys' => ['grupoCodigo', 'subGrupoCodigo'],
                'mode' => 'snapshot_new',
                'query' => [],
                'importer' => ProdutoSubgrupoImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'produtos' => [
                'endpoint' => '/INTEGRACAO/PRODUTO',
                'table' => 'produtos',
                'key' => 'produtoCodigo',
                'limit' => 1000,
                'query' => [],
                'importer' => ProdutoImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'produto_empresas' => [
                'endpoint' => '/INTEGRACAO/PRODUTO_EMPRESA',
                'table' => 'produto_empresas',
                'key' => 'produtoCodigo',
                'limit' => 1000,
                'query' => [],
                'importer' => ProdutoEmpresaImporter::class,
                'updated_field' => 'ultimaAlteracao',
            ],
            'estoque_periodos' => [
                'endpoint' => '/INTEGRACAO/ESTOQUE_PERIODO',
                'table' => 'estoque_periodos',
                'key' => 'codigo',
                'company_field' => 'codigoUnidadeNegocio',
                'query_company_field' => 'empresaCodigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => EstoquePeriodoImporter::class,
                'updated_field' => 'dataMovimento',
            ],
            'produto_lmc_lmp' => [
                'endpoint' => '/INTEGRACAO/PRODUTO_LMC_LMP',
                'table' => 'produto_lmc_lmp',
                'key' => 'produtoLmcCodigo',
                'natural_keys' => ['produtoLmcCodigo'],
                'mode' => 'snapshot_new',
                'query' => [],
                'importer' => ProdutoLmcLmpImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'lmcs' => [
                'endpoint' => '/INTEGRACAO/LMC',
                'table' => 'lmcs',
                'key' => 'lmcCodigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => LmcImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'tanques' => [
                'endpoint' => '/INTEGRACAO/TANQUE',
                'table' => 'tanques',
                'key' => 'tanqueCodigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => TanqueImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'bombas' => [
                'endpoint' => '/INTEGRACAO/BOMBA',
                'table' => 'bombas',
                'key' => 'bombaCodigo',
                'natural_keys' => ['bombaCodigo'],
                'mode' => 'snapshot_new',
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                    'limite' => 1000,
                ],
                'importer' => BombaImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'bicos' => [
                'endpoint' => '/INTEGRACAO/BICO',
                'table' => 'bicos',
                'key' => 'bicoCodigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => BicoImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'funcionario_funcoes' => [
                'endpoint' => '/INTEGRACAO/FUNCOES',
                'table' => 'funcionario_funcoes',
                'key' => 'funcaoCodigo',
                'natural_keys' => ['funcaoCodigo'],
                'mode' => 'snapshot_new',
                'company_scoped' => false,
                'query' => [],
                'importer' => FuncionarioFuncaoImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'funcionarios' => [
                'endpoint' => '/INTEGRACAO/FUNCIONARIO',
                'table' => 'funcionarios',
                'key' => 'funcionarioCodigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => FuncionarioImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'fornecedores' => [
                'endpoint' => '/INTEGRACAO/FORNECEDOR',
                'table' => 'fornecedores',
                'key' => 'fornecedorCodigo',
                'limit' => 1000,
                'query' => [],
                'importer' => FornecedorImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'compras' => [
                'endpoint' => '/INTEGRACAO/COMPRA',
                'table' => 'compras',
                'key' => 'compraCodigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => CompraImporter::class,
                'updated_field' => 'dataMovimento',
            ],
            'compra_itens' => [
                'endpoint' => '/INTEGRACAO/COMPRA_ITEM',
                'table' => 'compra_itens',
                'key' => 'compraCodigo',
                'natural_keys' => ['compraCodigo', 'sequencialItem'],
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => CompraItemImporter::class,
                'updated_field' => 'dataMovimento',
            ],
            'titulos_pagar' => [
                'endpoint' => '/INTEGRACAO/TITULO_PAGAR',
                'table' => 'titulos_pagar',
                'key' => 'tituloPagarCodigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => TituloPagarImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'clientes' => [
                'endpoint' => '/INTEGRACAO/CLIENTE',
                'table' => 'clientes',
                'key' => 'clienteCodigo',
                'limit' => 1000,
                'query' => [],
                'importer' => ClienteImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'vendas' => [
                'endpoint' => '/INTEGRACAO/VENDA',
                'table' => 'vendas',
                'key' => 'vendaCodigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => VendaImporter::class,
                'updated_field' => 'dataHora',
            ],
            'venda_formas_pagamento' => [
                'endpoint' => '/INTEGRACAO/VENDA_FORMA_PAGAMENTO',
                'table' => 'venda_formas_pagamento',
                'key' => 'codigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => VendaFormaPagamentoImporter::class,
                'updated_field' => 'dataMovimento',
            ],
            'titulos_receber' => [
                'endpoint' => '/INTEGRACAO/TITULO_RECEBER',
                'table' => 'titulos_receber',
                'key' => 'tituloCodigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => TituloReceberImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'venda_itens' => [
                'endpoint' => '/INTEGRACAO/VENDA_ITEM',
                'table' => 'venda_itens',
                'key' => 'vendaItemCodigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => VendaItemImporter::class,
                'updated_field' => 'dataMovimento',
            ],
            'abastecimentos' => [
                'endpoint' => '/INTEGRACAO/ABASTECIMENTO',
                'table' => 'abastecimentos',
                'key' => 'abastecimentoCodigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => AbastecimentoImporter::class,
                'updated_field' => 'dataHoraAbastecimento',
            ],
            'administradoras' => [
                'endpoint' => '/INTEGRACAO/ADMINISTRADORA',
                'table' => 'administradoras',
                'key' => 'administradoraCodigo',
                'limit' => 1000,
                'query' => [],
                'importer' => AdministradoraImporter::class,
                'updated_field' => 'dataHoraAtualizacao',
            ],
            'cartoes' => [
                'endpoint' => '/INTEGRACAO/CARTAO',
                'table' => 'cartoes',
                'key' => 'cartaoCodigo',
                'limit' => 1000,
                'query' => [
                    'dataInicial' => '2000-01-01',
                    'dataFinal' => now()->toDateString(),
                ],
                'importer' => CartaoImporter::class,
                'updated_field' => 'dataMovimento',
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function get(string $resource): array
    {
        return $this->all()[$resource] ?? throw new \InvalidArgumentException(
            "Recurso {$resource} nao esta liberado para sincronizacao incremental.",
        );
    }
}
