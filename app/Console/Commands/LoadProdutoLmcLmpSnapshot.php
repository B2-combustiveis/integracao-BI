<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\ProdutoLmcLmpImporter;
use App\Services\WebPosto\WebPostoClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LoadProdutoLmcLmpSnapshot extends Command
{
    protected $signature = 'webposto:load-produto-lmc-lmp {empresa=4604}';
    protected $description = 'Padrão 2000: reconcilia o snapshot completo de produtos LMC/LMP';

    public function handle(WebPostoClient $client, ProdutoLmcLmpImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $endpoint = '/INTEGRACAO/PRODUTO_LMC_LMP';
        $controlKey = $endpoint.':manual-initial';
        $control = WebPostoSyncControl::query()->updateOrCreate(
            ['empresa_codigo' => $empresa, 'endpoint' => $controlKey],
            ['strategy' => 'C', 'last_code' => 0, 'metadata' => ['mode' => 'snapshot']],
        );
        $control->update(['status' => 'running', 'last_started_at' => now(), 'last_error' => null]);

        try {
            $result = $client->get($endpoint, $empresa);
            if (! $result['response']->successful()) {
                throw new RuntimeException('WebPosto respondeu HTTP '.$result['response']->status().' em '.$endpoint.'.');
            }
            $rows = is_array($result['payload']) && array_is_list($result['payload']) ? $result['payload'] : [];
            $codes = collect($rows)->filter(fn ($row) => is_array($row) && is_numeric($row['produtoLmcCodigo'] ?? null))
                ->pluck('produtoLmcCodigo')->map(fn ($code) => (int) $code)->unique()->values();
            if ($codes->isEmpty()) throw new RuntimeException('O WebPosto nao retornou produtos LMC/LMP validos.');

            $stored = DB::connection('webposto')->transaction(function () use ($importer, $result, $empresa, $codes): array {
                $stored = $importer->import($result['payload'], $empresa);
                $stale = DB::connection('webposto')->table('produto_lmc_lmp')->where('empresaCodigo', $empresa)
                    ->whereNotIn('produtoLmcCodigo', $codes)->pluck('produtoLmcCodigo');
                if ($stale->isNotEmpty()) {
                    foreach ([['lmcs','produtoLmcCodigo'],['bicos','produtoLmcCodigo'],['produtos','produtoLmcCodigo'],['produto_empresas','produtoLmcCodigo']] as [$table,$field]) {
                        if (DB::connection('webposto')->table($table)->where('empresaCodigo', $empresa)->whereIn($field, $stale)->exists()) {
                            throw new RuntimeException("Existem produtos LMC/LMP ausentes no WebPosto ainda vinculados em {$table}.");
                        }
                    }
                    $stored['deleted'] = DB::connection('webposto')->table('produto_lmc_lmp')
                        ->where('empresaCodigo', $empresa)->whereIn('produtoLmcCodigo', $stale)->delete();
                } else $stored['deleted'] = 0;
                return $stored;
            });

            $control->update(['status' => 'ok', 'last_code' => $codes->max(), 'last_completed_at' => now(),
                'consecutive_failures' => 0, 'last_error' => null,
                'metadata' => ['mode' => 'snapshot', 'records' => $codes->count()]]);
            $this->info(json_encode($stored));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $control->update(['status' => 'error', 'last_completed_at' => now(),
                'consecutive_failures' => $control->consecutive_failures + 1,
                'last_error' => mb_substr($exception->getMessage(), 0, 2000)]);
            throw $exception;
        }
    }
}
