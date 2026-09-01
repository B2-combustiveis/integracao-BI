<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const REFERENCES = ['formas_pagamento', 'pdvs'];

    public function up(): void
    {
        $service = DB::table('integration_services')->where('resource', 'webposto-new-records')->first();
        if ($service === null) return;

        $settings = json_decode($service->settings ?? '{}', true) ?: [];
        $resources = array_values(array_filter(
            (array) ($settings['resources'] ?? []),
            fn (string $resource): bool => ! in_array($resource, self::REFERENCES, true),
        ));
        $position = array_search('vendas', $resources, true);
        array_splice($resources, $position === false ? count($resources) : $position, 0, self::REFERENCES);
        $settings['resources'] = $resources;

        DB::table('integration_services')->where('id', $service->id)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'active' => false,
            'next_run_at' => null,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        $service = DB::table('integration_services')->where('resource', 'webposto-new-records')->first();
        if ($service === null) return;

        $settings = json_decode($service->settings ?? '{}', true) ?: [];
        $settings['resources'] = array_values(array_filter(
            (array) ($settings['resources'] ?? []),
            fn (string $resource): bool => ! in_array($resource, self::REFERENCES, true),
        ));
        DB::table('integration_services')->where('id', $service->id)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
};
