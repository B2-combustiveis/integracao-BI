<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Services\Bi\EmpresaBiSynchronizer;
use App\Services\WebPosto\ClienteEmpresaImporter;
use App\Services\WebPosto\ClienteGrupoImporter;
use App\Services\WebPosto\ClienteImporter;
use App\Services\WebPosto\EmpresaImporter;
use App\Services\WebPosto\ProdutoEmpresaImporter;
use App\Services\WebPosto\ProdutoGrupoImporter;
use App\Services\WebPosto\ProdutoImporter;
use App\Services\WebPosto\ProdutoLmcLmpImporter;
use App\Services\WebPosto\ProdutoSubgrupoImporter;
use App\Services\WebPosto\RawResourceImporter;
use App\Services\WebPosto\WebPostoClient;
use App\Services\WebPosto\WebPostoResourceCatalog;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class SyncWebPostoDatabase implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 3;
    public int $timeout = 7200;
    public int $uniqueFor = 7200;

    public function __construct(public readonly int $serviceId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "webposto-database:{$this->serviceId}";
    }

    public function backoff(): array
    {
        return [120, 600];
    }

    public function handle(WebPostoClient $client, WebPostoResourceCatalog $catalog, RawResourceImporter $rawImporter,
        EmpresaImporter $empresaImporter, EmpresaBiSynchronizer $empresaBiSynchronizer): void
    {
        $service = IntegrationService::query()->findOrFail($this->serviceId);
        $end = today();
        $start = $end->copy()->subDays(max(1, $service->lookback_days) - 1);
        $run = IntegrationServiceRun::query()->create(['integration_service_id' => $service->id,
            'status' => 'running', 'period_start' => $start, 'period_end' => $end, 'started_at' => now()]);
        $service->update(['last_started_at' => now(), 'last_error' => null]);
        $totals = ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        try {
            $empresa = $service->empresa_codigo;
            $company = $this->request($client, '/INTEGRACAO/EMPRESAS', $empresa);
            $this->add($totals, $empresaImporter->import($company['payload']));
            $empresaBiSynchronizer->sync($company['payload']);

            $specialized = [
                ['/INTEGRACAO/GRUPO', ProdutoGrupoImporter::class, 1000],
                ['/INTEGRACAO/CONSULTAR_SUB_GRUPO_REDE', ProdutoSubgrupoImporter::class, 1000],
                ['/INTEGRACAO/PRODUTO', ProdutoImporter::class, 1000],
                ['/INTEGRACAO/PRODUTO_EMPRESA', ProdutoEmpresaImporter::class, 2000],
                ['/INTEGRACAO/PRODUTO_LMC_LMP', ProdutoLmcLmpImporter::class, 1000],
                ['/INTEGRACAO/GRUPO_CLIENTE', ClienteGrupoImporter::class, 1000],
                ['/INTEGRACAO/CLIENTE', ClienteImporter::class, 1000],
                ['/INTEGRACAO/CLIENTE_EMPRESA', ClienteEmpresaImporter::class, 200],
            ];
            foreach ($specialized as [$endpoint, $importerClass, $limit]) {
                $this->paginate($client, $endpoint, $empresa, $limit,
                    function (mixed $payload) use ($importerClass, $empresa, &$totals): void {
                        $this->add($totals, app($importerClass)->import($payload, $empresa));
                    });
            }

            $common = ['data_inicial' => $start->toDateString(), 'data_final' => $end->toDateString(),
                'empresa_webposto_codigo' => $empresa, 'tipo_data' => 'EMISSAO', 'apuracao_caixa' => true,
                'grupo_meta_codigo' => (int) ($service->settings['grupo_meta_codigo'] ?? 482)];
            foreach ($catalog->all() as $definition) {
                if ($definition['pathParameters'] !== []) continue;
                $query = [];
                foreach ($definition['queryMap'] as $local => $upstream) {
                    if (array_key_exists($local, $common)) $query[$upstream] = $common[$local];
                }
                $this->paginate($client, $definition['endpoint'], $empresa, 2000,
                    function (mixed $payload, array $requestQuery) use ($rawImporter, $definition, $empresa, &$totals): void {
                        $this->add($totals, $rawImporter->import($payload, $empresa, $definition['table'], $requestQuery));
                    }, $query);
            }

            $run->update([...$totals, 'status' => 'success', 'finished_at' => now()]);
            $service->update(['last_completed_at' => now(), 'next_run_at' => now()->addMinutes($service->frequency_minutes), 'last_error' => null]);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            $run->update([...$totals, 'status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            $service->update(['last_error' => $message, 'next_run_at' => now()->addMinutes($service->frequency_minutes)]);
            throw $exception;
        }
    }

    private function paginate(WebPostoClient $client, string $endpoint, int $empresa, int $limit, callable $consume, array $query = []): void
    {
        $cursor = 0;
        for ($page = 0; $page < 1000; $page++) {
            $requestQuery = [...$query, 'limite' => $limit, 'ultimoCodigo' => $cursor];
            $result = $this->request($client, $endpoint, $empresa, $requestQuery);
            $payload = $result['payload'];
            $consume($payload, $requestQuery);
            $rows = is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
            $next = is_numeric($payload['ultimoCodigo'] ?? null) ? (int) $payload['ultimoCodigo'] : 0;
            if (count($rows) < $limit || $next <= $cursor) return;
            $cursor = $next;
        }
        throw new RuntimeException("Limite de paginação atingido em {$endpoint}.");
    }

    private function request(WebPostoClient $client, string $endpoint, int $empresa, array $query = []): array
    {
        $result = $client->get($endpoint, $empresa, $query);
        if (! $result['response']->successful()) {
            throw new RuntimeException("WebPosto respondeu HTTP {$result['response']->status()} em {$endpoint}.");
        }
        return $result;
    }

    private function add(array &$totals, array $stored): void
    {
        foreach ($totals as $key => $value) $totals[$key] += (int) ($stored[$key] ?? 0);
    }
}
