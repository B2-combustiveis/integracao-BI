<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webposto_sync_pending_records', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresa_codigo')->index();
            $table->string('resource', 100);
            $table->string('table_name', 100);
            $table->char('natural_key_hash', 64);
            $table->json('natural_key');
            $table->json('payload');
            $table->json('parameters')->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->foreignId('first_seen_run_id')->nullable()->constrained('integration_service_runs')->nullOnDelete();
            $table->foreignId('last_attempt_run_id')->nullable()->constrained('integration_service_runs')->nullOnDelete();
            $table->foreignId('resolved_run_id')->nullable()->constrained('integration_service_runs')->nullOnDelete();
            $table->text('last_error')->nullable();
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['empresa_codigo', 'resource', 'natural_key_hash'], 'webposto_pending_company_resource_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webposto_sync_pending_records');
    }
};
