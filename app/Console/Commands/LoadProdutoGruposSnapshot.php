<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\ProdutoGrupoImporter;
use App\Services\WebPosto\WebPostoClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LoadProdutoGruposSnapshot extends Command
{
    protected $signature = 'webposto:load-produto-grupos {empresa=4604}';
    protected $description = 'Padrão 2000: reconcilia o snapshot completo de grupos de produtos';

    public function handle(WebPostoClient $client, ProdutoGrupoImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $endpoint = '/INTEGRACAO/GRUPO';
        $controlKey = $endpoint.':manual-initial';
        $control = WebPostoSyncControl::query()->updateOrCreate(
            ['empresa_codigo' => $empresa, 'endpoint' => $controlKey],
            ['strategy' => 'C', 'last_code' => 0, 'metadata' => ['mode' => 'snapshot']],
        );
        $control->update(['status' => 'running', 'last_started_at' => now(), 'last_error' => null]);

        try {
            $result = $client->get($endpoint, $empresa, ['empresaCodigo' => $empresa]);
            if (! $result['response']->successful()) {
                throw new RuntimeException('WebPosto respondeu HTTP '.$result['response']->status().' em '.$endpoint.'.');
            }

            $rows = is_array($result['payload']) && is_array($result['payload']['resultados'] ?? null)
                ? $result['payload']['resultados'] : [];
            $codes = collect($rows)->filter(fn ($row) => is_array($row) && is_numeric($row['grupoCodigo'] ?? null))
                ->pluck('grupoCodigo')->map(fn ($code) => (int) $code)->unique()->values();
            if ($codes->isEmpty()) {
                throw new RuntimeException('O WebPosto nao retornou grupos de produtos validos.');
            }

            $stored = DB::connection('webposto')->transaction(function () use ($importer, $result, $empresa, $codes): array {
                $stored = $importer->import($result['payload'], $empresa);
                $stale = DB::connection('webposto')->table('produto_grupos')->where('empresaCodigo', $empresa)
                    ->whereNotIn('grupoCodigo', $codes)->pluck('grupoCodigo');
                if ($stale->isNotEmpty()) {
                    $dependent = DB::connection('webposto')->table('produto_subgrupos')->where('empresaCodigo', $empresa)
                        ->whereIn('grupoCodigo', $stale)->exists()
                        || DB::connection('webposto')->table('produtos')->where('empresaCodigo', $empresa)
                            ->whereIn('grupoCodigo', $stale)->exists();
                    if ($dependent) {
                        throw new RuntimeException('Existem grupos ausentes no WebPosto ainda vinculados a produtos ou subgrupos.');
                    }
                    $stored['deleted'] = DB::connection('webposto')->table('produto_grupos')
                        ->where('empresaCodigo', $empresa)->whereIn('grupoCodigo', $stale)->delete();
                } else {
                    $stored['deleted'] = 0;
                }
                return $stored;
            });

            $lastCode = $codes->max();
            $control->update(['status' => 'ok', 'last_code' => $lastCode, 'last_completed_at' => now(),
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
