<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('integration_services')->where('resource', 'webposto-new-records')->get()
            ->each(function (object $service): void {
                $settings = json_decode($service->settings ?? '{}', true) ?: [];
                $chain = ['caixas', 'caixas_apresentados'];
                $settings['resources'] = [
                    ...array_values(array_diff($settings['resources'] ?? [], $chain)),
                    ...$chain,
                ];
                DB::table('integration_services')->where('id', $service->id)
                    ->update(['settings' => json_encode($settings), 'updated_at' => now()]);
            });
    }

    public function down(): void
    {
        DB::table('integration_services')->where('resource', 'webposto-new-records')->get()
            ->each(function (object $service): void {
                $settings = json_decode($service->settings ?? '{}', true) ?: [];
                $settings['resources'] = array_values(array_diff(
                    $settings['resources'] ?? [],
                    ['caixas', 'caixas_apresentados'],
                ));
                DB::table('integration_services')->where('id', $service->id)
                    ->update(['settings' => json_encode($settings), 'updated_at' => now()]);
            });
    }
};
