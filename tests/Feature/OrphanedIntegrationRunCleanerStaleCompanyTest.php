<?php

namespace Tests\Feature;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Services\Integration\OrphanedIntegrationRunCleaner;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class OrphanedIntegrationRunCleanerStaleCompanyTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_cancels_a_company_run_whose_heartbeat_has_gone_silent(): void
    {
        $service = IntegrationService::query()->create([
            'name' => 'Watchdog test',
            'slug' => 'watchdog-test-'.str()->uuid(),
            'category' => 'atualizacao',
            'resource' => 'webposto-b2-reconciliation',
            'empresa_codigo' => 0,
            'frequency_minutes' => 1440,
            'active' => false,
            'settings' => ['resources' => []],
        ]);
        $run = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $stale = IntegrationServiceCompanyRun::query()->create([
            'integration_service_run_id' => $run->id,
            'empresa_codigo' => 56046,
            'empresa_nome' => 'UAI COMBUSTIVEIS',
            'position' => 1,
            'status' => 'running',
            'current_resource' => 'abastecimentos',
            'current_page' => 320,
            'heartbeat_at' => now()->subMinutes(15),
            'resource_results' => [],
        ]);
        $fresh = IntegrationServiceCompanyRun::query()->create([
            'integration_service_run_id' => $run->id,
            'empresa_codigo' => 115408,
            'empresa_nome' => 'WJE',
            'position' => 2,
            'status' => 'running',
            'current_resource' => 'clientes',
            'current_page' => 3,
            'heartbeat_at' => now()->subSeconds(5),
            'resource_results' => [],
        ]);

        $cleaned = (new OrphanedIntegrationRunCleaner)->cleanStaleCompanyRuns(10);

        $this->assertSame(1, $cleaned);
        $stale->refresh();
        $this->assertSame('failed', $stale->status);
        $this->assertNull($stale->current_resource);
        $this->assertNull($stale->heartbeat_at);
        $this->assertNotNull($stale->finished_at);
        $this->assertStringContainsString('sem resposta', $stale->error);

        $fresh->refresh();
        $this->assertSame('running', $fresh->status);

        $run->refresh();
        $this->assertSame('running', $run->status);
    }

    public function test_it_finalizes_the_run_once_every_company_is_done(): void
    {
        $service = IntegrationService::query()->create([
            'name' => 'Watchdog finalize test',
            'slug' => 'watchdog-finalize-test-'.str()->uuid(),
            'category' => 'atualizacao',
            'resource' => 'webposto-b2-reconciliation',
            'empresa_codigo' => 0,
            'frequency_minutes' => 1440,
            'active' => false,
            'settings' => ['resources' => []],
        ]);
        $run = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        IntegrationServiceCompanyRun::query()->create([
            'integration_service_run_id' => $run->id,
            'empresa_codigo' => 56046,
            'empresa_nome' => 'UAI COMBUSTIVEIS',
            'position' => 1,
            'status' => 'running',
            'heartbeat_at' => now()->subMinutes(20),
            'resource_results' => [],
        ]);
        IntegrationServiceCompanyRun::query()->create([
            'integration_service_run_id' => $run->id,
            'empresa_codigo' => 115408,
            'empresa_nome' => 'WJE',
            'position' => 2,
            'status' => 'success',
            'finished_at' => now(),
            'resource_results' => [],
        ]);

        (new OrphanedIntegrationRunCleaner)->cleanStaleCompanyRuns(10);

        $run->refresh();
        $this->assertSame('partial', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertStringContainsString('UAI COMBUSTIVEIS', $run->error);
    }
}
