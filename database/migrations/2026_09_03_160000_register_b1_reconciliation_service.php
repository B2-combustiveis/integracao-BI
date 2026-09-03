<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $chimba = DB::table('integration_services')
            ->where('resource', 'webposto-chimba-reconciliation')
            ->first();
        if ($chimba === null) {
            return;
        }
        $settings = json_decode($chimba->settings ?? '{}', true) ?: [];
        unset($settings['worker_blocks'], $settings['empresa_codigos']);
        $settings['scope'] = 'b1';
        $settings['bases'] = ['b1'];
        $now = now();

        DB::table('integration_services')->updateOrInsert(
            ['slug' => 'webposto-reconciliacao-b1', 'empresa_codigo' => 0],
            [
                'name' => 'Reconciliação B1',
                'category' => 'atualizacao',
                'resource' => 'webposto-b1-reconciliation',
                'frequency_minutes' => 1440,
                'lookback_days' => 1,
                'active' => false,
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
            ->where('resource', 'webposto-b1-reconciliation')
            ->delete();
    }
};
