<?php

namespace App\Jobs;

use App\Exceptions\WebPostoSynchronizationCancelled;
use App\Models\WebPostoCredential;
use App\Models\WebPostoInitialSyncRun;
use App\Models\WebPostoReloadRun;
use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\B1ClienteEmpresaBatchSynchronizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class ReloadValidatedWebPostoTable implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable,InteractsWithQueue,Queueable,SerializesModels;

    public int $tries = 1;

    public int $timeout = 7200;

    public int $uniqueFor = 7500;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        $run = WebPostoReloadRun::find($this->runId);

        return $run ? $run->empresa_codigo.':'.$run->resource : (string) $this->runId;
    }

    public function handle(bool $duringInitialLoad = false, bool $atomic = false): void
    {
        $run = WebPostoReloadRun::findOrFail($this->runId);
        try {
            $credential = WebPostoCredential::query()->where('empresa_codigo', $run->empresa_codigo)->where('ativo', true)->first();
            if (! $credential || (! $duringInitialLoad && $credential->implantacao_status !== WebPostoCredential::STATUS_SINCRONIZADO)) {
                throw new RuntimeException('A empresa precisa estar sincronizada antes de executar recargas.');
            }
            if (! $duringInitialLoad) {
                $initialLoadRunning = WebPostoInitialSyncRun::query()->where('empresa_codigo', $run->empresa_codigo)
                    ->whereIn('status', ['queued', 'running'])->exists();
                if ($initialLoadRunning) {
                    throw new RuntimeException('A carga inicial desta empresa esta em andamento.');
                }
            }
            $run->update(['status' => 'running', 'started_at' => now(), 'error' => null]);
            if ($run->resource === 'venda_itens') {
                if (! $atomic) {
                    DB::connection('webposto')->table('abastecimentos')->where('empresaCodigo', $run->empresa_codigo)->delete();
                }
                $this->load('webposto:load-venda-itens', '/INTEGRACAO/VENDA_ITEM:manual-initial', $run->empresa_codigo);
                if (! $atomic) {
                    $run->update(['processed_tables' => ['venda_itens']]);
                    $this->load('webposto:load-abastecimentos', '/INTEGRACAO/ABASTECIMENTO:manual-initial', $run->empresa_codigo);
                }
                $done = $atomic ? ['venda_itens'] : ['venda_itens', 'abastecimentos'];
            } elseif ($run->resource === 'abastecimentos') {
                $this->load('webposto:load-abastecimentos', '/INTEGRACAO/ABASTECIMENTO:manual-initial', $run->empresa_codigo);
                $done = ['abastecimentos'];
            } elseif ($run->resource === 'bombas') {
                if (! $atomic) {
                    DB::connection('webposto')->table('bicos')->where('empresaCodigo', $run->empresa_codigo)->delete();
                }
                $this->load('webposto:load-bombas', '/INTEGRACAO/BOMBA:manual-initial', $run->empresa_codigo);
                if (! $atomic) {
                    $run->update(['processed_tables' => ['bombas']]);
                    $this->load('webposto:load-bicos', '/INTEGRACAO/BICO:manual-initial', $run->empresa_codigo);
                }
                $done = $atomic ? ['bombas'] : ['bombas', 'bicos'];
            } elseif ($run->resource === 'bicos') {
                $this->load('webposto:load-bicos', '/INTEGRACAO/BICO:manual-initial', $run->empresa_codigo);
                $done = ['bicos'];
            } elseif ($run->resource === 'tanques') {
                if (! $atomic) {
                    DB::connection('webposto')->table('bicos')->where('empresaCodigo', $run->empresa_codigo)->delete();
                }
                $this->load('webposto:load-tanques', '/INTEGRACAO/TANQUE:manual-initial', $run->empresa_codigo);
                if (! $atomic) {
                    $run->update(['processed_tables' => ['tanques']]);
                    $this->load('webposto:load-bicos', '/INTEGRACAO/BICO:manual-initial', $run->empresa_codigo);
                }
                $done = $atomic ? ['tanques'] : ['tanques', 'bicos'];
            } elseif ($run->resource === 'administradoras') {
                $resuming = in_array('administradoras', $run->processed_tables ?? [], true);
                if (! $resuming) {
                    if (! $atomic) {
                        DB::connection('webposto')->table('cartoes')->where('empresaCodigo', $run->empresa_codigo)->delete();
                    }
                    $this->load('webposto:load-administradoras', '/INTEGRACAO/ADMINISTRADORA:manual-initial', $run->empresa_codigo);
                    $run->update(['processed_tables' => ['administradoras']]);
                }
                if (! $atomic) {
                    $this->load('webposto:load-cartoes', '/INTEGRACAO/CARTAO:manual-initial', $run->empresa_codigo, $resuming);
                }
                $done = $atomic ? ['administradoras'] : ['administradoras', 'cartoes'];
            } elseif ($run->resource === 'produto_grupos') {
                $exit = Artisan::call('webposto:load-produto-grupos', ['empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de grupos de produtos.');
                }
                $done = ['produto_grupos'];
            } elseif ($run->resource === 'produto_subgrupos') {
                $exit = Artisan::call('webposto:load-produto-subgrupos', ['empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de subgrupos de produtos.');
                }
                $done = ['produto_subgrupos'];
            } elseif ($run->resource === 'produtos') {
                $exit = Artisan::call('webposto:load-produtos', ['empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de produtos.');
                }
                $done = ['produtos'];
            } elseif ($run->resource === 'produto_empresas') {
                $exit = Artisan::call('webposto:load-produto-empresas', ['empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de produtos por empresa.');
                }
                $done = ['produto_empresas'];
            } elseif ($run->resource === 'produto_lmc_lmp') {
                $exit = Artisan::call('webposto:load-produto-lmc-lmp', ['empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de produtos LMC/LMP.');
                }
                $done = ['produto_lmc_lmp'];
            } elseif ($run->resource === 'vales_funcionario') {
                $exit = Artisan::call('webposto:load-vales-funcionario', ['empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de vales de funcionarios.');
                }
                $done = ['vales_funcionario'];
            } elseif ($run->resource === 'funcionario_funcoes') {
                $exit = Artisan::call('webposto:load-funcionario-funcoes', ['empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de funcoes de funcionarios.');
                }
                $done = ['funcionario_funcoes'];
            } elseif ($run->resource === 'caixas') {
                $exit = Artisan::call('webposto:load-caixas', ['empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de caixas.');
                }
                if (! $atomic) {
                    $run->update(['processed_tables' => ['caixas']]);
                    $this->load('webposto:load-caixas-apresentados', '/INTEGRACAO/CAIXA_APRESENTADO:manual-initial', $run->empresa_codigo);
                }
                $done = $atomic ? ['caixas'] : ['caixas', 'caixas_apresentados'];
            } elseif ($run->resource === 'caixas_apresentados') {
                $this->load('webposto:load-caixas-apresentados', '/INTEGRACAO/CAIXA_APRESENTADO:manual-initial', $run->empresa_codigo);
                $done = ['caixas_apresentados'];
            } elseif (in_array($run->resource, ['planos_conta_gerencial', 'planos_conta_contabil'], true)) {
                $tipo = $run->resource === 'planos_conta_gerencial' ? 'gerencial' : 'contabil';
                $exit = Artisan::call('webposto:load-planos-conta', ['tipo' => $tipo, 'empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de planos de conta.');
                }
                $done = [$run->resource];
            } elseif ($run->resource === 'centros_custo') {
                $exit = Artisan::call('webposto:load-centros-custo', ['empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de centros de custo.');
                }
                $done = ['centros_custo'];
            } elseif ($run->resource === 'lmcs') {
                $exit = Artisan::call('webposto:load-lmcs', ['empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de LMCs.');
                }
                $done = ['lmcs'];
            } elseif ($run->resource === 'cartoes') {
                $this->load('webposto:load-cartoes', '/INTEGRACAO/CARTAO:manual-initial', $run->empresa_codigo);
                $done = ['cartoes'];
            } elseif ($run->resource === 'contas_bancarias') {
                if (! $atomic) {
                    DB::connection('webposto')->table('movimentos_conta')->where('empresaCodigo', $run->empresa_codigo)->delete();
                }
                $this->load('webposto:load-contas-bancarias', '/INTEGRACAO/CONTA:manual-initial', $run->empresa_codigo);
                if (! $atomic) {
                    $run->update(['processed_tables' => ['contas_bancarias']]);
                    $this->load('webposto:load-movimentos-conta', '/INTEGRACAO/MOVIMENTO_CONTA:manual-initial', $run->empresa_codigo);
                }
                $done = $atomic ? ['contas_bancarias'] : ['contas_bancarias', 'movimentos_conta'];
            } elseif ($run->resource === 'movimentos_conta') {
                $this->load('webposto:load-movimentos-conta', '/INTEGRACAO/MOVIMENTO_CONTA:manual-initial', $run->empresa_codigo);
                $done = ['movimentos_conta'];
            } elseif ($run->resource === 'funcionarios') {
                if (! $atomic) {
                    DB::connection('webposto')->table('vales_funcionario')->where('empresaCodigo', $run->empresa_codigo)->delete();
                    $exit = Artisan::call('webposto:load-funcionario-funcoes', ['empresa' => $run->empresa_codigo]);
                    if ($exit !== 0) {
                        throw new RuntimeException('Falha na carga de funcoes de funcionarios.');
                    }
                }
                $this->load('webposto:load-funcionarios', '/INTEGRACAO/FUNCIONARIO:manual-initial', $run->empresa_codigo);
                if (! $atomic) {
                    $exit = Artisan::call('webposto:load-vales-funcionario', ['empresa' => $run->empresa_codigo]);
                    if ($exit !== 0) {
                        throw new RuntimeException('Falha na carga de vales de funcionarios.');
                    }
                }
                $done = $atomic ? ['funcionarios'] : ['funcionario_funcoes', 'funcionarios', 'vales_funcionario'];
            } elseif ($run->resource === 'estoque_periodos') {
                $this->load('webposto:load-estoque-periodos', '/INTEGRACAO/ESTOQUE_PERIODO:manual-initial', $run->empresa_codigo);
                $done = ['estoque_periodos'];
            } elseif ($run->resource === 'fornecedores') {
                $this->load('webposto:load-fornecedores', '/INTEGRACAO/FORNECEDOR:manual-initial', $run->empresa_codigo);
                $done = ['fornecedores'];
            } elseif ($run->resource === 'compras') {
                $this->load('webposto:load-compras', '/INTEGRACAO/COMPRA:manual-initial', $run->empresa_codigo);
                $done = ['compras'];
            } elseif ($run->resource === 'compra_itens') {
                $this->load('webposto:load-compra-itens', '/INTEGRACAO/COMPRA_ITEM:manual-initial', $run->empresa_codigo);
                $done = ['compra_itens'];
            } elseif ($run->resource === 'titulos_pagar') {
                $this->loadIncremental('titulos_pagar', '/INTEGRACAO/TITULO_PAGAR:manual-initial', $run->empresa_codigo);
                $done = ['titulos_pagar'];
            } elseif ($run->resource === 'cliente_grupos') {
                $exit = Artisan::call('webposto:load-cliente-grupos', ['empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de grupos de clientes.');
                }
                $done = ['cliente_grupos'];
            } elseif ($run->resource === 'clientes') {
                if (! $atomic) {
                    DB::connection('webposto')->table('cliente_empresas')->where('empresaCodigo', $run->empresa_codigo)->delete();
                }
                $this->loadIncremental('clientes', '/INTEGRACAO/CLIENTE:manual-initial', $run->empresa_codigo);
                $run->update(['processed_tables' => ['clientes']]);
                if ($credential->base === WebPostoCredential::BASE_B1) {
                    $done = ['clientes'];
                } else {
                    $this->loadIncremental('cliente_empresas', '/INTEGRACAO/CLIENTE_EMPRESA:manual-initial', $run->empresa_codigo);
                    $done = ['clientes', 'cliente_empresas'];
                }
            } elseif ($run->resource === 'cliente_empresas') {
                if ($credential->base === WebPostoCredential::BASE_B1) {
                    app(B1ClienteEmpresaBatchSynchronizer::class)->synchronize($run->empresa_codigo);
                } else {
                    $this->loadIncremental('cliente_empresas', '/INTEGRACAO/CLIENTE_EMPRESA:manual-initial', $run->empresa_codigo);
                }
                $done = ['cliente_empresas'];
            } elseif (in_array($run->resource, ['formas_pagamento', 'pdvs'], true)) {
                $exit = Artisan::call('webposto:load-reference-snapshot', ['resource' => $run->resource, 'empresa' => $run->empresa_codigo]);
                if ($exit !== 0) {
                    throw new RuntimeException('Falha na carga de '.$run->resource.'.');
                }
                $done = [$run->resource];
            } elseif ($run->resource === 'vendas') {
                $connection = DB::connection('webposto');
                $resuming = $this->hasResumableControl('/INTEGRACAO/VENDA:manual-initial', $run->empresa_codigo);
                if (! $resuming) {
                    foreach ($atomic ? ['vendas'] : ['cartoes', 'abastecimentos', 'venda_itens', 'venda_formas_pagamento'] as $table) {
                        $connection->table($table)->where('empresaCodigo', $run->empresa_codigo)->delete();
                    }
                }
                $this->loadIncremental('vendas', '/INTEGRACAO/VENDA:manual-initial', $run->empresa_codigo, $resuming);
                if (! $atomic) {
                    $run->update(['processed_tables' => ['vendas']]);
                    $this->loadIncremental('venda_formas_pagamento', '/INTEGRACAO/VENDA_FORMA_PAGAMENTO:manual-initial', $run->empresa_codigo);
                    $this->load('webposto:load-venda-itens', '/INTEGRACAO/VENDA_ITEM:manual-initial', $run->empresa_codigo);
                    $this->load('webposto:load-abastecimentos', '/INTEGRACAO/ABASTECIMENTO:manual-initial', $run->empresa_codigo);
                    $this->load('webposto:load-cartoes', '/INTEGRACAO/CARTAO:manual-initial', $run->empresa_codigo);
                }
                $done = $atomic ? ['vendas'] : ['vendas', 'venda_formas_pagamento', 'venda_itens', 'abastecimentos', 'cartoes'];
            } elseif ($run->resource === 'venda_formas_pagamento') {
                $this->loadIncremental('venda_formas_pagamento', '/INTEGRACAO/VENDA_FORMA_PAGAMENTO:manual-initial', $run->empresa_codigo);
                $done = ['venda_formas_pagamento'];
            } elseif ($run->resource === 'titulos_receber') {
                $this->loadIncremental('titulos_receber', '/INTEGRACAO/TITULO_RECEBER:manual-initial', $run->empresa_codigo);
                $done = ['titulos_receber'];
            } else {
                throw new RuntimeException('Tabela nao habilitada.');
            }
            $run->update(['status' => 'success', 'processed_tables' => $done, 'finished_at' => now()]);
        } catch (Throwable $e) {
            $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }
    }

    private function load(string $command, string $key, int $empresa, ?bool $resume = null): void
    {
        $resume ??= $this->hasResumableControl($key, $empresa);
        for ($batch = 0; $batch < 1000; $batch++) {
            $this->ensureInitialLoadActive();
            try {
                $exit = Artisan::call($command, ['empresa' => $empresa, '--pages' => 80, '--resume' => $resume]);
            } catch (Throwable $e) {
                if (! $this->control($key, $empresa) || ! str_contains($e->getMessage(), 'Limite de paginacao atingido')) {
                    throw $e;
                }
            }
            $control = $this->control($key, $empresa);
            $this->ensureInitialLoadActive();
            if ($control?->status === 'ok') {
                return;
            }
            if (($exit ?? 1) !== 0 && ! str_contains((string) $control?->last_error, 'Limite de paginacao atingido')) {
                throw new RuntimeException($control?->last_error ?: 'Falha na carga '.$command);
            }
            $resume = true;
        }
        throw new RuntimeException('Limite de lotes excedido.');
    }

    private function loadIncremental(string $resource, string $key, int $empresa, ?bool $resume = null): void
    {
        $resume ??= $this->hasResumableControl($key, $empresa);
        for ($batch = 0; $batch < 1000; $batch++) {
            $this->ensureInitialLoadActive();
            try {
                $exit = Artisan::call('webposto:load-incremental-resource', ['resource' => $resource, 'empresa' => $empresa, '--pages' => 80, '--resume' => $resume]);
            } catch (Throwable $e) {
                if (! $this->control($key, $empresa) || ! str_contains($e->getMessage(), 'Limite de paginacao atingido')) {
                    throw $e;
                }
            }
            $control = $this->control($key, $empresa);
            $this->ensureInitialLoadActive();
            if ($control?->status === 'ok') {
                return;
            }
            if (($exit ?? 1) !== 0 && ! str_contains((string) $control?->last_error, 'Limite de paginacao atingido')) {
                throw new RuntimeException($control?->last_error ?: 'Falha na carga '.$resource);
            }
            $resume = true;
        }
        throw new RuntimeException('Limite de lotes excedido.');
    }

    private function control(string $key, int $empresa): ?WebPostoSyncControl
    {
        return WebPostoSyncControl::where('empresa_codigo', $empresa)->where('endpoint', $key)->first();
    }

    private function hasResumableControl(string $key, int $empresa): bool
    {
        $control = $this->control($key, $empresa);
        if ($control === null || ! in_array($control->status, ['error', 'idle', 'cancelled'], true)) {
            return false;
        }

        $metadata = is_array($control->metadata) ? $control->metadata : [];

        return ($metadata['resume_available'] ?? false) === true
            && ((int) ($metadata['checkpoint_cursor'] ?? $control->last_code)) > 1;
    }

    private function ensureInitialLoadActive(): void
    {
        $initialRunId = config('integration.runtime.webposto_initial_run_id');
        if ($initialRunId !== null && ! WebPostoInitialSyncRun::query()
            ->whereKey((int) $initialRunId)
            ->where('status', 'running')
            ->exists()) {
            throw new WebPostoSynchronizationCancelled;
        }
    }

    public function failed(?Throwable $e): void
    {
        WebPostoReloadRun::whereKey($this->runId)->whereIn('status', ['queued', 'running'])->update([
            'status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e?->getMessage() ?? 'Falha na fila.', 0, 2000)]);
    }
}
