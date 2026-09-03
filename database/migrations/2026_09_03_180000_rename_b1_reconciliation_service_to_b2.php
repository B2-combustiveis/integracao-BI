<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $service = DB::table('integration_services')
            ->where('resource', 'webposto-b1-reconciliation')
            ->first();
        if ($service === null) {
            return;
        }

        $settings = json_decode($service->settings ?? '{}', true) ?: [];
        $settings['scope'] = 'b2';
        $settings['bases'] = ['b2'];

        DB::table('integration_services')->where('id', $service->id)->update([
            'name' => 'Reconciliação B2',
            'slug' => 'webposto-reconciliacao-b2',
            'resource' => 'webposto-b2-reconciliation',
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $service = DB::table('integration_services')
            ->where('resource', 'webposto-b2-reconciliation')
            ->first();
        if ($service === null) {
            return;
        }

        $settings = json_decode($service->settings ?? '{}', true) ?: [];
        $settings['scope'] = 'b1';
        $settings['bases'] = ['b1'];

        DB::table('integration_services')->where('id', $service->id)->update([
            'name' => 'Reconciliação B1',
            'slug' => 'webposto-reconciliacao-b1',
            'resource' => 'webposto-b1-reconciliation',
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
};
