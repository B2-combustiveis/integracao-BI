<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('integration_service_run_changes', function (Blueprint $table): void {
   $table->id();
   $table->unsignedBigInteger('integration_service_run_id');
   $table->foreign('integration_service_run_id', 'service_run_changes_run_fk')->references('id')->on('integration_service_runs')->cascadeOnDelete();
   $table->string('resource', 100);
   $table->string('table_name', 100);
   $table->string('action', 20);
   $table->json('natural_key');
   $table->char('natural_key_hash', 64);
   $table->timestamp('source_updated_at')->nullable();
   $table->json('payload');
   $table->timestamp('detected_at');
   $table->timestamps();
   $table->unique(['integration_service_run_id', 'table_name', 'natural_key_hash'], 'service_run_change_record_unique');
   $table->index(['integration_service_run_id', 'action'], 'service_run_changes_action_idx');
  });
 }
 public function down(): void { Schema::dropIfExists('integration_service_run_changes'); }
};
