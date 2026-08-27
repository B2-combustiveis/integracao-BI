<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateResources('webposto-new-records', function (array $resources): array {
            $resources = $this->without($resources, ['tanques']);
            $position = array_search('bombas', $resources, true);
            $position = $position === false ? count($resources) : $position;
            array_splice($resources, $position, 0, ['tanques']);

            return $resources;
        });

        $this->updateResources('webposto-full-reconciliation', function (array $resources): array {
            return ['tanques', 'bicos', ...$this->without($resources, ['tanques', 'bicos'])];
        });
    }

    public function down(): void
    {
        $this->updateResources(
            'webposto-new-records',
            fn (array $resources): array => $this->without($resources, ['tanques']),
        );
        $this->updateResources(
            'webposto-full-reconciliation',
            fn (array $resources): array => $this->without($resources, ['bicos']),
        );
    }

    private function updateResources(string $resource, callable $transform): void
    {
        DB::table('integration_services')->where('resource', $resource)->get()
            ->each(function (object $service) use ($transform): void {
                $settings = json_decode($service->settings ?? '{}', true) ?: [];
                $settings['resources'] = array_values(array_unique($transform(
                    array_values($settings['resources'] ?? []),
                )));
                DB::table('integration_services')->where('id', $service->id)->update([
                    'settings' => json_encode($settings),
                    'updated_at' => now(),
                ]);
            });
    }

    /** @param array<int, string> $resources @param array<int, string> $removed */
    private function without(array $resources, array $removed): array
    {
        return array_values(array_filter(
            $resources,
            fn (string $resource): bool => ! in_array($resource, $removed, true),
        ));
    }
};
