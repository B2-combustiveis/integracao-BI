<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Services\WebPosto\ClienteEmpresaImporter;
use App\Services\WebPosto\ClienteImporter;
use App\Services\WebPosto\ProdutoEmpresaImporter;
use App\Services\WebPosto\ProdutoImporter;
use App\Services\WebPosto\WebPostoClient;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SyncWebPostoNewRecords implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 900;
    public int $uniqueFor = 900;

    public function __construct(public readonly int $serviceId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "webposto-new-records:{$this->serviceId}";
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(WebPostoClient $client): void
    {
        $service = IntegrationService::query()->findOrFail($this->serviceId);
        $run = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'period_start' => today(),
            'period_end' => today(),
            'started_at' => now(),
        ]);
        $service->update(['last_started_at' => now(), 'last_error' => null]);
        $totals = ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        try {
            $empresa = $service->empresa_codigo;
            $routes = [
                ['/INTEGRACAO/PRODUTO', ProdutoImporter::class, 'produtos', 'produtoCodigo', 1000],
                ['/INTEGRACAO/PRODUTO_EMPRESA', ProdutoEmpresaImporter::class, 'produto_empresas', 'produtoCodigo', 2000],
                ['/INTEGRACAO/CLIENTE', ClienteImporter::class, 'clientes', 'clienteCodigo', 1000],
                ['/INTEGRACAO/CLIENTE_EMPRESA', ClienteEmpresaImporter::class, 'cliente_empresas', 'clienteCodigo', 200],
            ];

            foreach ($routes as [$endpoint, $importerClass, $table, $cursorColumn, $limit]) {
                $cursor = (int) (DB::connection('webposto')->table($table)->max($cursorColumn) ?? 0);
                $this->consumePages($client, $endpoint, $empresa, $cursor, $limit,
                    function (mixed $payload) use ($importerClass, $empresa, &$totals): void {
                        $stored = app($importerClass)->import($payload, $empresa);
                        foreach ($totals as $key => $value) $totals[$key] += (int) ($stored[$key] ?? 0);
                    });
            }

            $run->update([...$totals, 'status' => 'success', 'finished_at' => now()]);
            $service->update(['last_completed_at' => now(),
                'next_run_at' => now()->addMinutes($service->frequency_minutes), 'last_error' => null]);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            $run->update([...$totals, 'status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            $service->update(['last_error' => $message,
                'next_run_at' => now()->addMinutes($service->frequency_minutes)]);
            throw $exception;
        }
    }

    private function consumePages(WebPostoClient $client, string $endpoint, int $empresa,
        int $cursor, int $limit, callable $consume): void
    {
        for ($page = 0; $page < 100; $page++) {
            $result = $client->get($endpoint, $empresa, ['ultimoCodigo' => $cursor, 'limite' => $limit]);
            if (! $result['response']->successful()) {
                throw new RuntimeException("WebPosto respondeu HTTP {$result['response']->status()} em {$endpoint}.");
            }
            $payload = $result['payload'];
            $rows = is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
            if ($rows === []) return;
            $consume($payload);
            $next = is_numeric($payload['ultimoCodigo'] ?? null) ? (int) $payload['ultimoCodigo'] : 0;
            if (count($rows) < $limit || $next <= $cursor) return;
            $cursor = $next;
        }
        throw new RuntimeException("Limite de paginação incremental atingido em {$endpoint}.");
    }
}
