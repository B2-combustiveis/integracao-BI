<?php

namespace App\Services\WebPosto;

use App\Models\IntegrationServiceRun;
use App\Services\Integration\IntegrationRunChangeRecorder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WebPostoNewRecordsSyncService
{
    public function __construct(
        private readonly WebPostoNewRecordsResourceCatalog $catalog,
        private readonly WebPostoCursorSynchronizer $synchronizer,
        private readonly IntegrationRunChangeRecorder $changeRecorder,
        private readonly WebPostoClient $client,
        private readonly WebPostoPendingRecordService $pendingRecords,
    ) {}

    /** @param array<int, string> $resources @return array<string, array<string, int>> */
    public function synchronize(
        int $empresa,
        array $resources,
        int $runId,
        ?callable $onProgress = null,
        bool $forceFullReconcile = false,
    ): array
    {
        $results = [];
        foreach (array_values(array_unique($resources)) as $resource) {
            $definition = $this->catalog->get($resource);
            if ($onProgress !== null) {
                $onProgress('running', $resource, null);
            }
            $retried = $this->pendingRecords->retry($definition, $empresa, $runId, $resource);
            $this->recordProgress($runId, $retried);
            if ($forceFullReconcile || ($definition['mode'] ?? 'cursor') === 'full_reconcile') {
                $synchronized = $this->synchronizeFullReconcile($definition, $empresa, $runId, $resource);
                $results[$resource] = $this->mergeResults($retried, $synchronized);
                if ($onProgress !== null) {
                    $onProgress('completed', $resource, $results[$resource]);
                }
                continue;
            }
            if (($definition['mode'] ?? 'cursor') === 'snapshot_new') {
                $synchronized = $this->synchronizeSnapshotNew($definition, $empresa, $runId, $resource);
                $results[$resource] = $this->mergeResults($retried, $synchronized);
                $this->recordProgress($runId, $synchronized);
                if ($onProgress !== null) {
                    $onProgress('completed', $resource, $results[$resource]);
                }
                continue;
            }
            $key = $definition['key'];
            $companyField = $definition['company_field'] ?? 'empresaCodigo';
            $initialCursor = (int) (DB::connection('webposto')->table($definition['table'])
                ->where($companyField, $empresa)->max($key) ?? 0);
            $query = $definition['query'];
            if (isset($definition['query_company_field'])) {
                $query[$definition['query_company_field']] = $empresa;
            }
            $query['limite'] = $definition['limit'];
            $synchronized = $this->synchronizer->synchronize(
                endpoint: $definition['endpoint'],
                empresaCodigo: $empresa,
                persist: function (mixed $payload, array $parameters) use ($definition, $empresa, $runId, $resource): array {
                    $companyField = $definition['company_field'] ?? 'empresaCodigo';
                    $rows = collect(is_array($payload) && is_array($payload['resultados'] ?? null)
                        ? $payload['resultados'] : [])
                        ->filter(fn ($row) => is_array($row)
                            && (! isset($row[$companyField]) || (int) $row[$companyField] === $empresa))
                        ->values();
                    $naturalKeys = $definition['natural_keys'] ?? [$definition['key']];
                    $keys = $rows
                        ->pluck($definition['key'])->filter()->unique()->values()->all();
                    $existing = DB::connection('webposto')->table($definition['table'])
                        ->where($companyField, $empresa)->whereIn($definition['key'], $keys)
                        ->get($naturalKeys)->mapWithKeys(fn ($row) => [collect($naturalKeys)
                            ->map(fn ($field) => (string) $row->{$field})->implode('|') => true])->all();
                    $newRows = $rows->filter(fn ($row) => collect($naturalKeys)
                            ->every(fn ($field) => isset($row[$field]))
                            && ! isset($existing[collect($naturalKeys)
                                ->map(fn ($field) => (string) $row[$field])->implode('|')]))
                        ->unique(fn ($row) => collect($naturalKeys)
                            ->map(fn ($field) => (string) $row[$field])->implode('|'))
                        ->values();
                    $filteredPayload = is_array($payload)
                        ? [...$payload, 'resultados' => $newRows->all()]
                        : ['resultados' => $newRows->all()];
                    $stored = app($definition['importer'])->import($filteredPayload, $empresa, $parameters);
                    $persisted = DB::connection('webposto')->table($definition['table'])
                        ->where($companyField, $empresa)->whereIn($definition['key'], $keys)
                        ->get($naturalKeys)->mapWithKeys(fn ($row) => [collect($naturalKeys)
                            ->map(fn ($field) => (string) $row->{$field})->implode('|') => true])->all();
                    $changes = $newRows
                        ->filter(fn ($row) => isset($persisted[collect($naturalKeys)
                            ->map(fn ($field) => (string) $row[$field])->implode('|')]))
                        ->map(fn ($row) => [
                            'action' => 'inserted',
                            'natural_key' => ['empresaCodigo' => $empresa, ...collect($naturalKeys)
                                ->mapWithKeys(fn ($field) => [$field => $row[$field]])->all()],
                            'source_updated_at' => $row[$definition['updated_field']] ?? null,
                            'payload' => $row,
                        ])->values()->all();
                    $this->changeRecorder->record($runId, $resource, $definition['table'], $changes);
                    $this->pendingRecords->storeMissing($definition, $empresa, $runId, $resource, $newRows->all(), $parameters);
                    $this->recordProgress($runId, [...$stored, 'received' => $rows->count()]);
                    return $stored;
                },
                query: $query,
                cursor: [
                    'initial_value' => $initialCursor,
                    'prefer_initial_value' => true,
                    ...($definition['cursor'] ?? []),
                ],
                initialQuery: null,
                integrationServiceRunId: $runId,
                controlKey: $definition['endpoint'].':new-records',
                omitCursorWhenZero: true,
            );
            $results[$resource] = $this->mergeResults($retried, $synchronized);
            if ($onProgress !== null) {
                $onProgress('completed', $resource, $results[$resource]);
            }
        }
        return $results;
    }

    /** @param array<string, mixed> $definition @return array<string, int> */
    private function synchronizeFullReconcile(array $definition, int $empresa, int $runId, string $resource): array
    {
        $query = $definition['query'];
        $query['limite'] = (int) ($definition['limit'] ?? $query['limite'] ?? 1000);

        return $this->synchronizer->synchronize(
            endpoint: $definition['endpoint'],
            empresaCodigo: $empresa,
            persist: function (mixed $payload, array $parameters) use ($definition, $empresa, $runId, $resource): array {
                $companyField = $definition['company_field'] ?? 'empresaCodigo';
                $key = $definition['key'];
                $rows = collect(is_array($payload) && is_array($payload['resultados'] ?? null)
                    ? $payload['resultados'] : [])
                    ->filter(fn ($row) => is_array($row)
                        && isset($row[$key])
                        && (! isset($row[$companyField]) || (int) $row[$companyField] === $empresa))
                    ->map(fn (array $row): array => isset($row[$companyField])
                        ? $row
                        : [$companyField => $empresa, ...$row])
                    ->unique(fn (array $row): string => (string) $row[$key])
                    ->values();
                $keys = $rows->pluck($key)->all();
                $before = DB::connection('webposto')->table($definition['table'])
                    ->where($companyField, $empresa)
                    ->whereIn($key, $keys)
                    ->get()
                    ->mapWithKeys(fn (object $row): array => [(string) $row->{$key} => (array) $row])
                    ->all();
                $filteredPayload = is_array($payload)
                    ? [...$payload, 'resultados' => $rows->all()]
                    : ['resultados' => $rows->all()];
                $stored = app($definition['importer'])->import($filteredPayload, $empresa, $parameters);
                $after = DB::connection('webposto')->table($definition['table'])
                    ->where($companyField, $empresa)
                    ->whereIn($key, $keys)
                    ->get()
                    ->mapWithKeys(fn (object $row): array => [(string) $row->{$key} => (array) $row])
                    ->all();
                $changes = $rows->map(function (array $row) use ($before, $after, $key, $empresa, $definition): ?array {
                    $value = (string) $row[$key];
                    if (! isset($after[$value])) {
                        return null;
                    }
                    $beforePayload = isset($before[$value])
                        ? collect($before[$value])->except(['created_at', 'updated_at'])->all()
                        : null;
                    $afterPayload = collect($after[$value])
                        ->except(['created_at', 'updated_at'])->all();
                    $changedFields = $beforePayload === null
                        ? array_keys($afterPayload)
                        : collect(array_unique([
                            ...array_keys($beforePayload),
                            ...array_keys($afterPayload),
                        ]))->filter(fn (string $field): bool =>
                            ($beforePayload[$field] ?? null) != ($afterPayload[$field] ?? null)
                        )->values()->all();
                    $action = $beforePayload === null
                        ? 'inserted'
                        : ($changedFields === [] ? null : 'updated');
                    if ($action === null) {
                        return null;
                    }

                    return [
                        'action' => $action,
                        'natural_key' => ['empresaCodigo' => $empresa, $key => $row[$key]],
                        'source_updated_at' => $row[$definition['updated_field']] ?? null,
                        'payload' => $row,
                        'before_payload' => $beforePayload,
                        'after_payload' => $afterPayload,
                        'changed_fields' => $changedFields,
                    ];
                })->filter()->values()->all();
                $this->changeRecorder->record($runId, $resource, $definition['table'], $changes);
                $this->pendingRecords->storeMissing(
                    $definition,
                    $empresa,
                    $runId,
                    $resource,
                    $rows->all(),
                    $parameters,
                );
                $stored['received'] = $rows->count();
                $this->recordProgress($runId, $stored);

                return $stored;
            },
            query: $query,
            cursor: [
                'initial_value' => 1,
                'prefer_initial_value' => true,
                ...($definition['cursor'] ?? []),
            ],
            initialQuery: null,
            integrationServiceRunId: $runId,
            controlKey: $definition['endpoint'].':full-reconcile',
        );
    }
    /** @param array<string, mixed> $definition @return array<string, int> */
    private function synchronizeSnapshotNew(array $definition, int $empresa, int $runId, string $resource): array
    {
        $result = $this->client->get($definition['endpoint'], $empresa, $definition['query']);
        if (! $result['response']->successful()) {
            throw new RuntimeException('WebPosto respondeu HTTP '.$result['response']->status().' em '.$definition['endpoint'].'.');
        }
        $payload = $result['payload'];
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null)
            ? $payload['resultados']
            : (is_array($payload) && array_is_list($payload) ? $payload : []);
        $rows = collect($rows)->filter(fn ($row) => is_array($row)
                && (! isset($row['empresaCodigo']) || (int) $row['empresaCodigo'] === $empresa))
            ->values()->all();
        $naturalKeys = $definition['natural_keys'];
        $existingQuery = DB::connection('webposto')->table($definition['table']);
        if (($definition['company_scoped'] ?? true) === true) {
            $existingQuery->where('empresaCodigo', $empresa);
        }
        $existing = $existingQuery->get($naturalKeys)->mapWithKeys(fn ($row) => [collect($naturalKeys)
                ->map(fn ($field) => (string) $row->{$field})->implode('|') => true])->all();
        $newRows = collect($rows)->filter(fn ($row) => is_array($row)
                && collect($naturalKeys)->every(fn ($field) => isset($row[$field]))
                && ! isset($existing[collect($naturalKeys)->map(fn ($field) => (string) $row[$field])->implode('|')]))
            ->unique(fn ($row) => collect($naturalKeys)->map(fn ($field) => (string) $row[$field])->implode('|'))
            ->values()->all();
        $stored = app($definition['importer'])->import(['resultados' => $newRows], $empresa);
        $persistedQuery = DB::connection('webposto')->table($definition['table']);
        if (($definition['company_scoped'] ?? true) === true) {
            $persistedQuery->where('empresaCodigo', $empresa);
        }
        $persisted = $persistedQuery->get($naturalKeys)->mapWithKeys(fn ($row) => [collect($naturalKeys)
            ->map(fn ($field) => (string) $row->{$field})->implode('|') => true])->all();
        $changes = collect($newRows)
            ->filter(fn ($row) => isset($persisted[collect($naturalKeys)
                ->map(fn ($field) => (string) $row[$field])->implode('|')]))
            ->map(fn ($row) => [
            'action' => 'inserted',
            'natural_key' => ['empresaCodigo' => $empresa, ...collect($naturalKeys)
                ->mapWithKeys(fn ($field) => [$field => $row[$field]])->all()],
            'source_updated_at' => $row[$definition['updated_field']] ?? null,
            'payload' => $row,
        ])->values()->all();
        $this->changeRecorder->record($runId, $resource, $definition['table'], $changes);
        $this->pendingRecords->storeMissing($definition, $empresa, $runId, $resource, $newRows);
        $stored['received'] = count($rows);
        return $stored;
    }

    /** @param array<string, int> $stored */
    private function recordProgress(int $runId, array $stored): void
    {
        $run = IntegrationServiceRun::query()->find($runId);
        if ($run === null) {
            return;
        }
        $progress = [];
        foreach (['received', 'inserted', 'updated', 'unchanged', 'skipped'] as $field) {
            $progress[$field] = (int) $run->{$field} + (int) ($stored[$field] ?? 0);
        }
        $run->update($progress);
    }

    /** @param array<string, int> $first @param array<string, int> $second @return array<string, int> */
    private function mergeResults(array $first, array $second): array
    {
        $merged = $second;
        foreach (['received', 'inserted', 'updated', 'unchanged', 'skipped'] as $field) {
            $merged[$field] = (int) ($first[$field] ?? 0) + (int) ($second[$field] ?? 0);
        }

        return $merged;
    }
}
