<?php
namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\VendaItemImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadVendaItensFromStart extends Command
{
    protected $signature = 'webposto:load-venda-itens {empresa=4604} {--resume} {--pages=20} {--data-inicial=} {--data-final=} {--append}';
    protected $description = 'Zera e recarrega venda_itens do cursor 1 ate o ultimo registro';

    public function handle(WebPostoCursorSynchronizer $sync, VendaItemImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $endpoint = '/INTEGRACAO/VENDA_ITEM';
        $resume = (bool) $this->option('resume');
        $append = (bool) $this->option('append');
        $dataInicial = trim((string) $this->option('data-inicial')) ?: '2000-01-01';
        $dataFinal = trim((string) $this->option('data-final')) ?: now()->toDateString();
        $controlKey = $endpoint.':manual-initial'.($append ? ':'.$dataInicial.':'.$dataFinal : '');

        if (! $resume && ! $append) {
            $connection = DB::connection('webposto');
            $connection->table('venda_itens')->where('empresaCodigo', $empresa)->delete();
        }
        if (! $resume) {
            WebPostoSyncControl::query()->where('empresa_codigo', $empresa)->where('endpoint', $controlKey)->delete();
            $this->info(($append ? 'Acumulando venda_itens. ' : 'venda_itens limpa para a empresa. ').'Iniciando no cursor 1.');
        } else {
            $this->info('Retomando venda_itens do ultimo cursor confirmado.');
        }

        $totals = $sync->synchronize(
            endpoint: $endpoint,
            empresaCodigo: $empresa,
            persist: fn (mixed $payload, array $parameters): array => $importer->import($payload, $empresa, $parameters),
            query: ['dataInicial' => $dataInicial, 'dataFinal' => $dataFinal, 'limite' => 1000, 'empresaCodigo' => $empresa],
            cursor: ['initial_value' => 1, 'prefer_initial_value' => ! $resume],
            initialQuery: null,
            maxPages: max(1, (int) $this->option('pages')),
            controlKey: $controlKey,
        );

        $this->table(['Paginas','Recebidos','Inseridos','Atualizados','Iguais','Ignorados','Cursor'], [[
            $totals['pages'], $totals['received'], $totals['inserted'], $totals['updated'],
            $totals['unchanged'], $totals['skipped'],
            WebPostoSyncControl::query()->where('empresa_codigo', $empresa)->where('endpoint', $controlKey)->value('last_code'),
        ]]);

        return self::SUCCESS;
    }
}
