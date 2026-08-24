<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';
    public function up(): void
    {
        Schema::connection($this->connection)->table('abastecimentos', function (Blueprint $table): void {
            $table->unique(['empresaCodigo','abastecimentoCodigo'], 'abastecimentos_empresa_codigo_unique');
            $table->index(['empresaCodigo','vendaItemCodigo'], 'abastecimentos_empresa_item_idx');
            $table->foreign(['empresaCodigo','vendaItemCodigo'], 'abastecimentos_venda_item_fk')
                ->references(['empresaCodigo','vendaItemCodigo'])->on('venda_itens')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
    }
    public function down(): void
    {
        Schema::connection($this->connection)->table('abastecimentos', function (Blueprint $table): void {
            $table->dropForeign('abastecimentos_venda_item_fk');
            $table->dropIndex('abastecimentos_empresa_item_idx');
            $table->dropUnique('abastecimentos_empresa_codigo_unique');
        });
    }
};
