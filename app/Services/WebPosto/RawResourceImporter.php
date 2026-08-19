<?php

namespace App\Services\WebPosto;

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

        foreach ($rows as $row) {
            if (! is_array($row)) {
                $skipped++;
                continue;
            }

            $mapped = $this->schemaPromoter->map($row, $schema);
            $criteria = $this->keys->criteria($table, $mapped);
            if ($criteria === []) { $skipped++; continue; }
            $query = $connection->table($table);
            foreach ($criteria as $field => $value) $query->where($field, $value);
            $existing = $query->first();
            if ($existing === null) {
                $connection->table($table)->insert([...$mapped, 'created_at' => $now, 'updated_at' => $now]);
                $inserted++;
                continue;
            }
            if (! $this->changed($existing, $mapped)) { $unchanged++; continue; }
            $connection->table($table)->where('id', $existing->id)->update([...$mapped, 'updated_at' => $now]);
            $updated++;
        }

        return [
            'database' => 'webposto', 'table' => $table,
            'sync_status' => $inserted > 0 ? 'synchronized' : ($unchanged > 0 ? 'already_synchronized' : 'no_valid_records'),
            'received' => count($rows), 'inserted' => $inserted, 'updated' => $updated,
            'unchanged' => $unchanged, 'skipped' => $skipped,
        ];
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
