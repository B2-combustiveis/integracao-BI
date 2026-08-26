<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('integration_services')
                ->where(fn ($query) => $query->where('category', 'financeiro')
                    ->orWhere('resource', 'titulos-receber'))
                ->delete();

            DB::table('integration_services')->where('resource', 'webposto-modified-records')
                ->get()->each(function ($service): void {
                    $settings = json_decode((string) $service->settings, true);
                    if (! is_array($settings)) return;
                    $settings['resources'] = array_values(array_diff(
                        $settings['resources'] ?? [],
                        ['titulos-pagar', 'titulos-receber'],
                    ));
                    DB::table('integration_services')->where('id', $service->id)
                        ->update(['settings' => json_encode($settings), 'updated_at' => now()]);
                });
        });
    }

    public function down(): void
    {
        // Funcionalidades financeiras removidas intencionalmente.
    }
};
