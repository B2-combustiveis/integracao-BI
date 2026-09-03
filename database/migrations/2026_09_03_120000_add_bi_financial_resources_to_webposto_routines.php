<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const MANAGED = [
        'vales_funcionario', 'contas_bancarias', 'movimentos_conta',
        'administradoras', 'centros_custo', 'cartoes',
    ];

    public function up(): void
    {
        DB::table('integration_services')
            ->whereIn('resource', ['webposto-new-records', 'webposto-chimba-reconciliation'])
            ->get()
            ->each(function (object $service): void {
                $settings = json_decode($service->settings ?? '{}', true) ?: [];
                $resources = array_values(array_diff((array) ($settings['resources'] ?? []), self::MANAGED));

                $this->insertAfter($resources, 'funcionarios', ['vales_funcionario']);
                $this->insertAfter($resources, 'titulos_receber', ['contas_bancarias', 'movimentos_conta']);
                $this->insertBefore($resources, 'vendas', ['administradoras', 'centros_custo']);
                $this->insertAfter($resources, 'vendas', ['cartoes']);

                $settings['resources'] = $resources;
                DB::table('integration_services')->where('id', $service->id)->update([
                    'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'active' => false,
                    'next_run_at' => null,
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        DB::table('integration_services')
            ->whereIn('resource', ['webposto-new-records', 'webposto-chimba-reconciliation'])
            ->get()
            ->each(function (object $service): void {
                $settings = json_decode($service->settings ?? '{}', true) ?: [];
                $settings['resources'] = array_values(array_diff(
                    (array) ($settings['resources'] ?? []),
                    ['vales_funcionario', 'movimentos_conta', 'centros_custo', 'cartoes'],
                ));
                DB::table('integration_services')->where('id', $service->id)->update([
                    'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'updated_at' => now(),
                ]);
            });
    }

    /** @param array<int, string> $resources @param array<int, string> $items */
    private function insertAfter(array &$resources, string $anchor, array $items): void
    {
        $position = array_search($anchor, $resources, true);
        array_splice($resources, $position === false ? count($resources) : $position + 1, 0, $items);
    }

    /** @param array<int, string> $resources @param array<int, string> $items */
    private function insertBefore(array &$resources, string $anchor, array $items): void
    {
        $position = array_search($anchor, $resources, true);
        array_splice($resources, $position === false ? count($resources) : $position, 0, $items);
    }
};
