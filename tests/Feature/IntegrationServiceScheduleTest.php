<?php

namespace Tests\Feature;

use App\Jobs\SyncWebPostoNewRecords;
use App\Jobs\SyncWebPostoReconciliation;
use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Services\Integration\IntegrationServiceDispatcher;
use App\Services\Integration\WebPostoReconciliationCoordinator;
use Carbon\Carbon;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class IntegrationServiceScheduleTest extends TestCase
{
    use DatabaseTransactions;

    public function test_reconciliations_take_priority_and_new_records_resume_only_after_both_finish(): void
    {
        Bus::fake([SyncWebPostoReconciliation::class, SyncWebPostoNewRecords::class]);
        IntegrationServiceRun::query()->whereIn('status', ['running', 'finalizing'])->update([
            'status' => 'failed',
            'finished_at' => now(),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-09-08 15:00:00', 'America/Sao_Paulo')->utc());

        try {
            $newRecords = IntegrationService::query()->where('resource', 'webposto-new-records')->sole();
            $chimba = IntegrationService::query()->where('resource', 'webposto-chimba-reconciliation')->sole();
            $b2 = IntegrationService::query()->where('resource', 'webposto-b2-reconciliation')->sole();
            app(UniqueLock::class)->release(new SyncWebPostoReconciliation($chimba->id));
            app(UniqueLock::class)->release(new SyncWebPostoReconciliation($b2->id));
            app(UniqueLock::class)->release(new SyncWebPostoNewRecords($newRecords->id));
            $newRecords->update(['active' => true, 'frequency_minutes' => 10, 'next_run_at' => now(), 'settings' => []]);
            $chimba->update(['active' => true, 'next_run_at' => now(), 'settings' => [...$chimba->settings, 'daily_at' => '15:00', 'schedule_timezone' => 'America/Sao_Paulo']]);
            $b2->update(['active' => true, 'next_run_at' => now()->addHour(), 'settings' => [...$b2->settings, 'daily_at' => '16:00', 'schedule_timezone' => 'America/Sao_Paulo']]);

            $this->assertSame(1, app(IntegrationServiceDispatcher::class)->dispatchDue());
            Bus::assertDispatched(SyncWebPostoReconciliation::class, fn ($job): bool => $job->serviceId === $chimba->id);
            Bus::assertNotDispatched(SyncWebPostoNewRecords::class);
            $this->assertSame('2026-09-09 15:00:00', $chimba->refresh()->next_run_at->timezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));

            Carbon::setTestNow(Carbon::parse('2026-09-08 16:00:00', 'America/Sao_Paulo')->utc());
            $this->assertSame(1, app(IntegrationServiceDispatcher::class)->dispatchDue());
            $holds = $newRecords->refresh()->settings['reconciliation_holds'];
            $this->assertEqualsCanonicalizing(['webposto-chimba-reconciliation', 'webposto-b2-reconciliation'], $holds);

            $coordinator = app(WebPostoReconciliationCoordinator::class);
            $coordinator->resumeNewRecordsIfNoReconciliationRunning('webposto-chimba-reconciliation');
            $this->assertTrue($coordinator->newRecordsAreSuspended());
            $coordinator->resumeNewRecordsIfNoReconciliationRunning('webposto-b2-reconciliation');
            $this->assertFalse($coordinator->newRecordsAreSuspended());
            $this->assertTrue($newRecords->refresh()->next_run_at->equalTo(now()));
        } finally {
            if (isset($chimba, $b2, $newRecords)) {
                app(UniqueLock::class)->release(new SyncWebPostoReconciliation($chimba->id));
                app(UniqueLock::class)->release(new SyncWebPostoReconciliation($b2->id));
                app(UniqueLock::class)->release(new SyncWebPostoNewRecords($newRecords->id));
            }
            Carbon::setTestNow();
        }
    }

    public function test_parent_job_failure_releases_its_reconciliation_hold(): void
    {
        $newRecords = IntegrationService::query()->where('resource', 'webposto-new-records')->sole();
        $newRecords->update(['active' => true, 'settings' => []]);
        $chimba = IntegrationService::query()->where('resource', 'webposto-chimba-reconciliation')->sole();
        $coordinator = app(WebPostoReconciliationCoordinator::class);
        $coordinator->pauseNewRecordsForReconciliation($chimba->resource);

        (new SyncWebPostoReconciliation($chimba->id))->failed(new \RuntimeException('falha simulada'));

        $this->assertFalse($coordinator->newRecordsAreSuspended());
        $this->assertArrayNotHasKey('reconciliation_holds', $newRecords->refresh()->settings ?? []);
    }

    public function test_service_does_not_dispatch_a_second_run_while_the_first_is_active(): void
    {
        Bus::fake([SyncWebPostoNewRecords::class]);
        $service = IntegrationService::query()->where('resource', 'webposto-new-records')->sole();
        $service->update(['active' => true, 'next_run_at' => now()]);
        IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->assertFalse(app(IntegrationServiceDispatcher::class)->dispatch($service->id));
        Bus::assertNotDispatched(SyncWebPostoNewRecords::class);
    }
}
