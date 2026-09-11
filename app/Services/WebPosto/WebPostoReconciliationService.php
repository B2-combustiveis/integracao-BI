<?php

namespace App\Services\WebPosto;

use App\Models\IntegrationServiceRun;
use App\Models\WebPostoCredential;
use App\Services\Integration\IntegrationRunChangeRecorder;
use Illuminate\Support\Facades\DB;
use Throwable;

class WebPostoReconciliationService
{
    public function __construct(
        private readonly WebPostoNewRecordsResourceCatalog $catalog,
        private readonly WebPostoCursorSynchronizer $synchronizer,
        private readonly IntegrationRunChangeRecorder $changeRecorder,
        private readonly WebPostoPendingRecordService $pendingRecords,
        private readonly WebPostoSourceDeletionService $sourceDeletions,
    ) {}

    /** @param array<int, string> $resources @return array<string, array<string, mixed>> */
    public function synchronize(
        int $empresa,
        array $resources,
        int $runId,
        ?callable $onProgress = null,
        string $controlNamespace = 'reconciliation',
        ?callable $shouldContinue = null,
    ): array
    {
        $results = [];
        $incrementalStart = $this->incrementalStartDate($runId);
        $base = WebPostoCredential::query()->where('empresa_codigo', $empresa)->value('base');
        foreach (array_values(array_unique($resources)) as $resource) {
            if ($shouldContinue !== null && $shouldContinue() !== true) {
                throw new \App\Exceptions\WebPostoSynchronizationCancelled;
            }
            $definition = $this->catalog->get($resource, $base);
            if ($onProgress !== null) {
                $onProgress('running', $resource, null);
            }

            try {
                $retried = $this->pendingRecords->retry($definition, $empresa, $runId, $resource);
                $this->recordProgress($runId, $retried);
                $reconciled = $this->reconcileResource($definition, $empresa, $runId, $resource, $onProgress, $incrementalStart, $controlNamespace, null, $shouldContinue);
                $results[$resource] = $this->mergeResults($retried, $reconciled) + ['status' => 'success'];
                if ($onProgress !== null) {
                    $onProgress('completed', $resource, $results[$resource]);
                }
            } catch (Throwable $exception) {
                if ($exception instanceof \App\Exceptions\WebPostoSynchronizationCancelled) {
                    throw $exception;
                }
                $results[$resource] = [
                    'status' => 'failed',
                    'error' => $this->safeError($exception),
                    'received' => 0,
                    'inserted' => 0,
                    'updated' => 0,
                    'unchanged' => 0,
                    'skipped' => 0,
                ];
                if ($onProgress !== null) {
                    $onProgress('failed', $resource, $results[$resource]);
                }
            }
        }

        $this->retryPendingUntilStable($empresa, $resources, $runId, $results);

        return $results;
    }

    /**
     * Reconciles endpoints whose cursor is global to the credential only once,
     * then routes every row to its company using the source empresaCodigo.
     *
     * @param array<int, int> $companyCodes
     * @param array<int, string> $resources
     * @return array<string, array<string, mixed>>
     */
    public function synchronizeShared(
        int $credentialCompany,
        array $companyCodes,
        array $resources,
        int $runId,
        ?callable $onProgress = null,
        string $controlNamespace = 'reconciliation',
        ?callable $shouldContinue = null,
    ): array {
        $allowedCompanies = array_values(array_unique(array_map('intval', $companyCodes)));
        $results = [];
        $incrementalStart = $this->incrementalStartDate($runId);
        $base = WebPostoCredential::query()->where('empresa_codigo', $credentialCompany)->value('base');

        foreach (array_values(array_unique($resources)) as $resource) {
            if ($shouldContinue !== null && $shouldContinue() !== true) {
                throw new \App\Exceptions\WebPostoSynchronizationCancelled;
            }
            $definition = $this->catalog->get($resource, $base);
            if ($onProgress !== null) {
                $onProgress('running', $resource, null);
            }

            try {
                $retried = ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
                foreach ($allowedCompanies as $empresa) {
                    $companyRetry = $this->pendingRecords->retry($definition, $empresa, $runId, $resource);
                    foreach (array_keys($retried) as $field) {
                        $retried[$field] += (int) ($companyRetry[$field] ?? 0);
                    }
                    $this->recordProgress($runId, $companyRetry);
                }
                $reconciled = $this->reconcileResource(
                    $definition,
                    $credentialCompany,
                    $runId,
                    $resource,
                    $onProgress,
                    $incrementalStart,
                    $controlNamespace.':shared',
                    $allowedCompanies,
                    $shouldContinue,
                );
                $results[$resource] = $this->mergeResults($retried, $reconciled) + ['status' => 'success'];
                if ($onProgress !== null) {
                    $onProgress('completed', $resource, $results[$resource]);
                }
            } catch (Throwable $exception) {
                if ($exception instanceof \App\Exceptions\WebPostoSynchronizationCancelled) {
                    throw $exception;
                }
                $results[$resource] = [
                    'status' => 'failed', 'error' => $this->safeError($exception),
                    'received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0,
                ];
                if ($onProgress !== null) {
                    $onProgress('failed', $resource, $results[$resource]);
                }
            }
        }

        foreach ($allowedCompanies as $empresa) {
            $this->retryPendingUntilStable($empresa, $resources, $runId, $results);
        }

        return $results;
    }

