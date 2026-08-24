<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'webposto';

    public function up(): void
    {
        Schema::connection($this->connection)->table('vendas', function (Blueprint $table): void {
            $table->unique(['empresaCodigo','vendaCodigo'], 'vendas_empresa_venda_unique');
        });
        Schema::connection($this->connection)->table('venda_itens', function (Blueprint $table): void {
            $table->unique(['empresaCodigo','vendaItemCodigo'], 'venda_itens_empresa_item_unique');
            $table->index(['empresaCodigo','vendaCodigo'], 'venda_itens_empresa_venda_idx');
            $table->foreign(['empresaCodigo','vendaCodigo'], 'venda_itens_venda_fk')
                ->references(['empresaCodigo','vendaCodigo'])->on('vendas')
                ->cascadeOnUpdate()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('venda_itens', function (Blueprint $table): void {
            $table->dropForeign('venda_itens_venda_fk');
            $table->dropIndex('venda_itens_empresa_venda_idx');
            $table->dropUnique('venda_itens_empresa_item_unique');
        });
        Schema::connection($this->connection)->table('vendas', function (Blueprint $table): void {
            $table->dropUnique('vendas_empresa_venda_unique');
        });
    }
};
