<?php

use App\Models\WebPostoCredential;
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
            $table->dropIndex('webposto_credentials_emp_eligibility_index');
        });

        DB::connection($this->connection)->table('webposto_credentials')
            ->where('implantacao_status', 'pronta')
            ->update(['implantacao_status' => WebPostoCredential::STATUS_SINCRONIZADO]);
        DB::connection($this->connection)->table('webposto_credentials')
            ->where('implantacao_status', '<>', WebPostoCredential::STATUS_SINCRONIZADO)
            ->update(['implantacao_status' => WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO]);

        DB::connection($this->connection)->statement(
            "ALTER TABLE webposto_credentials MODIFY implantacao_status VARCHAR(30) NOT NULL DEFAULT 'aguardando_sincronizacao'"
        );

        Schema::connection($this->connection)->table('webposto_credentials', function (Blueprint $table): void {
            $table->dropColumn('emp_liberado');
            $table->index(['ativo', 'implantacao_status'], 'webposto_credentials_sync_eligibility_index');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('webposto_credentials', function (Blueprint $table): void {
            $table->dropIndex('webposto_credentials_sync_eligibility_index');
            $table->boolean('emp_liberado')->default(false)->after('implantacao_status');
            $table->index(['ativo', 'emp_liberado', 'implantacao_status'], 'webposto_credentials_emp_eligibility_index');
        });
        DB::connection($this->connection)->table('webposto_credentials')
            ->where('implantacao_status', WebPostoCredential::STATUS_SINCRONIZADO)
            ->update(['implantacao_status' => 'pronta', 'emp_liberado' => true]);
    }
};
