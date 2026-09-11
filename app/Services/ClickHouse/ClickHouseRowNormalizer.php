<?php

namespace App\Services\ClickHouse;

use DateTimeInterface;

/**
 * Normaliza uma linha do MySQL para o formato que o ClickHouse aceita, dado o
 * mapa de tipos declarado em ClickHouseTableCatalog. Extraida da logica antes
 * duplicada entre os dois comandos de sync do Back-end-bi.
 */
class ClickHouseRowNormalizer
{
    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $columns nome da coluna => tipo ClickHouse
     * @return array<string, mixed>
     */
    public static function normalize(array $row, array $columns): array
    {
        $normalized = [];

        foreach ($columns as $name => $type) {
            $normalized[$name] = self::normalizeValue($row[$name] ?? null, $type);
        }

        return $normalized;
    }

    private static function normalizeValue(mixed $value, string $type): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if ($value !== null) {
            return $value;
        }

        if (str_starts_with($type, 'Nullable(')) {
            return null;
        }

        if (str_starts_with($type, 'String') || str_starts_with($type, 'FixedString')) {
            return '';
        }

        if ($type === 'DateTime' || $type === 'Date') {
            return '1970-01-01 00:00:00';
        }

        return 0;
    }
}
