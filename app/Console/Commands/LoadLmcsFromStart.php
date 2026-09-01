<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\LmcImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadLmcsFromStart extends Command
{
    protected $signature = 'webposto:load-lmcs {empresa=4604} {--resume} {--pages=200}';
    protected $description = 'Padrao 2000: reconcilia LMCs desde o cursor 1';

    public function handle(WebPostoCursorSynchronizer $sync, LmcImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $key = '/INTEGRACAO/LMC:manual-initial';
        $resume = (bool) $this->option('resume');
        if (! $resume) WebPostoSyncControl::query()->where('empresa_codigo', $empresa)->where('endpoint', $key)->delete();
        $seen = [];
        $totals = $sync->synchronize(
            endpoint: '/INTEGRACAO/LMC', empresaCodigo: $empresa,
            persist: function ($payload, $parameters) use ($importer, $empresa, &$seen): array {
                $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
                foreach ($rows as $row) if (is_array($row) && is_numeric($row['lmcCodigo'] ?? null)) $seen[(int) $row['lmcCodigo']] = true;
                return $importer->import($payload, $empresa, $parameters);
            },
            query: ['dataInicial' => '2000-01-01', 'dataFinal' => now()->toDateString(), 'limite' => 1000, 'empresaCodigo' => $empresa],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => ! $resume],
            maxPages: max(1, (int) $this->option('pages')), controlKey: $key,
        );
        $deleted = ! $resume && $seen !== []
            ? DB::connection('webposto')->table('lmcs')->where('empresaCodigo', $empresa)
                ->whereNotIn('lmcCodigo', array_keys($seen))->delete()
            : 0;
        $this->info(json_encode([...$totals, 'deleted' => $deleted]));
        return self::SUCCESS;
    }
}
