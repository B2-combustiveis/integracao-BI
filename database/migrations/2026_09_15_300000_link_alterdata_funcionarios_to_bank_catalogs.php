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
            $table->foreign('tipo_conta_id')
                ->references('alterdata_id')->on('tipos_conta')->nullOnDelete();
            $table->foreign('tipo_chave_pix_id')
                ->references('alterdata_id')->on('tipos_chave_pix')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('funcionarios', function (Blueprint $table): void {
            $table->dropForeign(['tipo_conta_id']);
            $table->dropForeign(['tipo_chave_pix_id']);
        });
    }
};
