<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateResources(
            fn (array $resources): array => array_values(array_diff($resources, ['cartoes'])),
        );
    }

    public function down(): void
    {
        $this->updateResources(function (array $resources): array {
            if (in_array('cartoes', $resources, true)) {
                return $resources;
            }

            $position = array_search('administradoras', $resources, true);
            array_splice($resources, $position === false ? count($resources) : $position + 1, 0, ['cartoes']);

            return $resources;
        });
    }

    private function updateResources(callable $callback): void
    {
        $service = DB::table('integration_services')
            ->where('resource', 'webposto-new-records')
            ->first();

        if ($service === null) {
            return;
        }

        $settings = json_decode($service->settings ?? '{}', true) ?: [];
        $settings['resources'] = $callback((array) ($settings['resources'] ?? []));

        DB::table('integration_services')->where('id', $service->id)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
};
