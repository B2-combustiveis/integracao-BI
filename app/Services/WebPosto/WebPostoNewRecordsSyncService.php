<?php

namespace App\Services\WebPosto;

use App\Models\IntegrationServiceRun;
use App\Models\WebPostoCredential;
use App\Models\WebPostoSyncControl;
use App\Services\Integration\IntegrationRunChangeRecorder;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

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
    ): array {
        $results = [];
        $base = WebPostoCredential::query()->where('empresa_codigo', $empresa)->value('base');
        foreach (array_values(array_unique($resources)) as $resource) {
            $definition = $this->catalog->get($resource, $base);
            if ($onProgress !== null) {
                $onProgress('running', $resource, null);
            }
            try {
                $retried = $this->pendingRecords->retry($definition, $empresa, $runId, $resource);
                $this->recordProgress($runId, $retried);
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
                        $stored = [...$stored, 'received' => $rows->count()];
                        $this->recordProgress($runId, $stored);

                        return $stored;
                    },
                    query: $query,
                    cursor: [
                        'initial_value' => $initialCursor,
                        'prefer_initial_value' => false,
                        ...($definition['cursor'] ?? []),
                    ],
                    initialQuery: null,
                    integrationServiceRunId: $runId,
                    controlKey: $definition['endpoint'].':new-records',
                    omitCursorWhenZero: true,
                    onPageProgress: $onProgress === null
                        ? null
                        : fn (array $progress) => $onProgress('progress', $resource, $progress),
                );
                $results[$resource] = $this->mergeResults($retried, $synchronized);
                if ($onProgress !== null) {
                    $onProgress('completed', $resource, $results[$resource]);
                }
            } catch (Throwable $exception) {
                throw $exception;
            }
        }

        return $results;
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
        $this->normalizeLegacySnapshotControl($definition, $empresa);
        $stored['received'] = count($rows);

        return $stored;
    }

    /** @param array<string, mixed> $definition */
    private function normalizeLegacySnapshotControl(array $definition, int $empresa): void
    {
        $control = WebPostoSyncControl::query()
            ->where('empresa_codigo', $empresa)
            ->where('endpoint', $definition['endpoint'].':new-records')
            ->first();

        if ($control === null) {
            return;
        }

        $metadata = is_array($control->metadata) ? $control->metadata : [];
        $control->update([
            'status' => 'ok',
            'consecutive_failures' => 0,
            'last_error' => null,
            'last_completed_at' => now(),
            'metadata' => [
                ...$metadata,
                'snapshot_new' => true,
                'legacy_cursor_retired' => true,
            ],
        ]);
    }

    /**
     * Atomic SQL increment: multiple companies of the same run can call this concurrently
     * (one worker per company), so a read-then-write update here would lose increments.
     *
     * @param array<string, int> $stored
     */
    private function recordProgress(int $runId, array $stored): void
    {
        $increments = [];
        foreach (['received', 'inserted', 'updated', 'unchanged', 'skipped'] as $field) {
            $delta = (int) ($stored[$field] ?? 0);
            if ($delta !== 0) {
                $increments[$field] = DB::raw("{$field} + {$delta}");
            }
        }
        if ($increments === []) {
            return;
        }
        IntegrationServiceRun::query()->whereKey($runId)->update($increments);
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
