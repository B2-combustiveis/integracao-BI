<?php

namespace App\Console\Commands;

use App\Models\WebPostoSyncControl;
use App\Services\WebPosto\FuncionarioFuncaoImporter;
use App\Services\WebPosto\WebPostoClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class LoadFuncionarioFuncoesSnapshot extends Command
{
    protected $signature = 'webposto:load-funcionario-funcoes {empresa=4604}';
    protected $description = 'Padrao 2000: reconcilia o snapshot completo de funcoes de funcionarios';

    public function handle(WebPostoClient $client, FuncionarioFuncaoImporter $importer): int
    {
        $empresa = (int) $this->argument('empresa');
        $endpoint = '/INTEGRACAO/FUNCOES';
        $key = $endpoint.':manual-initial';
        $control = WebPostoSyncControl::query()->updateOrCreate(
            ['empresa_codigo' => $empresa, 'endpoint' => $key],
            ['strategy' => 'C', 'last_code' => 0, 'metadata' => ['mode' => 'snapshot']],
        );
        $control->update(['status' => 'running', 'last_started_at' => now(), 'last_error' => null]);

        try {
            $result = $client->get($endpoint, $empresa);
            if (! $result['response']->successful()) {
                throw new RuntimeException('WebPosto respondeu HTTP '.$result['response']->status().' em '.$endpoint.'.');
            }
            $rows = is_array($result['payload']) && is_array($result['payload']['resultados'] ?? null)
                ? $result['payload']['resultados']
                : (is_array($result['payload']) && array_is_list($result['payload']) ? $result['payload'] : []);
            $codes = collect($rows)->filter(fn ($row) => is_array($row) && is_numeric($row['funcaoCodigo'] ?? null))
                ->pluck('funcaoCodigo')->map(fn ($code) => (int) $code)->unique()->values();
            if ($codes->isEmpty()) throw new RuntimeException('O WebPosto nao retornou funcoes validas.');

            $payload = isset($result['payload']['resultados']) ? $result['payload'] : ['resultados' => $rows];
            $stored = DB::connection('webposto')->transaction(function () use ($importer, $payload, $empresa, $codes): array {
                $stored = $importer->import($payload, $empresa);
                $stale = DB::connection('webposto')->table('funcionario_funcoes')->whereNotIn('funcaoCodigo', $codes)
                    ->pluck('funcaoCodigo');
                $referenced = $stale->isEmpty() ? collect() : DB::connection('webposto')->table('funcionarios')
                    ->whereIn('funcaoCodigo', $stale)->pluck('funcaoCodigo')->unique();
                $deletable = $stale->diff($referenced);
                $stored['deleted'] = $deletable->isEmpty() ? 0 : DB::connection('webposto')->table('funcionario_funcoes')
                    ->whereIn('funcaoCodigo', $deletable)->delete();
                $stored['protected'] = $referenced->count();
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
