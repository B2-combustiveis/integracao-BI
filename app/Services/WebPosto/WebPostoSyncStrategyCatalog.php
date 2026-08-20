<?php

namespace App\Services\WebPosto;

class WebPostoSyncStrategyCatalog
{
    public function strategyFor(string $endpoint): string
    {
        return in_array($endpoint, $this->optimized(), true) ? 'A'
            : (in_array($endpoint, $this->total(), true) ? 'C' : 'B');
    }

    public function isSyncable(string $endpoint): bool
    {
        return ! in_array($endpoint, $this->excluded(), true);
    }

    public function optimized(): array
    {
        return ['/INTEGRACAO/PRODUTO_EMPRESA', '/INTEGRACAO/CLIENTE', '/INTEGRACAO/FORNECEDOR',
            '/INTEGRACAO/ESTOQUE_PERIODO', '/INTEGRACAO/LMC', '/INTEGRACAO/TITULO_RECEBER',
            '/INTEGRACAO/DUPLICATA', '/INTEGRACAO/CHEQUE', '/INTEGRACAO/CARTAO',
            '/INTEGRACAO/TITULO_PAGAR', '/INTEGRACAO/VALE_FUNCIONARIO',
            '/INTEGRACAO/CAIXA_APRESENTADO', '/INTEGRACAO/MOVIMENTO_CONTA', '/INTEGRACAO/CAIXA'];
    }

    public function total(): array
    {
        return ['/INTEGRACAO/PRODUTO_LMC_LMP', '/INTEGRACAO/CONSULTAR_SUB_GRUPO_REDE', '/INTEGRACAO/PRAZOS'];
    }

    public function excluded(): array
    {
        return ['/INTEGRACAO/NFE/XML', '/INTEGRACAO/DFE_XML', '/INTEGRACAO/DRE',
            '/INTEGRACAO/MAPA_DESEMPENHO', '/INTEGRACAO/CONSULTAR_ANALISE_VENDAS_COMBUSTIVEL',
            '/INTEGRACAO/CONSULTAR_DESPESAS_FINANCEIRO_REDE'];
    }
}
