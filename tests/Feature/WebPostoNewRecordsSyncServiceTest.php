<?php

namespace Tests\Feature;

use App\Jobs\SyncWebPostoCompanyNewRecords;
use App\Jobs\SyncWebPostoNewRecords;
use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Models\WebPostoSyncControl;
use App\Services\Integration\IntegrationRunChangeRecorder;
use App\Services\WebPosto\WebPostoClient;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use App\Services\WebPosto\WebPostoNewRecordsResourceCatalog;
use App\Services\WebPosto\WebPostoNewRecordsSyncService;
use App\Services\WebPosto\WebPostoPendingRecordService;
use App\Services\WebPosto\CartaoImporter;
use App\Services\WebPosto\CentroCustoImporter;
use App\Services\WebPosto\ContaBancariaImporter;
use App\Services\WebPosto\MovimentoContaImporter;
use App\Services\WebPosto\ValeFuncionarioImporter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class WebPostoNewRecordsSyncServiceTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, int> */
    private array $serviceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.webposto' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
        DB::purge('webposto');
        Schema::connection('webposto')->create('strict_new_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresaCodigo');
            $table->unsignedBigInteger('registroCodigo');
            $table->string('nome');
            $table->timestamps();
            $table->unique(['empresaCodigo', 'registroCodigo']);
        });
        Schema::connection('webposto')->create('empresas', function (Blueprint $table): void {
            $table->unsignedBigInteger('empresaCodigo')->primary();
            $table->string('fantasia')->nullable();
            $table->string('razao')->nullable();
        });
        Schema::connection('webposto')->create('webposto_credentials', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresa_codigo')->unique();
            $table->string('base_url');
            $table->text('token');
            $table->string('base')->default('b1');
            $table->boolean('ativo')->default(true);
            $table->string('implantacao_status')->default('aguardando_sincronizacao');
            $table->timestamps();
        });
        DB::connection('webposto')->table('empresas')->insert([
            'empresaCodigo' => 4604, 'fantasia' => 'Posto Chimba', 'razao' => null,
        ]);
        DB::connection('webposto')->table('webposto_credentials')->insert([
            'empresa_codigo' => 4604, 'base_url' => 'https://example.test', 'token' => 'test',
            'implantacao_status' => 'sincronizado',
            'ativo' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        StrictNewRecordImporter::$payload = [];
    }

    protected function tearDown(): void
    {
        IntegrationService::query()->whereIn('id', $this->serviceIds)->delete();
        parent::tearDown();
    }

    public function test_it_only_persists_and_logs_unknown_rows_from_the_requested_company(): void
    {
        DB::connection('webposto')->table('strict_new_records')->insert([
            'empresaCodigo' => 4604,
            'registroCodigo' => 10,
            'nome' => 'Original',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = IntegrationService::query()->create([
            'name' => 'Strict new records test',
            'slug' => 'strict-new-records-'.str()->uuid(),
            'category' => 'cadastros',
            'resource' => 'webposto-new-records',
            'empresa_codigo' => 4604,
            'settings' => ['resources' => ['strict']],
        ]);
        $this->serviceIds[] = $service->id;
        $run = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $definition = [
            'endpoint' => '/STRICT',
            'table' => 'strict_new_records',
            'key' => 'registroCodigo',
            'limit' => 1000,
            'query' => [],
            'importer' => StrictNewRecordImporter::class,
            'updated_field' => 'dataHoraAtualizacao',
        ];
        $catalog = Mockery::mock(WebPostoNewRecordsResourceCatalog::class);
        $catalog->shouldReceive('get')->once()->with('strict', 'b1')->andReturn($definition);
        $synchronizer = Mockery::mock(WebPostoCursorSynchronizer::class);
        $synchronizer->shouldReceive('synchronize')->once()->andReturnUsing(function (...$arguments): array {
            $persist = $arguments[2];
            $stored = $persist(['ultimoCodigo' => 12, 'resultados' => [
                ['empresaCodigo' => 4604, 'registroCodigo' => 10, 'nome' => 'Alterado'],
                ['empresaCodigo' => 4604, 'registroCodigo' => 11, 'nome' => 'Novo'],
                ['empresaCodigo' => 9999, 'registroCodigo' => 12, 'nome' => 'Outra empresa'],
            ]], ['ultimoCodigo' => 10]);
            return ['pages' => 1, 'received' => 3, ...$stored];
        });

        $subject = new WebPostoNewRecordsSyncService(
            $catalog,
            $synchronizer,
            app(IntegrationRunChangeRecorder::class),
            Mockery::mock(WebPostoClient::class),
            app(WebPostoPendingRecordService::class),
        );
        $subject->synchronize(4604, ['strict'], $run->id);

        $this->assertSame([11], collect(StrictNewRecordImporter::$payload)->pluck('registroCodigo')->all());
        $this->assertDatabaseHas('strict_new_records', [
            'empresaCodigo' => 4604,
            'registroCodigo' => 10,
            'nome' => 'Original',
        ], 'webposto');
        $this->assertDatabaseHas('strict_new_records', [
            'empresaCodigo' => 4604,
            'registroCodigo' => 11,
            'nome' => 'Novo',
        ], 'webposto');
        $this->assertDatabaseMissing('strict_new_records', [
            'empresaCodigo' => 9999,
            'registroCodigo' => 12,
        ], 'webposto');
        $this->assertSame([11], $run->fresh()->changes()->pluck('natural_key')->map(
            fn (array $key) => $key['registroCodigo'],
        )->all());
        $this->assertSame(2, $run->fresh()->received);
        $this->assertSame(1, $run->fresh()->inserted);
    }

    public function test_successful_snapshot_normalizes_a_legacy_cursor_error(): void
    {
        $service = IntegrationService::query()->create([
            'name' => 'Snapshot legacy control test',
            'slug' => 'snapshot-legacy-control-'.str()->uuid(),
            'category' => 'cadastros',
            'resource' => 'webposto-new-records',
            'empresa_codigo' => 4604,
            'settings' => ['resources' => ['bombas']],
        ]);
        $this->serviceIds[] = $service->id;
        $run = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $control = WebPostoSyncControl::query()->updateOrCreate([
            'empresa_codigo' => 4604,
            'endpoint' => '/INTEGRACAO/BOMBA:new-records',
        ], [
            'strategy' => 'B',
            'last_code' => 6857,
            'status' => 'error',
            'consecutive_failures' => 3,
            'last_error' => 'Cursor ultimoCodigo ausente ou sem avanco em /INTEGRACAO/BOMBA.',
            'metadata' => ['cursor_type' => 'ultimo_codigo', 'cursor_value' => 6857],
        ]);
        $definition = [
            'endpoint' => '/INTEGRACAO/BOMBA',
            'table' => 'strict_new_records',
            'key' => 'registroCodigo',
            'natural_keys' => ['registroCodigo'],
            'mode' => 'snapshot_new',
            'query' => [],
            'importer' => StrictNewRecordImporter::class,
            'updated_field' => 'dataHoraAtualizacao',
        ];
        $catalog = Mockery::mock(WebPostoNewRecordsResourceCatalog::class);
        $catalog->shouldReceive('get')->once()->with('bombas', 'b1')->andReturn($definition);
        $client = Mockery::mock(WebPostoClient::class);
        $response = Mockery::mock();
        $response->shouldReceive('successful')->once()->andReturnTrue();
        $client->shouldReceive('get')->once()->andReturn([
            'response' => $response,
            'duration_ms' => 1,
            'payload' => ['resultados' => [[
                'empresaCodigo' => 4604,
                'registroCodigo' => 10,
                'nome' => 'Bomba 1',
            ]]],
        ]);
        $subject = new WebPostoNewRecordsSyncService(
            $catalog,
            Mockery::mock(WebPostoCursorSynchronizer::class),
            app(IntegrationRunChangeRecorder::class),
            $client,
            app(WebPostoPendingRecordService::class),
        );

        $result = $subject->synchronize(4604, ['bombas'], $run->id);

        $this->assertSame(1, $result['bombas']['inserted']);
        $control->refresh();
        $this->assertSame('ok', $control->status);
        $this->assertSame(0, $control->consecutive_failures);
        $this->assertNull($control->last_error);
        $this->assertTrue($control->metadata['snapshot_new']);
        $this->assertTrue($control->metadata['legacy_cursor_retired']);
    }

    public function test_it_retries_a_pending_record_and_logs_it_when_the_dependency_is_available(): void
    {
        $service = IntegrationService::query()->create([
            'name' => 'Pending records test',
            'slug' => 'pending-records-'.str()->uuid(),
            'category' => 'cadastros',
            'resource' => 'webposto-new-records',
            'empresa_codigo' => 4604,
            'settings' => ['resources' => ['strict']],
        ]);
        $this->serviceIds[] = $service->id;
        $firstRun = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'success',
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        $secondRun = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'started_at' => now(),
        ]);
        $definition = [
            'endpoint' => '/STRICT',
            'table' => 'strict_new_records',
            'key' => 'registroCodigo',
            'limit' => 1000,
            'query' => [],
            'importer' => StrictNewRecordImporter::class,
            'updated_field' => 'dataHoraAtualizacao',
        ];
        $pending = app(WebPostoPendingRecordService::class);
        $pending->storeMissing($definition, 4604, $firstRun->id, 'strict', [[
            'empresaCodigo' => 4604,
            'registroCodigo' => 21,
            'nome' => 'Chegou depois',
        ]]);

        $this->assertDatabaseHas('webposto_sync_pending_records', [
            'empresa_codigo' => 4604,
            'resource' => 'strict',
            'status' => 'pending',
            'attempt_count' => 0,
        ]);

        $result = $pending->retry($definition, 4604, $secondRun->id, 'strict');

        $this->assertSame(1, $result['inserted']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseHas('strict_new_records', [
            'empresaCodigo' => 4604,
            'registroCodigo' => 21,
        ], 'webposto');
        $this->assertDatabaseHas('webposto_sync_pending_records', [
            'empresa_codigo' => 4604,
            'resource' => 'strict',
            'status' => 'resolved',
            'attempt_count' => 1,
            'resolved_run_id' => $secondRun->id,
        ]);
        $this->assertSame([21], $secondRun->changes()->pluck('natural_key')->map(
            fn (array $key) => $key['registroCodigo'],
        )->all());
    }

    public function test_new_records_dispatches_one_job_per_company_and_aggregates_the_run(): void
    {
        DB::connection('webposto')->table('empresas')->insert([
            'empresaCodigo' => 9999,
            'fantasia' => 'Segundo Posto',
            'razao' => null,
        ]);
        DB::connection('webposto')->table('webposto_credentials')->insert([
            'empresa_codigo' => 9999,
            'base_url' => 'https://second.example.test',
            'token' => 'second-test-token',
            'implantacao_status' => 'sincronizado',
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $service = IntegrationService::query()->create([
            'name' => 'Multi-company new records test',
            'slug' => 'multi-company-new-records-'.str()->uuid(),
            'category' => 'cadastros',
            'resource' => 'webposto-new-records',
            'empresa_codigo' => 4604,
            'frequency_minutes' => 1440,
            'settings' => ['resources' => ['tanques', 'bombas', 'bicos']],
        ]);
        $this->serviceIds[] = $service->id;

        Queue::fake([SyncWebPostoCompanyNewRecords::class]);
        (new SyncWebPostoNewRecords($service->id))->handle();
        Queue::assertPushed(SyncWebPostoCompanyNewRecords::class, 2);

        $calls = [];
        $synchronizer = Mockery::mock(WebPostoNewRecordsSyncService::class);
        $synchronizer->shouldReceive('synchronize')->twice()
            ->andReturnUsing(function (
                int $empresa,
                array $resources,
                int $runId,
                ?callable $onProgress = null,
            ) use (&$calls): array {
                $calls[] = [
                    'empresa' => $empresa,
                    'resources' => $resources,
                ];
                $results = [];
                foreach ($resources as $resource) {
                    $result = [
                        'pages' => 1,
                        'received' => 1,
                        'inserted' => 0,
                        'updated' => 0,
                        'unchanged' => 1,
                        'skipped' => 0,
                    ];
                    if ($onProgress !== null) $onProgress('running', $resource, null);
                    if ($onProgress !== null) $onProgress('completed', $resource, $result);
                    $results[$resource] = $result;
                }

                IntegrationServiceRun::query()->whereKey($runId)
                    ->increment('received', count($resources));

                return $results;
            });

        // Simulates the queue workers picking up each per-company job (order is
        // deterministic here because the parent dispatches them in empresa_codigo order).
        Queue::pushed(SyncWebPostoCompanyNewRecords::class)->each(
            fn (SyncWebPostoCompanyNewRecords $job) => $job->handle($synchronizer),
        );

        $this->assertSame([
            [
                'empresa' => 4604,
                'resources' => ['tanques', 'bombas', 'bicos'],
            ],
            [
                'empresa' => 9999,
                'resources' => ['tanques', 'bombas', 'bicos'],
            ],
        ], $calls);

        $run = IntegrationServiceRun::query()
            ->where('integration_service_id', $service->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('success', $run->status);
        $this->assertSame(6, $run->received);
        $this->assertSame(
            [
                ['empresa_codigo' => 4604, 'position' => 1, 'status' => 'success'],
                ['empresa_codigo' => 9999, 'position' => 2, 'status' => 'success'],
            ],
            $run->companyRuns()->orderBy('position')
                ->get(['empresa_codigo', 'position', 'status'])
                ->map(fn ($company): array => [
                    'empresa_codigo' => (int) $company->empresa_codigo,
                    'position' => (int) $company->position,
                    'status' => $company->status,
                ])->all(),
        );
    }

    public function test_new_records_excludes_chimba_from_the_eligible_companies(): void
    {
        DB::connection('webposto')->table('webposto_credentials')
            ->where('empresa_codigo', 4604)
            ->update(['base' => 'chimba']);
        $service = IntegrationService::query()->create([
            'name' => 'Exclude Chimba from new records test',
            'slug' => 'exclude-chimba-new-records-'.str()->uuid(),
            'category' => 'cadastros',
            'resource' => 'webposto-new-records',
            'empresa_codigo' => 4604,
            'frequency_minutes' => 1440,
            'settings' => ['resources' => ['strict']],
        ]);
        $this->serviceIds[] = $service->id;

        Queue::fake([SyncWebPostoCompanyNewRecords::class]);
        (new SyncWebPostoNewRecords($service->id))->handle();

        Queue::assertNothingPushed();
        $run = IntegrationServiceRun::query()
            ->where('integration_service_id', $service->id)
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame('Nenhum posto sincronizado e ativo foi encontrado.', $run->error);
        $this->assertCount(0, $run->companyRuns);
    }

    public function test_emp_ignores_a_company_that_is_still_awaiting_initial_load(): void
    {
        DB::connection('webposto')->table('webposto_credentials')
            ->where('empresa_codigo', 4604)
            ->update([
                'implantacao_status' => 'aguardando_sincronizacao',
            ]);
        $service = IntegrationService::query()->create([
            'name' => 'EMP eligibility test',
            'slug' => 'emp-eligibility-'.str()->uuid(),
            'category' => 'cadastros',
            'resource' => 'webposto-new-records',
            'empresa_codigo' => 4604,
            'frequency_minutes' => 5,
            'settings' => ['resources' => ['strict']],
        ]);
        $this->serviceIds[] = $service->id;
        $synchronizer = Mockery::mock(WebPostoNewRecordsSyncService::class);
        $synchronizer->shouldNotReceive('synchronize');

        (new SyncWebPostoNewRecords($service->id))->handle($synchronizer);

        $run = IntegrationServiceRun::query()->where('integration_service_id', $service->id)
            ->latest('id')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame(
            'Nenhum posto sincronizado e ativo foi encontrado.',
            $run->error,
        );
    }

    public function test_each_dispatch_has_only_one_attempt_and_therefore_one_report(): void
    {
        $this->assertSame(1, (new SyncWebPostoNewRecords(123))->tries);
    }

    public function test_bi_financial_resources_have_their_dependencies_available(): void
    {
        $catalog = app(WebPostoNewRecordsResourceCatalog::class);

        $this->assertSame('/INTEGRACAO/CONTA', $catalog->get('contas_bancarias')['endpoint']);
        $this->assertSame(ContaBancariaImporter::class, $catalog->get('contas_bancarias')['importer']);
        $this->assertSame(MovimentoContaImporter::class, $catalog->get('movimentos_conta')['importer']);
        $this->assertSame(CartaoImporter::class, $catalog->get('cartoes')['importer']);
        $this->assertSame(CentroCustoImporter::class, $catalog->get('centros_custo')['importer']);
        $this->assertSame(ValeFuncionarioImporter::class, $catalog->get('vales_funcionario')['importer']);
    }

    public function test_a_failure_does_not_erase_progress_already_recorded_by_resources(): void
    {
        $service = IntegrationService::query()->create([
            'name' => 'Partial progress test',
            'slug' => 'partial-progress-'.str()->uuid(),
            'category' => 'cadastros',
            'resource' => 'webposto-new-records',
            'empresa_codigo' => 4604,
            'frequency_minutes' => 5,
            'settings' => ['resources' => ['first', 'second']],
        ]);
        $this->serviceIds[] = $service->id;

        Queue::fake([SyncWebPostoCompanyNewRecords::class]);
        (new SyncWebPostoNewRecords($service->id))->handle();

        $synchronizer = Mockery::mock(WebPostoNewRecordsSyncService::class);
        $synchronizer->shouldReceive('synchronize')->once()
            ->andReturnUsing(function (int $empresa, array $resources, int $runId): never {
                IntegrationServiceRun::query()->whereKey($runId)->update([
                    'received' => 7,
                    'inserted' => 2,
                ]);
                throw new RuntimeException('Falha na segunda tabela.');
            });

        Queue::pushed(SyncWebPostoCompanyNewRecords::class)->each(
            fn (SyncWebPostoCompanyNewRecords $job) => $job->handle($synchronizer),
        );

        $run = IntegrationServiceRun::query()->where('integration_service_id', $service->id)->latest('id')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame(7, $run->received);
        $this->assertSame(2, $run->inserted);
        $this->assertSame('Posto Chimba: Falha na segunda tabela.', $run->error);
        $this->assertDatabaseHas('integration_service_company_runs', [
            'integration_service_run_id' => $run->id,
            'empresa_codigo' => 4604,
            'status' => 'failed',
        ]);
    }
}

class StrictNewRecordImporter
{
    public static array $payload = [];

    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        self::$payload = $payload['resultados'] ?? [];
        foreach (self::$payload as $row) {
            DB::connection('webposto')->table('strict_new_records')->insert([
                ...$row,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        return [
            'received' => count(self::$payload),
            'inserted' => count(self::$payload),
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
        ];
    }
}
