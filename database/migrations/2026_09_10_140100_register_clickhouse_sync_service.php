<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('integration_services')->updateOrInsert(
            ['slug' => 'clickhouse-incremental-sync', 'empresa_codigo' => 0],
            [
                'name' => 'Sincronizacao ClickHouse',
                'category' => 'atualizacao',
                'resource' => 'clickhouse-incremental-sync',
                'frequency_minutes' => 15,
                'lookback_days' => 1,
                // Inativo ate a validacao contra bi_staging terminar (ver plano
                // "Migrar a carga do ClickHouse do Back-end-bi para o integracao-BI").
                'active' => false,
                'settings' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'next_run_at' => null,
                'last_error' => null,
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('integration_services')
            ->where('resource', 'clickhouse-incremental-sync')
            ->delete();
    }
};
