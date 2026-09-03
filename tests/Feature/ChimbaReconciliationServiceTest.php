<?php

namespace Tests\Feature;

use App\Jobs\SyncWebPostoCompanyReconciliation;
use App\Jobs\SyncWebPostoReconciliation;
use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Models\WebPostoCredential;
use App\Services\WebPosto\WebPostoReconciliationService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ChimbaReconciliationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_service_is_paused_and_scoped_only_to_chimba(): void
    {
        $service = IntegrationService::query()->where('resource', 'webposto-chimba-reconciliation')->sole();

        $this->assertSame('Reconciliação Chimba', $service->name);
        $this->assertFalse($service->active);
        $this->assertSame([4604], $service->settings['empresa_codigos']);
        $this->assertCount(34, $service->settings['resources']);
        $this->assertContains('tanques', $service->settings['resources']);
        $this->assertContains('contas_bancarias', $service->settings['resources']);
        $this->assertContains('cartoes', $service->settings['resources']);
        $this->assertContains('vales_funcionario', $service->settings['resources']);
        $this->assertContains('movimentos_conta', $service->settings['resources']);
        $this->assertContains('centros_custo', $service->settings['resources']);
        $this->assertCount(4, $service->settings['worker_blocks']);
        $blockResources = collect($service->settings['worker_blocks'])->pluck('resources')->flatten();
        $this->assertCount(count($service->settings['resources']), $blockResources);
        $this->assertSame([], $blockResources->duplicates()->values()->all());
        $this->assertEqualsCanonicalizing($service->settings['resources'], $blockResources->all());
        $newRecords = IntegrationService::query()->where('resource', 'webposto-new-records')->sole();
        $this->assertEmpty(array_diff($newRecords->settings['resources'], $service->settings['resources']));
    }

    public function test_parent_job_creates_only_the_chimba_company_run(): void
    {
        Bus::fake([SyncWebPostoCompanyReconciliation::class]);
        $service = IntegrationService::query()->where('resource', 'webposto-chimba-reconciliation')->sole();
        WebPostoCredential::query()->where('empresa_codigo', 4604)->update([
            'base' => WebPostoCredential::BASE_CHIMBA,
            'ativo' => true,
            'implantacao_status' => WebPostoCredential::STATUS_SINCRONIZADO,
        ]);

        (new SyncWebPostoReconciliation($service->id))->handle();

        $run = $service->runs()->latest('id')->with('companyRuns')->firstOrFail();
        $this->assertSame('running', $run->status);
        $this->assertCount(4, $run->companyRuns);
        $this->assertSame([4604], $run->companyRuns->pluck('empresa_codigo')->unique()->values()->all());
        $this->assertSame([
            'POSTO CHIMBA · Cadastros e estoque',
            'POSTO CHIMBA · Vendas e financeiro',
            'POSTO CHIMBA · Itens de venda',
            'POSTO CHIMBA · Abastecimentos e fechamento',
        ], $run->companyRuns->sortBy('position')->pluck('empresa_nome')->all());
        Bus::assertDispatched(SyncWebPostoCompanyReconciliation::class, 4);
        Bus::assertDispatched(SyncWebPostoCompanyReconciliation::class, function ($job): bool {
            return $job->resources === ['venda_itens'];
        });
    }

    public function test_incremental_window_uses_previous_success_with_one_day_overlap(): void
    {
        $service = IntegrationService::query()->where('resource', 'webposto-chimba-reconciliation')->sole();
        $previous = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'success',
            'finished_at' => Carbon::parse('2026-09-02 18:35:20'),
        ]);
        $current = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $method = new \ReflectionMethod(WebPostoReconciliationService::class, 'incrementalStartDate');

        $this->assertSame('2026-09-01', $method->invoke(app(WebPostoReconciliationService::class), $current->id));
        $this->assertLessThan($current->id, $previous->id);
    }
}
