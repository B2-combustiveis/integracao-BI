<?php

namespace Tests\Feature;

use Tests\TestCase;

class SwaggerDocumentationTest extends TestCase
{
    public function test_swagger_ui_is_available(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('SwaggerUIBundle', false);
    }

    public function test_openapi_specification_is_available(): void
    {
        $this->get('/docs/openapi')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/yaml; charset=UTF-8')
            ->assertSee('openapi: 3.0.3', false)
            ->assertSee('/api/webposto/empresas:', false);
    }
}
