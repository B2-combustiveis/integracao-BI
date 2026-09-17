<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webposto_initial_sync_runs', function (Blueprint $table): void {
            $table->uuid('batch_key')->nullable()->after('empresa_codigo')->index();
        });
    }

    public function down(): void
    {
        Schema::table('webposto_initial_sync_runs', function (Blueprint $table): void {
            $table->dropColumn('batch_key');
        });
    }
};
