<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->removeResource('webposto-new-records', 'tanques');
        $this->appendResource('webposto-full-reconciliation', 'tanques');
    }

    public function down(): void
    {
        $this->removeResource('webposto-full-reconciliation', 'tanques');
        $this->appendResource('webposto-new-records', 'tanques', 'produto_lmc_lmp');
    }

    private function removeResource(string $serviceResource, string $resource): void
    {
        DB::table('integration_services')->where('resource', $serviceResource)->get()
            ->each(function (object $service) use ($resource): void {
                $settings = json_decode($service->settings ?? '{}', true) ?: [];
                $settings['resources'] = array_values(array_filter(
                    $settings['resources'] ?? [],
                    fn (string $configured): bool => $configured !== $resource,
                ));
                DB::table('integration_services')->where('id', $service->id)
                    ->update(['settings' => json_encode($settings), 'updated_at' => now()]);
            });
    }

    private function appendResource(
        string $serviceResource,
        string $resource,
        ?string $after = null,
    ): void {
        DB::table('integration_services')->where('resource', $serviceResource)->get()
            ->each(function (object $service) use ($resource, $after): void {
                $settings = json_decode($service->settings ?? '{}', true) ?: [];
                $resources = array_values(array_filter(
                    $settings['resources'] ?? [],
                    fn (string $configured): bool => $configured !== $resource,
                ));
                $position = $after === null ? count($resources) : array_search($after, $resources, true);
                $position = $position === false ? count($resources) : $position + 1;
                array_splice($resources, $position, 0, [$resource]);
                $settings['resources'] = $resources;
                DB::table('integration_services')->where('id', $service->id)
                    ->update(['settings' => json_encode($settings), 'updated_at' => now()]);
            });
    }
};