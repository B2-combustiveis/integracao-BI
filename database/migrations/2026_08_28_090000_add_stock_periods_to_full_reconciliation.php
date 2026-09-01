<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateResources(function (array $resources): array {
            $resources = array_values(array_diff($resources, ['estoque_periodos']));
            $position = array_search('produto_empresas', $resources, true);
            array_splice($resources, $position === false ? count($resources) : $position + 1, 0, ['estoque_periodos']);

            return $resources;
        }, pause: true);
    }

    public function down(): void
    {
        $this->updateResources(
            fn (array $resources): array => array_values(array_diff($resources, ['estoque_periodos'])),
        );
    }

    private function updateResources(callable $callback, bool $pause = false): void
    {
        $service = DB::table('integration_services')
            ->where('resource', 'webposto-full-reconciliation')
            ->first();

        if ($service === null) {
            return;
        }

        $settings = json_decode($service->settings ?? '{}', true) ?: [];
        $settings['resources'] = $callback((array) ($settings['resources'] ?? []));
        $update = [
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ];
        if ($pause) {
            $update['active'] = false;
            $update['next_run_at'] = null;
        }

        DB::table('integration_services')->where('id', $service->id)->update($update);
    }
};
