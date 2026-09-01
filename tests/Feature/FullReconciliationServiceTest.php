<?php

namespace Tests\Feature;

use App\Jobs\SyncWebPostoNewRecords;
use App\Models\IntegrationService;
use App\Services\Integration\IntegrationServiceDispatcher;
use App\Services\WebPosto\WebPostoNewRecordsResourceCatalog;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class FullReconciliationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_is_registered_paused_with_the_fuel_structure_chain(): void
    {
        $service = IntegrationService::query()
            ->where('resource', 'webposto-full-reconciliation')
            ->sole();

        $this->assertSame('Reconciliação completa WebPosto', $service->name);
        $this->assertFalse($service->active);

        $this->assertSame(1440, $service->frequency_minutes);
        $this->assertSame([
            'tanques',
            'bombas',
            'produto_grupos',
            'produto_subgrupos',
            'produtos',
            'produto_empresas',
            'produto_lmc_lmp',
            'bicos',
            'lmcs',
            'estoque_periodos',
            'fornecedores',
            'compras',
            'titulos_pagar',
            'cliente_grupos',
            'clientes',
            'titulos_receber',
            'contas_bancarias',
            'caixas',
            'caixas_apresentados',
        ], $service->settings['resources']);
        $newRecords = IntegrationService::query()
            ->where('resource', 'webposto-new-records')
            ->sole();
        $resources = $newRecords->settings['resources'];
        $clientStart = array_search('cliente_grupos', $resources, true);
        $this->assertNotFalse($clientStart);
        $this->assertSame(
            ['cliente_grupos', 'clientes', 'cliente_empresas'],
            array_slice($resources, $clientStart, 3),
        );
        $salesStart = array_search('formas_pagamento', $resources, true);
        $this->assertNotFalse($salesStart);
        $this->assertSame(
            ['formas_pagamento', 'pdvs', 'vendas'],
            array_slice($resources, $salesStart, 3),
        );
        $this->assertNotContains('tanques', $resources);
        $this->assertContains('bombas', $newRecords->settings['resources']);
        $this->assertContains('bicos', $newRecords->settings['resources']);

        $bombas = app(WebPostoNewRecordsResourceCatalog::class)->get('bombas');
        $this->assertSame(1000, $bombas['limit']);
        $this->assertTrue($bombas['cursor']['single_page']);

        $bankAccounts = app(WebPostoNewRecordsResourceCatalog::class)->get('contas_bancarias');
        $this->assertSame('/INTEGRACAO/CONTA', $bankAccounts['endpoint']);
        $this->assertSame('contaCodigo', $bankAccounts['key']);
        $this->assertSame('2000-01-01', $bankAccounts['query']['dataInicial']);

        $lmcProducts = app(WebPostoNewRecordsResourceCatalog::class)->get('produto_lmc_lmp');
        $this->assertTrue($lmcProducts['cursor']['single_page']);
        $this->assertTrue($lmcProducts['cursor']['direct_list']);
    }

    public function test_it_has_a_safe_dispatch_path(): void
    {
        Bus::fake();
        $service = IntegrationService::query()
            ->where('resource', 'webposto-full-reconciliation')
            ->sole();

        $job = new SyncWebPostoNewRecords($service->id);
        $uniqueLock = app(UniqueLock::class);
        $uniqueLock->release($job);

        try {
            app(IntegrationServiceDispatcher::class)->dispatch($service->id);

            $this->assertNotNull($service->fresh()->next_run_at);
        } finally {
            $uniqueLock->release($job);
        }
    }
}
