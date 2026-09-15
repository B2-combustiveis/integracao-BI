<?php

namespace Tests\Unit;

use App\Services\ClickHouse\ClickHouseTableCatalog;
use PHPUnit\Framework\TestCase;

class ClickHouseTableCatalogTest extends TestCase
{
    public function test_it_contains_every_webposto_business_table_required_by_bi(): void
    {
        $catalog = new ClickHouseTableCatalog;

        $expected = [
            'produto_subgrupos',
            'produto_lmc_lmp',
            'cliente_empresas',
            'pdvs',
            'vales_funcionario',
            'movimentos_conta',
            'centros_custo',
        ];

        foreach ($expected as $table) {
            $this->assertArrayHasKey($table, $catalog->all());
            $this->assertSame('id', $catalog->get($table)['cursor']['key']);
            $this->assertArrayHasKey('id', $catalog->get($table)['columns']);
            $this->assertArrayHasKey('updated_at', $catalog->get($table)['columns']);
        }

        $this->assertCount(35, $catalog->tables());
        $this->assertSame(ClickHouseTableCatalog::HEAVY_QUEUE, $catalog->get('movimentos_conta')['queue']);
        $this->assertTrue($catalog->get('clientes')['reconcile_count']);
        $this->assertTrue($catalog->get('produtos')['reconcile_count']);
        foreach (['venda_itens', 'abastecimentos', 'vendas', 'compra_itens', 'movimentos_conta'] as $table) {
            $this->assertTrue($catalog->get($table)['reconcile_count']);
        }
    }

    public function test_empresas_keeps_the_useful_mysql_business_columns(): void
    {
        $columns = (new ClickHouseTableCatalog)->get('empresas')['columns'];

        $this->assertSame([
            'id', 'codigo', 'empresaCodigo', 'cnpj', 'razao', 'fantasia',
            'tipoLogradouro', 'logradouro', 'endereco', 'bairro', 'numero',
            'cep', 'cidade', 'estado', 'latitude', 'longitude',
            'ultimoUsuarioAlteracao', 'centroCustoPrincipal',
            'empresaCodigoExterno', 'sigla', 'tipoImposto', 'created_at',
            'updated_at',
        ], array_keys($columns));
    }
}
