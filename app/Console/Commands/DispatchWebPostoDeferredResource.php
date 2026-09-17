<?php

namespace App\Console\Commands;

use App\Jobs\SyncWebPostoCompanyInitialLoad;
use App\Jobs\SyncWebPostoInitialResource;
use App\Models\WebPostoInitialSyncRun;
use Illuminate\Console\Command;

class DispatchWebPostoDeferredResource extends Command
{
    protected $signature = 'webposto:b1-dispatch-deferred
        {resource=cliente_empresas : Recurso adiado a disparar (ver SyncWebPostoCompanyInitialLoad::DEFERRED_B1_RESOURCES)}
        {--empresa=* : Restringe a empresas especificas; sem isso, aplica a todas as cargas B1 elegiveis}';

    protected $description = 'Dispara manualmente um recurso da B1 que ficou em standby na carga inicial (ex: cliente_empresas)';

    public function handle(): int
    {
        $resource = (string) $this->argument('resource');
        if (! in_array($resource, SyncWebPostoCompanyInitialLoad::DEFERRED_B1_RESOURCES, true)) {
            $this->error("'{$resource}' nao esta na lista de recursos adiados da B1.");

            return self::INVALID;
        }

        $empresas = array_map('intval', (array) $this->option('empresa'));

        $runs = WebPostoInitialSyncRun::query()
            ->where('status', 'running')
            ->when($empresas !== [], fn ($query) => $query->whereIn('empresa_codigo', $empresas))
            ->get()
            ->filter(fn (WebPostoInitialSyncRun $run): bool => ! in_array($resource, $run->completed_resources ?? [], true));

        if ($runs->isEmpty()) {
            $this->info("Nenhuma carga B1 em andamento esta esperando o recurso '{$resource}'.");

            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            SyncWebPostoInitialResource::dispatch($run->id, $resource);
        }

        $this->info("'{$resource}' disparado para {$runs->count()} empresa(s).");
        $this->table(['Empresa'], $runs->map(fn (WebPostoInitialSyncRun $run): array => [$run->empresa_codigo])->all());

        return self::SUCCESS;
    }
}
