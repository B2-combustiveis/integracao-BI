<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** @var array<int, string> */
    private const PRODUCT_RESOURCES = [
        'produto_grupos',
        'produto_subgrupos',
        'produtos',
        'produto_empresas',
    ];

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
            ...self::PRODUCT_RESOURCES,
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
            fn (string $resource): bool => ! in_array($resource, self::PRODUCT_RESOURCES, true),
        ));

        DB::table('integration_services')->where('id', $service->id)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
};
