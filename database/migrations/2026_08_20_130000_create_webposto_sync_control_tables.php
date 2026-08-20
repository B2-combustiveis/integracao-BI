<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webposto_sync_controls', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresa_codigo')->index();
            $table->string('endpoint', 100);
            $table->char('strategy', 2);
            $table->unsignedBigInteger('last_code')->default(0);
            $table->timestamp('last_change_sync_at')->nullable();
            $table->timestamp('last_full_sync_at')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->string('status', 20)->default('idle')->index();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['empresa_codigo', 'endpoint']);
        });

        Schema::create('webposto_sync_endpoint_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webposto_sync_control_id')->constrained()->cascadeOnDelete();
            $table->foreignId('integration_service_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 20);
            $table->string('status', 20)->index();
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webposto_sync_endpoint_runs');
        Schema::dropIfExists('webposto_sync_controls');
    }
};
