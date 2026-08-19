<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class ProdutoSubgrupoImporter
{
    public function import(mixed $payload, int $empresaCodigo): array
    {
        $resultados = is_array($payload)
            ? (array_is_list($payload) ? $payload : ($payload['resultados'] ?? []))
            : [];
        $resultados = is_array($resultados) ? $resultados : [];

        $validos = collect($resultados)
            ->filter(fn (mixed $subgrupo): bool =>
                is_array($subgrupo)
                && isset($subgrupo['subGrupoCodigo'], $subgrupo['grupoCodigo'])
                && is_numeric($subgrupo['subGrupoCodigo'])
                && is_numeric($subgrupo['grupoCodigo'])
            )
            ->unique(fn (array $subgrupo): string =>
                (int) $subgrupo['grupoCodigo'].'-'.(int) $subgrupo['subGrupoCodigo']
            )
            ->values();

        $connection = DB::connection('webposto');
        $gruposExistentes = $validos->isEmpty()
            ? []
            : $connection->table('produto_grupos')
                ->where('empresaCodigo', $empresaCodigo)
                ->whereIn('grupoCodigo', $validos->pluck('grupoCodigo')->map(fn (mixed $codigo): int => (int) $codigo))
                ->pluck('grupoCodigo')
                ->map(fn (mixed $codigo): int => (int) $codigo)
                ->all();
        $gruposLookup = array_fill_keys($gruposExistentes, true);
        $comGrupo = $validos
            ->filter(fn (array $subgrupo): bool => isset($gruposLookup[(int) $subgrupo['grupoCodigo']]))
            ->values();

        $existentes = $comGrupo->isEmpty()
            ? collect()
            : $connection->table('produto_subgrupos')
                ->where('empresaCodigo', $empresaCodigo)
                ->whereIn('subGrupoCodigo', $comGrupo->pluck('subGrupoCodigo')->map(fn (mixed $codigo): int => (int) $codigo))
                ->get()
                ->keyBy(fn (object $subgrupo): string => $subgrupo->grupoCodigo.'-'.$subgrupo->subGrupoCodigo);

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $agora = now();

        foreach ($comGrupo as $subgrupo) {
            $dados = $this->map($subgrupo, $empresaCodigo);
            $chave = $dados['grupoCodigo'].'-'.$dados['subGrupoCodigo'];
            $existente = $existentes->get($chave);

            if ($existente === null) {
                $connection->table('produto_subgrupos')->insert([
                    ...$dados,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
                $inserted++;

                continue;
            }

            if (! $this->changed($existente, $dados)) {
                $unchanged++;

                continue;
            }

            $connection->table('produto_subgrupos')
                ->where('empresaCodigo', $empresaCodigo)
                ->where('grupoCodigo', $dados['grupoCodigo'])
                ->where('subGrupoCodigo', $dados['subGrupoCodigo'])
                ->update([...$dados, 'updated_at' => $agora]);
            $updated++;
        }

        $missingParent = $validos->count() - $comGrupo->count();

        return [
            'database' => 'webposto',
            'table' => 'produto_subgrupos',
            'sync_status' => $this->status($inserted, $updated, $unchanged, $comGrupo->count()),
            'received' => count($resultados),
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'missing_parent_group' => $missingParent,
            'skipped' => count($resultados) - $validos->count() + $missingParent,
        ];
    }

    private function map(array $subgrupo, int $empresaCodigo): array
    {
        return [
            'empresaCodigo' => $empresaCodigo,
            'grupoCodigo' => (int) $subgrupo['grupoCodigo'],
            'subGrupoCodigo' => (int) $subgrupo['subGrupoCodigo'],
            'descricao' => is_scalar($subgrupo['descricao'] ?? null) ? (string) $subgrupo['descricao'] : null,
            'produtoSubGrupo2' => array_key_exists('produtoSubGrupo2', $subgrupo)
                ? json_encode($subgrupo['produtoSubGrupo2'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
        ];
    }

    private function changed(object $existente, array $dados): bool
    {
        foreach ($dados as $campo => $valor) {
            if ($campo === 'produtoSubGrupo2' && $this->sameJson($existente->{$campo} ?? null, $valor)) {
                continue;
            }

            if (($existente->{$campo} ?? null) != $valor) {
                return true;
            }
        }

        return false;
    }

    private function sameJson(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return json_decode((string) $left, true) == json_decode((string) $right, true);
    }

    private function status(int $inserted, int $updated, int $unchanged, int $valid): string
    {
        if ($valid === 0) {
            return 'no_valid_records';
        }

        return $inserted > 0 || $updated > 0
            ? 'synchronized'
            : ($unchanged > 0 ? 'already_synchronized' : 'no_valid_records');
    }
}
