<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\AbastecimentoImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadAbastecimentosFromStart extends Command
{
    protected $signature = 'webposto:load-abastecimentos {empresa=4604} {--resume} {--pages=1000} {--data-inicial=} {--data-final=} {--append}';

    protected $description = 'Zera e recarrega abastecimentos do cursor 1 ate o ultimo registro';

    public function handle(WebPostoCursorSynchronizer $sync, AbastecimentoImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $endpoint = '/INTEGRACAO/ABASTECIMENTO';
        $resume = (bool) $this->option('resume');
        $append = (bool) $this->option('append');
        $dataInicial = $this->resolveStartDate($empresa);
        $dataFinal = trim((string) $this->option('data-final')) ?: now()->toDateString();
        $controlKey = $endpoint.':manual-initial'.($append ? ':'.$dataInicial.':'.$dataFinal : '');
        if (! $resume && ! $append) {
            DB::connection('webposto')->table('abastecimentos')->where('empresaCodigo', $empresa)->delete();
        }
        if (! $resume) {
            WebPostoSyncControl::query()->where('empresa_codigo', $empresa)->where('endpoint', $controlKey)->delete();
            $this->info(($append ? 'Acumulando abastecimentos. ' : 'abastecimentos zerada. ').'Iniciando no cursor 1.');
        } else {
            $this->info('Retomando abastecimentos do ultimo cursor confirmado.');
        }
        $totals = $sync->synchronize(
            endpoint: $endpoint, empresaCodigo: $empresa,
            persist: fn (mixed $payload, array $parameters): array => $importer->import($payload, $empresa, $parameters),
            query: ['dataInicial' => $dataInicial, 'dataFinal' => $dataFinal, 'limite' => 1000, 'empresaCodigo' => $empresa],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => ! $resume], initialQuery: null,
            maxPages: max(1, (int) $this->option('pages')), controlKey: $controlKey,
        );
        $this->table(['Paginas', 'Recebidos', 'Inseridos', 'Atualizados', 'Iguais', 'Ignorados', 'Cursor'], [[
            $totals['pages'], $totals['received'], $totals['inserted'], $totals['updated'], $totals['unchanged'], $totals['skipped'],
            WebPostoSyncControl::query()->where('empresa_codigo', $empresa)->where('endpoint', $controlKey)->value('last_code'),
        ]]);

        return self::SUCCESS;
    }

    private function resolveStartDate(int $empresa): string
    {
        $explicit = trim((string) $this->option('data-inicial'));
        if ($explicit !== '') {
            return $explicit;
        }

        $dates = collect([
            DB::connection('webposto')->table('venda_itens')->where('empresaCodigo', $empresa)->min('dataMovimento'),
            DB::connection('webposto')->table('lmcs')->where('empresaCodigo', $empresa)->min('dataMovimento'),
        ])->filter()->map(fn (mixed $date): string => substr((string) $date, 0, 10));

        return $dates->min() ?: '2000-01-01';
    }
}
