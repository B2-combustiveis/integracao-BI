<?php

namespace App\Services\WebPosto;

use App\Models\WebPostoSyncControl;
use App\Services\Integration\IntegrationRunChangeRecorder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WebPostoModifiedRecordsSyncService
{
    private const OVERLAP_MINUTES = 5;

    public function __construct(
        private readonly WebPostoModifiedResourceCatalog $catalog,
        private readonly WebPostoCursorSynchronizer $cursorSynchronizer,
        private readonly RawResourceImporter $rawImporter,
        private readonly IntegrationRunChangeRecorder $changeRecorder,
    ) {
    }

    /** @return array<string, array<string, int>> */
    public function synchronizeAll(int $empresaCodigo, ?int $integrationServiceRunId = null): array
    {
        $results = [];
        foreach (array_keys($this->catalog->all()) as $resource) {
            $results[$resource] = $this->synchronizeResource($resource, $empresaCodigo, $integrationServiceRunId);
        }

        return $results;
    }

    /** @return array<string, int> */
    public function synchronizeResource(string $resource, int $empresaCodigo, ?int $integrationServiceRunId = null): array
    {
        $definition = $this->catalog->get($resource);
        $endpoint = $definition['endpoint'];
        $controlKey = $endpoint.':modified';
        $cycleStartedAt = now();
        $existingControl = WebPostoSyncControl::query()
            ->where('empresa_codigo', $empresaCodigo)
            ->where('endpoint', $controlKey)
            ->first();
        $modifiedSince = ($existingControl?->last_change_sync_at?->copy()
            ?? Carbon::create(2000, 1, 1, 0, 0, 0, config('app.timezone')))
            ->subMinutes(self::OVERLAP_MINUTES);
        $query = [
            ...$definition['query'],
            'limite' => $definition['limit'],
            'dataHoraAtualizacao' => $modifiedSince->toIso8601String(),
        ];

        $totals = $this->cursorSynchronizer->synchronize(
            endpoint: $endpoint,
            empresaCodigo: $empresaCodigo,
            persist: function (mixed $payload, array $parameters) use ($definition, $empresaCodigo, $integrationServiceRunId, $resource): array {
                ['payload' => $filtered, 'changes' => $changes] = $this->classifyNewerRecords($payload, $definition, $empresaCodigo);
                $importer = $definition['importer'];

                $persistStartedAt = now()->startOfSecond();
                $stored = $importer !== null
                    ? app($importer)->import($filtered, $empresaCodigo)
                    : $this->rawImporter->import($filtered, $empresaCodigo, $definition['table'], $parameters);

                if ($integrationServiceRunId !== null) {
                    $persisted = $this->persistedChanges($changes, $definition, $empresaCodigo, $persistStartedAt);
                    $this->changeRecorder->record($integrationServiceRunId, $resource, $definition['table'], $persisted);
                }

                return $stored;
            },
            query: $query,
            initialQuery: [],
            integrationServiceRunId: $integrationServiceRunId,
            controlKey: $controlKey,
            omitCursorWhenZero: true,
        );

        $control = WebPostoSyncControl::query()
            ->where('empresa_codigo', $empresaCodigo)
            ->where('endpoint', $controlKey)
            ->firstOrFail();
        $metadata = is_array($control->metadata) ? $control->metadata : [];
        $control->update([
            'last_code' => 0,
            'last_change_sync_at' => $cycleStartedAt,
            'metadata' => [
                ...$metadata,
                'last_cycle_cursor' => $metadata['cursor_value'] ?? $control->last_code,
                'cursor_value' => 0,
                'updated_field' => $definition['updatedField'],
                'resource' => $resource,
                'modified_since' => $modifiedSince->toIso8601String(),
                'overlap_minutes' => self::OVERLAP_MINUTES,
            ],
        ]);

        return $totals;
    }

    /** @param array<int, array<string, mixed>> $changes @param array<string, mixed> $definition */
    private function persistedChanges(array $changes, array $definition, int $empresaCodigo, Carbon $startedAt): array
    {
        $hasCompany = Schema::connection('webposto')->hasColumn($definition['table'], 'empresaCodigo');

        return collect($changes)->filter(function (array $change) use ($definition, $empresaCodigo, $startedAt, $hasCompany): bool {
            $query = DB::connection('webposto')->table($definition['table'])
                ->where($definition['key'], $change['natural_key'][$definition['key']]);
            if ($hasCompany) $query->where('empresaCodigo', $change['natural_key']['empresaCodigo'] ?? $empresaCodigo);
            $updatedAt = $query->value('updated_at');
            if ($updatedAt === null) return false;

            try {
                return Carbon::parse((string) $updatedAt)->greaterThanOrEqualTo($startedAt);
            } catch (\Throwable) {
                return false;
            }
        })->values()->all();
    }

    /** @param array<string, mixed> $definition
     *  @return array{payload:mixed,changes:array<int,array<string,mixed>>}
     */
    private function classifyNewerRecords(mixed $payload, array $definition, int $empresaCodigo): array
    {
        if (! is_array($payload) || ! is_array($payload['resultados'] ?? null)) {
            return ['payload' => $payload, 'changes' => []];
        }

        $rows = $payload['resultados'];
        $key = $definition['key'];
        $updatedField = $definition['updatedField'];
        $keys = collect($rows)
            ->filter(fn (mixed $row): bool => is_array($row) && array_key_exists($key, $row))
            ->pluck($key)->unique()->values()->all();
        $query = DB::connection('webposto')->table($definition['table'])->whereIn($key, $keys);
        if (Schema::connection('webposto')->hasColumn($definition['table'], 'empresaCodigo')) {
            $query->where('empresaCodigo', $empresaCodigo);
        }
        $localDates = $keys === [] ? [] : $query->pluck($updatedField, $key)->all();

        $classified = collect($rows)->map(function (mixed $row) use ($key, $updatedField, $localDates, $empresaCodigo): ?array {
            if (! is_array($row) || ! array_key_exists($key, $row)) return null;

            $recordKey = $row[$key];
            $action = array_key_exists($recordKey, $localDates) ? 'updated' : 'inserted';
            $isNewer = $action === 'inserted';

            if (! $isNewer && (! is_string($row[$updatedField] ?? null) || $row[$updatedField] === '')) return null;
            if (! $isNewer && ($localDates[$recordKey] === null || $localDates[$recordKey] === '')) $isNewer = true;

            try {
                if (! $isNewer) {
                    $remoteDate = Carbon::parse($row[$updatedField])->setMicrosecond(0);
                    $localDate = Carbon::parse((string) $localDates[$recordKey])->setMicrosecond(0);
                    $isNewer = $remoteDate->greaterThan($localDate);
                }
            } catch (\Throwable) {
                return null;
            }

            if (! $isNewer) return null;

            return [
                'row' => $row,
                'change' => [
                    'action' => $action,
                    'natural_key' => [
                        'empresaCodigo' => $row['empresaCodigo'] ?? $empresaCodigo,
                        $key => $recordKey,
                    ],
                    'source_updated_at' => $row[$updatedField] ?? null,
                    'payload' => $row,
                ],
            ];
        })->filter()->values()->all();

        $payload['resultados'] = array_column($classified, 'row');

        return ['payload' => $payload, 'changes' => array_column($classified, 'change')];
    }
}
