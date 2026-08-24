<?php

namespace App\Services\WebPosto;

class WebPostoModifiedResourceCatalog
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $period = ['dataInicial' => '2000-01-01', 'dataFinal' => today()->toDateString()];

        return [
            'caixas' => $this->raw('/INTEGRACAO/CAIXA', 'caixas', 'caixaCodigo', 'dataHoraAtualizacao', $period),
            'caixas-apresentados' => $this->raw('/INTEGRACAO/CAIXA_APRESENTADO', 'caixas_apresentados', 'caixaCodigo', 'dataHoraAtualizacao', $period),
            'clientes' => $this->specialized('/INTEGRACAO/CLIENTE', 'clientes', 'clienteCodigo', 'dataHoraAtualizacao', ClienteImporter::class, 1000),
            'fornecedores' => $this->raw('/INTEGRACAO/FORNECEDOR', 'fornecedores', 'fornecedorCodigo', 'dataHoraAtualizacao'),
            'lmcs' => $this->raw('/INTEGRACAO/LMC', 'lmcs', 'lmcCodigo', 'dataHoraAtualizacao', $period),
            'produto-empresas' => $this->specialized('/INTEGRACAO/PRODUTO_EMPRESA', 'produto_empresas', 'produtoCodigo', 'ultimaAlteracao', ProdutoEmpresaImporter::class, 2000),
            'titulos-pagar' => $this->raw('/INTEGRACAO/TITULO_PAGAR', 'titulos_pagar', 'tituloPagarCodigo', 'dataHoraAtualizacao', $period),
            'titulos-receber' => $this->raw('/INTEGRACAO/TITULO_RECEBER', 'titulos_receber', 'tituloCodigo', 'dataHoraAtualizacao', $period),
        ];
    }

    /** @return array<string, mixed> */
    public function get(string $resource): array
    {
        return $this->all()[$resource] ?? throw new \InvalidArgumentException(
            "Recurso {$resource} nao possui sincronizacao por data de atualizacao.",
        );
    }

    /** @param array<string, mixed> $query @return array<string, mixed> */
    private function raw(
        string $endpoint,
        string $table,
        string $key,
        string $updatedField,
        array $query = [],
        int $limit = 1000,
    ): array {
        return compact('endpoint', 'table', 'key', 'updatedField', 'query', 'limit')
            + ['importer' => null];
    }

    /** @return array<string, mixed> */
    private function specialized(
        string $endpoint,
        string $table,
        string $key,
        string $updatedField,
        string $importer,
        int $limit,
    ): array {
        return compact('endpoint', 'table', 'key', 'updatedField', 'importer', 'limit')
            + ['query' => []];
    }
}
