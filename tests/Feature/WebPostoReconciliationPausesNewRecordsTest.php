<?php

namespace Tests\Feature;

use App\Jobs\SyncWebPostoCompanyReconciliation;
use App\Jobs\SyncWebPostoReconciliation;
use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Models\WebPostoCredential;
use App\Services\Integration\WebPostoReconciliationCoordinator;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use ReflectionMethod;
use Tests\TestCase;

class WebPostoReconciliationPausesNewRecordsTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string|null> */
    protected array $connectionsToTransact = [null, 'webposto'];

    public function test_starting_a_reconciliation_pauses_active_new_records(): void
    {
        Bus::fake([SyncWebPostoCompanyReconciliation::class]);
        $newRecords = IntegrationService::query()->where('resource', 'webposto-new-records')->firstOrFail();
        $newRecords->update(['active' => true, 'next_run_at' => now(), 'settings' => []]);
        $chimbaService = IntegrationService::query()->where('resource', 'webposto-chimba-reconciliation')->sole();
        WebPostoCredential::query()->where('empresa_codigo', 4604)->update([
            'base' => WebPostoCredential::BASE_CHIMBA,
            'ativo' => true,
            'implantacao_status' => WebPostoCredential::STATUS_SINCRONIZADO,
        ]);

        (new SyncWebPostoReconciliation($chimbaService->id))->handle();

        $newRecords->refresh();
        $this->assertTrue($newRecords->active);
        $this->assertTrue($newRecords->settings['suspended_by_reconciliation'] ?? false);
        $this->assertTrue(app(WebPostoReconciliationCoordinator::class)->newRecordsAreSuspended());
    }

    public function test_finishing_the_only_running_reconciliation_resumes_new_records(): void
    {
        Bus::fake([SyncWebPostoCompanyReconciliation::class]);
        $newRecords = IntegrationService::query()->where('resource', 'webposto-new-records')->firstOrFail();
        $newRecords->update(['active' => true, 'next_run_at' => now(), 'settings' => []]);
        $chimbaService = IntegrationService::query()->where('resource', 'webposto-chimba-reconciliation')->sole();
        WebPostoCredential::query()->where('empresa_codigo', 4604)->update([
            'base' => WebPostoCredential::BASE_CHIMBA,
            'ativo' => true,
            'implantacao_status' => WebPostoCredential::STATUS_SINCRONIZADO,
        ]);
        (new SyncWebPostoReconciliation($chimbaService->id))->handle();
        $this->assertTrue($newRecords->refresh()->settings['suspended_by_reconciliation'] ?? false);

        $run = $chimbaService->runs()->latest('id')->firstOrFail();
        $run->companyRuns()->update(['status' => 'success', 'finished_at' => now()]);
        $job = new SyncWebPostoCompanyReconciliation(
            $run->id,
            $run->companyRuns()->first()->id,
            $chimbaService->id,
            [],
            SyncWebPostoReconciliation::CHIMBA_QUEUE,
        );
        (new ReflectionMethod(SyncWebPostoCompanyReconciliation::class, 'finalizeRunIfComplete'))->invoke($job);

        $newRecords->refresh();
        $this->assertTrue($newRecords->active);
        $this->assertArrayNotHasKey('suspended_by_reconciliation', $newRecords->settings ?? []);
        $this->assertNotNull($newRecords->next_run_at);
    }

    public function test_does_not_resume_new_records_while_a_sibling_reconciliation_is_still_running(): void
    {
        Bus::fake([SyncWebPostoCompanyReconciliation::class]);
        $newRecords = IntegrationService::query()->where('resource', 'webposto-new-records')->firstOrFail();
        $newRecords->update(['active' => true, 'next_run_at' => now(), 'settings' => []]);
        $chimbaService = IntegrationService::query()->where('resource', 'webposto-chimba-reconciliation')->sole();
        $b2Service = IntegrationService::query()->where('resource', 'webposto-b2-reconciliation')->sole();
        WebPostoCredential::query()->where('empresa_codigo', 4604)->update([
            'base' => WebPostoCredential::BASE_CHIMBA,
            'ativo' => true,
            'implantacao_status' => WebPostoCredential::STATUS_SINCRONIZADO,
        ]);
        IntegrationServiceRun::query()->create([
            'integration_service_id' => $b2Service->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        (new SyncWebPostoReconciliation($chimbaService->id))->handle();
        $this->assertTrue($newRecords->refresh()->settings['suspended_by_reconciliation'] ?? false);

        $run = $chimbaService->runs()->latest('id')->firstOrFail();
        $run->companyRuns()->update(['status' => 'success', 'finished_at' => now()]);
        $job = new SyncWebPostoCompanyReconciliation(
            $run->id,
            $run->companyRuns()->first()->id,
            $chimbaService->id,
            [],
            SyncWebPostoReconciliation::CHIMBA_QUEUE,
        );
        (new ReflectionMethod(SyncWebPostoCompanyReconciliation::class, 'finalizeRunIfComplete'))->invoke($job);

        $this->assertTrue($newRecords->refresh()->active);
        $this->assertTrue($newRecords->settings['suspended_by_reconciliation'] ?? false, 'deveria continuar suspenso enquanto o B2 esta rodando');
    }

    public function test_does_not_touch_new_records_that_was_already_manually_paused(): void
    {
        $newRecords = IntegrationService::query()->where('resource', 'webposto-new-records')->firstOrFail();
        $newRecords->update(['active' => false, 'next_run_at' => null, 'settings' => []]);

        app(WebPostoReconciliationCoordinator::class)->pauseNewRecordsForReconciliation();
        $newRecords->refresh();
        $this->assertFalse($newRecords->active);
        $this->assertTrue($newRecords->settings['suspended_by_reconciliation'] ?? false);

        app(WebPostoReconciliationCoordinator::class)->resumeNewRecordsIfNoReconciliationRunning();
        $newRecords->refresh();
        $this->assertFalse($newRecords->active, 'pause manual pre-existente nao deveria ser reativado pela reconciliacao');
        $this->assertArrayNotHasKey('suspended_by_reconciliation', $newRecords->settings ?? []);
    }
}
