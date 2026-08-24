<?php

namespace App\Services\WebPosto;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class RawResourceImporter
{
    public function __construct(
        private readonly RawSchemaPromoter $schemaPromoter,
        private readonly RawNaturalKeyResolver $keys,
    ) {
    }

    public function import(mixed $payload, int $empresaCodigo, string $table, array $parameters): array
    {
        $rows = $this->rows($payload);
        $schema = $this->schemaPromoter->promote($table, $rows);
        $inserted = $updated = $unchanged = $skipped = 0;
        $connection = DB::connection('webposto');
        $now = now();

        $records = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;
                continue;
            }

            $mapped = $this->schemaPromoter->map($row, $schema);
            $criteria = $this->keys->criteria($table, $mapped);
            if ($criteria === []) { $skipped++; continue; }
            $key = $this->criteriaKey($criteria);
            if (isset($records[$key])) $skipped++;
            $records[$key] = ['mapped' => $mapped, 'criteria' => $criteria];
        }

        $existingRows = $this->existingRows($connection->table($table), $records);
        $inserts = [];
        foreach ($records as $key => $record) {
            $mapped = $record['mapped'];
            $criteria = $record['criteria'];
            $existing = $existingRows[$key] ?? null;
            if ($existing === null) {
                $inserts[] = [...$mapped, 'created_at' => $now, 'updated_at' => $now];
                $inserted++;
                continue;
            }
            if (! $this->changed($existing, $mapped)) { $unchanged++; continue; }
            $query = $connection->table($table);
            foreach ($criteria as $field => $value) $query->where($field, $value);
            $query->update([...$mapped, 'updated_at' => $now]);
            $updated++;
        }
        foreach (array_chunk($inserts, 500) as $chunk) {
            $connection->table($table)->insert($chunk);
        }

        return [
            'database' => 'webposto', 'table' => $table,
            'sync_status' => $inserted > 0 ? 'synchronized' : ($unchanged > 0 ? 'already_synchronized' : 'no_valid_records'),
            'received' => count($rows), 'inserted' => $inserted, 'updated' => $updated,
            'unchanged' => $unchanged, 'skipped' => $skipped,
        ];
    }

    /**
     * @param array<string, array{mapped: array<string, mixed>, criteria: array<string, mixed>}> $records
     * @return array<string, object>
     */
    private function existingRows(Builder $query, array $records): array
    {
        if ($records === []) return [];
        $criteriaSets = array_column($records, 'criteria');
        $fields = array_keys($criteriaSets[0]);
        $sameFields = collect($criteriaSets)->every(
            fn (array $criteria): bool => array_keys($criteria) === $fields,
        );

        if ($sameFields && count($fields) === 1) {
            $field = $fields[0];
            $rows = $query->whereIn($field, array_column($criteriaSets, $field))->get();

            return $rows->mapWithKeys(fn (object $row): array => [
                $this->criteriaKey([$field => $row->{$field}]) => $row,
            ])->all();
        }

        if ($sameFields && count($fields) === 2) {
            [$fixed, $varying] = $fields;
            $fixedValues = array_unique(array_column($criteriaSets, $fixed), SORT_REGULAR);
            if (count($fixedValues) === 1) {
                $rows = $query->where($fixed, $fixedValues[0])
                    ->whereIn($varying, array_column($criteriaSets, $varying))
                    ->get();

                return $rows->mapWithKeys(fn (object $row): array => [
                    $this->criteriaKey([$fixed => $row->{$fixed}, $varying => $row->{$varying}]) => $row,
                ])->all();
            }
        }

        $existing = [];
        foreach ($records as $key => $record) {
            $recordQuery = clone $query;
            foreach ($record['criteria'] as $field => $value) $recordQuery->where($field, $value);
            $row = $recordQuery->first();
            if ($row !== null) $existing[$key] = $row;
        }

        return $existing;
    }

    /** @param array<string, mixed> $criteria */
    private function criteriaKey(array $criteria): string
    {
        return json_encode($criteria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string, mixed> $mapped */
    private function changed(object $existing, array $mapped): bool
    {
        foreach ($mapped as $field => $value) {
            $current = $existing->{$field} ?? null;
            if (is_string($value) && in_array($value[0] ?? '', ['[', '{'], true)) {
                if (json_decode((string) $current, true) != json_decode($value, true)) return true;
                continue;
            }
            if ($current != $value) return true;
        }
        return false;
    }

    /** @return array<int, mixed> */
    private function rows(mixed $payload): array
    {
        if (is_string($payload) && $payload !== '') return [['conteudo' => $payload]];
        if (! is_array($payload)) return [];
        if (isset($payload['resultados']) && is_array($payload['resultados'])) return $payload['resultados'];
        if (array_is_list($payload)) return $payload;
        return [$payload];
    }
}
