<?php

namespace Tests\Feature;

use App\Jobs\SyncWebPostoCompanyInitialLoad;
use App\Models\WebPostoCredential;
use App\Models\WebPostoInitialSyncRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebPostoB1OnboardingTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = [null, 'webposto'];

    public function test_it_starts_at_most_five_b1_companies_and_does_not_include_other_bases(): void
    {
        Queue::fake();
        WebPostoCredential::query()->where('base', WebPostoCredential::BASE_B1)->update(['ativo' => false]);
        WebPostoInitialSyncRun::query()->whereBetween('empresa_codigo', [910001, 910006])->delete();
        foreach (range(910001, 910006) as $code) {
            DB::connection('webposto')->table('empresas')->insert([
                'empresaCodigo' => $code,
                'fantasia' => 'Posto B1 '.$code,
            ]);
            WebPostoCredential::query()->create([
                'empresa_codigo' => $code,
                'base_url' => 'https://example.test/',
                'token' => 'secret',
                'base' => WebPostoCredential::BASE_B1,
                'ativo' => true,
                'implantacao_status' => WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
            ]);
        }
        DB::connection('webposto')->table('empresas')->insert([
            'empresaCodigo' => 919999,
            'fantasia' => 'Posto B2',
        ]);
        WebPostoCredential::query()->create([
            'empresa_codigo' => 919999,
            'base_url' => 'https://example.test/',
            'token' => 'secret',
            'base' => WebPostoCredential::BASE_B2,
            'ativo' => true,
            'implantacao_status' => WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
        ]);

        $this->artisan('webposto:b1-onboard', ['--start-next' => 5])->assertSuccessful();

        $runs = WebPostoInitialSyncRun::query()->whereBetween('empresa_codigo', [910001, 910006])->get();
        $this->assertCount(5, $runs);
        $this->assertCount(1, $runs->pluck('batch_key')->unique());
        $this->assertFalse(WebPostoInitialSyncRun::query()->where('empresa_codigo', 919999)->exists());
    }

    public function test_it_refuses_a_new_batch_when_five_b1_loads_are_active(): void
    {
        Queue::fake();
        WebPostoCredential::query()->where('base', WebPostoCredential::BASE_B1)->update(['ativo' => false]);
        DB::connection('webposto')->table('empresas')->insert([
            'empresaCodigo' => 920001,
            'fantasia' => 'Posto B1 ativo',
        ]);
        WebPostoCredential::query()->create([
            'empresa_codigo' => 920001,
            'base_url' => 'https://example.test/',
            'token' => 'secret',
            'base' => WebPostoCredential::BASE_B1,
            'ativo' => true,
            'implantacao_status' => WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
        ]);
        foreach (range(1, 5) as $_) {
            WebPostoInitialSyncRun::query()->create([
                'empresa_codigo' => 920001,
                'status' => 'running',
                'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
                'completed_resources' => [],
            ]);
        }

        $this->artisan('webposto:b1-onboard', ['--start-next' => 10])->assertFailed();
        Queue::assertNothingPushed();
    }
}
