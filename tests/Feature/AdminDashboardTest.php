<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdminSession;
use App\Services\Admin\AdminOverviewService;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    public function test_login_screen_is_available(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Integração BI')->assertSee('api_tokens');
    }

    public function test_dashboard_requires_an_admin_session(): void
    {
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    public function test_dashboard_renders_local_overview(): void
    {
        $overview = [
            'generated_at' => now()->toIso8601String(),
            'connections' => [['key' => 'webposto', 'label' => 'WebPosto', 'status' => 'online', 'database' => 'webposto', 'latency_ms' => 1.2]],
            'summary' => ['companies' => 1, 'credentials' => 1, 'active_credentials' => 1, 'api_tokens' => 1, 'tables' => 2],
            'credentials' => [['empresa_codigo' => 4604, 'empresa_nome' => 'Posto Teste', 'base_url' => 'https://webposto.test', 'active' => true, 'last_used' => null]],
            'tables' => [['name' => 'fornecedores', 'records' => 200, 'columns' => 27, 'last_update' => null, 'size_bytes' => 1024]],
        ];
        $this->mock(AdminOverviewService::class, fn (MockInterface $mock) => $mock->shouldReceive('get')->once()->andReturn($overview));

        $this->withoutMiddleware(EnsureAdminSession::class)->get('/admin')
            ->assertOk()->assertSee('Integração BI')->assertSee('fornecedores');
    }

    public function test_overview_endpoint_returns_json(): void
    {
        $overview = ['generated_at' => now()->toIso8601String(), 'connections' => [],
            'summary' => ['companies' => 0, 'credentials' => 0, 'active_credentials' => 0, 'api_tokens' => 0, 'tables' => 0],
            'credentials' => [], 'tables' => []];
        $this->mock(AdminOverviewService::class, fn (MockInterface $mock) => $mock->shouldReceive('get')->once()->andReturn($overview));
        $this->withoutMiddleware(EnsureAdminSession::class)->getJson('/admin/overview')
            ->assertOk()->assertJsonPath('summary.tables', 0);
    }

    public function test_services_screen_is_available(): void
    {
        $this->withoutMiddleware(EnsureAdminSession::class)->get('/admin/services')
            ->assertOk()->assertSee('Serviços')->assertSee('Última execução')->assertSee('Ações')
            ->assertSee('Como funciona')->assertSee('Campos de controle no banco de integração');
    }
}
