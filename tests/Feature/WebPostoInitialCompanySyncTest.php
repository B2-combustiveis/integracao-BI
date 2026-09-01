<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdminSession;
use App\Jobs\ReloadValidatedWebPostoTable;
use App\Jobs\SyncWebPostoCompanyInitialLoad;
use App\Models\WebPostoCredential;
use App\Models\WebPostoInitialSyncRun;
use App\Models\WebPostoReloadRun;
use App\Services\WebPosto\WebPostoInitialLoadRunner;
use Illuminate\Bus\UniqueLock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
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

    public function test_initial_load_runs_every_stage_in_order_and_only_then_releases_company(): void
    {
        $run = WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => 9999,
            'status' => 'queued',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
        ]);
        $called = [];
        $runner = Mockery::mock(WebPostoInitialLoadRunner::class);
        $runner->shouldReceive('run')
            ->times(count(SyncWebPostoCompanyInitialLoad::RESOURCES))
            ->andReturnUsing(function (int $empresa, string $resource) use (&$called): array {
                $this->assertSame(9999, $empresa);
                $called[] = $resource;
                $this->assertSame(
                    WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
                    WebPostoCredential::query()->where('empresa_codigo', 9999)->value('implantacao_status'),
                );

                return [$resource];
            });

        (new SyncWebPostoCompanyInitialLoad($run->id))->handle($runner);

        $this->assertSame(SyncWebPostoCompanyInitialLoad::RESOURCES, $called);
        $resources = SyncWebPostoCompanyInitialLoad::RESOURCES;
        $this->assertLessThan(array_search('caixas', $resources, true), array_search('pdvs', $resources, true));
        $this->assertLessThan(array_search('vendas', $resources, true), array_search('formas_pagamento', $resources, true));
        $this->assertLessThan(array_search('vendas', $resources, true), array_search('caixas', $resources, true));
        $this->assertSame('success', $run->fresh()->status);
        $this->assertSame(
            WebPostoCredential::STATUS_SINCRONIZADO,
            WebPostoCredential::query()->where('empresa_codigo', 9999)->value('implantacao_status'),
        );
        $this->assertNotNull(WebPostoCredential::query()
            ->where('empresa_codigo', 9999)->value('carga_inicial_concluida_em'));
    }

    public function test_failure_keeps_company_unsynchronized_and_records_stage_error(): void
    {
        $run = WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => 9999,
            'status' => 'queued',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
        ]);
        $runner = Mockery::mock(WebPostoInitialLoadRunner::class);
        $runner->shouldReceive('run')->once()->andThrow(new RuntimeException('API indisponível'));

        try {
            (new SyncWebPostoCompanyInitialLoad($run->id))->handle($runner);
            $this->fail('A falha deveria ter sido propagada.');
        } catch (RuntimeException $exception) {
            $this->assertSame('API indisponível', $exception->getMessage());
        }

        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame('API indisponível', $run->fresh()->error);
        $credential = WebPostoCredential::query()->where('empresa_codigo', 9999)->sole();
        $this->assertSame(WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO, $credential->implantacao_status);
        $this->assertSame('API indisponível', $credential->carga_inicial_erro);
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
}
