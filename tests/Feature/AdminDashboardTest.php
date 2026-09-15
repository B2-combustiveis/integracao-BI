<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdminSession;
use App\Models\IntegrationService;
use App\Services\Admin\AdminOverviewService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use DatabaseTransactions;

    public function test_login_screen_is_available(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Integração BI')->assertSee('api_tokens');
    }

    public function test_dashboard_requires_an_admin_session(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_dashboard_waits_for_source_selection_before_loading_overview(): void
    {
        $this->mock(AdminOverviewService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('get'));

        $this->withoutMiddleware(EnsureAdminSession::class)->get('/admin')
            ->assertOk()->assertSee('Integração BI')->assertSee('Fonte da integração')
            ->assertSee('WebPosto')->assertSee('Alterdata')->assertSee('refresh-tables');
    }

    public function test_overview_endpoint_returns_json(): void
    {
        $overview = ['generated_at' => now()->toIso8601String(), 'connections' => [],
            'summary' => ['base_1' => 0, 'base_2' => 0, 'api_tokens' => 0, 'tables' => 0],
            'credentials' => [], 'tables' => []];
        $this->mock(AdminOverviewService::class, fn (MockInterface $mock) => $mock->shouldReceive('get')->once()->with('webposto', null)->andReturn($overview));
        $this->withoutMiddleware(EnsureAdminSession::class)->getJson('/admin/overview?source=webposto')
            ->assertOk()->assertJsonPath('summary.tables', 0);
    }

    public function test_overview_endpoint_requires_a_valid_source(): void
    {
        $this->withoutMiddleware(EnsureAdminSession::class)->getJson('/admin/overview')->assertUnprocessable();
        $this->withoutMiddleware(EnsureAdminSession::class)->getJson('/admin/overview?source=clickhouse')->assertUnprocessable();
    }

    public function test_services_screen_is_available(): void
    {
        $this->withoutMiddleware(EnsureAdminSession::class)->get('/admin/services')
            ->assertOk()->assertSee('Serviços')->assertSee('Última execução')->assertSee('Ações')
            ->assertSee('Relatórios por execução')->assertSee('Próximo acionamento')
            ->assertDontSee('Tabelas vinculadas')->assertDontSee('Como funciona')
            ->assertDontSee('Campos de controle no banco de integração');
    }

    public function test_financial_dashboard_is_not_exposed(): void
    {
        $this->withoutMiddleware(EnsureAdminSession::class)->get('/admin/financial')->assertNotFound();
    }

    public function test_services_status_groups_worker_blocks_by_posto(): void
    {
        $service = IntegrationService::query()->where('resource', 'webposto-chimba-reconciliation')->sole();
        $run = $service->runs()->create([
            'status' => 'partial',
            'period_start' => today(),
            'period_end' => today(),
            'started_at' => now()->subMinutes(10),
            'finished_at' => now(),
        ]);
        collect($service->settings['worker_blocks'])->values()->each(function (array $block, int $index) use ($run): void {
            $run->companyRuns()->create([
                'empresa_codigo' => 4604,
                'empresa_nome' => 'POSTO CHIMBA · '.$block['name'],
                'block_key' => 'block-'.($index + 1),
                'position' => $index + 1,
                'status' => $index === 1 ? 'partial' : 'success',
                'received' => 100,
                'inserted' => 10,
                'skipped' => 0,
                'resource_results' => [],
                'error' => $index === 1 ? 'Falha de teste' : null,
                'started_at' => now()->subMinutes(10),
                'finished_at' => now(),
            ]);
        });

        $response = $this->withoutMiddleware(EnsureAdminSession::class)->getJson('/admin/services/status');

        $response->assertOk();
        $serviceEntry = collect($response->json('services'))->firstWhere('id', $service->id);
        $this->assertNotNull($serviceEntry);
        $companies = $serviceEntry['run']['companies'];
        $this->assertCount(1, $companies, 'os 4 blocos do mesmo posto devem virar 1 linha na tela');
        $this->assertSame('POSTO CHIMBA', $companies[0]['empresa_nome']);
        $this->assertSame('partial', $companies[0]['status']);
        $this->assertSame(400, $companies[0]['received']);
        $this->assertSame(40, $companies[0]['inserted']);
        $this->assertSame('Falha de teste', $companies[0]['error']);
    }

    public function test_services_status_does_not_attach_shared_b2_blocks_to_posto_das_pedras(): void
    {
        $service = IntegrationService::query()->where('resource', 'webposto-b2-reconciliation')->sole();
        $run = $service->runs()->create([
            'status' => 'running',
            'period_start' => today(),
            'period_end' => today(),
            'started_at' => now(),
            'created_at' => now()->addMinute(),
        ]);

        $run->companyRuns()->create([
            'empresa_codigo' => 48659,
            'empresa_nome' => 'POSTO DAS PEDRAS LTDA',
            'block_key' => 'block-1',
            'position' => 1,
            'status' => 'success',
            'received' => 100,
            'inserted' => 10,
            'skipped' => 0,
            'resource_results' => [],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        $run->companyRuns()->create([
            'empresa_codigo' => 48659,
            'empresa_nome' => 'B2 · cliente empresas (compartilhado)',
            'block_key' => 'shared-cliente_empresas',
            'position' => 100,
            'status' => 'running',
            'received' => 50,
            'inserted' => 5,
            'skipped' => 0,
            'resource_results' => [],
            'started_at' => now(),
        ]);

        $response = $this->withoutMiddleware(EnsureAdminSession::class)->getJson('/admin/services/status');

        $response->assertOk();
        $serviceEntry = collect($response->json('services'))->firstWhere('id', $service->id);
        $companies = collect($serviceEntry['run']['companies']);

        $this->assertCount(2, $companies);
        $this->assertSame('success', $companies->firstWhere('empresa_nome', 'POSTO DAS PEDRAS LTDA')['status']);
        $shared = $companies->firstWhere('empresa_nome', 'B2 compartilhado');
        $this->assertNotNull($shared);
        $this->assertNull($shared['empresa_codigo']);
        $this->assertSame('running', $shared['status']);
    }
}
