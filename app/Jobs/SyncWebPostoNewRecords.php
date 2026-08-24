<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
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
use App\Services\WebPosto\WebPostoCursorSynchronizer;
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
    public int $timeout = 7200;
    public int $uniqueFor = 7200;

    public function __construct(
        public readonly int $serviceId,
        public readonly bool $reconcileFromStart = false,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "webposto-new-records:{$this->serviceId}:".($this->reconcileFromStart ? 'full' : 'incremental');
    }

    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(
        WebPostoCursorSynchronizer $synchronizer,
        WebPostoClient $client,
        RawResourceImporter $rawImporter,
    ): void
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
            $this->synchronizeParents($client, $empresa, $totals, $run);

            $routes = [
                ['/INTEGRACAO/PRODUTO', ProdutoImporter::class, 'produtos', 'produtoCodigo', 1000],
                ['/INTEGRACAO/PRODUTO_EMPRESA', ProdutoEmpresaImporter::class, 'produto_empresas', 'produtoCodigo', 2000],
                ['/INTEGRACAO/CLIENTE', ClienteImporter::class, 'clientes', 'clienteCodigo', 1000],
                ['/INTEGRACAO/CLIENTE_EMPRESA', ClienteEmpresaImporter::class, 'cliente_empresas', 'clienteCodigo', 200],
            ];

            foreach ($routes as [$endpoint, $importerClass, $table, $cursorColumn, $limit]) {
                $initialCursor = $this->reconcileFromStart
                    ? 0
                    : (int) (DB::connection('webposto')->table($table)->max($cursorColumn) ?? 0);
                $stored = $synchronizer->synchronize(
                    endpoint: $endpoint,
                    empresaCodigo: $empresa,
                    persist: fn (mixed $payload): array => app($importerClass)->import($payload, $empresa),
                    query: ['limite' => $limit],
                    cursor: [
                        'initial_value' => $initialCursor,
                        'prefer_initial_value' => true,
                    ],
                    initialQuery: [],
                    integrationServiceRunId: $run->id,
                    omitCursorWhenZero: true,
                );
                $this->add($totals, $stored);
                $run->update($totals);
            }

            $period = ['dataInicial' => '2000-01-01', 'dataFinal' => today()->toDateString()];
            foreach ([
                ['/INTEGRACAO/CAIXA', 'caixas'],
                ['/INTEGRACAO/CAIXA_APRESENTADO', 'caixas_apresentados'],
                ['/INTEGRACAO/LMC', 'lmcs'],
            ] as [$endpoint, $table]) {
                $initialCursor = $this->reconcileFromStart
                    ? 0
                    : (int) (DB::connection('webposto')->table($table)
                        ->max($table === 'lmcs' ? 'lmcCodigo' : 'caixaCodigo') ?? 0);
                $stored = $synchronizer->synchronize(
                    endpoint: $endpoint,
                    empresaCodigo: $empresa,
                    persist: fn (mixed $payload, array $parameters): array => $rawImporter->import(
                        $payload,
                        $empresa,
                        $table,
                        $parameters,
                    ),
                    query: [...$period, 'limite' => 1000],
                    cursor: [
                        'initial_value' => $initialCursor,
                        'prefer_initial_value' => true,
                    ],
                    initialQuery: [],
                    integrationServiceRunId: $run->id,
                    omitCursorWhenZero: true,
                );
                $this->add($totals, $stored);
                $run->update($totals);
            }

            $stored = $synchronizer->synchronize(
                endpoint: '/INTEGRACAO/VENDA',
                empresaCodigo: $empresa,
                persist: fn (mixed $payload, array $parameters): array => $rawImporter->import(
                    $payload,
                    $empresa,
                    'vendas',
                    $parameters,
                ),
                query: $period,
                initialQuery: [],
                integrationServiceRunId: $run->id,
            );
            $this->add($totals, $stored);
            $run->update($totals);

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

    /** @param array<string, int> $totals */
    private function synchronizeParents(
        WebPostoClient $client,
        int $empresa,
        array &$totals,
        IntegrationServiceRun $run,
    ): void {
        $parents = [
            ['/INTEGRACAO/EMPRESAS', EmpresaImporter::class, false],
            ['/INTEGRACAO/GRUPO', ProdutoGrupoImporter::class, true],
            ['/INTEGRACAO/CONSULTAR_SUB_GRUPO_REDE', ProdutoSubgrupoImporter::class, true],
            ['/INTEGRACAO/PRODUTO_LMC_LMP', ProdutoLmcLmpImporter::class, true],
            ['/INTEGRACAO/GRUPO_CLIENTE', ClienteGrupoImporter::class, true],
        ];

        foreach ($parents as [$endpoint, $importerClass, $withEmpresa]) {
            $result = $client->get($endpoint, $empresa);
            if (! $result['response']->successful()) {
                throw new RuntimeException('Falha ao consultar recurso pai do WebPosto.');
            }

            $stored = DB::connection('webposto')->transaction(
                fn (): array => $withEmpresa
                    ? app($importerClass)->import($result['payload'], $empresa)
                    : app($importerClass)->import($result['payload']),
            );
            $this->add($totals, $stored);
            $run->update($totals);
        }
    }

    /** @param array<string, int> $totals @param array<string, mixed> $stored */
    private function add(array &$totals, array $stored): void
    {
        foreach ($totals as $field => $value) {
            $totals[$field] += (int) ($stored[$field] ?? 0);
        }
    }
}
