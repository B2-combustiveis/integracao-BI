<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('integration_services')->where('resource', 'webposto-new-records')->get()
            ->each(function (object $service): void {
                $settings = json_decode($service->settings ?? '{}', true) ?: [];
                $current = $settings['resources'] ?? [];
                $chain = ['produto_lmc_lmp', 'tanques', 'bombas', 'bicos'];
                $without = array_values(array_diff($current, $chain));
                $productCompanyIndex = array_search('produto_empresas', $without, true);
                $position = $productCompanyIndex === false ? 0 : $productCompanyIndex + 1;
                array_splice($without, $position, 0, $chain);
                $settings['resources'] = $without;
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
                    ['produto_lmc_lmp', 'tanques', 'bombas', 'bicos'],
                ));
                DB::table('integration_services')->where('id', $service->id)
                    ->update(['settings' => json_encode($settings), 'updated_at' => now()]);
            });
    }
};
