<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_service_run_changes', function (Blueprint $table): void {
            $table->json('before_payload')->nullable()->after('payload');
            $table->json('after_payload')->nullable()->after('before_payload');
            $table->json('changed_fields')->nullable()->after('after_payload');
        });
    }

    public function down(): void
    {
        Schema::table('integration_service_run_changes', function (Blueprint $table): void {
            $table->dropColumn(['before_payload', 'after_payload', 'changed_fields']);
        });
    }
};
