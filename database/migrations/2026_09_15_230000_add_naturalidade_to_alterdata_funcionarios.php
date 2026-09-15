<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'alterdata';

    public function up(): void
    {
        Schema::connection($this->connection)->table('funcionarios', function (Blueprint $table): void {
            $table->string('naturalidade_estado_id', 100)->nullable()->after('nacionalidade_pais_nome')->index();
            $table->string('naturalidade_estado_nome', 100)->nullable()->after('naturalidade_estado_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('funcionarios', function (Blueprint $table): void {
            $table->dropColumn(['naturalidade_estado_id', 'naturalidade_estado_nome']);
        });
    }
};
