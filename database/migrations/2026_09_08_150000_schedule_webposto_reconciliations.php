<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'webposto-chimba-reconciliation' => '15:00',
            'webposto-b2-reconciliation' => '16:00',
        ] as $resource => $dailyAt) {
            $service = DB::table('integration_services')->where('resource', $resource)->first();
            if ($service === null) {
                continue;
            }

            $settings = json_decode($service->settings ?: '[]', true) ?: [];
            $settings['daily_at'] = $dailyAt;
            $settings['schedule_timezone'] = 'America/Sao_Paulo';
            $localNow = Carbon::now('America/Sao_Paulo');
            $nextRun = Carbon::createFromFormat('Y-m-d H:i', $localNow->format('Y-m-d').' '.$dailyAt, 'America/Sao_Paulo');
            if ($nextRun->lessThanOrEqualTo($localNow)) {
                $nextRun->addDay();
            }

            DB::table('integration_services')->where('id', $service->id)->update([
                'active' => true,
                'frequency_minutes' => 1440,
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'next_run_at' => $nextRun->utc(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        foreach (['webposto-chimba-reconciliation', 'webposto-b2-reconciliation'] as $resource) {
            $service = DB::table('integration_services')->where('resource', $resource)->first();
            if ($service === null) {
                continue;
            }

            $settings = json_decode($service->settings ?: '[]', true) ?: [];
            unset($settings['daily_at'], $settings['schedule_timezone']);
            DB::table('integration_services')->where('id', $service->id)->update([
                'active' => false,
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'next_run_at' => null,
                'updated_at' => now(),
            ]);
        }
    }
};
