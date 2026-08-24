<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
 public function up(): void {
  Schema::create('webposto_reload_runs', function (Blueprint $table): void {
   $table->id(); $table->unsignedBigInteger('empresa_codigo'); $table->string('resource', 80);
   $table->string('status', 20)->default('queued'); $table->json('processed_tables')->nullable();
   $table->timestamp('started_at')->nullable(); $table->timestamp('finished_at')->nullable();
   $table->text('error')->nullable(); $table->timestamps();
   $table->index(['empresa_codigo','resource','status'], 'reload_runs_lookup_index');
  });
 }
 public function down(): void { Schema::dropIfExists('webposto_reload_runs'); }
};
