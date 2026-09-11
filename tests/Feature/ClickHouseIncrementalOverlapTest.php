<?php

namespace Tests\Feature;

use App\Jobs\SyncClickHouseIncremental;
use App\Jobs\SyncClickHouseTable;
use App\Models\IntegrationService;
use App\Services\ClickHouse\ClickHouseTableCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class ClickHouseIncrementalOverlapTest extends TestCase
{
    use DatabaseTransactions;

    public function test_it_does_not_create_another_run_while_one_is_active(): void
    {
        Bus::fake([SyncClickHouseTable::class]);

        $service = IntegrationService::query()
            ->where('resource', 'clickhouse-incremental-sync')
            ->sole();
        $service->runs()
            ->whereIn('status', ['running', 'finalizing'])
            ->update(['status' => 'success', 'finished_at' => now()]);
        $activeRun = $service->runs()->create([
            'status' => 'running',
            'period_start' => today(),
            'period_end' => today(),
            'started_at' => now(),
        ]);
        $runsBefore = $service->runs()->count();

        (new SyncClickHouseIncremental($service->id))->handle(app(ClickHouseTableCatalog::class));

        $this->assertSame($runsBefore, $service->runs()->count());
        $this->assertSame('running', $activeRun->refresh()->status);
        $this->assertTrue($service->refresh()->next_run_at->between(now()->addSeconds(55), now()->addSeconds(65)));
        Bus::assertNotDispatched(SyncClickHouseTable::class);
    }
}
