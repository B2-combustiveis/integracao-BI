<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webposto_source_absences', function (Blueprint $table): void {
            $table->id();
            $table->string('resource', 100);
            $table->string('table_name', 100);
            $table->unsignedBigInteger('empresa_codigo')->index();
            $table->json('natural_key');
            $table->char('natural_key_hash', 64);
            $table->unsignedTinyInteger('consecutive_absences')->default(1);
            $table->foreignId('first_missing_run_id')->nullable()->constrained('integration_service_runs')->nullOnDelete();
            $table->foreignId('last_checked_run_id')->nullable()->constrained('integration_service_runs')->nullOnDelete();
            $table->timestamp('first_missing_at');
            $table->timestamp('last_missing_at');
            $table->timestamps();
            $table->unique(['resource', 'empresa_codigo', 'natural_key_hash'], 'webposto_source_absence_unique');
        });

        Schema::create('webposto_source_deleted_records', function (Blueprint $table): void {
            $table->id();
            $table->string('resource', 100);
            $table->string('table_name', 100);
            $table->unsignedBigInteger('empresa_codigo')->index();
            $table->json('natural_key');
            $table->char('natural_key_hash', 64);
            $table->json('payload');
            $table->foreignId('confirmed_run_id')->nullable()->constrained('integration_service_runs')->nullOnDelete();
            $table->timestamp('archived_at');
            $table->timestamps();
            $table->unique(['resource', 'empresa_codigo', 'natural_key_hash'], 'webposto_source_deleted_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webposto_source_deleted_records');
        Schema::dropIfExists('webposto_source_absences');
    }
};
