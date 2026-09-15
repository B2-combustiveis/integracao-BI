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
            $table->string('estado_civil_id', 100)->nullable()->after('sexo_descricao')->index();
            $table->string('estado_civil_descricao', 100)->nullable()->after('estado_civil_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('funcionarios', function (Blueprint $table): void {
            $table->dropColumn(['estado_civil_id', 'estado_civil_descricao']);
        });
    }
};
