<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\ProdutoSubgrupoImporter;
use App\Services\WebPosto\WebPostoClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LoadProdutoSubgruposSnapshot extends Command
{
    protected $signature = 'webposto:load-produto-subgrupos {empresa=4604}';
    protected $description = 'Padrão 2000: reconcilia o snapshot completo de subgrupos de produtos';

    public function handle(WebPostoClient $client, ProdutoSubgrupoImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $endpoint = '/INTEGRACAO/CONSULTAR_SUB_GRUPO_REDE';
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
            $rows = is_array($result['payload'])
                ? (array_is_list($result['payload']) ? $result['payload'] : ($result['payload']['resultados'] ?? [])) : [];
            $keys = collect(is_array($rows) ? $rows : [])->filter(fn ($row) => is_array($row)
                    && is_numeric($row['grupoCodigo'] ?? null) && is_numeric($row['subGrupoCodigo'] ?? null))
                ->map(fn ($row) => (int) $row['grupoCodigo'].'|'.(int) $row['subGrupoCodigo'])->unique()->values();
            if ($keys->isEmpty()) {
                throw new RuntimeException('O WebPosto nao retornou subgrupos de produtos validos.');
            }

            $stored = DB::connection('webposto')->transaction(function () use ($importer, $result, $empresa, $keys): array {
                $stored = $importer->import($result['payload'], $empresa);
                if (($stored['missing_parent_group'] ?? 0) > 0) {
                    throw new RuntimeException('O WebPosto retornou subgrupos sem grupo-pai cadastrado.');
                }
                $source = array_fill_keys($keys->all(), true);
                $stale = DB::connection('webposto')->table('produto_subgrupos')->where('empresaCodigo', $empresa)
                    ->get(['grupoCodigo', 'subGrupoCodigo'])->filter(fn ($row) => ! isset($source[$row->grupoCodigo.'|'.$row->subGrupoCodigo]));
                foreach ($stale as $row) {
                    if (DB::connection('webposto')->table('produtos')->where('empresaCodigo', $empresa)
                        ->where('grupoCodigo', $row->grupoCodigo)->where('subGrupo1Codigo', $row->subGrupoCodigo)->exists()) {
                        throw new RuntimeException('Existem subgrupos ausentes no WebPosto ainda vinculados a produtos.');
                    }
                    DB::connection('webposto')->table('produto_subgrupos')->where('empresaCodigo', $empresa)
                        ->where('grupoCodigo', $row->grupoCodigo)->where('subGrupoCodigo', $row->subGrupoCodigo)->delete();
                }
                $stored['deleted'] = $stale->count();
                return $stored;
            });

            $lastCode = collect($rows)->max(fn ($row) => (int) ($row['subGrupoCodigo'] ?? 0));
            $control->update(['status' => 'ok', 'last_code' => $lastCode, 'last_completed_at' => now(),
                'consecutive_failures' => 0, 'last_error' => null,
                'metadata' => ['mode' => 'snapshot', 'records' => $keys->count()]]);
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
