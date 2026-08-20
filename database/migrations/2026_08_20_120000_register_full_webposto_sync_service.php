<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('integration_services')->updateOrInsert(
            ['slug' => 'webposto-database-completa', 'empresa_codigo' => 4604],
            [
                'name' => 'Sincronização completa WebPosto',
                'category' => 'cadastros',
                'resource' => 'webposto-database',
                'frequency_minutes' => 360,
                'lookback_days' => 2,
                'active' => false,
                'settings' => json_encode(['grupo_meta_codigo' => 482]),
                'next_run_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('integration_services')
            ->where('slug', 'webposto-database-completa')
            ->where('empresa_codigo', 4604)
            ->delete();
    }
};
