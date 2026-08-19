<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Services\WebPosto\RawResourceImporter;
use App\Services\WebPosto\WebPostoClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class SyncFinancialReceivables implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $uniqueFor = 3600;

    public function __construct(public readonly int $serviceId)
    {
        $this->onQueue('financial');
    }

    public function uniqueId(): string
    {
        return "financial-receivables:{$this->serviceId}";
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(WebPostoClient $client, RawResourceImporter $importer): void
    {
        $service = IntegrationService::query()->findOrFail($this->serviceId);
        $end = today();
        $start = $end->copy()->subDays(max(1, $service->lookback_days) - 1);
        $run = IntegrationServiceRun::query()->create(['integration_service_id' => $service->id,
            'status' => 'running', 'period_start' => $start, 'period_end' => $end, 'started_at' => now()]);
        $service->update(['last_started_at' => now(), 'last_error' => null]);

        try {
            $query = ['dataInicial' => $start->toDateString(), 'dataFinal' => $end->toDateString()];
            $result = $client->get('/INTEGRACAO/TITULO_RECEBER', $service->empresa_codigo, $query);
            if (! $result['response']->successful()) throw new RuntimeException("WebPosto respondeu HTTP {$result['response']->status()}.");
            $stored = $importer->import($result['payload'], $service->empresa_codigo, 'titulos_receber', $query);
            $run->update(['status' => 'success', 'received' => $stored['received'], 'inserted' => $stored['inserted'],
                'updated' => $stored['updated'], 'unchanged' => $stored['unchanged'], 'skipped' => $stored['skipped'], 'finished_at' => now()]);
            $service->update(['last_completed_at' => now(), 'next_run_at' => now()->addMinutes($service->frequency_minutes), 'last_error' => null]);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            $run->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            $service->update(['last_error' => $message, 'next_run_at' => now()->addMinutes($service->frequency_minutes)]);
            throw $exception;
        }
    }
}
