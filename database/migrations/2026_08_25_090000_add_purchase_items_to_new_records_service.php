<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $this->updateResources(true);
    }

    public function down(): void
    {
        $this->updateResources(false);
    }

    private function updateResources(bool $includePurchaseItems): void
    {
        DB::table('integration_services')
            ->where('slug', 'webposto-novos-fornecedores')
            ->get()
            ->each(function (object $service) use ($includePurchaseItems): void {
                $settings = json_decode($service->settings, true) ?: [];
                $resources = array_values(array_filter(
                    $settings['resources'] ?? [],
                    fn (string $resource): bool => $resource !== 'compra_itens',
                ));

                if ($includePurchaseItems) {
                    $purchaseIndex = array_search('compras', $resources, true);
                    array_splice($resources, $purchaseIndex === false ? count($resources) : $purchaseIndex + 1, 0, ['compra_itens']);
                }

                DB::table('integration_services')->where('id', $service->id)->update([
                    'active' => false,
                    'settings' => json_encode([...$settings, 'resources' => $resources]),
                    'next_run_at' => null,
                    'updated_at' => now(),
                ]);
            });
    }
};
