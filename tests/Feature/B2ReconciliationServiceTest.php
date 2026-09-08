<?php

namespace Tests\Feature;

use App\Jobs\SyncWebPostoCompanyReconciliation;
use App\Jobs\SyncWebPostoReconciliation;
use App\Models\IntegrationService;
use App\Models\WebPostoCredential;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2ReconciliationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_service_is_paused_and_scoped_to_b2_with_all_resources(): void
    {
        $service = IntegrationService::query()->where('resource', 'webposto-b2-reconciliation')->sole();

        $this->assertSame('Reconciliação B2', $service->name);
        $this->assertFalse($service->active);
        $this->assertSame(['b2'], $service->settings['bases']);
        $this->assertCount(34, $service->settings['resources']);
        $this->assertCount(4, $service->settings['worker_blocks']);
        $blockResources = collect($service->settings['worker_blocks'])->pluck('resources')->flatten();
        $this->assertCount(count($service->settings['resources']), $blockResources);
        $this->assertSame([], $blockResources->duplicates()->values()->all());
        $this->assertEqualsCanonicalizing($service->settings['resources'], $blockResources->all());
    }

    public function test_parent_dispatches_one_job_per_block_for_each_active_synchronized_b2_company(): void
    {
        Bus::fake([SyncWebPostoCompanyReconciliation::class]);
        $service = IntegrationService::query()->where('resource', 'webposto-b2-reconciliation')->sole();
        $expectedCodes = DB::connection('webposto')->table('webposto_credentials')
            ->where('base', WebPostoCredential::BASE_B2)
            ->where('ativo', true)
            ->where('implantacao_status', WebPostoCredential::STATUS_SINCRONIZADO)
            ->orderBy('empresa_codigo')
            ->pluck('empresa_codigo')
            ->map(fn ($code): int => (int) $code)
            ->all();
        $blockCount = count($service->settings['worker_blocks']);

        (new SyncWebPostoReconciliation($service->id))->handle();

        $run = $service->runs()->latest('id')->with('companyRuns')->firstOrFail();
        $this->assertCount(count($expectedCodes) * $blockCount, $run->companyRuns);
        $actualCodes = $run->companyRuns->pluck('empresa_codigo')
            ->map(fn ($code): int => (int) $code)
            ->unique()->sort()->values()->all();
        $this->assertSame($expectedCodes, $actualCodes);
        $this->assertNotContains(4604, $actualCodes);
        $run->companyRuns->groupBy('empresa_codigo')->each(function ($blocks) use ($blockCount): void {
            $this->assertCount($blockCount, $blocks);
        });
        Bus::assertDispatched(SyncWebPostoCompanyReconciliation::class, count($expectedCodes) * $blockCount);
        Bus::assertDispatched(SyncWebPostoCompanyReconciliation::class, function ($job): bool {
            return $job->resources === ['venda_itens'];
        });
        Bus::assertDispatched(SyncWebPostoCompanyReconciliation::class, function ($job): bool {
            return $job->queue === 'default';
        });
    }
}
