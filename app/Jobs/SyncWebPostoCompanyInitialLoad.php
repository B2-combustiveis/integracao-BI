<?php

namespace App\Jobs;

use App\Models\WebPostoCredential;
use App\Models\WebPostoInitialSyncRun;
use App\Services\WebPosto\WebPostoInitialLoadRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SyncWebPostoCompanyInitialLoad implements ShouldQueue, ShouldBeUnique
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
        'clientes',
        'funcionarios',
        'caixas',
        'vendas',
        'titulos_receber',
    ];

    public function __construct(public readonly int $runId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        $run = WebPostoInitialSyncRun::query()->find($this->runId);

        return 'initial-company-load:'.($run?->empresa_codigo ?? $this->runId);
    }

    public function handle(WebPostoInitialLoadRunner $runner): void
    {
        $run = WebPostoInitialSyncRun::query()->findOrFail($this->runId);
        $credential = WebPostoCredential::query()
            ->where('empresa_codigo', $run->empresa_codigo)
            ->firstOrFail();

        if ($credential->implantacao_status === WebPostoCredential::STATUS_SINCRONIZADO) {
            $run->update(['status' => 'success', 'finished_at' => now()]);
            return;
        }

        $completed = [];
        $run->update([
            'status' => 'running',
            'current_position' => 0,
            'total_resources' => count(self::RESOURCES),
            'completed_resources' => [],
            'started_at' => now(),
            'finished_at' => null,
            'error' => null,
        ]);
        $credential->update([
            'carga_inicial_iniciada_em' => now(),
            'carga_inicial_concluida_em' => null,
            'carga_inicial_erro' => null,
        ]);

        try {
            foreach (self::RESOURCES as $position => $resource) {
                $run->update([
                    'current_resource' => $resource,
                    'current_position' => $position + 1,
                ]);
                $completed = [
                    ...$completed,
                    ...$runner->run($run->empresa_codigo, $resource),
                ];
                $run->update([
                    'completed_resources' => array_values(array_unique($completed)),
                ]);
            }

            $run->update([
                'status' => 'success',
                'current_resource' => null,
                'finished_at' => now(),
            ]);
            $credential->update([
                'implantacao_status' => WebPostoCredential::STATUS_SINCRONIZADO,
                'carga_inicial_concluida_em' => now(),
                'carga_inicial_erro' => null,
            ]);
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            $run->update([
                'status' => 'failed',
                'current_resource' => null,
                'finished_at' => now(),
                'error' => $message,
            ]);
            $credential->update([
                'implantacao_status' => WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
                'carga_inicial_erro' => $message,
            ]);
            throw $exception;
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
            'implantacao_status' => WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
            'carga_inicial_erro' => $message,
        ]);
    }
}
