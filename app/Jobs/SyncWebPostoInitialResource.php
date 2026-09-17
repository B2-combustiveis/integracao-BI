<?php

namespace App\Jobs;

use App\Exceptions\WebPostoSynchronizationCancelled;
use App\Models\WebPostoCredential;
use App\Models\WebPostoInitialSyncRun;
use App\Models\WebPostoReloadRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class SyncWebPostoInitialResource implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Esperas por uma das vagas pesadas voltam para a fila sem serem uma falha. */
    public int $tries = 100;

    /** Uma excecao real continua encerrando imediatamente a etapa. */
    public int $maxExceptions = 1;

    public int $timeout = 21600;

    public int $uniqueFor = 22000;

    private const HEAVY_RESOURCES = [
        'vendas', 'venda_formas_pagamento', 'venda_itens', 'abastecimentos',
        'cartoes', 'titulos_receber', 'movimentos_conta',
    ];

    private const SHARED_RESOURCES = ['cliente_empresas'];

    public function __construct(
        public readonly int $initialRunId,
        public readonly string $resource,
    ) {
        $this->onQueue(self::queueFor($resource));
    }

    public function uniqueId(): string
    {
        return $this->initialRunId.':'.$this->resource;
    }

    public static function queueFor(string $resource): string
    {
        if (in_array($resource, self::SHARED_RESOURCES, true)) {
            return 'webposto-shared';
        }

        return in_array($resource, self::HEAVY_RESOURCES, true)
            ? 'webposto-heavy'
            : 'webposto-light';
    }

    public function handle(): void
    {
        $initial = WebPostoInitialSyncRun::query()->findOrFail($this->initialRunId);
        if ($initial->status !== 'running' || in_array($this->resource, $initial->completed_resources ?? [], true)) {
            return;
        }

        $lock = $this->acquireHeavySlot();
        if (in_array($this->resource, self::HEAVY_RESOURCES, true) && $lock === null) {
            $this->release(20);

            return;
        }

        $reload = null;
        try {
            $reload = WebPostoReloadRun::query()->create([
                'empresa_codigo' => $initial->empresa_codigo,
                'resource' => $this->resource,
                'status' => 'queued',
                'processed_tables' => [],
            ]);
            $previousRunId = config('integration.runtime.webposto_initial_run_id');
            config(['integration.runtime.webposto_initial_run_id' => $initial->id]);
            try {
                (new ReloadValidatedWebPostoTable($reload->id))->handle(duringInitialLoad: true, atomic: true);
            } finally {
                config(['integration.runtime.webposto_initial_run_id' => $previousRunId]);
            }
            $reload->refresh();
            if ($reload->status !== 'success') {
                throw new RuntimeException($reload->error ?: 'Falha na carga de '.$this->resource.'.');
            }
            $this->complete($reload->processed_tables ?: [$this->resource]);
        } catch (WebPostoSynchronizationCancelled) {
            if ($reload !== null) {
                WebPostoReloadRun::query()->whereKey($reload->id)
                    ->whereIn('status', ['queued', 'running', 'failed'])
                    ->update([
                        'status' => 'cancelled',
                        'finished_at' => now(),
                        'error' => 'Cancelado junto com a carga inicial.',
                    ]);
            }
        } finally {
            $lock?->release();
        }
    }

    /** @param array<int, string> $resources */
    private function complete(array $resources): void
    {
        DB::transaction(function () use ($resources): void {
            $run = WebPostoInitialSyncRun::query()->lockForUpdate()->findOrFail($this->initialRunId);
            if ($run->status !== 'running') {
                return;
            }
            $completed = array_values(array_unique([...(array) $run->completed_resources, ...$resources]));
            $base = (string) WebPostoCredential::query()
                ->where('empresa_codigo', $run->empresa_codigo)
                ->value('base');
            $required = SyncWebPostoCompanyInitialLoad::requiredResourcesFor($base);
            $finished = count(array_diff($required, $completed)) === 0;
            $run->update([
                'completed_resources' => $completed,
                'total_resources' => count($required),
                'current_resource' => $finished ? null : 'carga modular em paralelo',
                'current_position' => count(array_intersect($required, $completed)),
                'status' => $finished ? 'success' : 'running',
                'finished_at' => $finished ? now() : null,
            ]);
            if ($finished) {
                WebPostoCredential::query()->where('empresa_codigo', $run->empresa_codigo)->update([
                    'implantacao_status' => WebPostoCredential::STATUS_SINCRONIZADO,
                    'carga_inicial_concluida_em' => now(),
                    'carga_inicial_erro' => null,
                ]);
            }
        });
    }

    private function acquireHeavySlot(): mixed
    {
        if (! in_array($this->resource, self::HEAVY_RESOURCES, true)) {
            return null;
        }
        foreach (range(1, 3) as $slot) {
            $lock = Cache::lock('webposto:b1:initial-heavy-slot:'.$slot, 22000);
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    public function failed(?Throwable $exception): void
    {
        $message = mb_substr($exception?->getMessage() ?? 'Falha na carga modular.', 0, 2000);
        $run = WebPostoInitialSyncRun::query()->find($this->initialRunId);
        if ($run === null || $run->status !== 'running') {
            return;
        }
        $run->update(['status' => 'failed', 'finished_at' => now(), 'error' => $message]);
        WebPostoCredential::query()->where('empresa_codigo', $run->empresa_codigo)->update([
            'implantacao_status' => $run->was_synchronized
                ? WebPostoCredential::STATUS_SINCRONIZADO
                : WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
            'carga_inicial_erro' => $message,
        ]);
    }
}
