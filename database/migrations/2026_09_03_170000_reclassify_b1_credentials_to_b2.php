<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        DB::connection($this->connection)->table('webposto_credentials')
            ->where('base', 'b1')
            ->update(['base' => 'b2']);
    }

    public function down(): void
    {
        DB::connection($this->connection)->table('webposto_credentials')
            ->where('base', 'b2')
            ->update(['base' => 'b1']);
    }
};
