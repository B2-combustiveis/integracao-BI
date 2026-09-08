<?php

namespace Tests\Feature;

use App\Jobs\SyncWebPostoCompanyReconciliation;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Models\IntegrationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SyncWebPostoCompanyReconciliationTimeoutTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.webposto' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('webposto');
        Schema::connection('webposto')->create('abastecimentos', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('abastecimentoCodigo');
        });
    }

    private function companyRun(int $empresa): IntegrationServiceCompanyRun
    {
        $service = IntegrationService::query()->create([
            'name' => 'Timeout test',
            'slug' => 'timeout-test-'.str()->uuid(),
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

        return IntegrationServiceCompanyRun::query()->create([
            'integration_service_run_id' => $run->id,
            'empresa_codigo' => $empresa,
            'empresa_nome' => 'Empresa '.$empresa,
            'position' => 1,
            'status' => 'running',
            'resource_results' => [],
        ]);
    }

    public function test_small_company_gets_the_minimum_timeout_floor(): void
    {
        $companyRun = $this->companyRun(1);
        DB::connection('webposto')->table('abastecimentos')->insert([
            'empresaCodigo' => 1, 'abastecimentoCodigo' => 1,
        ]);
        $job = new SyncWebPostoCompanyReconciliation(
            $companyRun->integration_service_run_id,
            $companyRun->id,
            0,
            ['abastecimentos'],
        );

        $this->assertSame(2400, $job->timeout());
    }

    public function test_a_company_with_a_large_local_volume_gets_a_proportionally_larger_timeout(): void
    {
        $companyRun = $this->companyRun(2);
        $rows = array_map(fn (int $i): array => [
            'empresaCodigo' => 2, 'abastecimentoCodigo' => $i,
        ], range(1, 1_000_000));
        foreach (array_chunk($rows, 5000) as $chunk) {
            DB::connection('webposto')->table('abastecimentos')->insert($chunk);
        }
        $job = new SyncWebPostoCompanyReconciliation(
            $companyRun->integration_service_run_id,
            $companyRun->id,
            0,
            ['abastecimentos'],
        );

        // 1.000.000 linhas / 14.000 por minuto * 60s * fator de seguranca 2 = 8.572s,
        // mas o teto de 5.400s (90min) limita antes disso pra nao segurar o worker
        // indefinidamente (o resto fica pro webposto_sync_pending_records pegar depois).
        $this->assertSame(5400, $job->timeout());
    }

    public function test_no_resources_falls_back_to_the_minimum_timeout(): void
    {
        $companyRun = $this->companyRun(3);
        $job = new SyncWebPostoCompanyReconciliation(
            $companyRun->integration_service_run_id,
            $companyRun->id,
            0,
            [],
        );

        $this->assertSame(2400, $job->timeout());
    }
}
