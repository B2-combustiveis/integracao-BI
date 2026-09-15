<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('integration_services')->updateOrInsert(
            ['slug' => 'alterdata-sync', 'empresa_codigo' => 0],
            [
                'name' => 'Sincronização Alterdata',
                'category' => 'departamento-pessoal',
                'resource' => 'alterdata-sync',
                'frequency_minutes' => 180,
                'lookback_days' => 0,
                'active' => false,
                'settings' => json_encode(['mode' => 'incremental', 'watermark_overlap_minutes' => 5]),
                'next_run_at' => null,
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('integration_services')->where('resource', 'alterdata-sync')->delete();
    }
};
