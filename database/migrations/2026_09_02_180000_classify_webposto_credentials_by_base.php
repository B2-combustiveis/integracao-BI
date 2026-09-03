<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->table('webposto_credentials', function (Blueprint $table): void {
            $table->string('base', 20)->nullable()->after('token')->index();
        });

        DB::connection($this->connection)->table('webposto_credentials')
            ->where('empresa_codigo', 4604)
            ->update(['base' => 'chimba']);
        DB::connection($this->connection)->table('webposto_credentials')
            ->where('empresa_codigo', '<>', 4604)
            ->update(['base' => 'b1']);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('webposto_credentials', function (Blueprint $table): void {
            $table->dropIndex(['base']);
            $table->dropColumn('base');
        });
    }
};
