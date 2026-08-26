<?php

namespace Tests\Unit;

use App\Services\WebPosto\BicoImporter;
use App\Services\WebPosto\BombaImporter;
use App\Services\WebPosto\FuncionarioFuncaoImporter;
use App\Services\WebPosto\FuncionarioImporter;
use App\Services\WebPosto\EstoquePeriodoImporter;
use App\Services\WebPosto\LmcImporter;
use App\Services\WebPosto\ProdutoLmcLmpImporter;
use App\Services\WebPosto\TanqueImporter;
use App\Services\WebPosto\WebPostoNewRecordsResourceCatalog;
use Tests\TestCase;

class WebPostoNewRecordsResourceCatalogTest extends TestCase
{
    public function test_it_defines_the_fuel_chain_with_the_expected_keys_and_importers(): void
    {
        $catalog = app(WebPostoNewRecordsResourceCatalog::class)->all();

        $this->assertSame('snapshot_new', $catalog['produto_lmc_lmp']['mode']);
        $this->assertSame(ProdutoLmcLmpImporter::class, $catalog['produto_lmc_lmp']['importer']);
        $this->assertSame('codigo', $catalog['estoque_periodos']['key']);
        $this->assertSame('codigoUnidadeNegocio', $catalog['estoque_periodos']['company_field']);
        $this->assertSame(EstoquePeriodoImporter::class, $catalog['estoque_periodos']['importer']);
        $this->assertSame('lmcCodigo', $catalog['lmcs']['key']);
        $this->assertSame(LmcImporter::class, $catalog['lmcs']['importer']);
        $this->assertSame('tanqueCodigo', $catalog['tanques']['key']);
        $this->assertSame(TanqueImporter::class, $catalog['tanques']['importer']);
        $this->assertSame('bombaCodigo', $catalog['bombas']['key']);
        $this->assertSame(BombaImporter::class, $catalog['bombas']['importer']);
        $this->assertSame('snapshot_new', $catalog['bombas']['mode']);
        $this->assertSame(['bombaCodigo'], $catalog['bombas']['natural_keys']);
        $this->assertSame('bicoCodigo', $catalog['bicos']['key']);
        $this->assertSame(BicoImporter::class, $catalog['bicos']['importer']);
        $this->assertSame('snapshot_new', $catalog['funcionario_funcoes']['mode']);
        $this->assertFalse($catalog['funcionario_funcoes']['company_scoped']);
        $this->assertSame(FuncionarioFuncaoImporter::class, $catalog['funcionario_funcoes']['importer']);
        $this->assertSame('funcionarioCodigo', $catalog['funcionarios']['key']);
        $this->assertSame(FuncionarioImporter::class, $catalog['funcionarios']['importer']);

        $resources = array_keys($catalog);
        $positions = array_map(fn (string $resource): int => array_search($resource, $resources, true), [
            'produto_lmc_lmp', 'lmcs', 'tanques', 'bombas', 'bicos',
        ]);
        $this->assertSame(range($positions[0], $positions[0] + 4), $positions);
    }
}
