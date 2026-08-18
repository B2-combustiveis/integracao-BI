<?php

namespace Tests\Feature;

use Tests\TestCase;

class FixedBearerTokenTest extends TestCase
{
    public function test_request_without_token_is_rejected(): void
    {
        config(['integration.api_token' => 'test-token']);

        $this->getJson('/api/teste')
            ->assertUnauthorized()
            ->assertJson(['message' => 'Não autenticado.']);
    }

    public function test_request_with_valid_token_is_accepted(): void
    {
        config(['integration.api_token' => 'test-token']);

        $this->withToken('test-token')
            ->getJson('/api/teste')
            ->assertOk()
            ->assertJson([
                'authenticated' => true,
                'connection' => [
                    'method' => 'GET',
                    'ip' => '127.0.0.1',
                    'protocol' => 'http',
                ],
            ])
            ->assertJsonStructure([
                'status',
                'connection_status',
                'connection' => ['url'],
                'database' => [
                    'connected',
                    'driver',
                    'database',
                    'host',
                    'port',
                ],
                'server_time',
            ]);
    }
}
