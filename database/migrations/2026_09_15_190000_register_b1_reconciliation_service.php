<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('integration_services')->where('resource', 'webposto-b1-reconciliation')->exists()) {
            return;
        }

        $template = DB::table('integration_services')
            ->where('resource', 'webposto-b2-reconciliation')
            ->first();
        if ($template === null) {
            throw new RuntimeException('A Reconciliação B2 é necessária como modelo para criar a B1.');
        }

        $settings = json_decode($template->settings ?: '[]', true) ?: [];
        $settings['scope'] = 'b1';
        $settings['bases'] = ['b1'];
        $settings['daily_at'] = '02:00';
        $settings['schedule_timezone'] = 'America/Sao_Paulo';

        DB::table('integration_services')->insert([
            'name' => 'Reconciliação B1',
            'slug' => 'webposto-reconciliacao-b1',
            'category' => $template->category,
            'resource' => 'webposto-b1-reconciliation',
            'empresa_codigo' => 0,
            'frequency_minutes' => 1440,
            'lookback_days' => $template->lookback_days,
            'active' => false,
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'next_run_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('integration_services')->where('resource', 'webposto-b1-reconciliation')->delete();
    }
};
