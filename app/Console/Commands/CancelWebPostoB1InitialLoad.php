<?php

namespace App\Console\Commands;

use App\Models\WebPostoCredential;
use App\Models\WebPostoInitialSyncRun;
use Illuminate\Console\Command;

class CancelWebPostoB1InitialLoad extends Command
{
    protected $signature = 'webposto:b1-cancel {empresa : Codigo da empresa B1 a cancelar}';

    protected $description = 'Cancela a carga inicial modular de uma empresa B1 sem precisar derrubar worker nenhum';

    public function handle(): int
    {
        $empresa = (int) $this->argument('empresa');
        $credential = WebPostoCredential::query()->where('empresa_codigo', $empresa)->first();
        if ($credential === null) {
            $this->error("Nenhuma credencial encontrada para a empresa {$empresa}.");

            return self::FAILURE;
        }
        if ($credential->base !== WebPostoCredential::BASE_B1) {
            $this->error("Empresa {$empresa} nao e da base B1 - esse comando so cobre a carga modular da B1.");

            return self::FAILURE;
        }

        $run = WebPostoInitialSyncRun::query()
            ->where('empresa_codigo', $empresa)
            ->where('status', 'running')
            ->latest('id')
            ->first();
        if ($run === null) {
            $this->info("Nenhuma carga inicial em andamento para a empresa {$empresa}.");

            return self::SUCCESS;
        }

        $run->update([
            'status' => 'cancelled',
            'current_resource' => null,
            'finished_at' => now(),
            'error' => 'Cancelado manualmente via webposto:b1-cancel.',
        ]);
        $credential->update([
            'implantacao_status' => $run->was_synchronized
                ? WebPostoCredential::STATUS_SINCRONIZADO
                : WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO,
            'carga_inicial_erro' => null,
        ]);

        $completedCount = count($run->completed_resources ?? []);
        $pending = count(array_diff(
            \App\Jobs\SyncWebPostoCompanyInitialLoad::RESOURCES,
            $run->completed_resources ?? [],
        ));

        $this->info("Carga da empresa {$empresa} marcada como cancelada.");
        $this->line('Jobs ainda nao iniciados vao encerrar ao ler o status cancelado.');
        $this->line('Jobs em andamento param na proxima fronteira de pagina, antes de uma nova gravacao.');
        $this->line("{$pending} recurso(s) ainda pendente(s); os ja completos ({$completedCount}) ficam preservados pra proxima tentativa.");

        return self::SUCCESS;
    }
}
