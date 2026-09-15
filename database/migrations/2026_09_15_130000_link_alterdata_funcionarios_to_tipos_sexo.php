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
            $table->foreign('sexo_id')
                ->references('alterdata_id')
                ->on('tipos_sexo')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('funcionarios', function (Blueprint $table): void {
            $table->dropForeign(['sexo_id']);
        });
    }
};
