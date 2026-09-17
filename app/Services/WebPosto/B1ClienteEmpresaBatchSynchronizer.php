<?php

namespace App\Services\WebPosto;

use App\Exceptions\WebPostoSynchronizationCancelled;
use App\Models\WebPostoCredential;
use App\Models\WebPostoInitialSyncRun;
use App\Models\WebPostoSyncControl;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class B1ClienteEmpresaBatchSynchronizer
{
    private const ENDPOINT = '/INTEGRACAO/CLIENTE_EMPRESA';

    /**
     * O feed de CLIENTE_EMPRESA e global (nao filtra por empresa na origem),
     * entao o cursor e travado no proprio codigo do endpoint em vez de no
     * batch_key: assim um lote novo retoma de onde o lote anterior parou em
     * vez de escanear o feed inteiro de novo a cada lote da B1.
     */
    private const SHARED_CONTROL_KEY = self::ENDPOINT.':b1-shared';

    private const SHARED_LOCK_KEY = 'webposto:b1:cliente-empresas:shared';

    public function __construct(
        private readonly WebPostoCursorSynchronizer $synchronizer,
        private readonly ClienteEmpresaImporter $importer,
    ) {}

    /** @return array<string, int> */
    public function synchronize(int $empresaCodigo): array
    {
        $current = WebPostoInitialSyncRun::query()
            ->where('empresa_codigo', $empresaCodigo)
            ->whereNotNull('batch_key')
            ->latest('id')
            ->firstOrFail();
        $cohort = WebPostoInitialSyncRun::query()
            ->where('batch_key', $current->batch_key)
            ->orderBy('id')
            ->get();
        $companyCodes = $cohort->pluck('empresa_codigo')->map(fn ($code): int => (int) $code)->sort()->values();
        $credentialCompany = (int) $companyCodes->first();
        WebPostoCredential::query()
            ->where('empresa_codigo', $credentialCompany)
            ->where('base', WebPostoCredential::BASE_B1)
            ->firstOrFail();

        return Cache::lock(self::SHARED_LOCK_KEY, 10800)
            ->block(10800, function () use ($cohort, $current): array {
                $shouldContinue = fn (): bool => WebPostoInitialSyncRun::query()
                    ->whereKey($current->id)
                    ->where('status', 'running')
                    ->exists();
                $this->waitForCohortClients($cohort, $shouldContinue);

                // Todas as empresas B1 ja cadastradas, nao so as do lote atual: o feed
                // e global, entao qualquer vinculo de qualquer empresa B1 encontrado no
                // caminho e persistido de uma vez, e nao descartado so por nao ser do
                // lote que disparou o scan.
                $allB1Codes = WebPostoCredential::query()
                    ->where('base', WebPostoCredential::BASE_B1)
                    ->pluck('empresa_codigo')
                    ->map(fn (mixed $code): int => (int) $code)
                    ->values();

                // Ancora estavel (qualquer credencial B1 ativa autentica o mesmo feed
                // global igual) usada tanto pra autenticar a chamada quanto pra travar
                // a linha de controle do cursor compartilhado entre lotes.
                $anchorEmpresa = (int) WebPostoCredential::query()
                    ->where('base', WebPostoCredential::BASE_B1)
                    ->where('ativo', true)
                    ->orderBy('empresa_codigo')
                    ->value('empresa_codigo');

                $sharedControl = WebPostoSyncControl::query()
                    ->where('empresa_codigo', $anchorEmpresa)
                    ->where('endpoint', self::SHARED_CONTROL_KEY)
                    ->first();
                if ($sharedControl !== null && $sharedControl->status === 'ok') {
                    return $this->emptyTotals();
                }

                // Primeira vez que a chave compartilhada roda: nao comeca do zero se
                // scans antigos (por batch_key, de antes dessa mudanca) ja avancaram
                // o cursor - aproveita o ponto mais longe ja alcancado por eles.
                $startValue = 0;
                if ($sharedControl === null) {
                    $startValue = (int) WebPostoSyncControl::query()
                        ->where('endpoint', 'like', self::ENDPOINT.':b1-initial-batch:%')
                        ->max('last_code');
                }

                return $this->synchronizer->synchronize(
                    endpoint: self::ENDPOINT,
                    empresaCodigo: $anchorEmpresa,
                    persist: function (mixed $payload) use ($allB1Codes): array {
                        $rows = collect(is_array($payload) ? ($payload['resultados'] ?? []) : [])
                            ->filter(fn ($row): bool => is_array($row)
                                && $allB1Codes->contains((int) ($row['empresaCodigo'] ?? 0)))
                            ->groupBy(fn (array $row): int => (int) $row['empresaCodigo']);
                        $totals = $this->emptyTotals();
                        foreach ($rows as $code => $companyRows) {
                            $stored = $this->importer->import(['resultados' => $companyRows->values()->all()], (int) $code);
                            foreach (array_keys($totals) as $field) {
                                $totals[$field] += (int) ($stored[$field] ?? 0);
                            }
                        }

                        return $totals;
                    },
                    query: ['limite' => 200],
                    cursor: ['initial_value' => $startValue, 'prefer_initial_value' => true, 'request_overlap' => 1],
                    maxPages: 100000,
                    controlKey: self::SHARED_CONTROL_KEY,
                    resumeFromCheckpoint: true,
                    shouldContinue: $shouldContinue,
                );
            });
    }

    private function waitForCohortClients(Collection $cohort, callable $shouldContinue): void
    {
        $runIds = $cohort->pluck('id')->all();
        $deadline = now()->addHours(2);
        do {
            if ($shouldContinue() !== true) {
                throw new WebPostoSynchronizationCancelled;
            }
            $runs = WebPostoInitialSyncRun::query()->whereIn('id', $runIds)->get();
            if ($runs->contains(fn ($run): bool => $run->status === 'failed')) {
                throw new RuntimeException('Uma carga do lote B1 falhou antes de cliente_empresas.');
            }
            if ($runs->count() === count($runIds) && $runs->every(
                fn ($run): bool => in_array('clientes', $run->completed_resources ?? [], true),
            )) {
                return;
            }
            sleep(2);
        } while (now()->lt($deadline));

        throw new RuntimeException('Tempo excedido aguardando os clientes do lote B1.');
    }

    /** @return array<string, int> */
    private function emptyTotals(): array
    {
        return ['pages' => 0, 'received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'duration_ms' => 0];
    }
}
