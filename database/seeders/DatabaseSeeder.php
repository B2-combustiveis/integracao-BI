<?php

namespace Database\Seeders;

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
        $token = config('integration.api_token');

        if (is_string($token) && $token !== '') {
            DB::table('api_tokens')->updateOrInsert(
                ['nome' => 'integracao_webposto'],
                [
                    'token' => $token,
                    'ativo' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
}
