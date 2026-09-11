<?php

namespace App\Services\ClickHouse;

use App\Models\ClickHouseSyncControl;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Sincroniza uma tabela do MySQL (webposto) para o ClickHouse. Porta a logica
 * de App\Console\Commands\ClickHouseSyncCommand / ClickHouseSyncIncrementalCommand
 * do Back-end-bi, unificando a normalizacao de linha (antes duplicada) e usando
 * uma tabela de watermark propria (clickhouse_sync_controls) em vez de guardar
 * estado dentro do proprio ClickHouse.
 */
class ClickHouseTableSynchronizer
{
    private const MUTATION_POLL_SECONDS = 60;

    public function __construct(
        private readonly ClickHouseService $clickHouse,
        private readonly ClickHouseTableCatalog $catalog,
    ) {
    }

    /**
     * Dropa, recria e recarrega uma tabela inteira. So para bootstrap/DR - nunca agendado.
     *
     * @return array{received: int, inserted: int, updated: int, unchanged: int, skipped: int}
     */
    public function syncFull(string $table): array
    {
        $definition = $this->catalog->get($table);
        $batchSize = (int) config('clickhouse.sync_batch_size', 10000);

        $database = config('clickhouse.database');
        if ($this->clickHouse->tableExists($table)) {
            $this->clickHouse->execute("DROP TABLE IF EXISTS {$database}.{$table}");
        }
        $this->createTable($table, $definition);

        $cursor = $definition['cursor'];
        $lastValue = 0;
        $lastEmpresa = 0;
        $inserted = 0;

        do {
            $rows = $this->baseQuery($table, $definition)
                ->when($cursor['empresa_col'] !== null, function (Builder $query) use ($cursor, $lastEmpresa, $lastValue): void {
                    $query->where(function (Builder $inner) use ($cursor, $lastEmpresa, $lastValue): void {
                        $inner->where($cursor['empresa_col'], '>', $lastEmpresa)
                            ->orWhere(function (Builder $nested) use ($cursor, $lastEmpresa, $lastValue): void {
                                $nested->where($cursor['empresa_col'], '=', $lastEmpresa)
                                    ->where($cursor['col'], '>', $lastValue);
                            });
                    })->orderBy($cursor['empresa_col'])->orderBy($cursor['col']);
                }, function (Builder $query) use ($cursor, $lastValue): void {
                    $query->where($cursor['col'], '>', $lastValue)->orderBy($cursor['col']);
                })
                ->limit($batchSize)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $this->insertBatch($table, $rows, $definition['columns']);
            $inserted += $rows->count();

            $last = $rows->last();
            $lastValue = (int) $last->{$cursor['key']};
            if ($cursor['empresa_col'] !== null) {
                $lastEmpresa = (int) ($last->empresaCodigo ?? $lastEmpresa);
            }
        } while ($rows->count() >= $batchSize);

        $control = ClickHouseSyncControl::query()->firstOrCreate(['table_name' => $table]);
        $control->update([
            'status' => 'ok',
            'last_full_sync_at' => now(),
            'last_completed_at' => now(),
            'last_watermark' => DB::connection('webposto')->table($table)->max('updated_at'),
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);

        return ['received' => $inserted, 'inserted' => $inserted, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
    }

    /**
     * Sincroniza so o que mudou desde o ultimo watermark (delete-then-insert,
     * ja que MergeTree nao tem upsert). Cadencia normal desta rotina.
     *
     * @return array{received: int, inserted: int, updated: int, unchanged: int, skipped: int}
     */
    public function syncIncremental(string $table): array
    {
        $definition = $this->catalog->get($table);
        $batchSize = (int) config('clickhouse.sync_batch_size', 10000);
        $control = ClickHouseSyncControl::query()->firstOrCreate(['table_name' => $table]);

        if (! $this->clickHouse->tableExists($table)) {
            // Nunca rodou full sync - bootstrap automatico antes do incremental fazer sentido.
            return $this->syncFull($table);
        }

        $watermark = $control->last_watermark?->format('Y-m-d H:i:s') ?? '1970-01-01 00:00:00';

        $changedCount = $this->baseQuery($table, $definition)
            ->where($this->watermarkColumn($definition), '>', $watermark)
            ->count();

        if ($changedCount === 0) {
            $control->update(['status' => 'ok', 'last_completed_at' => now(), 'consecutive_failures' => 0, 'last_error' => null]);

            return ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        }

        $pkCol = array_key_exists('codigo', $definition['columns']) ? 'codigo' : 'id';
        $pks = $this->baseQuery($table, $definition)
            ->where($this->watermarkColumn($definition), '>', $watermark)
            ->pluck($pkCol)
            ->unique()
            ->values();

        $database = config('clickhouse.database');
        foreach ($pks->chunk(1000) as $chunk) {
            $list = $chunk->map(fn ($value) => is_numeric($value) ? $value : "'".addslashes((string) $value)."'")->implode(',');
            $this->clickHouse->execute("ALTER TABLE {$database}.{$table} DELETE WHERE {$pkCol} IN ({$list})");
            $this->waitForMutation($table);
        }

        $processed = 0;
        $offset = 0;
        do {
            $rows = $this->baseQuery($table, $definition)
                ->where($this->watermarkColumn($definition), '>', $watermark)
                ->orderBy('id')
                ->limit($batchSize)
                ->offset($offset)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $this->insertBatch($table, $rows, $definition['columns']);
            $processed += $rows->count();
            $offset += $batchSize;
        } while ($rows->count() >= $batchSize);

        $newWatermark = DB::connection('webposto')->table($table)->where('updated_at', '>', $watermark)->max('updated_at');

        $control->update([
            'status' => 'ok',
            'last_watermark' => $newWatermark ?? $watermark,
            'last_completed_at' => now(),
            'consecutive_failures' => 0,
            'last_error' => null,
        ]);

        return ['received' => $processed, 'inserted' => 0, 'updated' => $processed, 'unchanged' => 0, 'skipped' => 0];
    }

    private function watermarkColumn(array $definition): string
    {
        $prefix = $definition['parent_join'] !== null ? $definition['parent_join']['child_alias'].'.' : '';

        return $prefix.'updated_at';
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function createTable(string $table, array $definition): void
    {
        $columnsSql = [];
        foreach ($definition['columns'] as $name => $type) {
            $columnsSql[] = "`{$name}` {$type}";
        }
        $columnsStr = implode(', ', $columnsSql);
        $partitionBy = $definition['partition_by'] ? "PARTITION BY {$definition['partition_by']}" : '';
        $database = config('clickhouse.database');
        $sql = "CREATE TABLE IF NOT EXISTS {$database}.{$table} ({$columnsStr}) ENGINE = MergeTree() ORDER BY {$definition['order_by']} {$partitionBy}";
        $this->clickHouse->execute($sql);
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function baseQuery(string $table, array $definition): Builder
    {
        $join = $definition['parent_join'];
        $columns = array_keys($definition['columns']);

        if ($join === null) {
            return DB::connection('webposto')->table($table)->select($columns);
        }

        $childAlias = $join['child_alias'];
        $parentAlias = $join['parent_alias'];
        [$onChild, $onEmpresa] = $join['on'];

        $select = array_map(fn (string $column) => $column === $join['backfill_column']
            ? DB::raw("COALESCE({$join['backfill_source']}, 0) as `{$column}`")
            : "{$childAlias}.{$column}", $columns);

        return DB::connection('webposto')->table("{$table} as {$childAlias}")
            ->leftJoin("{$join['parent_table']} as {$parentAlias}", function ($joinClause) use ($childAlias, $parentAlias, $onChild, $onEmpresa): void {
                $joinClause->on("{$parentAlias}.{$onChild}", '=', "{$childAlias}.{$onChild}")
                    ->on("{$parentAlias}.{$onEmpresa}", '=', "{$childAlias}.{$onEmpresa}");
            })
            ->select($select);
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $rows
     * @param array<string, string> $columns
     */
    private function insertBatch(string $table, $rows, array $columns): void
    {
        $normalized = $rows->map(fn ($row) => ClickHouseRowNormalizer::normalize((array) $row, $columns))->all();
        $this->clickHouse->insert($table, $normalized);
    }

    private function waitForMutation(string $table): void
    {
        $database = config('clickhouse.database');
        $deadline = now()->addSeconds(self::MUTATION_POLL_SECONDS);

        try {
            while (now()->lessThan($deadline)) {
                $pending = (int) $this->clickHouse->queryScalar(
                    "SELECT count() FROM system.mutations WHERE database = '{$database}' AND table = '{$table}' AND NOT is_done"
                );
                if ($pending === 0) {
                    return;
                }
                sleep(1);
            }
        } catch (\Throwable) {
            sleep(3);
        }
    }
}
