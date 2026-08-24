<?php
namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\AbastecimentoImporter;
use App\Services\WebPosto\WebPostoCursorSynchronizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LoadAbastecimentosFromStart extends Command
{
    protected $signature = 'webposto:load-abastecimentos {empresa=4604} {--resume} {--pages=40}';
    protected $description = 'Zera e recarrega abastecimentos do cursor 1 ate o ultimo registro';

    public function handle(WebPostoCursorSynchronizer $sync, AbastecimentoImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $endpoint = '/INTEGRACAO/ABASTECIMENTO';
        $controlKey = $endpoint.':manual-initial';
        $resume = (bool) $this->option('resume');
        if (! $resume) {
            DB::connection('webposto')->table('abastecimentos')->truncate();
            WebPostoSyncControl::query()->where('empresa_codigo', $empresa)->where('endpoint', $controlKey)->delete();
            $this->info('abastecimentos zerada. Iniciando no cursor 1.');
        } else {
            $this->info('Retomando abastecimentos do ultimo cursor confirmado.');
        }
        $totals = $sync->synchronize(
            endpoint: $endpoint, empresaCodigo: $empresa,
            persist: fn (mixed $payload, array $parameters): array => $importer->import($payload, $empresa, $parameters),
            query: ['dataInicial'=>'2000-01-01','dataFinal'=>now()->toDateString(),'limite'=>1000],
            cursor: ['initial_value'=>1,'prefer_initial_value'=>! $resume], initialQuery: null,
            maxPages: max(1, (int) $this->option('pages')), controlKey: $controlKey,
        );
        $this->table(['Paginas','Recebidos','Inseridos','Atualizados','Iguais','Ignorados','Cursor'], [[
            $totals['pages'],$totals['received'],$totals['inserted'],$totals['updated'],$totals['unchanged'],$totals['skipped'],
            WebPostoSyncControl::query()->where('empresa_codigo',$empresa)->where('endpoint',$controlKey)->value('last_code'),
        ]]);
        return self::SUCCESS;
    }
}
