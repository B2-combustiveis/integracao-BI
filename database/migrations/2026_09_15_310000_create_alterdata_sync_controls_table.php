<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alterdata_sync_controls', function (Blueprint $table): void {
            $table->id();
            $table->string('resource', 100)->unique();
            $table->timestamp('last_watermark', 6)->nullable();
            $table->timestamp('last_full_sync_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alterdata_sync_controls');
    }
};
