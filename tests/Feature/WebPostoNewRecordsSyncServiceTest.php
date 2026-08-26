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

        try {
            (new SyncWebPostoNewRecords($service->id))->handle($synchronizer);
            $this->fail('A falha esperada nao foi lancada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Falha na segunda tabela.', $exception->getMessage());
        }

        $run = IntegrationServiceRun::query()->where('integration_service_id', $service->id)->latest('id')->firstOrFail();
        $this->assertSame('failed', $run->status);
        $this->assertSame(7, $run->received);
        $this->assertSame(2, $run->inserted);
        $this->assertSame('Falha na segunda tabela.', $run->error);
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
