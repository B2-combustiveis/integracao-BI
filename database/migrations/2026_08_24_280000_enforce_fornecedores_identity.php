<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        if (! Schema::connection('webposto')->hasColumn('fornecedores', 'empresaCodigo')) {
            Schema::connection('webposto')->table('fornecedores', function (Blueprint $table): void {
                $table->unsignedBigInteger('empresaCodigo')->nullable()->after('updated_at');
            });
        }
        $db = DB::connection('webposto');
        $db->table('fornecedores')->whereNull('empresaCodigo')->update(['empresaCodigo' => 4604]);
        $db->statement('ALTER TABLE fornecedores MODIFY empresaCodigo BIGINT UNSIGNED NOT NULL, MODIFY fornecedorCodigo BIGINT NOT NULL, ADD UNIQUE fornecedores_empresa_codigo_unique (empresaCodigo, fornecedorCodigo)');
    }

    public function down(): void
    {
        $db = DB::connection('webposto');
        $db->statement('ALTER TABLE fornecedores DROP INDEX fornecedores_empresa_codigo_unique, MODIFY fornecedorCodigo BIGINT NULL');
        Schema::connection('webposto')->table('fornecedores', fn (Blueprint $table) => $table->dropColumn('empresaCodigo'));
    }
};
