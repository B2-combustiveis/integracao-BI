<?php

namespace Tests\Feature;

use App\Jobs\SyncWebPostoCompanyReconciliation;
use App\Jobs\SyncWebPostoReconciliation;
use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Models\WebPostoCredential;
use App\Services\WebPosto\WebPostoReconciliationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class B2ReconciliationServiceTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string|null> */
    protected array $connectionsToTransact = [null, 'webposto'];

    public function test_service_is_paused_and_scoped_to_b2_with_all_resources(): void
    {
        $service = IntegrationService::query()->where('resource', 'webposto-b2-reconciliation')->sole();

        $this->assertSame('Reconciliação B2', $service->name);
        $this->assertTrue($service->active);
        $this->assertSame('America/Sao_Paulo', $service->settings['schedule_timezone']);
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
        $this->assertCount(count($expectedCodes) * $blockCount + 2, $run->companyRuns);
        $actualCodes = $run->companyRuns->pluck('empresa_codigo')
            ->map(fn ($code): int => (int) $code)
            ->unique()->sort()->values()->all();
        $this->assertSame($expectedCodes, $actualCodes);
        $this->assertNotContains(4604, $actualCodes);
        $regularRuns = $run->companyRuns->reject(fn ($companyRun): bool => str_starts_with($companyRun->block_key, 'shared-'));
        $regularRuns->groupBy('empresa_codigo')->each(function ($blocks) use ($blockCount): void {
            $this->assertCount($blockCount, $blocks);
        });
        Bus::assertDispatched(SyncWebPostoCompanyReconciliation::class, count($expectedCodes) * $blockCount + 2);
        Bus::assertDispatched(SyncWebPostoCompanyReconciliation::class, function ($job): bool {
            return $job->resources === ['venda_itens'];
        });
        Bus::assertDispatched(SyncWebPostoCompanyReconciliation::class, function ($job): bool {
            return $job->queue === 'default';
        });
        foreach (['cliente_empresas', 'abastecimentos'] as $sharedResource) {
            Bus::assertDispatched(SyncWebPostoCompanyReconciliation::class, function ($job) use ($sharedResource, $expectedCodes): bool {
                return $job->resources === [$sharedResource]
                    && $job->sharedCompanyCodes === $expectedCodes;
            });
            Bus::assertNotDispatched(SyncWebPostoCompanyReconciliation::class, function ($job) use ($sharedResource): bool {
                return $job->sharedCompanyCodes === [] && in_array($sharedResource, $job->resources, true);
            });
        }
    }

    public function test_b2_reconciliation_without_previous_success_starts_two_months_back(): void
    {
        $service = IntegrationService::query()->create([
            'name' => 'Reconciliação B2 teste de janela',
            'slug' => 'b2-window-'.str()->uuid(),
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

        $this->assertSame(
            now()->subMonths(2)->toDateString(),
            $method->invoke(app(WebPostoReconciliationService::class), $run->id),
        );
    }

    public function test_b2_reconciliation_starts_cursor_immediately_before_first_local_code_in_window(): void
    {
        $empresa = 99999999;
        DB::connection('webposto')->table('abastecimentos')->insert([
            'empresaCodigo' => $empresa,
            'abastecimentoCodigo' => 190000123,
            'dataHoraAbastecimento' => now()->subDays(10),
        ]);
        DB::connection('webposto')->table('abastecimentos')->insert([
            'empresaCodigo' => $empresa,
            'abastecimentoCodigo' => 120,
            'dataHoraAbastecimento' => now()->subMonths(4),
        ]);

        $method = new \ReflectionMethod(WebPostoReconciliationService::class, 'reconciliationCursorInitialValue');
        $definition = app(\App\Services\WebPosto\WebPostoNewRecordsResourceCatalog::class)->get('abastecimentos');

        $this->assertSame(190000122, $method->invoke(
            app(WebPostoReconciliationService::class),
            $definition,
            $empresa,
            ['dataInicial' => now()->subMonths(2)->toDateString()],
            'webposto-b2-reconciliation',
        ));
    }

    public function test_b2_reconciliation_keeps_safe_cursor_when_company_has_no_local_rows_in_window(): void
    {
        $method = new \ReflectionMethod(WebPostoReconciliationService::class, 'reconciliationCursorInitialValue');
        $definition = app(\App\Services\WebPosto\WebPostoNewRecordsResourceCatalog::class)->get('abastecimentos');

        $this->assertSame(1, $method->invoke(
            app(WebPostoReconciliationService::class),
            $definition,
            99999998,
            ['dataInicial' => now()->subMonths(2)->toDateString()],
            'webposto-b2-reconciliation',
        ));
    }

    public function test_updated_titles_always_start_from_the_safe_cursor(): void
    {
        $method = new \ReflectionMethod(WebPostoReconciliationService::class, 'reconciliationCursorInitialValue');
        $definition = app(\App\Services\WebPosto\WebPostoNewRecordsResourceCatalog::class)->get('titulos_pagar');

        $this->assertSame(1, $method->invoke(
            app(WebPostoReconciliationService::class),
            $definition,
            115424,
            ['dataInicial' => now()->subDay()->toDateString()],
            'webposto-b2-reconciliation',
        ));
    }

    public function test_shared_pages_are_strictly_partitioned_by_whitelisted_company(): void
    {
        $method = new \ReflectionMethod(WebPostoReconciliationService::class, 'sharedRowsByCompany');
        $groups = $method->invoke(app(WebPostoReconciliationService::class), [
            'resultados' => [
                ['empresaCodigo' => 10, 'codigo' => 1],
                ['empresaCodigo' => 20, 'codigo' => 2],
                ['empresaCodigo' => 30, 'codigo' => 3],
                ['codigo' => 4],
            ],
        ], 'empresaCodigo', [10, 20]);

        $this->assertSame([10, 20], $groups->keys()->all());
        $this->assertSame([1], $groups->get(10)->pluck('codigo')->all());
        $this->assertSame([2], $groups->get(20)->pluck('codigo')->all());
    }
}
