<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->table('cartoes', function (Blueprint $table): void {
            $table->bigInteger('vendaCodigo')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('cartoes', function (Blueprint $table): void {
            $table->bigInteger('vendaCodigo')->nullable(false)->change();
        });
    }
};
