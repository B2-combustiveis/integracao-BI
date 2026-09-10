<?php

namespace Tests\Feature;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Services\WebPosto\WebPostoSourceDeletionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WebPostoSourceDeletionServiceTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<int, string|null> */
    protected array $connectionsToTransact = [null, 'webposto'];

    public function test_it_requires_two_complete_absences_archives_then_deletes(): void
    {
        DB::connection('webposto')->statement('CREATE TEMPORARY TABLE source_deletion_probe (empresaCodigo BIGINT NOT NULL, codigo BIGINT NOT NULL, valor VARCHAR(50), PRIMARY KEY (empresaCodigo, codigo))');
        try {
            DB::connection('webposto')->table('source_deletion_probe')->insert([
                'empresaCodigo' => 991001, 'codigo' => 10, 'valor' => 'preservar no arquivo',
            ]);
            [$run1, $run2] = $this->runs();
            $definition = $this->definition();
            $service = app(WebPostoSourceDeletionService::class);

            $first = $service->confirm($definition, 'cliente_empresas', [], [991001], [], $run1->id);
            $this->assertSame(1, $first['missing_first_confirmation']);
            $this->assertSame(0, $first['deleted']);
            $this->assertTrue(DB::connection('webposto')->table('source_deletion_probe')->where('codigo', 10)->exists());

            $second = $service->confirm($definition, 'cliente_empresas', [], [991001], [], $run2->id);
            $this->assertSame(1, $second['deleted']);
            $this->assertFalse(DB::connection('webposto')->table('source_deletion_probe')->where('codigo', 10)->exists());
            $archive = DB::table('webposto_source_deleted_records')->where('empresa_codigo', 991001)->sole();
            $this->assertSame('preservar no arquivo', json_decode($archive->payload, true)['valor']);
            $this->assertDatabaseHas('integration_service_run_changes', ['integration_service_run_id' => $run2->id, 'action' => 'deleted']);
        } finally {
            DB::connection('webposto')->statement('DROP TEMPORARY TABLE IF EXISTS source_deletion_probe');
        }
    }

    public function test_reappearance_cancels_the_pending_deletion(): void
    {
        DB::connection('webposto')->statement('CREATE TEMPORARY TABLE source_deletion_probe (empresaCodigo BIGINT NOT NULL, codigo BIGINT NOT NULL, PRIMARY KEY (empresaCodigo, codigo))');
        try {
            DB::connection('webposto')->table('source_deletion_probe')->insert(['empresaCodigo' => 991002, 'codigo' => 20]);
            [$run1, $run2] = $this->runs();
            $definition = $this->definition();
            $service = app(WebPostoSourceDeletionService::class);
            $service->confirm($definition, 'cliente_empresas', [], [991002], [], $run1->id);

            $key = ['codigo' => 20];
            $seen = [991002 => [hash('sha256', json_encode($key, JSON_UNESCAPED_UNICODE)) => true]];
            $result = $service->confirm($definition, 'cliente_empresas', $seen, [991002], [], $run2->id);

            $this->assertSame(0, $result['deleted']);
            $this->assertTrue(DB::connection('webposto')->table('source_deletion_probe')->where('codigo', 20)->exists());
            $this->assertDatabaseMissing('webposto_source_absences', ['empresa_codigo' => 991002]);
        } finally {
            DB::connection('webposto')->statement('DROP TEMPORARY TABLE IF EXISTS source_deletion_probe');
        }
    }

    /** @return array{IntegrationServiceRun, IntegrationServiceRun} */
    private function runs(): array
    {
        $service = IntegrationService::query()->create([
            'name' => 'Exclusão segura teste', 'slug' => 'deletion-test-'.str()->uuid(),
            'category' => 'atualizacao', 'resource' => 'webposto-b2-reconciliation',
            'empresa_codigo' => 0, 'active' => false,
        ]);
        return collect([1, 2])->map(fn (): IntegrationServiceRun => IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id, 'status' => 'success',
            'started_at' => now(), 'finished_at' => now(),
        ]))->all();
    }

    /** @return array<string, mixed> */
    private function definition(): array
    {
        return [
            'table' => 'source_deletion_probe', 'key' => 'codigo',
            'natural_keys' => ['codigo'], 'company_field' => 'empresaCodigo',
            'company_scoped' => true,
        ];
    }
}
