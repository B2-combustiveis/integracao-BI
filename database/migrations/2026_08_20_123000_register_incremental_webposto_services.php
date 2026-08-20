<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        DB::table('integration_services')->upsert([
            [
                'name' => 'Novos dados WebPosto',
                'slug' => 'webposto-novos-dados',
                'category' => 'cadastros',
                'resource' => 'webposto-new-records',
                'empresa_codigo' => 4604,
                'frequency_minutes' => 1,
                'lookback_days' => 1,
                'active' => false,
                'settings' => json_encode(['mode' => 'cursor']),
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'Revisão de alterações WebPosto',
                'slug' => 'webposto-revisao-alteracoes',
                'category' => 'cadastros',
                'resource' => 'webposto-database-changes',
                'empresa_codigo' => 4604,
                'frequency_minutes' => 60,
                'lookback_days' => 2,
                'active' => false,
                'settings' => json_encode(['mode' => 'reconcile', 'grupo_meta_codigo' => 482]),
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['slug', 'empresa_codigo'], [
            'name', 'category', 'resource', 'frequency_minutes', 'lookback_days',
            'active', 'settings', 'next_run_at', 'updated_at',
        ]);
    }

    public function down(): void
    {
        DB::table('integration_services')->whereIn('slug', [
            'webposto-novos-dados', 'webposto-revisao-alteracoes',
        ])->where('empresa_codigo', 4604)->delete();
    }
};
