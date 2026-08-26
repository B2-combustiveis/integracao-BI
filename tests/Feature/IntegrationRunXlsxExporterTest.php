<?php

namespace Tests\Feature;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Models\IntegrationServiceRunChange;
use App\Services\Integration\IntegrationRunXlsxExporter;
use Tests\TestCase;
use ZipArchive;

class IntegrationRunXlsxExporterTest extends TestCase
{
    public function test_it_creates_a_summary_and_one_sheet_per_resource_with_payload_columns(): void
    {
        $service = IntegrationService::query()->create([
            'name' => 'Novos fornecedores WebPosto',
            'slug' => 'xlsx-test-'.str()->uuid(),
            'category' => 'cadastros',
            'resource' => 'webposto-new-records',
            'empresa_codigo' => 4604,
            'settings' => ['resources' => ['fornecedores', 'sem_novos', 'administradoras']],
        ]);
        $run = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'success',
            'received' => 1,
            'inserted' => 1,
            'updated' => 1,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
        IntegrationServiceRunChange::query()->create([
            'integration_service_run_id' => $run->id,
            'resource' => 'fornecedores',
            'table_name' => 'fornecedores',
            'action' => 'inserted',
            'natural_key' => ['empresaCodigo' => 4604, 'fornecedorCodigo' => 122296],
            'natural_key_hash' => hash('sha256', 'xlsx-test'),
            'payload' => ['fornecedorCodigo' => 122296, 'razao' => 'Fornecedor Teste'],
            'detected_at' => now(),
        ]);
        IntegrationServiceRunChange::query()->create([
            'integration_service_run_id' => $run->id,
            'resource' => 'administradoras',
            'table_name' => 'administradoras',
            'action' => 'updated',
            'natural_key' => ['empresaCodigo' => 4604, 'administradoraCodigo' => 1],
            'natural_key_hash' => hash('sha256', 'xlsx-updated-test'),
            'payload' => ['administradoraCodigo' => 1, 'razao' => 'Registro Atualizado'],
            'detected_at' => now(),
        ]);

        $path = null;
        try {
            $path = app(IntegrationRunXlsxExporter::class)->create($run);
            $zip = new ZipArchive();
            $this->assertTrue($zip->open($path) === true);
            $workbook = $zip->getFromName('xl/workbook.xml');
            $summary = $zip->getFromName('xl/worksheets/sheet1.xml');
            $sheet = $zip->getFromName('xl/worksheets/sheet2.xml');
            $zip->close();
            $this->assertStringContainsString('Resumo', $workbook);
            $this->assertStringContainsString('fornecedores', $workbook);
            $this->assertStringNotContainsString('sem_novos', $workbook);
            $this->assertStringNotContainsString('administradoras', $workbook);
            $this->assertStringNotContainsString('recebidos', $summary);
            $this->assertStringNotContainsString('atualizados', $summary);
            $this->assertStringContainsString('fornecedorCodigo', $sheet);
            $this->assertStringContainsString('Fornecedor Teste', $sheet);
            $this->assertStringNotContainsString('_acao', $sheet);
            $this->assertStringNotContainsString('Registro Atualizado', $sheet);
        } finally {
            if ($path !== null) @unlink($path);
            $service->delete();
        }
    }
}
