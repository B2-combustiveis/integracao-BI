<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('integration_services')
            ->where('slug', 'webposto-novos-fornecedores')
            ->update([
                'active' => false,
                'settings' => json_encode([
                    'strategy' => 'ultimo_codigo',
                    'resources' => ['fornecedores', 'vendas', 'venda_itens', 'abastecimentos'],
                ]),
                'next_run_at' => null,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('integration_services')
            ->where('slug', 'webposto-novos-fornecedores')
            ->update([
                'active' => false,
                'settings' => json_encode([
                    'strategy' => 'ultimo_codigo',
                    'resources' => ['fornecedores', 'vendas'],
                ]),
                'next_run_at' => null,
                'updated_at' => now(),
            ]);
    }
};
