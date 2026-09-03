<?php

namespace Tests\Feature;

use App\Models\IntegrationService;
use App\Models\WebPostoCredential;
use App\Services\Bi\EmpresaBiSynchronizer;
use App\Services\WebPosto\EmpresaImporter;
use App\Services\WebPosto\WebPostoCredentialRegistrationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class WebPostoCredentialRegistrationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_registering_a_credential_does_not_create_an_integration_service(): void
    {
        $empresa = 999991;
        $before = IntegrationService::query()->count();
        DB::connection('webposto')->table('empresas')->insert([
            'empresaCodigo' => $empresa,
            'razao' => 'Empresa simulada',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Http::fake([
            'https://webposto.test/INTEGRACAO/EMPRESAS*' => Http::response([
                'resultados' => [[
                    'empresaCodigo' => $empresa,
                    'razaoSocial' => 'Empresa simulada',
                ]],
            ]),
        ]);
        $importer = Mockery::mock(EmpresaImporter::class);
        $importer->shouldReceive('import')->once()->andReturn([
            'received' => 1, 'inserted' => 1, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0,
        ]);
        $bi = Mockery::mock(EmpresaBiSynchronizer::class);
        $bi->shouldReceive('sync')->once()->andReturn([
            'received' => 1, 'inserted' => 1, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0,
        ]);

        try {
            $result = (new WebPostoCredentialRegistrationService($importer, $bi))
                ->register('https://webposto.test', 'token-simulado', WebPostoCredential::BASE_B2);

            $this->assertSame([$empresa], $result['companies']);
            $this->assertSame($before, IntegrationService::query()->count());
            $this->assertDatabaseHas('webposto_credentials', [
                'empresa_codigo' => $empresa,
                'ativo' => 1,
                'base' => WebPostoCredential::BASE_B2,
                'implantacao_status' => WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
            ], 'webposto');
        } finally {
            WebPostoCredential::query()->where('empresa_codigo', $empresa)->delete();
            DB::connection('webposto')->table('empresas')->where('empresaCodigo', $empresa)->delete();
        }
    }
}
