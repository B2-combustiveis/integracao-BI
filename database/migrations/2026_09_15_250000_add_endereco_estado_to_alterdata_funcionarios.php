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
            $table->string('endereco_estado_id', 100)->nullable()->after('cep')->index();
            $table->string('endereco_estado_nome', 100)->nullable()->after('endereco_estado_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('funcionarios', function (Blueprint $table): void {
            $table->dropColumn(['endereco_estado_id', 'endereco_estado_nome']);
        });
    }
};
