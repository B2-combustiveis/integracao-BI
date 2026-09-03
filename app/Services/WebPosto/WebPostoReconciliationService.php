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
    ) {}

    /** @param array<int, string> $resources @return array<string, array<string, mixed>> */
    public function synchronize(
        int $empresa,
        array $resources,
        int $runId,
        ?callable $onProgress = null,
        string $controlNamespace = 'reconciliation',
    ): array
    {
        $results = [];
        $incrementalStart = $this->incrementalStartDate($runId);
        $base = WebPostoCredential::query()->where('empresa_codigo', $empresa)->value('base');
        foreach (array_values(array_unique($resources)) as $resource) {
            $definition = $this->catalog->get($resource, $base);
            if ($onProgress !== null) {
                $onProgress('running', $resource, null);
            }

            try {
                $retried = $this->pendingRecords->retry($definition, $empresa, $runId, $resource);
                $this->recordProgress($runId, $retried);
                $reconciled = $this->reconcileResource($definition, $empresa, $runId, $resource, $onProgress, $incrementalStart, $controlNamespace);
                $results[$resource] = $this->mergeResults($retried, $reconciled) + ['status' => 'success'];
                if ($onProgress !== null) {
                    $onProgress('completed', $resource, $results[$resource]);
                }
            } catch (Throwable $exception) {
                $results[$resource] = [
                    'status' => 'failed',
                    'error' => mb_substr($exception->getMessage(), 0, 2000),
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
    ): array
    {
        $query = $definition['query'];
        if (($definition['reconciliation_updated_period'] ?? false) === true && $incrementalStart !== null) {
            $query['dataInicial'] = $incrementalStart;
            $query['dataFinal'] = now()->toDateString();
        }
        if (isset($definition['query_company_field'])) {
            $query[$definition['query_company_field']] = $empresa;
        }
        if (! ($definition['cursor']['direct_list'] ?? false)) {
            $query['limite'] = (int) ($definition['limit'] ?? $query['limite'] ?? 1000);
        }

        $result = $this->synchronizer->synchronize(
            endpoint: $definition['endpoint'],
            empresaCodigo: $empresa,
            persist: function (mixed $payload, array $parameters) use ($definition, $empresa, $runId, $resource): array {
                $companyField = $definition['company_field'] ?? 'empresaCodigo';
                $key = $definition['key'];
                $naturalKeys = $definition['natural_keys'] ?? [$key];
                $companyScoped = ($definition['company_scoped'] ?? true) === true;
                $rows = collect(is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [])
                    ->filter(fn ($row): bool => is_array($row)
                        && collect($naturalKeys)->every(fn (string $field): bool => array_key_exists($field, $row))
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
            },
            query: $query,
            cursor: ['initial_value' => 1, 'prefer_initial_value' => true, ...($definition['cursor'] ?? [])],
            integrationServiceRunId: $runId,
            controlKey: $definition['endpoint'].':'.$controlNamespace,
            resumeFromCheckpoint: true,
            onPageProgress: $onProgress === null ? null : fn (array $progress) => $onProgress('progress', $resource, $progress),
        );

        return [
            ...$result,
            'period_start' => ($definition['reconciliation_updated_period'] ?? false) === true ? $incrementalStart : null,
            'period_end' => ($definition['reconciliation_updated_period'] ?? false) === true ? now()->toDateString() : null,
        ];
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
        if ($previous === null) {
            return null;
        }
        $lookbackDays = max(1, (int) ($run->service?->lookback_days ?? 1));

        return $previous->finished_at->copy()->subDays($lookbackDays)->toDateString();
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
