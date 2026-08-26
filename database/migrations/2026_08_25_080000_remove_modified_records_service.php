<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('integration_services')
            ->where('resource', 'webposto-modified-records')
            ->delete();
    }

    public function down(): void {}
};
