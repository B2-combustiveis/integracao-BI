<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('webposto_sync_controls')
                ->where('endpoint', 'like', '%:full-reconcile')
                ->delete();

            DB::table('integration_services')
                ->where('resource', 'webposto-full-reconciliation')
                ->delete();
        });
    }

    public function down(): void
    {
        // A rotina antiga foi removida de forma intencional e será redesenhada.
    }
};
