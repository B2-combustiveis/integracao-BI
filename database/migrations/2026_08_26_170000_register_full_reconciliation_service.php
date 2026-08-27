<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('integration_services')->updateOrInsert(
            [
                'slug' => 'webposto-reconciliacao-completa',
                'empresa_codigo' => 4604,
            ],
            [
                'name' => 'Reconciliação completa WebPosto',
                'category' => 'cadastros',
                'resource' => 'webposto-full-reconciliation',
                'frequency_minutes' => 1440,
                'lookback_days' => 1,
                'active' => false,
                'settings' => json_encode([
                    'mode' => 'full_reconcile',
                    'resources' => [],
                ]),
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('integration_services')
            ->where('slug', 'webposto-reconciliacao-completa')
            ->where('empresa_codigo', 4604)
            ->delete();
    }
};