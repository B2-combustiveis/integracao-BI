<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\PlanoContaImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LoadPlanosContaSnapshot extends Command
{
    protected $signature = 'webposto:load-planos-conta {tipo : gerencial ou contabil} {empresa=4604} {--resume} {--pages=200}';
    protected $description = 'Padrao 2000: reconcilia planos de conta paginados desde o cursor 1';

    public function handle(WebPostoCursorSynchronizer $sync, PlanoContaImporter $importer): int
    {
        $tipo = (string) $this->argument('tipo');
        [$endpoint, $table, $keyField] = match ($tipo) {
            'gerencial' => ['/INTEGRACAO/PLANO_CONTA_GERENCIAL', 'planos_conta_gerencial', 'planoContaCodigo'],
            'contabil' => ['/INTEGRACAO/PLANO_CONTA_CONTABIL', 'planos_conta_contabil', 'planoContaContabilCodigo'],
            default => throw new InvalidArgumentException('Tipo deve ser gerencial ou contabil.'),
        };
        $empresa = (int) $this->argument('empresa');
        $controlKey = $endpoint.':manual-initial';
        $resume = (bool) $this->option('resume');
        if (! $resume) {
            WebPostoSyncControl::query()->where('empresa_codigo', $empresa)->where('endpoint', $controlKey)->delete();
        }
        $seen = [];
        $totals = $sync->synchronize(
            endpoint: $endpoint,
            empresaCodigo: $empresa,
            persist: function ($payload, $parameters) use ($importer, $empresa, $table, $keyField, &$seen): array {
                $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
                foreach ($rows as $row) {
                    if (is_array($row) && is_numeric($row[$keyField] ?? null)) $seen[(int) $row[$keyField]] = true;
                }
                return $importer->import($payload, $empresa, $table, $keyField);
            },
            query: ['limite' => 1000, 'empresaCodigo' => $empresa],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => ! $resume],
            maxPages: max(1, (int) $this->option('pages')),
            controlKey: $controlKey,
        );
        $deleted = 0;
        if (! $resume && $seen !== []) {
            $deleted = DB::connection('webposto')->table($table)->where('empresaCodigo', $empresa)
                ->whereNotIn($keyField, array_keys($seen))->delete();
        }
        $this->info(json_encode([...$totals, 'deleted' => $deleted]));
        return self::SUCCESS;
    }
}
