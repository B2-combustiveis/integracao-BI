<?php

namespace App\Jobs;

use App\Models\WebPostoCredential;
use App\Models\WebPostoInitialSyncRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncWebPostoCompanyInitialLoad implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 21600;

    public int $uniqueFor = 21600;

    /** @var array<int, string> */
    public const RESOURCES = [
        'produto_grupos',
        'produto_subgrupos',
        'produtos',
        'produto_empresas',
        'produto_lmc_lmp',
        'estoque_periodos',
        'administradoras',
        'tanques',
        'bombas',
        'lmcs',
        'fornecedores',
        'compras',
        'compra_itens',
        'titulos_pagar',
        'cliente_grupos',
        'clientes',
        'funcionarios',
        'formas_pagamento',
        'pdvs',
        'caixas',
        'vendas',
        'titulos_receber',
        'bicos',
        'centros_custo',
        'cartoes',
        'venda_formas_pagamento',
        'contas_bancarias',
        'movimentos_conta',
        'venda_itens',
        'abastecimentos',
        'funcionario_funcoes',
        'vales_funcionario',
        'caixas_apresentados',
        'cliente_empresas',
    ];

    /**
     * Recursos que, pra base B1, ficam de fora do disparo em paralelo da carga
     * inicial e esperam um gatilho manual (comando webposto:b1-dispatch-deferred).
     * Hoje so cliente_empresas, porque o endpoint dele e um feed global
     * compartilhado entre empresas - rodar em paralelo com outro scan do mesmo
     * feed (ex: durante um lote anterior ainda em andamento) so duplica trabalho.
     *
     * @var array<int, string>
     */
    public const DEFERRED_B1_RESOURCES = [
        'cliente_empresas',
    ];

    /** @return array<int, string> */
    public static function requiredResourcesFor(string $base): array
    {
        return $base === WebPostoCredential::BASE_B1
            ? array_values(array_diff(self::RESOURCES, self::DEFERRED_B1_RESOURCES))
            : self::RESOURCES;
    }

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('webposto-coordinator');
    }

    public function uniqueId(): string
    {
        $run = WebPostoInitialSyncRun::query()->find($this->runId);

        return 'initial-company-load:'.($run?->empresa_codigo ?? $this->runId);
    }

    public function handle(): void
    {
        $run = WebPostoInitialSyncRun::query()->findOrFail($this->runId);
        $credential = WebPostoCredential::query()
            ->where('empresa_codigo', $run->empresa_codigo)
            ->firstOrFail();

        $wasSynchronized = (bool) $run->was_synchronized
            || $credential->implantacao_status === WebPostoCredential::STATUS_SINCRONIZADO;
        $completed = array_values(array_unique($run->completed_resources ?? []));
        $required = self::requiredResourcesFor((string) $credential->base);
        $run->update([
            'status' => 'running',
            'total_resources' => count($required),
            'completed_resources' => $completed,
            'was_synchronized' => $wasSynchronized,
            'started_at' => now(),
            'finished_at' => null,
            'error' => null,
        ]);
        $credential->update([
            'implantacao_status' => WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
            'carga_inicial_iniciada_em' => now(),
            'carga_inicial_concluida_em' => null,
            'carga_inicial_erro' => null,
        ]);

        // Carga modular em paralelo pra todas as bases - o caminho sequencial
        // antigo (um unico job de horas, sem resiliencia por recurso) nao
        // existe mais aqui de proposito: um recurso pesado travando nao deve
        // mais segurar os outros 33 nem exigir matar o worker inteiro pra sair
        // do lugar.
        $run->update([
            'current_resource' => 'carga modular em paralelo',
            'current_position' => count(array_intersect($required, $completed)),
        ]);
        foreach (array_diff($required, $completed) as $resource) {
            SyncWebPostoInitialResource::dispatch($run->id, $resource);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = WebPostoInitialSyncRun::query()->find($this->runId);
        if ($run === null || ! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }
        $message = mb_substr($exception?->getMessage() ?? 'Falha na carga inicial.', 0, 2000);
        $run->update(['status' => 'failed', 'current_resource' => null, 'finished_at' => now(), 'error' => $message]);
        WebPostoCredential::query()->where('empresa_codigo', $run->empresa_codigo)->update([
            'implantacao_status' => $run->was_synchronized
                ? WebPostoCredential::STATUS_SINCRONIZADO
                : WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
            'carga_inicial_erro' => $message,
        ]);
    }
}