    private function safeError(Throwable $exception): string
    {
        return mb_substr(
            (string) preg_replace('/([?&]chave=)[^&\s]+/i', '$1SEU_TOKEN_AQUI', $exception->getMessage()),
            0,
            2000,
        );
    }

    /** @param array<int, string> $resources @param array<string, array<string, mixed>> $results */
    private function retryPendingUntilStable(int $empresa, array $resources, int $runId, array &$results): void
    {
        for ($pass = 0; $pass < 3; $pass++) {
            $resolvedThisPass = 0;
            foreach (array_values(array_unique($resources)) as $resource) {
                $retried = $this->pendingRecords->retry($this->catalog->get($resource), $empresa, $runId, $resource);
                $resolved = (int) ($retried['inserted'] ?? 0);
                if ($resolved === 0) {
                    continue;
                }
                $resolvedThisPass += $resolved;
                $this->recordProgress($runId, ['inserted' => $resolved]);
                $results[$resource]['inserted'] = (int) ($results[$resource]['inserted'] ?? 0) + $resolved;
                $results[$resource]['post_processed'] = (int) ($results[$resource]['post_processed'] ?? 0) + $resolved;
            }
            if ($resolvedThisPass === 0) {
                break;
            }
        }
    }

    /** @param array<string, mixed> $definition @return array<string, int> */
    private function reconcileResource(
        array $definition,
        int $empresa,
        int $runId,
        string $resource,
        ?callable $onProgress,
        ?string $incrementalStart,
        string $controlNamespace,
        ?array $allowedCompanies = null,
        ?callable $shouldContinue = null,
    ): array
    {
        $query = $definition['query'];
        $fullWindowStart = null;
        if (($definition['reconciliation_full_window'] ?? false) === true
            && $this->usesLimitedReconciliationWindow($controlNamespace)) {
            $months = max(1, (int) config('integration.webposto.reconciliation_max_lookback_months', 2));
            $fullWindowStart = now()->subMonths($months)->toDateString();
            $query['dataInicial'] = $fullWindowStart;
            $query['dataFinal'] = now()->toDateString();
        } elseif (($definition['reconciliation_updated_period'] ?? false) === true && $incrementalStart !== null) {
            $query['dataInicial'] = $incrementalStart;
            $query['dataFinal'] = now()->toDateString();
        } elseif ($this->usesLimitedReconciliationWindow($controlNamespace) && isset($query['dataInicial'])) {
            // Recursos filtrados por data da transacao (nao por data de atualizacao) nao tem
            // como saber com seguranca "o que mudou desde o ultimo sucesso" - por isso nao usam
            // $incrementalStart. Pra Chimba, a decisao de negocio e nao reconciliar mais que os
            // ultimos N meses de qualquer jeito, aceitando que correcao em registro mais antigo
            // que isso nao sera capturada.
            $months = (int) config('integration.webposto.reconciliation_max_lookback_months', 2);
            $query['dataInicial'] = max($query['dataInicial'], now()->subMonths($months)->toDateString());
            $query['dataFinal'] = now()->toDateString();
        }
        if ($allowedCompanies === null && isset($definition['query_company_field'])) {
            $query[$definition['query_company_field']] = $empresa;
        }
        if (! ($definition['cursor']['direct_list'] ?? false)) {
            $query['limite'] = (int) ($definition['limit'] ?? $query['limite'] ?? 1000);
        }

        $cursorInitialValue = $this->reconciliationCursorInitialValue(
            $definition,
            $empresa,
            $query,
            $controlNamespace,
            $allowedCompanies,
        );

        $seenByCompany = [];
        $result = $this->synchronizer->synchronize(
            endpoint: $definition['endpoint'],
            empresaCodigo: $empresa,
            persist: function (mixed $payload, array $parameters) use ($definition, $empresa, $runId, $resource, $allowedCompanies, &$seenByCompany): array {
                $this->rememberSeenRows($payload, $definition, $empresa, $allowedCompanies, $seenByCompany);
                if ($allowedCompanies === null) {
                    return $this->persistCompanyPage($payload, $parameters, $definition, $empresa, $runId, $resource, false);
                }

                $companyField = $definition['company_field'] ?? 'empresaCodigo';
                $rows = $this->sharedRowsByCompany($payload, $companyField, $allowedCompanies);
                $totals = ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
                foreach ($rows as $companyCode => $companyRows) {
                    $companyPayload = is_array($payload)
                        ? [...$payload, 'resultados' => $companyRows->values()->all()]
                        : ['resultados' => $companyRows->values()->all()];
                    $stored = $this->persistCompanyPage($companyPayload, $parameters, $definition, (int) $companyCode, $runId, $resource, true);
                    foreach (array_keys($totals) as $field) {
                        $totals[$field] += (int) ($stored[$field] ?? 0);
                    }
                }

                return $totals;
            },
            query: $query,
            cursor: [
                'initial_value' => $cursorInitialValue,
                'prefer_initial_value' => true,
                ...($definition['cursor'] ?? []),
            ],
            integrationServiceRunId: $runId,
            controlKey: $definition['endpoint'].':'.$controlNamespace,
            resumeFromCheckpoint: true,
            onPageProgress: $onProgress === null ? null : fn (array $progress) => $onProgress('progress', $resource, $progress),
            shouldContinue: $shouldContinue,
        );
        $deletions = $this->sourceDeletions->confirm(
            $definition,
            $resource,
            $seenByCompany,
            $allowedCompanies ?? [$empresa],
            $query,
            $runId,
        );

        return [
            ...$result,
            ...$deletions,
            'period_start' => ($definition['reconciliation_updated_period'] ?? false) === true
                ? ($fullWindowStart ?? $incrementalStart)
                : null,
            'period_end' => ($definition['reconciliation_updated_period'] ?? false) === true ? now()->toDateString() : null,
        ];
    }

