<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('integration_services')->where('resource', 'webposto-modified-records')
                ->update(['active' => false, 'next_run_at' => null, 'updated_at' => now()]);
            DB::table('integration_services')->where('slug', 'webposto-novos-dados')->delete();
            DB::table('integration_services')->upsert([[
                'name' => 'Novos fornecedores WebPosto',
                'slug' => 'webposto-novos-fornecedores',
                'category' => 'cadastros',
                'resource' => 'webposto-new-records',
                'empresa_codigo' => 4604,
                'frequency_minutes' => 5,
                'lookback_days' => 1,
                'active' => true,
                'settings' => json_encode([
                    'strategy' => 'ultimo_codigo',
                    'resources' => ['fornecedores'],
                ]),
                'next_run_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['slug', 'empresa_codigo'], [
                'name', 'category', 'resource', 'frequency_minutes', 'lookback_days',
                'active', 'settings', 'next_run_at', 'updated_at',
            ]);
        });
    }

    public function down(): void
    {
        DB::table('integration_services')->where('slug', 'webposto-novos-fornecedores')->delete();
    }
};
