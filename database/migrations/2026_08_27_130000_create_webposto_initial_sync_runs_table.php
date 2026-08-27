<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webposto_initial_sync_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('empresa_codigo')->index();
            $table->string('status', 20)->default('queued')->index();
            $table->string('current_resource', 80)->nullable();
            $table->unsignedInteger('current_position')->default(0);
            $table->unsignedInteger('total_resources')->default(0);
            $table->json('completed_resources')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['empresa_codigo', 'status'], 'initial_sync_company_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webposto_initial_sync_runs');
    }
};