    /** @param array<string, mixed> $definition @return array<string, int> */
    private function persistCompanyPage(mixed $payload, array $parameters, array $definition, int $empresa, int $runId, string $resource, bool $requireCompany): array
    {
                $companyField = $definition['company_field'] ?? 'empresaCodigo';
                $key = $definition['key'];
                $naturalKeys = $definition['natural_keys'] ?? [$key];
                $companyScoped = ($definition['company_scoped'] ?? true) === true;
                $rows = collect(is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [])
                    ->filter(fn ($row): bool => is_array($row)
                        && collect($naturalKeys)->every(fn (string $field): bool => array_key_exists($field, $row))
                        && (! $companyScoped || ($requireCompany ? isset($row[$companyField]) : true))
                        && (! $companyScoped || ! isset($row[$companyField]) || (int) $row[$companyField] === $empresa))
                    ->map(fn (array $row): array => $companyScoped && ! isset($row[$companyField]) ? [$companyField => $empresa, ...$row] : $row)
                    ->unique(fn (array $row): string => $this->naturalKeySignature($row, $naturalKeys))
                    ->values();
                $keys = $rows->pluck($key)->unique()->values()->all();

                $beforeQuery = DB::connection('webposto')->table($definition['table'])->whereIn($key, $keys);
                if ($companyScoped) {
                    $beforeQuery->where($companyField, $empresa);
                }
                $before = $beforeQuery->get()->mapWithKeys(fn (object $row): array => [
                    $this->naturalKeySignature((array) $row, $naturalKeys) => (array) $row,
                ])->all();

                $filteredPayload = is_array($payload) ? [...$payload, 'resultados' => $rows->all()] : ['resultados' => $rows->all()];
                $stored = app($definition['importer'])->import($filteredPayload, $empresa, $parameters);

                $afterQuery = DB::connection('webposto')->table($definition['table'])->whereIn($key, $keys);
                if ($companyScoped) {
                    $afterQuery->where($companyField, $empresa);
                }
                $after = $afterQuery->get()->mapWithKeys(fn (object $row): array => [
                    $this->naturalKeySignature((array) $row, $naturalKeys) => (array) $row,
                ])->all();

                $changes = $rows->map(function (array $row) use ($before, $after, $naturalKeys, $empresa, $definition): ?array {
                    $signature = $this->naturalKeySignature($row, $naturalKeys);
                    if (! isset($after[$signature])) {
                        return null;
                    }
                    $beforePayload = isset($before[$signature]) ? collect($before[$signature])->except(['created_at', 'updated_at'])->all() : null;
                    $afterPayload = collect($after[$signature])->except(['created_at', 'updated_at'])->all();
                    $changedFields = $beforePayload === null ? array_keys($afterPayload) : collect(array_unique([
                        ...array_keys($beforePayload), ...array_keys($afterPayload),
                    ]))->filter(fn (string $field): bool => ($beforePayload[$field] ?? null) != ($afterPayload[$field] ?? null))->values()->all();
                    $action = $beforePayload === null ? 'inserted' : ($changedFields === [] ? null : 'updated');
                    if ($action === null) {
                        return null;
                    }

                    return [
                        'action' => $action,
                        'natural_key' => ['empresaCodigo' => $empresa, ...collect($naturalKeys)->mapWithKeys(fn (string $field): array => [$field => $row[$field]])->all()],
                        'source_updated_at' => $row[$definition['updated_field']] ?? null,
                        'payload' => $row,
                        'before_payload' => $beforePayload,
                        'after_payload' => $afterPayload,
                        'changed_fields' => $changedFields,
                    ];
                })->filter()->values()->all();

                $this->changeRecorder->record($runId, $resource, $definition['table'], $changes);
                $this->pendingRecords->storeMissing($definition, $empresa, $runId, $resource, $rows->all(), $parameters);
                $stored['received'] = $rows->count();
                $this->recordProgress($runId, $stored);

                return $stored;
    }

