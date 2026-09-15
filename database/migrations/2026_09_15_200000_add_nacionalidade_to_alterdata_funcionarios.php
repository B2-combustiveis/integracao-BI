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
            $table->string('nacionalidade_pais_id', 100)->nullable()->after('estado_civil_descricao')->index();
            $table->string('nacionalidade_pais_nome', 150)->nullable()->after('nacionalidade_pais_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('funcionarios', function (Blueprint $table): void {
            $table->dropColumn(['nacionalidade_pais_id', 'nacionalidade_pais_nome']);
        });
    }
};
