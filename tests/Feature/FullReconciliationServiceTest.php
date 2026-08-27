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
            'bicos',
            'produto_grupos',
            'produto_subgrupos',
            'produtos',
            'produto_empresas',
        ], $service->settings['resources']);
        $newRecords = IntegrationService::query()
            ->where('resource', 'webposto-new-records')
            ->sole();
        $this->assertContains('tanques', $newRecords->settings['resources']);
        $this->assertContains('bombas', $newRecords->settings['resources']);
        $this->assertContains('bicos', $newRecords->settings['resources']);

        $bombas = app(WebPostoNewRecordsResourceCatalog::class)->get('bombas');
        $this->assertSame(1000, $bombas['limit']);
        $this->assertTrue($bombas['cursor']['single_page']);
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