    /** @param array<int, int> $allowedCompanies */
    private function sharedRowsByCompany(mixed $payload, string $companyField, array $allowedCompanies): \Illuminate\Support\Collection
    {
        return collect(is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [])
            ->filter(fn ($row): bool => is_array($row)
                && isset($row[$companyField])
                && in_array((int) $row[$companyField], $allowedCompanies, true))
            ->groupBy(fn (array $row): int => (int) $row[$companyField]);
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<int, int>|null $allowedCompanies
     * @param array<int, array<string, true>> $seenByCompany
     */
    private function rememberSeenRows(mixed $payload, array $definition, int $fallbackCompany, ?array $allowedCompanies, array &$seenByCompany): void
    {
        $companyField = (string) ($definition['company_field'] ?? 'empresaCodigo');
        $naturalKeys = (array) ($definition['natural_keys'] ?? [$definition['key']]);
        foreach (is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [] as $row) {
            if (! is_array($row) || ! collect($naturalKeys)->every(fn (string $field): bool => array_key_exists($field, $row))) {
                continue;
            }
            $company = isset($row[$companyField]) ? (int) $row[$companyField] : $fallbackCompany;
            if ($allowedCompanies !== null && ! in_array($company, $allowedCompanies, true)) {
                continue;
            }
            if ($allowedCompanies !== null && ! isset($row[$companyField])) {
                continue;
            }
            $key = collect($naturalKeys)->mapWithKeys(fn (string $field): array => [$field => $row[$field]])->all();
            ksort($key);
            $seenByCompany[$company][hash('sha256', json_encode($key, JSON_UNESCAPED_UNICODE))] = true;
        }
    }

    /**
     * Endpoints por cursor usam codigos globais e podem gastar centenas de
     * requisicoes atravessando codigos anteriores a janela reconciliada. Quando
     * ja existe um espelho local da empresa, iniciar imediatamente antes do menor
     * codigo da janela preserva a leitura completa do periodo sem esse vazio.
     *
     * A carga inicial e Novos Dados nao passam por este servico. Se a tabela nao
     * possuir uma referencia local confiavel, mantemos o comportamento seguro de
     * iniciar em 1.
     *
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $query
     */
    private function reconciliationCursorInitialValue(
        array $definition,
        int $empresa,
        array $query,
        string $controlNamespace,
        ?array $allowedCompanies = null,
    ): int {
        // Em recursos filtrados por data de atualizacao, um codigo antigo pode ser
        // alterado hoje. Comecar no menor codigo local recente pularia esse registro.
        if (($definition['reconciliation_cursor_from_start'] ?? false) === true) {
            return 1;
        }

        if (! $this->usesLimitedReconciliationWindow($controlNamespace)
            || ! isset($query['dataInicial'])
            || ($definition['cursor']['direct_list'] ?? false)
            || ($definition['cursor']['single_page'] ?? false)) {
            return 1;
        }

        $key = (string) ($definition['key'] ?? '');
        $dateField = (string) ($definition['updated_field'] ?? '');
        if ($key === '' || $dateField === '') {
            return 1;
        }

        try {
            $local = DB::connection('webposto')
                ->table($definition['table'])
                ->where($dateField, '>=', $query['dataInicial']);
            if (($definition['company_scoped'] ?? true) === true && $allowedCompanies !== null) {
                $local->whereIn($definition['company_field'] ?? 'empresaCodigo', $allowedCompanies);
            } elseif (($definition['company_scoped'] ?? true) === true) {
                $local->where($definition['company_field'] ?? 'empresaCodigo', $empresa);
            }

            $minimum = $local->min($key);
        } catch (Throwable) {
            return 1;
        }

        return is_numeric($minimum) ? max(1, (int) $minimum - 1) : 1;
    }

    private function incrementalStartDate(int $runId): ?string
    {
        $run = IntegrationServiceRun::query()->find($runId);
        if ($run === null) {
            return null;
        }
        $previous = IntegrationServiceRun::query()
            ->where('integration_service_id', $run->integration_service_id)
            ->where('id', '<', $runId)
            ->where('status', 'success')
            ->whereNotNull('finished_at')
            ->latest('finished_at')
            ->first();
        $isReconciliation = $this->usesLimitedReconciliationWindow((string) $run->service?->resource);
        if ($previous === null) {
            if (! $isReconciliation) {
                return null;
            }
            $start = null;
        } else {
            $lookbackDays = max(1, (int) ($run->service?->lookback_days ?? 1));
            $start = $previous->finished_at->copy()->subDays($lookbackDays);
        }

        if ($isReconciliation) {
            $months = (int) config('integration.webposto.reconciliation_max_lookback_months', 2);
            $floor = now()->subMonths($months);
            $start = $start === null ? $floor : $start->max($floor);
        }

        return $start?->toDateString();
    }

    private function usesLimitedReconciliationWindow(string $resource): bool
    {
        return str_starts_with($resource, 'webposto-chimba-reconciliation')
            || str_starts_with($resource, 'webposto-b2-reconciliation');
    }

    /** @param array<string, mixed> $row @param array<int, string> $naturalKeys */
    private function naturalKeySignature(array $row, array $naturalKeys): string
    {
        return collect($naturalKeys)->map(fn (string $field): string => (string) ($row[$field] ?? ''))->implode('|');
    }

    /** @param array<string, int> $stored */
    private function recordProgress(int $runId, array $stored): void
    {
        $increments = [];
        foreach (['received', 'inserted', 'updated', 'unchanged', 'skipped'] as $field) {
            $delta = (int) ($stored[$field] ?? 0);
            if ($delta !== 0) {
                $increments[$field] = DB::raw("{$field} + {$delta}");
            }
        }
        if ($increments !== []) {
            IntegrationServiceRun::query()->whereKey($runId)->update($increments);
        }
    }

    /** @param array<string, int> $first @param array<string, int> $second @return array<string, int> */
    private function mergeResults(array $first, array $second): array
    {
        foreach (['received', 'inserted', 'updated', 'unchanged', 'skipped'] as $field) {
            $second[$field] = (int) ($first[$field] ?? 0) + (int) ($second[$field] ?? 0);
        }

        return $second;
    }
}
