<?php

namespace Tests\Feature;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\WebPostoClient;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class WebPostoCursorSynchronizerTest extends TestCase
{
    private string $originalDefaultConnection;

    private array $originalWebPostoConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalDefaultConnection = (string) config('database.default');
        $this->originalWebPostoConnection = config('database.connections.webposto');
        config([
            'database.default' => 'cursor_testing',
            'database.connections.cursor_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'foreign_key_constraints' => true,
            ],
            'database.connections.webposto' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('cursor_testing');
        DB::purge('webposto');

        Schema::create('webposto_sync_controls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresa_codigo');
            $table->string('endpoint');
            $table->char('strategy', 2);
            $table->unsignedBigInteger('last_code')->default(0);
            $table->timestamp('last_change_sync_at')->nullable();
            $table->timestamp('last_full_sync_at')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->string('status')->default('idle');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['empresa_codigo', 'endpoint']);
        });
        Schema::create('webposto_sync_endpoint_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webposto_sync_control_id');
            $table->unsignedBigInteger('integration_service_run_id')->nullable();
            $table->string('mode');
            $table->string('status');
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('webposto_sync_endpoint_runs');
        Schema::dropIfExists('webposto_sync_controls');
        DB::purge('cursor_testing');
        DB::purge('webposto');
        config([
            'database.default' => $this->originalDefaultConnection,
            'database.connections.webposto' => $this->originalWebPostoConnection,
        ]);

        parent::tearDown();
    }

    public function test_initial_batch_is_persisted_before_exclusive_cursor_is_used(): void
    {
        $queries = [];
        $responses = [
            $this->httpResult(['ultimoCodigo' => 1, 'resultados' => [['vendaCodigo' => 1]]]),
            $this->httpResult(['ultimoCodigo' => 1, 'resultados' => []]),
        ];
        $this->mock(WebPostoClient::class, function (MockInterface $mock) use (&$queries, &$responses): void {
            $mock->shouldReceive('get')->twice()->andReturnUsing(
                function (string $endpoint, int $empresa, array $query) use (&$queries, &$responses): array {
                    $queries[] = $query;

                    return array_shift($responses);
                },
            );
        });

        $persisted = [];
        app(WebPostoCursorSynchronizer::class)->synchronize(
            endpoint: '/INTEGRACAO/VENDA',
            empresaCodigo: 4604,
            persist: function (mixed $payload) use (&$persisted): array {
                $persisted[] = $payload['resultados'][0]['vendaCodigo'];

                return ['inserted' => 1];
            },
            query: ['dataInicial' => '2000-01-01', 'dataFinal' => '2026-08-21'],
            initialQuery: [],
        );

        $this->assertSame([1], $persisted);
        $this->assertArrayNotHasKey('ultimoCodigo', $queries[0]);
        $this->assertSame(1, $queries[1]['ultimoCodigo']);
        $this->assertSame(1, WebPostoSyncControl::query()->value('last_code'));
    }

    public function test_initial_load_can_reconcile_from_the_local_cursor(): void
    {
        WebPostoSyncControl::query()->create([
            'empresa_codigo' => 4604,
            'endpoint' => '/INTEGRACAO/PRODUTO_EMPRESA',
            'strategy' => 'A',
            'last_code' => 999,
            'metadata' => ['cursor_type' => 'ultimo_codigo', 'cursor_value' => 999],
        ]);
        $queries = [];
        $this->mock(WebPostoClient::class, function (MockInterface $mock) use (&$queries): void {
            $mock->shouldReceive('get')->once()->andReturnUsing(
                function (string $endpoint, int $empresa, array $query) use (&$queries): array {
                    $queries[] = $query;

                    return $this->httpResult(['ultimoCodigo' => 10, 'resultados' => []]);
                },
            );
        });

        app(WebPostoCursorSynchronizer::class)->synchronize(
            endpoint: '/INTEGRACAO/PRODUTO_EMPRESA',
            empresaCodigo: 4604,
            persist: fn (): array => [],
            query: ['limite' => 2000],
            cursor: ['initial_value' => 10, 'prefer_initial_value' => true],
        );

        $this->assertSame(10, $queries[0]['ultimoCodigo']);
        $this->assertSame(10, WebPostoSyncControl::query()->value('last_code'));
    }

    public function test_cursor_does_not_advance_when_persistence_fails(): void
    {
        $this->mock(WebPostoClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('get')->once()->andReturn(
                $this->httpResult(['ultimoCodigo' => 10, 'resultados' => [['vendaCodigo' => 10]]]),
            );
        });

        try {
            app(WebPostoCursorSynchronizer::class)->synchronize(
                endpoint: '/INTEGRACAO/VENDA',
                empresaCodigo: 4604,
                persist: fn (): array => throw new RuntimeException('database failure'),
                query: ['dataInicial' => '2000-01-01', 'dataFinal' => '2026-08-21'],
                initialQuery: [],
            );
            $this->fail('A falha de persistencia deveria ser propagada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('database failure', $exception->getMessage());
        }

        $control = WebPostoSyncControl::query()->firstOrFail();
        $this->assertSame(0, $control->last_code);
        $this->assertSame('error', $control->status);
    }

    public function test_direct_list_resource_is_persisted_as_one_page_without_cursor(): void
    {
        $queries = [];
        $this->mock(WebPostoClient::class, function (MockInterface $mock) use (&$queries): void {
            $mock->shouldReceive('get')->once()->andReturnUsing(
                function (string $endpoint, int $empresa, array $query) use (&$queries): array {
                    $queries[] = $query;

                    return $this->httpResult([
                        ['subGrupoCodigo' => 4, 'grupoCodigo' => 15451, 'descricao' => 'DIVERSOS'],
                        ['subGrupoCodigo' => 5, 'grupoCodigo' => 15449, 'descricao' => 'AR'],
                    ]);
                },
            );
        });

        $persisted = [];
        $totals = app(WebPostoCursorSynchronizer::class)->synchronize(
            endpoint: '/INTEGRACAO/CONSULTAR_SUB_GRUPO_REDE',
            empresaCodigo: 4604,
            persist: function (mixed $payload) use (&$persisted): array {
                $persisted = $payload['resultados'];

                return ['unchanged' => 2];
            },
            query: [],
            cursor: ['single_page' => true, 'direct_list' => true],
        );

        $this->assertSame([], $queries[0]);
        $this->assertCount(2, $persisted);
        $this->assertSame(1, $totals['pages']);
        $this->assertSame(2, $totals['received']);
        $this->assertSame(2, $totals['unchanged']);
    }

    public function test_direct_list_resource_fails_for_an_unexpected_payload(): void
    {
        $this->mock(WebPostoClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('get')->once()->andReturn(
                $this->httpResult(['mensagem' => 'formato alterado']),
            );
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Formato de lista direta inesperado');

        app(WebPostoCursorSynchronizer::class)->synchronize(
            endpoint: '/INTEGRACAO/CONSULTAR_SUB_GRUPO_REDE',
            empresaCodigo: 4604,
            persist: fn (): array => [],
            cursor: ['single_page' => true, 'direct_list' => true],
        );
    }

    public function test_full_reconciliation_resumes_from_last_confirmed_checkpoint(): void
    {
        WebPostoSyncControl::query()->create([
            'empresa_codigo' => 4604,
            'endpoint' => '/INTEGRACAO/TITULO_PAGAR:full-reconcile',
            'strategy' => 'A',
            'last_code' => 50,
            'status' => 'error',
            'metadata' => [
                'cursor_type' => 'ultimo_codigo',
                'cursor_value' => 50,
                'checkpoint_cursor' => 50,
                'checkpoint_page' => 3,
                'resume_available' => true,
            ],
        ]);
        $queries = [];
        $this->mock(WebPostoClient::class, function (MockInterface $mock) use (&$queries): void {
            $mock->shouldReceive('get')->once()->andReturnUsing(
                function (string $endpoint, int $empresa, array $query) use (&$queries): array {
                    $queries[] = $query;

                    return $this->httpResult(['ultimoCodigo' => 50, 'resultados' => []]);
                },
            );
        });
        $progress = [];

        app(WebPostoCursorSynchronizer::class)->synchronize(
            endpoint: '/INTEGRACAO/TITULO_PAGAR',
            empresaCodigo: 4604,
            persist: fn (): array => [],
            query: ['limite' => 1000],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => true],
            controlKey: '/INTEGRACAO/TITULO_PAGAR:full-reconcile',
            resumeFromCheckpoint: true,
            onPageProgress: function (array $state) use (&$progress): void {
                $progress[] = $state;
            },
        );

        $control = WebPostoSyncControl::query()->firstOrFail();
        $this->assertSame(50, $queries[0]['ultimoCodigo']);
        $this->assertSame(4, $progress[0]['page']);
        $this->assertSame('ok', $control->status);
        $this->assertFalse($control->metadata['resume_available']);
    }

    /** @param array<string, mixed> $payload */
    private function httpResult(array $payload): array
    {
        return [
            'response' => new Response(new PsrResponse(200)),
            'duration_ms' => 1,
            'payload' => $payload,
        ];
    }
}
