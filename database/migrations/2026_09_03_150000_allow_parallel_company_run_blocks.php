<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('integration_service_company_runs', function (Blueprint $table): void {
            $table->index('integration_service_run_id', 'company_runs_service_run_fk_index');
        });
        Schema::table('integration_service_company_runs', function (Blueprint $table): void {
            $table->dropUnique('service_company_run_unique');
            $table->string('block_key', 40)->default('company')->after('empresa_nome');
            $table->unique(
                ['integration_service_run_id', 'empresa_codigo', 'block_key'],
                'service_company_block_run_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('integration_service_company_runs', function (Blueprint $table): void {
            $table->dropUnique('service_company_block_run_unique');
            $table->dropColumn('block_key');
            $table->unique(
                ['integration_service_run_id', 'empresa_codigo'],
                'service_company_run_unique',
            );
            $table->dropIndex('company_runs_service_run_fk_index');
        });
    }
};
