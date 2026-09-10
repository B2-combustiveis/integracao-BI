<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->schedule('webposto-chimba-reconciliation', '00:00');
        $this->schedule('webposto-b2-reconciliation', '01:00');
    }

    public function down(): void
    {
        $this->schedule('webposto-chimba-reconciliation', '15:00');
        $this->schedule('webposto-b2-reconciliation', '16:00');
    }

    private function schedule(string $resource, string $dailyAt): void
    {
        $service = DB::table('integration_services')->where('resource', $resource)->first();
        if ($service === null) {
            return;
        }

        $settings = json_decode($service->settings ?: '[]', true) ?: [];
        $settings['daily_at'] = $dailyAt;
        $settings['schedule_timezone'] = 'America/Sao_Paulo';
        $localNow = Carbon::now('America/Sao_Paulo');
        $next = Carbon::createFromFormat('Y-m-d H:i', $localNow->format('Y-m-d').' '.$dailyAt, 'America/Sao_Paulo');
        if ($next->lessThanOrEqualTo($localNow)) {
            $next->addDay();
        }

        DB::table('integration_services')->where('id', $service->id)->update([
            'active' => true,
            'frequency_minutes' => 1440,
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'next_run_at' => $next->utc(),
            'updated_at' => now(),
        ]);
    }
};
