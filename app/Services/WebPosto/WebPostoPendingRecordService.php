<?php

namespace App\Services\WebPosto;

use App\Services\Integration\IntegrationRunChangeRecorder;
use Illuminate\Support\Facades\DB;

class WebPostoPendingRecordService
{
    public function __construct(private readonly IntegrationRunChangeRecorder $changeRecorder) {}

    /** @param array<string, mixed> $definition @return array<string, int> */
    public function retry(array $definition, int $empresa, int $runId, string $resource): array
    {
        $pending = DB::table('webposto_sync_pending_records')
            ->where('empresa_codigo', $empresa)->where('resource', $resource)
            ->where('status', 'pending')->orderBy('id')->get();

        if ($pending->isEmpty()) {
            return $this->emptyResult();
        }

        $rows = $pending->map(fn (object $record): array => $this->decode($record->payload))->values();
        $parameters = $this->decode($pending->first()->parameters ?? '[]');
        $payload = ['resultados' => $rows->all()];

        if (($definition['mode'] ?? 'cursor') === 'snapshot_new') {
            app($definition['importer'])->import($payload, $empresa);
        } else {
            app($definition['importer'])->import($payload, $empresa, $parameters);
        }

        $persisted = $this->persistedKeys($definition, $empresa, $rows->all());
        $resolved = $pending->filter(fn (object $record): bool => isset($persisted[$record->natural_key_hash]));
        $unresolved = $pending->reject(fn (object $record): bool => isset($persisted[$record->natural_key_hash]));
        $now = now();

        if ($resolved->isNotEmpty()) {
            DB::table('webposto_sync_pending_records')->whereIn('id', $resolved->pluck('id'))->update([
                'status' => 'resolved',
                'attempt_count' => DB::raw('attempt_count + 1'),
                'last_attempt_run_id' => $runId,
                'resolved_run_id' => $runId,
                'last_error' => null,
                'last_attempt_at' => $now,
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($unresolved->isNotEmpty()) {
            DB::table('webposto_sync_pending_records')->whereIn('id', $unresolved->pluck('id'))->update([
                'attempt_count' => DB::raw('attempt_count + 1'),
                'last_attempt_run_id' => $runId,
                'last_error' => 'Vinculo obrigatorio ainda ausente.',
                'last_attempt_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $changes = $resolved->map(function (object $record) use ($empresa, $definition): array {
            $row = $this->decode($record->payload);

            return [
                'action' => 'inserted',
                'natural_key' => ['empresaCodigo' => $empresa, ...$this->decode($record->natural_key)],
                'source_updated_at' => $row[$definition['updated_field']] ?? null,
                'payload' => $row,
            ];
        })->values()->all();

        $this->changeRecorder->record($runId, $resource, $definition['table'], $changes);

        return [
            'received' => $pending->count(),
            'inserted' => $resolved->count(),
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => $unresolved->count(),
        ];
    }

    /** @param array<string, mixed> $definition @param array<int, array<string, mixed>> $rows */
    public function storeMissing(
        array $definition,
        int $empresa,
        int $runId,
        string $resource,
        array $rows,
        array $parameters = [],
    ): void {
        if ($rows === []) {
            return;
        }

        $naturalKeys = $definition['natural_keys'] ?? [$definition['key']];
        $persisted = $this->persistedKeys($definition, $empresa, $rows);
        $now = now();

        foreach ($rows as $row) {
            $naturalKey = collect($naturalKeys)
                ->mapWithKeys(fn (string $field): array => [$field => $row[$field]])
                ->all();
            $hash = $this->keyHash($naturalKey);
            $query = DB::table('webposto_sync_pending_records')
                ->where('empresa_codigo', $empresa)
                ->where('resource', $resource)
                ->where('natural_key_hash', $hash);

            if (isset($persisted[$hash])) {
                $query->where('status', 'pending')->update([
                    'status' => 'resolved',
                    'resolved_run_id' => $runId,
                    'resolved_at' => $now,
                    'last_error' => null,
                    'updated_at' => $now,
                ]);
                continue;
            }

            $values = [
                'table_name' => $definition['table'],
                'natural_key' => json_encode($naturalKey, JSON_THROW_ON_ERROR),
                'payload' => json_encode($row, JSON_THROW_ON_ERROR),
                'parameters' => json_encode($parameters, JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'resolved_run_id' => null,
                'resolved_at' => null,
                'last_error' => 'Vinculo obrigatorio ausente na importacao.',
                'updated_at' => $now,
            ];
            $existing = $query->first();

            if ($existing === null) {
                DB::table('webposto_sync_pending_records')->insert($values + [
                    'empresa_codigo' => $empresa,
                    'resource' => $resource,
                    'natural_key_hash' => $hash,
                    'first_seen_run_id' => $runId,
                    'created_at' => $now,
                ]);
            } else {
                DB::table('webposto_sync_pending_records')->where('id', $existing->id)->update($values);
            }
        }
    }

    /** @param array<string, mixed> $definition @param array<int, array<string, mixed>> $rows @return array<string, true> */
    private function persistedKeys(array $definition, int $empresa, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $naturalKeys = $definition['natural_keys'] ?? [$definition['key']];
        $key = $definition['key'] ?? $naturalKeys[0];
        $values = collect($rows)->pluck($key)->filter(fn ($value) => $value !== null)->unique()->all();
        $query = DB::connection('webposto')->table($definition['table'])->whereIn($key, $values);

        if (($definition['company_scoped'] ?? true) === true) {
            $query->where($definition['company_field'] ?? 'empresaCodigo', $empresa);
        }

        return $query->get($naturalKeys)->mapWithKeys(function (object $record) use ($naturalKeys): array {
            $key = collect($naturalKeys)->mapWithKeys(
                fn (string $field): array => [$field => $record->{$field}],
            )->all();

            return [$this->keyHash($key) => true];
        })->all();
    }

    /** @param array<string, mixed> $key */
    private function keyHash(array $key): string
    {
        return hash('sha256', json_encode($key, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, int> */
    private function emptyResult(): array
    {
        return ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
    }
}
