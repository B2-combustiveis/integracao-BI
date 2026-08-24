<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('integration_services')->where('resource', 'titulos-receber')->delete();

            $now = now();
            DB::table('integration_services')->upsert([[
                'name' => 'Atualizações WebPosto por data',
                'slug' => 'webposto-atualizacoes-por-data',
                'category' => 'cadastros',
                'resource' => 'webposto-modified-records',
                'empresa_codigo' => 4604,
                'frequency_minutes' => 60,
                'lookback_days' => 1,
                'active' => false,
                'settings' => json_encode([
                    'strategy' => 'modified_at',
                    'resources' => [
                        'caixas',
                        'caixas-apresentados',
                        'clientes',
                        'fornecedores',
                        'lmcs',
                        'produto-empresas',
                        'titulos-pagar',
                        'titulos-receber',
                    ],
                ]),
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['slug', 'empresa_codigo'], [
                'name',
                'category',
                'resource',
                'frequency_minutes',
                'lookback_days',
                'active',
                'settings',
                'next_run_at',
                'updated_at',
            ]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('integration_services')
                ->where('slug', 'webposto-atualizacoes-por-data')
                ->where('empresa_codigo', 4604)
                ->delete();

            $now = now();
            DB::table('integration_services')->upsert([[
                'name' => 'Títulos a receber',
                'slug' => 'financeiro-titulos-receber',
                'category' => 'financeiro',
                'resource' => 'titulos-receber',
                'empresa_codigo' => 4604,
                'frequency_minutes' => 5,
                'lookback_days' => 2,
                'active' => false,
                'settings' => null,
                'next_run_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]], ['slug', 'empresa_codigo'], [
                'name',
                'category',
                'resource',
                'frequency_minutes',
                'lookback_days',
                'active',
                'settings',
                'next_run_at',
                'updated_at',
            ]);
        });
    }
};
