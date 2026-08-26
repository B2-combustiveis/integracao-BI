<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('integration_services')->where('resource', 'webposto-new-records')->get()
            ->each(function (object $service): void {
                $settings = json_decode($service->settings ?? '{}', true) ?: [];
                $resources = array_values(array_unique([
                    ...($settings['resources'] ?? []),
                    'administradoras',
                    'cartoes',
                ]));
                DB::table('integration_services')->where('id', $service->id)
                    ->update(['settings' => json_encode([...$settings, 'resources' => $resources]), 'updated_at' => now()]);
            });
    }

    public function down(): void
    {
        DB::table('integration_services')->where('resource', 'webposto-new-records')->get()
            ->each(function (object $service): void {
                $settings = json_decode($service->settings ?? '{}', true) ?: [];
                $settings['resources'] = array_values(array_diff($settings['resources'] ?? [], ['administradoras', 'cartoes']));
                DB::table('integration_services')->where('id', $service->id)
                    ->update(['settings' => json_encode($settings), 'updated_at' => now()]);
            });
    }
};
