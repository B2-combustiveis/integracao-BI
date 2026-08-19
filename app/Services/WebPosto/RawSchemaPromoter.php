<?php

namespace App\Services\WebPosto;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class RawSchemaPromoter
{
    private const TECHNICAL_COLUMNS = [
        'id', 'credencialEmpresaCodigo', 'record_hash', 'payload', 'request_parameters',
        'created_at', 'updated_at',
    ];

    /** @param array<int, mixed> $rows */
    public function promote(string $tableName, array $rows): array
    {
        $discovered = $this->discover($rows);
        $existing = array_flip(Schema::connection('webposto')->getColumnListing($tableName));
        $missing = array_filter($discovered, fn (string $type, string $field): bool => ! isset($existing[$field]), ARRAY_FILTER_USE_BOTH);

        if ($missing !== []) {
            Schema::connection('webposto')->table($tableName, function (Blueprint $table) use ($missing): void {
                foreach ($missing as $field => $type) {
                    match ($type) {
                        'boolean' => $table->boolean($field)->nullable(),
                        'integer' => $table->bigInteger($field)->nullable(),
                        'decimal' => $table->decimal($field, 24, 8)->nullable(),
                        'json' => $table->json($field)->nullable(),
                        'datetime' => $table->dateTime($field)->nullable(),
                        default => $table->longText($field)->nullable(),
                    };
                }
            });
        }

        return $discovered;
    }

    /** @param array<string, string> $schema */
    public function map(array $row, array $schema): array
    {
        $mapped = [];
        foreach ($schema as $field => $type) {
            if (! array_key_exists($field, $row) || $row[$field] === null) {
                $mapped[$field] = null;
                continue;
            }
            $value = $row[$field];
            $mapped[$field] = match ($type) {
                'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION),
                'boolean' => (bool) $value,
                'integer' => is_numeric($value) ? (int) $value : null,
                'decimal' => is_numeric($value) ? $value : null,
                'datetime' => $this->dateTime($value),
                default => is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            };
        }
        return $mapped;
    }

    /** @param array<int, mixed> $rows @return array<string, string> */
    private function discover(array $rows): array
    {
        $schema = [];
        foreach ($rows as $row) {
            if (! is_array($row)) continue;
            foreach ($row as $field => $value) {
                if (in_array($field, self::TECHNICAL_COLUMNS, true) || $value === null) continue;
                $type = $this->type($value);
                $schema[$field] = isset($schema[$field]) ? $this->merge($schema[$field], $type) : $type;
            }
        }
        ksort($schema);
        return $schema;
    }

    private function type(mixed $value): string
    {
        if (is_array($value) || is_object($value)) return 'json';
        if (is_bool($value)) return 'boolean';
        if (is_int($value)) return 'integer';
        if (is_float($value)) return 'decimal';
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ][0-2]\d:[0-5]\d(?::[0-5]\d(?:\.\d+)?)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value)) return 'datetime';
        return 'text';
    }

    private function merge(string $current, string $incoming): string
    {
        if ($current === $incoming) return $current;
        if (in_array($current, ['integer', 'decimal'], true) && in_array($incoming, ['integer', 'decimal'], true)) return 'decimal';
        if ($current === 'json' || $incoming === 'json') return 'json';
        return 'text';
    }

    private function dateTime(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') return null;
        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }
}
