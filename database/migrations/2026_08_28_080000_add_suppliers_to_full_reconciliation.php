<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $service = DB::table('integration_services')
            ->where('resource', 'webposto-full-reconciliation')
            ->first();

        if ($service === null) {
            return;
        }

        $settings = json_decode($service->settings ?? '{}', true) ?: [];
        $settings['resources'] = array_values(array_unique([
            ...(array) ($settings['resources'] ?? []),
            'fornecedores',
        ]));

        DB::table('integration_services')->where('id', $service->id)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'active' => false,
            'next_run_at' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $service = DB::table('integration_services')
            ->where('resource', 'webposto-full-reconciliation')
            ->first();

        if ($service === null) {
            return;
        }

        $settings = json_decode($service->settings ?? '{}', true) ?: [];
        $settings['resources'] = array_values(array_filter(
            (array) ($settings['resources'] ?? []),
            fn (string $resource): bool => $resource !== 'fornecedores',
        ));

        DB::table('integration_services')->where('id', $service->id)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
};
