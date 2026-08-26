<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('webposto')->table('planos_conta_gerencial', function (Blueprint $table): void {
            $table->unsignedBigInteger('empresaCodigo')->nullable()->after('id');
        });
        DB::connection('webposto')->table('planos_conta_gerencial')->whereNull('empresaCodigo')->update(['empresaCodigo' => 4604]);
        DB::connection('webposto')->statement('ALTER TABLE planos_conta_gerencial MODIFY empresaCodigo BIGINT UNSIGNED NOT NULL, MODIFY planoContaCodigo BIGINT NOT NULL, ADD UNIQUE planos_gerencial_empresa_codigo_unique (empresaCodigo, planoContaCodigo)');
        DB::connection('webposto')->statement('ALTER TABLE planos_conta_contabil MODIFY empresaCodigo BIGINT NOT NULL, MODIFY planoContaContabilCodigo BIGINT NOT NULL, ADD UNIQUE planos_contabil_empresa_codigo_unique (empresaCodigo, planoContaContabilCodigo)');
    }

    public function down(): void
    {
        DB::connection('webposto')->statement('ALTER TABLE planos_conta_contabil DROP INDEX planos_contabil_empresa_codigo_unique, MODIFY empresaCodigo BIGINT NULL, MODIFY planoContaContabilCodigo BIGINT NULL');
        DB::connection('webposto')->statement('ALTER TABLE planos_conta_gerencial DROP INDEX planos_gerencial_empresa_codigo_unique, MODIFY planoContaCodigo BIGINT NULL');
        Schema::connection('webposto')->table('planos_conta_gerencial', fn (Blueprint $table) => $table->dropColumn('empresaCodigo'));
    }
};
