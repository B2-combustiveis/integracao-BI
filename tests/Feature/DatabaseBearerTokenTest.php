<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class DatabaseBearerTokenTest extends TestCase
{
    public function test_request_without_token_is_rejected_before_database_query(): void
    {
        DB::shouldReceive('table')->never();

        $this->getJson('/api/teste')
            ->assertUnauthorized()
            ->assertJson([
                'status' => false,
                'token_status' => 'missing',
            ]);
    }

    public function test_valid_active_database_token_is_accepted(): void
    {
        $query = Mockery::mock();
        $query->shouldReceive('select')->once()->with(['id', 'nome'])->andReturnSelf();
        $query->shouldReceive('where')->once()->with('token', 'database-token')->andReturnSelf();
        $query->shouldReceive('where')->once()->with('ativo', true)->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn((object) [
            'id' => 1,
            'nome' => 'integracao_principal',
        ]);

        DB::shouldReceive('table')->once()->with('api_tokens')->andReturn($query);

        $this->withToken('database-token')
            ->getJson('/api/verificar-token')
            ->assertOk()
            ->assertJson([
                'status' => true,
                'token_status' => 'valid',
                'authenticated' => true,
                'token' => [
                    'id' => 1,
                    'name' => 'integracao_principal',
                ],
            ]);
    }
}
