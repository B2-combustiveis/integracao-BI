<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webposto_source_deleted_records', function (Blueprint $table): void {
            $table->timestamp('restored_at')->nullable()->after('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('webposto_source_deleted_records', function (Blueprint $table): void {
            $table->dropColumn('restored_at');
        });
    }
};
