<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_services', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('category', 50)->index();
            $table->string('resource', 100);
            $table->unsignedBigInteger('empresa_codigo')->index();
            $table->unsignedInteger('frequency_minutes')->default(60);
            $table->unsignedSmallInteger('lookback_days')->default(2);
            $table->boolean('active')->default(false)->index();
            $table->json('settings')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_completed_at')->nullable();
            $table->timestamp('next_run_at')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['slug', 'empresa_codigo']);
        });

        Schema::create('integration_service_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_service_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->index();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_service_runs');
        Schema::dropIfExists('integration_services');
    }
};
