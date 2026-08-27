<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->setReconciliationChain(['tanques', 'bombas', 'bicos']);
    }

    public function down(): void
    {
        $this->setReconciliationChain(['tanques', 'bicos']);
    }

    /** @param array<int, string> $chain */
    private function setReconciliationChain(array $chain): void
    {
        DB::table('integration_services')
            ->where('resource', 'webposto-full-reconciliation')
            ->get()
            ->each(function (object $service) use ($chain): void {
                $settings = json_decode($service->settings ?? '{}', true) ?: [];
                $remaining = array_values(array_filter(
                    $settings['resources'] ?? [],
                    fn (string $resource): bool => ! in_array(
                        $resource,
                        ['tanques', 'bombas', 'bicos'],
                        true,
                    ),
                ));
                $settings['resources'] = [...$chain, ...$remaining];

                DB::table('integration_services')->where('id', $service->id)->update([
                    'settings' => json_encode($settings),
                    'updated_at' => now(),
                ]);
            });
    }
};
