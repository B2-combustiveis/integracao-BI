<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clickhouse_sync_controls', function (Blueprint $table): void {
            $table->id();
            $table->string('table_name')->unique();
            $table->timestamp('last_watermark')->nullable();
            $table->timestamp('last_full_sync_at')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->string('status', 30)->default('idle');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clickhouse_sync_controls');
    }
};
