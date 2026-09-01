<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    /** @var array<string, string> */
    private const TABLES = [
        'formas_pagamento' => 'formaPagamentoCodigo',
        'pdvs' => 'pdvCodigo',
    ];

    public function up(): void
    {
        $defaultCompany = (int) (DB::connection($this->connection)
            ->table('webposto_credentials')->orderBy('empresa_codigo')->value('empresa_codigo') ?? 4604);

        foreach (self::TABLES as $table => $key) {
            if (! Schema::connection($this->connection)->hasColumn($table, 'empresaCodigo')) {
                Schema::connection($this->connection)->table($table, function (Blueprint $blueprint): void {
                    $blueprint->unsignedBigInteger('empresaCodigo')->nullable()->after('id');
                });
            }
            DB::connection($this->connection)->table($table)
                ->whereNull('empresaCodigo')->update(['empresaCodigo' => $defaultCompany]);
            DB::connection($this->connection)->statement(
                "ALTER TABLE `{$table}` MODIFY `empresaCodigo` BIGINT UNSIGNED NOT NULL"
            );
            Schema::connection($this->connection)->table($table, function (Blueprint $blueprint) use ($table, $key): void {
                $blueprint->index(['empresaCodigo', $key], $table.'_company_key_index');
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::TABLES)) as $table) {
            Schema::connection($this->connection)->table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex($table.'_company_key_index');
                $blueprint->dropColumn('empresaCodigo');
            });
        }
    }
};
