<?php

namespace App\Services\Admin;

use App\Models\IntegrationService;
use App\Models\WebPostoInitialSyncRun;
use App\Models\WebPostoReloadRun;
use App\Services\WebPosto\WebPostoNewRecordsResourceCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdminOverviewService
{
    public function __construct(
        private readonly WebPostoNewRecordsResourceCatalog $newRecordsResourceCatalog,
    ) {
    }

    public function get(?int $empresaCodigo = null): array
    {
        $tables = $this->tables($empresaCodigo);
        $connections = collect(['mysql' => 'Integração', 'webposto' => 'WebPosto', 'bi' => 'BI'])
            ->map(fn (string $label, string $connection): array => $this->connection($connection, $label))->values()->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'connections' => $connections,
            'summary' => [
                'companies' => $this->safeCount('webposto', 'empresas'),
                'credentials' => $this->safeCount('webposto', 'webposto_credentials'),
                'api_tokens' => $this->safeCount('mysql', 'api_tokens'),
                'tables' => count($tables),
            ],
            'credentials' => $this->credentials(),
            'tables' => $tables,
            'reloads' => $this->reloads(),
            'selected_company' => $empresaCodigo,
        ];
    }

    private function connection(string $connection, string $label): array
    {
        $started = hrtime(true);
        try {
            DB::connection($connection)->select('select 1');
            return ['key' => $connection, 'label' => $label, 'status' => 'online', 'database' => DB::connection($connection)->getDatabaseName(), 'latency_ms' => round((hrtime(true) - $started) / 1_000_000, 2)];
        } catch (Throwable $e) {
            return ['key' => $connection, 'label' => $label, 'status' => 'offline', 'database' => config("database.connections.{$connection}.database"), 'latency_ms' => null, 'error' => class_basename($e)];
        }
    }

    private function tables(?int $empresaCodigo = null): array
    {
        try {
            $database = DB::connection('webposto')->getDatabaseName();
            $catalog = $this->newRecordsResourceCatalog->all();
            $newRecordsTables = collect($this->serviceResources('webposto-new-records'))
                ->map(fn (string $resource) => $catalog[$resource]['table'] ?? null)
                ->filter()->unique()->values()->all();
            $reconciliationTables = collect($this->serviceResources('webposto-full-reconciliation'))
                ->map(fn (string $resource) => $catalog[$resource]['table'] ?? null)
                ->filter()->unique()->values()->all();
            $rows = DB::connection('webposto')->table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', $database)->where('TABLE_TYPE', 'BASE TABLE')
                ->orderBy('TABLE_NAME')->get(['TABLE_NAME', 'DATA_LENGTH', 'INDEX_LENGTH']);
            return $rows->map(function (object $row) use ($newRecordsTables, $reconciliationTables, $empresaCodigo): array {
                $table = $row->TABLE_NAME;
                $columns = Schema::connection('webposto')->getColumnListing($table);
                $query = DB::connection('webposto')->table($table);
                if ($empresaCodigo !== null && in_array('empresaCodigo', $columns, true)) {
                    $query->where('empresaCodigo', $empresaCodigo);
                }
                $updated = in_array('updated_at', $columns, true)
                    ? (clone $query)->max('updated_at') : null;
                return ['name' => $table, 'records' => (clone $query)->count(),
                    'columns' => count($columns), 'last_update' => $updated,
                    'size_bytes' => (int) $row->DATA_LENGTH + (int) $row->INDEX_LENGTH,
                    'new_records_sync' => in_array($table, $newRecordsTables, true),
                    'full_reconciliation_sync' => in_array($table, $reconciliationTables, true)];
            })->all();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int, string> */
    private function serviceResources(string $serviceResource): array
    {
        try {
            $service = IntegrationService::query()
                ->where('resource', $serviceResource)
                ->first();

            return $service?->settings['resources'] ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    private function credentials(): array
    {
        try {
            $runs = WebPostoInitialSyncRun::query()->latest('id')->get()
                ->unique('empresa_codigo')
                ->keyBy('empresa_codigo');

            return DB::connection('webposto')->table('webposto_credentials as credentials')
                ->leftJoin('empresas', 'empresas.empresaCodigo', '=', 'credentials.empresa_codigo')
                ->orderBy('credentials.empresa_codigo')
                ->get(['credentials.empresa_codigo', 'credentials.implantacao_status',
                    'empresas.fantasia', 'empresas.razao'])
                ->map(fn (object $item): array => ['empresa_codigo' => $item->empresa_codigo,
                    'empresa_nome' => $item->fantasia ?: ($item->razao ?: "Empresa {$item->empresa_codigo}"),
                    'onboarding_status' => $item->implantacao_status,
                    'initial_sync' => ($run = $runs->get($item->empresa_codigo)) ? [
                        'id' => $run->id,
                        'status' => $run->status,
                        'current_resource' => $run->current_resource,
                        'current_position' => $run->current_position,
                        'total_resources' => $run->total_resources,
                        'completed_resources' => $run->completed_resources ?? [],
                        'started_at' => $run->started_at?->toIso8601String(),
                        'finished_at' => $run->finished_at?->toIso8601String(),
                        'error' => $run->error,
                    ] : null,
                ])->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function reloads(): array
    {
        try {
            return WebPostoReloadRun::query()->latest('id')->get()
                ->unique(fn (WebPostoReloadRun $run): string => $run->empresa_codigo.'-'.$run->resource)
                ->map(fn (WebPostoReloadRun $run): array => [
                    'id' => $run->id, 'empresa_codigo' => $run->empresa_codigo,
                    'resource' => $run->resource, 'status' => $run->status,
                    'processed_tables' => $run->processed_tables,
                    'started_at' => $run->started_at?->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                    'error' => $run->error,
                ])->values()->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function safeCount(string $connection, string $table, array $where = []): int
    {
        try {
            $query = DB::connection($connection)->table($table);
            foreach ($where as $field => $value) $query->where($field, $value);
            return $query->count();
        } catch (Throwable) {
            return 0;
        }
    }
}
