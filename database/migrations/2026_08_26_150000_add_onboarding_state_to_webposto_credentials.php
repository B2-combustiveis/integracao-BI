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
            $table->string('implantacao_status', 30)
                ->default(WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO)
                ->after('ativo');
            $table->boolean('emp_liberado')->default(false)->after('implantacao_status');
            $table->timestamp('carga_inicial_iniciada_em')->nullable()->after('emp_liberado');
            $table->timestamp('carga_inicial_concluida_em')->nullable()->after('carga_inicial_iniciada_em');
            $table->text('carga_inicial_erro')->nullable()->after('carga_inicial_concluida_em');
            $table->index(
                ['ativo', 'emp_liberado', 'implantacao_status'],
                'webposto_credentials_emp_eligibility_index',
            );
        });

        // Credenciais já existentes representam empresas cuja carga inicial foi validada.
        DB::connection($this->connection)->table('webposto_credentials')->update([
            'implantacao_status' => WebPostoCredential::STATUS_SINCRONIZADO,
            'emp_liberado' => true,
            'carga_inicial_concluida_em' => now(),
            'carga_inicial_erro' => null,
        ]);
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('webposto_credentials', function (Blueprint $table): void {
            $table->dropIndex('webposto_credentials_emp_eligibility_index');
            $table->dropColumn([
                'implantacao_status',
                'emp_liberado',
                'carga_inicial_iniciada_em',
                'carga_inicial_concluida_em',
                'carga_inicial_erro',
            ]);
        });
    }
};
