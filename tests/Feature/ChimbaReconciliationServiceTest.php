<?php

namespace Tests\Feature;

use App\Jobs\SyncWebPostoCompanyReconciliation;
use App\Jobs\SyncWebPostoReconciliation;
use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Models\WebPostoCredential;
use App\Services\Integration\IntegrationRunChangeRecorder;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use App\Services\WebPosto\WebPostoNewRecordsResourceCatalog;
use App\Services\WebPosto\WebPostoPendingRecordService;
use App\Services\WebPosto\WebPostoReconciliationService;
use Illuminate\Support\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Tests\TestCase;

class ChimbaReconciliationServiceTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string|null> */
    protected array $connectionsToTransact = [null, 'webposto'];

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
        Bus::assertDispatched(SyncWebPostoCompanyReconciliation::class, function ($job): bool {
            return $job->queue === SyncWebPostoReconciliation::CHIMBA_QUEUE;
        });
    }

    public function test_b2_reconciliation_keeps_using_the_default_queue(): void
    {
        Bus::fake([SyncWebPostoCompanyReconciliation::class]);
        $service = IntegrationService::query()->create([
            'name' => 'Reconciliação B2 teste',
            'slug' => 'b2-reconciliation-'.str()->uuid(),
            'category' => 'atualizacao',
            'resource' => 'webposto-b2-reconciliation',
            'empresa_codigo' => 0,
            'frequency_minutes' => 1440,
            'active' => false,
            'settings' => ['resources' => ['abastecimentos']],
        ]);
        WebPostoCredential::query()->where('empresa_codigo', 4604)->update([
            'base' => WebPostoCredential::BASE_B2,
            'ativo' => true,
            'implantacao_status' => WebPostoCredential::STATUS_SINCRONIZADO,
        ]);

        (new SyncWebPostoReconciliation($service->id))->handle();

        Bus::assertDispatched(SyncWebPostoCompanyReconciliation::class, function ($job): bool {
            return $job->queue === 'default';
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

    public function test_incremental_window_falls_back_to_two_months_for_chimba_without_a_previous_success(): void
    {
        $service = IntegrationService::query()->create([
            'name' => 'Reconciliação Chimba teste sem historico',
            'slug' => 'chimba-reconciliation-'.str()->uuid(),
            'category' => 'atualizacao',
            'resource' => 'webposto-chimba-reconciliation',
            'empresa_codigo' => 4604,
            'frequency_minutes' => 1440,
            'active' => false,
            'settings' => ['empresa_codigos' => [4604], 'resources' => ['vendas']],
        ]);
        $run = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $method = new \ReflectionMethod(WebPostoReconciliationService::class, 'incrementalStartDate');

        $this->assertSame(
            now()->subMonths(2)->toDateString(),
            $method->invoke(app(WebPostoReconciliationService::class), $run->id),
        );
    }

    public function test_incremental_window_is_clamped_to_two_months_even_with_an_older_chimba_success(): void
    {
        $service = IntegrationService::query()->create([
            'name' => 'Reconciliação Chimba teste sucesso antigo',
            'slug' => 'chimba-reconciliation-'.str()->uuid(),
            'category' => 'atualizacao',
            'resource' => 'webposto-chimba-reconciliation',
            'empresa_codigo' => 4604,
            'frequency_minutes' => 1440,
            'active' => false,
            'settings' => ['empresa_codigos' => [4604], 'resources' => ['vendas']],
        ]);
        IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'success',
            'finished_at' => now()->subMonths(4),
        ]);
        $current = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $method = new \ReflectionMethod(WebPostoReconciliationService::class, 'incrementalStartDate');

        $this->assertSame(
            now()->subMonths(2)->toDateString(),
            $method->invoke(app(WebPostoReconciliationService::class), $current->id),
        );
    }

    public function test_chimba_clamps_transaction_dated_resources_to_two_months_even_without_the_updated_period_flag(): void
    {
        $service = IntegrationService::query()->create([
            'name' => 'Reconciliação Chimba teste clamp de vendas',
            'slug' => 'chimba-reconciliation-'.str()->uuid(),
            'category' => 'atualizacao',
            'resource' => 'webposto-chimba-reconciliation',
            'empresa_codigo' => 4604,
            'frequency_minutes' => 1440,
            'active' => false,
            'settings' => ['empresa_codigos' => [4604], 'resources' => ['vendas']],
        ]);
        $run = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $capturedQuery = null;
        $synchronizer = Mockery::mock(WebPostoCursorSynchronizer::class);
        $synchronizer->shouldReceive('synchronize')
            ->once()
            ->andReturnUsing(function (string $endpoint, int $empresaCodigo, callable $persist, array $query = []) use (&$capturedQuery): array {
                $capturedQuery = $query;

                return ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
            });
        $reconciliation = new WebPostoReconciliationService(
            app(WebPostoNewRecordsResourceCatalog::class),
            $synchronizer,
            app(IntegrationRunChangeRecorder::class),
            app(WebPostoPendingRecordService::class),
        );

        $reconciliation->synchronize(4604, ['vendas'], $run->id, null, 'webposto-chimba-reconciliation');

        $this->assertNotNull($capturedQuery);
        $this->assertSame(now()->subMonths(2)->toDateString(), $capturedQuery['dataInicial']);
        $this->assertSame(now()->toDateString(), $capturedQuery['dataFinal']);
    }

    public function test_incremental_window_stays_unbounded_for_other_services_without_a_previous_success(): void
    {
        $service = IntegrationService::query()->create([
            'name' => 'Reconciliação B2 teste sem historico',
            'slug' => 'b2-reconciliation-'.str()->uuid(),
            'category' => 'atualizacao',
            'resource' => 'webposto-b2-reconciliation',
            'empresa_codigo' => 0,
            'frequency_minutes' => 1440,
            'active' => false,
            'settings' => ['bases' => ['b2'], 'resources' => ['vendas']],
        ]);
        $run = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $method = new \ReflectionMethod(WebPostoReconciliationService::class, 'incrementalStartDate');

        $this->assertNull($method->invoke(app(WebPostoReconciliationService::class), $run->id));
    }
}
