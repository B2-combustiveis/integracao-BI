<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\CaixaImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadCaixasFromStart extends Command
{
    protected $signature = 'webposto:load-caixas {empresa=4604} {--resume} {--pages=200}';
    protected $description = 'Padrao 2000: reconcilia caixas desde o cursor 1 sem quebrar dependencias';

    public function handle(WebPostoCursorSynchronizer $sync, CaixaImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $key = '/INTEGRACAO/CAIXA:manual-initial';
        $resume = (bool) $this->option('resume');
        if (! $resume) {
            WebPostoSyncControl::query()->where('empresa_codigo', $empresa)->where('endpoint', $key)->delete();
        }
        $seen = [];
        $totals = $sync->synchronize(
            endpoint: '/INTEGRACAO/CAIXA',
            empresaCodigo: $empresa,
            persist: function ($payload, $parameters) use ($importer, $empresa, &$seen): array {
                $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
                foreach ($rows as $row) {
                    if (is_array($row) && is_numeric($row['caixaCodigo'] ?? null)) $seen[(int) $row['caixaCodigo']] = true;
                }
                return $importer->import($payload, $empresa, $parameters);
            },
            query: ['dataInicial' => '2000-01-01', 'dataFinal' => now()->toDateString(), 'limite' => 1000],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => ! $resume],
            maxPages: max(1, (int) $this->option('pages')),
            controlKey: $key,
        );

        $deleted = $protected = 0;
        if (! $resume && $seen !== []) {
            DB::connection('webposto')->transaction(function () use ($empresa, $seen, &$deleted, &$protected): void {
                $stale = DB::connection('webposto')->table('caixas')->where('empresaCodigo', $empresa)
                    ->whereNotIn('caixaCodigo', array_keys($seen))->pluck('caixaCodigo');
                $referenced = $stale->isEmpty() ? collect() : DB::connection('webposto')->table('caixas_apresentados')
                    ->where('empresaCodigo', $empresa)->whereIn('caixaCodigo', $stale)->pluck('caixaCodigo')
                    ->merge(DB::connection('webposto')->table('vales_funcionario')->where('empresaCodigo', $empresa)
                        ->whereIn('caixaCodigo', $stale)->pluck('caixaCodigo'))->unique();
                $protected = $referenced->count();
                $deletable = $stale->diff($referenced);
                if ($deletable->isNotEmpty()) {
                    $deleted = DB::connection('webposto')->table('caixas')->where('empresaCodigo', $empresa)
                        ->whereIn('caixaCodigo', $deletable)->delete();
                }
            });
        }
        $this->info(json_encode([...$totals, 'deleted' => $deleted, 'protected' => $protected]));
        return self::SUCCESS;
    }
}
