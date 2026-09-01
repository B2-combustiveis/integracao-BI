<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_service_company_runs', function (Blueprint $table): void {
            $table->unsignedInteger('current_page')->nullable()->after('current_resource');
            $table->unsignedBigInteger('current_cursor')->nullable()->after('current_page');
            $table->timestamp('heartbeat_at')->nullable()->after('current_cursor');
        });
    }

    public function down(): void
    {
        Schema::table('integration_service_company_runs', function (Blueprint $table): void {
            $table->dropColumn([
                'current_page',
                'current_cursor',
                'heartbeat_at',
            ]);
        });
    }
};
