<?php

namespace Tests\Feature;

use App\Jobs\ReloadValidatedWebPostoTable;
use App\Jobs\SyncWebPostoCompanyInitialLoad;
use App\Jobs\SyncWebPostoInitialResource;
use App\Models\WebPostoCredential;
use App\Models\WebPostoInitialSyncRun;
use App\Models\WebPostoReloadRun;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class WebPostoInitialCompanySyncTest extends TestCase
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
            $table->string('base')->nullable();
            $table->boolean('ativo')->default(true);
            $table->string('implantacao_status')->default('aguardando_sincronizacao');
            $table->timestamp('carga_inicial_iniciada_em')->nullable();
            $table->timestamp('carga_inicial_concluida_em')->nullable();
            $table->text('carga_inicial_erro')->nullable();
            $table->timestamp('ultimo_uso_em')->nullable();
            $table->timestamps();
        });
        DB::connection('webposto')->table('empresas')->insert([
            'empresaCodigo' => 9999,
            'fantasia' => 'Novo Posto',
        ]);
        DB::connection('webposto')->table('webposto_credentials')->insert([
            'empresa_codigo' => 9999,
            'base_url' => 'https://example.test',
            'token' => encrypt('token'),
            'base' => WebPostoCredential::BASE_B2,
            'ativo' => true,
            'implantacao_status' => WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_admin_can_queue_initial_load_from_the_company_card(): void
    {
        Queue::fake();

        try {
            $this->withoutMiddleware()
                ->post('/admin/credentials/9999/synchronize')
                ->assertRedirect()
                ->assertSessionHas('status', 'Carga inicial adicionada à fila.');

            $run = WebPostoInitialSyncRun::query()->where('empresa_codigo', 9999)->sole();
            $this->assertSame('queued', $run->status);
            $this->assertSame(count(SyncWebPostoCompanyInitialLoad::RESOURCES), $run->total_resources);
            Queue::assertPushed(SyncWebPostoCompanyInitialLoad::class);
        } finally {
            app(UniqueLock::class)->release(new SyncWebPostoCompanyInitialLoad(
                WebPostoInitialSyncRun::query()->where('empresa_codigo', 9999)->value('id') ?? 0,
            ));
        }
    }

    public function test_admin_can_queue_a_complete_resynchronization_for_a_synchronized_company(): void
    {
        Queue::fake();
        WebPostoCredential::query()->where('empresa_codigo', 9999)->update([
            'implantacao_status' => WebPostoCredential::STATUS_SINCRONIZADO,
        ]);

        try {
            $this->withoutMiddleware()
                ->post('/admin/credentials/9999/synchronize')
                ->assertRedirect()
                ->assertSessionHas('status', 'Ressincronização completa adicionada à fila.');

            $run = WebPostoInitialSyncRun::query()->where('empresa_codigo', 9999)->sole();
            $this->assertTrue($run->was_synchronized);
            $this->assertSame('queued', $run->status);
            Queue::assertPushed(SyncWebPostoCompanyInitialLoad::class);
        } finally {
            app(UniqueLock::class)->release(new SyncWebPostoCompanyInitialLoad(
                WebPostoInitialSyncRun::query()->where('empresa_codigo', 9999)->value('id') ?? 0,
            ));
        }
    }

    public function test_b1_retry_resumes_completed_resources_inside_a_valid_batch(): void
    {
        Queue::fake();
        WebPostoCredential::query()->where('empresa_codigo', 9999)->update([
            'base' => WebPostoCredential::BASE_B1,
        ]);
        WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => 9999,
            'status' => 'failed',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
            'completed_resources' => ['produto_grupos', 'produtos'],
            'error' => 'Carga interrompida',
        ]);

        try {
            $this->withoutMiddleware()
                ->post('/admin/credentials/9999/synchronize')
                ->assertRedirect()
                ->assertSessionHas('status', 'Carga inicial adicionada à fila.');

            $run = WebPostoInitialSyncRun::query()->where('empresa_codigo', 9999)->latest('id')->firstOrFail();
            $this->assertSame(['produto_grupos', 'produtos'], $run->completed_resources);
            $this->assertNotNull($run->batch_key);
            Queue::assertPushed(SyncWebPostoCompanyInitialLoad::class);
        } finally {
            app(UniqueLock::class)->release(new SyncWebPostoCompanyInitialLoad(
                WebPostoInitialSyncRun::query()->where('empresa_codigo', 9999)->latest('id')->value('id') ?? 0,
            ));
        }
    }

    public function test_b1_cancel_command_stops_a_running_load_without_touching_completed_resources(): void
    {
        WebPostoCredential::query()->where('empresa_codigo', 9999)->update([
            'base' => WebPostoCredential::BASE_B1,
        ]);
        $run = WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => 9999,
            'status' => 'running',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
            'completed_resources' => ['produto_grupos', 'produtos'],
        ]);

        $this->artisan('webposto:b1-cancel', ['empresa' => 9999])->assertSuccessful();

        $run->refresh();
        $this->assertSame('cancelled', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(['produto_grupos', 'produtos'], $run->completed_resources);
        $this->assertSame(
            WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
            WebPostoCredential::query()->where('empresa_codigo', 9999)->value('implantacao_status'),
        );

        // Um recurso que ainda nao tinha comecado a rodar desiste sozinho ao notar o cancelamento.
        $resource = new SyncWebPostoInitialResource($run->id, 'clientes');
        $resource->handle();
        $this->assertSame('cancelled', $run->fresh()->status);
    }

    public function test_b1_retry_after_cancellation_preserves_completed_resources(): void
    {
        Queue::fake();
        WebPostoCredential::query()->where('empresa_codigo', 9999)->update([
            'base' => WebPostoCredential::BASE_B1,
        ]);
        WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => 9999,
            'status' => 'cancelled',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
            'completed_resources' => ['produto_grupos', 'produtos'],
        ]);

        try {
            $this->withoutMiddleware()->post('/admin/credentials/9999/synchronize')->assertRedirect();

            $run = WebPostoInitialSyncRun::query()->where('empresa_codigo', 9999)->latest('id')->firstOrFail();
            $this->assertSame(['produto_grupos', 'produtos'], $run->completed_resources);
        } finally {
            app(UniqueLock::class)->release(new SyncWebPostoCompanyInitialLoad(
                WebPostoInitialSyncRun::query()->where('empresa_codigo', 9999)->latest('id')->value('id') ?? 0,
            ));
        }
    }

    public function test_initial_resources_use_dedicated_queues(): void
    {
        $runId = 123;

        $this->assertSame('webposto-light', (new SyncWebPostoInitialResource($runId, 'produtos'))->queue);
        $this->assertSame('webposto-heavy', (new SyncWebPostoInitialResource($runId, 'vendas'))->queue);
        $this->assertSame('webposto-shared', (new SyncWebPostoInitialResource($runId, 'cliente_empresas'))->queue);
        $this->assertSame('webposto-coordinator', (new SyncWebPostoCompanyInitialLoad($runId))->queue);
    }

    public function test_b1_cancel_command_refuses_a_non_b1_company(): void
    {
        $this->artisan('webposto:b1-cancel', ['empresa' => 9999])
            ->assertFailed();
    }

    public function test_b1_initial_load_dispatches_each_remaining_resource_as_an_independent_job(): void
    {
        Queue::fake();
        WebPostoCredential::query()->where('empresa_codigo', 9999)->update([
            'base' => WebPostoCredential::BASE_B1,
        ]);
        $run = WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => 9999,
            'status' => 'queued',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
            'completed_resources' => ['produto_grupos', 'produtos'],
        ]);

        (new SyncWebPostoCompanyInitialLoad($run->id))->handle();

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertSame('carga modular em paralelo', $run->current_resource);
        Queue::assertPushed(SyncWebPostoInitialResource::class,
            count(SyncWebPostoCompanyInitialLoad::RESOURCES) - 2
            - count(SyncWebPostoCompanyInitialLoad::DEFERRED_B1_RESOURCES));
        Queue::assertNotPushed(SyncWebPostoInitialResource::class,
            fn (SyncWebPostoInitialResource $job): bool => in_array($job->resource, ['produto_grupos', 'produtos'], true));
        Queue::assertNotPushed(SyncWebPostoInitialResource::class,
            fn (SyncWebPostoInitialResource $job): bool => in_array(
                $job->resource, SyncWebPostoCompanyInitialLoad::DEFERRED_B1_RESOURCES, true,
            ));
    }

    public function test_b1_dispatch_deferred_command_only_reaches_running_loads_missing_the_resource(): void
    {
        Queue::fake();
        WebPostoCredential::query()->where('empresa_codigo', 9999)->update([
            'base' => WebPostoCredential::BASE_B1,
        ]);
        WebPostoCredential::query()->create([
            'empresa_codigo' => 8888,
            'base_url' => 'https://example.test',
            'token' => 'token',
            'base' => WebPostoCredential::BASE_B1,
            'ativo' => true,
        ]);

        $waiting = WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => 9999,
            'status' => 'running',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
            'completed_resources' => array_values(array_diff(
                SyncWebPostoCompanyInitialLoad::RESOURCES,
                ['cliente_empresas'],
            )),
        ]);
        $alreadyDone = WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => 8888,
            'status' => 'running',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
            'completed_resources' => SyncWebPostoCompanyInitialLoad::RESOURCES,
        ]);

        $this->artisan('webposto:b1-dispatch-deferred', ['resource' => 'cliente_empresas'])
            ->assertSuccessful();

        Queue::assertPushed(SyncWebPostoInitialResource::class, fn (SyncWebPostoInitialResource $job): bool => $job->initialRunId === $waiting->id
            && $job->resource === 'cliente_empresas');
        Queue::assertNotPushed(SyncWebPostoInitialResource::class, fn (SyncWebPostoInitialResource $job): bool => $job->initialRunId === $alreadyDone->id);
    }

    public function test_initial_load_dispatches_every_resource_as_an_independent_job_for_any_base(): void
    {
        Queue::fake();
        $run = WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => 9999,
            'status' => 'queued',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
        ]);

        (new SyncWebPostoCompanyInitialLoad($run->id))->handle();

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertSame('carga modular em paralelo', $run->current_resource);
        Queue::assertPushed(SyncWebPostoInitialResource::class, count(SyncWebPostoCompanyInitialLoad::RESOURCES));
        $this->assertSame(
            WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
            WebPostoCredential::query()->where('empresa_codigo', 9999)->value('implantacao_status'),
        );
    }

    public function test_initial_resource_failure_keeps_company_unsynchronized_and_records_error(): void
    {
        $run = WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => 9999,
            'status' => 'running',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
        ]);

        (new SyncWebPostoInitialResource($run->id, 'clientes'))
            ->failed(new RuntimeException('API indisponível'));

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('API indisponível', $run->fresh()->error);
        $credential = WebPostoCredential::query()->where('empresa_codigo', 9999)->sole();
        $this->assertSame(WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO, $credential->implantacao_status);
        $this->assertSame('API indisponível', $credential->carga_inicial_erro);
    }

    public function test_initial_resource_failed_resynchronization_preserves_the_previous_synchronized_status(): void
    {
        WebPostoCredential::query()->where('empresa_codigo', 9999)->update([
            'implantacao_status' => WebPostoCredential::STATUS_SINCRONIZADO,
        ]);
        $run = WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => 9999,
            'status' => 'running',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
            'was_synchronized' => true,
        ]);

        (new SyncWebPostoInitialResource($run->id, 'clientes'))
            ->failed(new RuntimeException('API indisponível'));

        $this->assertSame(
            WebPostoCredential::STATUS_SINCRONIZADO,
            WebPostoCredential::query()->where('empresa_codigo', 9999)->value('implantacao_status'),
        );
    }

    public function test_initial_load_skips_resources_already_completed_by_dependencies(): void
    {
        Queue::fake();
        $run = WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => 9999,
            'status' => 'queued',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
            'completed_resources' => ['cartoes', 'venda_itens', 'abastecimentos'],
        ]);
        $expected = array_values(array_diff(
            SyncWebPostoCompanyInitialLoad::RESOURCES,
            ['cartoes', 'venda_itens', 'abastecimentos'],
        ));

        (new SyncWebPostoCompanyInitialLoad($run->id))->handle();

        Queue::assertPushed(SyncWebPostoInitialResource::class, count($expected));
        Queue::assertNotPushed(SyncWebPostoInitialResource::class,
            fn (SyncWebPostoInitialResource $job): bool => in_array(
                $job->resource, ['cartoes', 'venda_itens', 'abastecimentos'], true,
            ));
    }

    public function test_unsynchronized_company_cannot_queue_a_manual_reload(): void
    {
        Queue::fake();

        $this->withoutMiddleware()
            ->post('/admin/tables/fornecedores/reload', ['empresa_codigo' => 9999])
            ->assertUnprocessable();

        $this->assertFalse(WebPostoReloadRun::query()
            ->where('empresa_codigo', 9999)
            ->exists());
    }

    public function test_initial_load_runner_bypasses_the_synchronized_guard_but_manual_reload_still_enforces_it(): void
    {
        $fromInitialLoad = WebPostoReloadRun::query()->create([
            'empresa_codigo' => 9999,
            'resource' => 'recurso_inexistente',
            'status' => 'queued',
            'processed_tables' => [],
        ]);

        try {
            (new ReloadValidatedWebPostoTable($fromInitialLoad->id))->handle(duringInitialLoad: true);
            $this->fail('Deveria ter lancado excecao de tabela nao habilitada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Tabela nao habilitada.', $exception->getMessage());
        }

        $manual = WebPostoReloadRun::query()->create([
            'empresa_codigo' => 9999,
            'resource' => 'recurso_inexistente',
            'status' => 'queued',
            'processed_tables' => [],
        ]);

        try {
            (new ReloadValidatedWebPostoTable($manual->id))->handle();
            $this->fail('Deveria ter lancado excecao de empresa nao sincronizada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('A empresa precisa estar sincronizada antes de executar recargas.', $exception->getMessage());
        }
    }

    public function test_initial_load_waits_until_company_reload_finishes(): void
    {
        Queue::fake();
        WebPostoReloadRun::query()->create([
            'empresa_codigo' => 9999,
            'resource' => 'fornecedores',
            'status' => 'running',
            'processed_tables' => [],
        ]);

        $this->withoutMiddleware()
            ->post('/admin/credentials/9999/synchronize')
            ->assertRedirect();

        $this->assertFalse(WebPostoInitialSyncRun::query()
            ->where('empresa_codigo', 9999)
            ->exists());
        Queue::assertNothingPushed();
    }

    public function test_retry_closes_an_old_orphan_reload_and_queues_a_new_initial_load(): void
    {
        Queue::fake();
        $reload = WebPostoReloadRun::query()->create([
            'empresa_codigo' => 9999,
            'resource' => 'vendas',
            'status' => 'running',
            'processed_tables' => [],
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        try {
            $this->withoutMiddleware()
                ->post('/admin/credentials/9999/synchronize')
                ->assertRedirect()
                ->assertSessionHas('status', 'Carga inicial adicionada à fila.');

            $this->assertSame('failed', $reload->fresh()->status);
            $this->assertNotNull($reload->fresh()->finished_at);
            $run = WebPostoInitialSyncRun::query()->where('empresa_codigo', 9999)->sole();
            $this->assertSame('queued', $run->status);
            Queue::assertPushed(SyncWebPostoCompanyInitialLoad::class);
        } finally {
            app(UniqueLock::class)->release(new SyncWebPostoCompanyInitialLoad(
                WebPostoInitialSyncRun::query()->where('empresa_codigo', 9999)->value('id') ?? 0,
            ));
        }
    }
}
