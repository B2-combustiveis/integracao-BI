<?php

namespace Database\Seeders;

use App\Models\IntegrationService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Tokens de acesso são administrados diretamente em api_tokens.
        if (DB::connection('webposto')->table('empresas')->where('empresaCodigo', 4604)->exists()) {
            IntegrationService::query()->firstOrCreate(
                ['slug' => 'financeiro-titulos-receber', 'empresa_codigo' => 4604],
                ['name' => 'Títulos a receber', 'category' => 'financeiro', 'resource' => 'titulos-receber',
                    'frequency_minutes' => 60, 'lookback_days' => 2, 'active' => false],
            );
        }
    }
}
