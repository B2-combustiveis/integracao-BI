<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_service_company_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('integration_service_run_id');
            $table->foreign('integration_service_run_id', 'service_company_run_fk')
                ->references('id')->on('integration_service_runs')->cascadeOnDelete();
            $table->unsignedBigInteger('empresa_codigo')->index();
            $table->string('empresa_nome')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->string('status', 30)->default('pending')->index();
            $table->string('current_resource', 100)->nullable();
            $table->unsignedInteger('received')->default(0);
            $table->unsignedInteger('inserted')->default(0);
            $table->unsignedInteger('updated')->default(0);
            $table->unsignedInteger('unchanged')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->json('resource_results')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['integration_service_run_id', 'empresa_codigo'], 'service_company_run_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_service_company_runs');
    }
};
