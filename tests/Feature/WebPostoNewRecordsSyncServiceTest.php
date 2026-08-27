<?php

namespace Tests\Feature;

use App\Jobs\SyncWebPostoNewRecords;
use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Services\Integration\IntegrationRunChangeRecorder;
use App\Services\WebPosto\WebPostoClient;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use App\Services\WebPosto\WebPostoNewRecordsResourceCatalog;
use App\Services\WebPosto\WebPostoNewRecordsSyncService;
use App\Services\WebPosto\WebPostoPendingRecordService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
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
        $catalog->shouldReceive('get')->once()->with('strict')->andReturn($definition);
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

    public function test_forced_full_reconcile_restarts_at_one_and_updates_existing_rows(): void
    {
        DB::connection('webposto')->table('strict_new_records')->insert([
            [
                'empresaCodigo' => 4604,
                'registroCodigo' => 10,
                'nome' => 'Original',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'empresaCodigo' => 9999,
                'registroCodigo' => 10,
                'nome' => 'Outra empresa',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
        $service = IntegrationService::query()->create([
            'name' => 'Full reconcile test',
            'slug' => 'full-reconcile-'.str()->uuid(),
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
            'cursor' => ['single_page' => true],
            'query' => ['dataInicial' => '2000-01-01', 'dataFinal' => '2026-08-26'],
            'importer' => FullReconcileStrictImporter::class,
            'updated_field' => 'dataHoraAtualizacao',
        ];
        $catalog = Mockery::mock(WebPostoNewRecordsResourceCatalog::class);
        $catalog->shouldReceive('get')->once()->with('strict')->andReturn($definition);
        $synchronizer = Mockery::mock(WebPostoCursorSynchronizer::class);
        $synchronizer->shouldReceive('synchronize')->once()->andReturnUsing(function (...$arguments): array {
            $this->assertSame(1, $arguments[4]['initial_value']);
            $this->assertTrue($arguments[4]['prefer_initial_value']);
            $this->assertTrue($arguments[4]['single_page']);
            $this->assertSame('2000-01-01', $arguments[3]['dataInicial']);
            $stored = $arguments[2](['resultados' => [[
                'registroCodigo' => 10,
                'nome' => 'Atualizado',
                'dataHoraAtualizacao' => '2026-08-26 12:00:00',
            ]]], ['ultimoCodigo' => 1]);

            return ['pages' => 1, 'received' => 1, ...$stored];
        });
        $subject = new WebPostoNewRecordsSyncService(
            $catalog,
            $synchronizer,
            app(IntegrationRunChangeRecorder::class),
            Mockery::mock(WebPostoClient::class),
            app(WebPostoPendingRecordService::class),
        );

        $result = $subject->synchronize(4604, ['strict'], $run->id, forceFullReconcile: true);

        $this->assertSame(1, $result['strict']['updated']);
        $this->assertDatabaseHas('strict_new_records', [
            'empresaCodigo' => 4604,
            'registroCodigo' => 10,
            'nome' => 'Atualizado',
        ], 'webposto');
        $this->assertDatabaseHas('strict_new_records', [
            'empresaCodigo' => 9999,
            'registroCodigo' => 10,
            'nome' => 'Outra empresa',
        ], 'webposto');
        $this->assertSame('updated', $run->fresh()->changes()->sole()->action);
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

    public function test_full_reconciliation_finishes_one_company_before_starting_the_next(): void
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
            'name' => 'Multi-company reconciliation test',
            'slug' => 'multi-company-reconciliation-'.str()->uuid(),
            'category' => 'cadastros',
            'resource' => 'webposto-full-reconciliation',
            'empresa_codigo' => 4604,
            'frequency_minutes' => 1440,
            'settings' => ['resources' => ['tanques', 'bombas', 'bicos']],
        ]);
        $this->serviceIds[] = $service->id;
        $calls = [];
        $synchronizer = Mockery::mock(WebPostoNewRecordsSyncService::class);
        $synchronizer->shouldReceive('synchronize')->twice()
            ->andReturnUsing(function (
                int $empresa,
                array $resources,
                int $runId,
                ?callable $onProgress = null,
                bool $forceFullReconcile = false,
            ) use (&$calls): array {
                $calls[] = [
                    'empresa' => $empresa,
                    'resources' => $resources,
                    'force_full_reconcile' => $forceFullReconcile,
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

        (new SyncWebPostoNewRecords($service->id))->handle($synchronizer);

        $this->assertSame([
            [
                'empresa' => 4604,
                'resources' => ['tanques', 'bombas', 'bicos'],
                'force_full_reconcile' => true,
            ],
            [
                'empresa' => 9999,
                'resources' => ['tanques', 'bombas', 'bicos'],
                'force_full_reconcile' => true,
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
        $synchronizer = Mockery::mock(WebPostoNewRecordsSyncService::class);
        $synchronizer->shouldReceive('synchronize')->once()
            ->andReturnUsing(function (int $empresa, array $resources, int $runId): never {
                IntegrationServiceRun::query()->whereKey($runId)->update([
                    'received' => 7,
                    'inserted' => 2,
                ]);
                throw new RuntimeException('Falha na segunda tabela.');
            });

        (new SyncWebPostoNewRecords($service->id))->handle($synchronizer);

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

class FullReconcileStrictImporter
{
    public function import(mixed $payload, int $empresa, array $parameters = []): array
    {
        $rows = $payload['resultados'] ?? [];
        $inserted = $updated = 0;
        foreach ($rows as $row) {
            $query = DB::connection('webposto')->table('strict_new_records')
                ->where('empresaCodigo', $empresa)
                ->where('registroCodigo', $row['registroCodigo']);
            $exists = $query->exists();
            DB::connection('webposto')->table('strict_new_records')->updateOrInsert(
                ['empresaCodigo' => $empresa, 'registroCodigo' => $row['registroCodigo']],
                ['nome' => $row['nome'], 'updated_at' => now(), ...($exists ? [] : ['created_at' => now()])],
            );
            $exists ? $updated++ : $inserted++;
        }

        return [
            'received' => count($rows),
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => 0,
            'skipped' => 0,
        ];
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
