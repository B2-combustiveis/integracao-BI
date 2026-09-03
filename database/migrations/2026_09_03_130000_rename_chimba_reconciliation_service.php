<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('integration_services')
            ->where('resource', 'webposto-chimba-reconciliation')
            ->update([
                'name' => 'Reconciliação Chimba',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('integration_services')
            ->where('resource', 'webposto-chimba-reconciliation')
            ->update([
                'name' => 'Reconciliação WebPosto',
                'updated_at' => now(),
            ]);
    }
};
