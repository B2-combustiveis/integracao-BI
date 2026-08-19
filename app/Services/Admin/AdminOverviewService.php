<?php

namespace App\Services\Admin;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AdminOverviewService
{
    public function get(): array
    {
        $tables = $this->tables();
        $connections = collect(['mysql' => 'Integração', 'webposto' => 'WebPosto', 'bi' => 'BI'])
            ->map(fn (string $label, string $connection): array => $this->connection($connection, $label))->values()->all();

        return [
            'generated_at' => now()->toIso8601String(),
            'connections' => $connections,
            'summary' => [
                'companies' => $this->safeCount('webposto', 'empresas'),
                'credentials' => $this->safeCount('webposto', 'webposto_credentials'),
                'active_credentials' => $this->safeCount('webposto', 'webposto_credentials', ['ativo' => 1]),
                'api_tokens' => $this->safeCount('mysql', 'api_tokens'),
                'tables' => count($tables),
            ],
            'credentials' => $this->credentials(),
            'tables' => $tables,
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

    private function tables(): array
    {
        try {
            $database = DB::connection('webposto')->getDatabaseName();
            $rows = DB::connection('webposto')->table('information_schema.TABLES')
                ->where('TABLE_SCHEMA', $database)->where('TABLE_TYPE', 'BASE TABLE')
                ->orderBy('TABLE_NAME')->get(['TABLE_NAME', 'DATA_LENGTH', 'INDEX_LENGTH']);
            return $rows->map(function (object $row): array {
                $table = $row->TABLE_NAME;
                $columns = Schema::connection('webposto')->getColumnListing($table);
                $updated = in_array('updated_at', $columns, true)
                    ? DB::connection('webposto')->table($table)->max('updated_at') : null;
                return ['name' => $table, 'records' => DB::connection('webposto')->table($table)->count(),
                    'columns' => count($columns), 'last_update' => $updated,
                    'size_bytes' => (int) $row->DATA_LENGTH + (int) $row->INDEX_LENGTH];
            })->all();
        } catch (Throwable) {
            return [];
        }
    }

    private function credentials(): array
    {
        try {
            return DB::connection('webposto')->table('webposto_credentials as credentials')
                ->leftJoin('empresas', 'empresas.empresaCodigo', '=', 'credentials.empresa_codigo')
                ->orderBy('credentials.empresa_codigo')
                ->get(['credentials.empresa_codigo', 'credentials.base_url', 'credentials.ativo',
                    'credentials.ultimo_uso_em', 'empresas.fantasia', 'empresas.razao'])
                ->map(fn (object $item): array => ['empresa_codigo' => $item->empresa_codigo,
                    'empresa_nome' => $item->fantasia ?: ($item->razao ?: "Empresa {$item->empresa_codigo}"),
                    'base_url' => $item->base_url,
                    'active' => (bool) $item->ativo, 'last_used' => $item->ultimo_uso_em])->all();
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
