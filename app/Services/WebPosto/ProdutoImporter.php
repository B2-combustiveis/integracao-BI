<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class ProdutoImporter
{
    public function import(mixed $payload, int $empresaCodigo): array
    {
        $resultados = is_array($payload) && is_array($payload['resultados'] ?? null)
            ? $payload['resultados']
            : [];

        $validos = collect($resultados)
            ->filter(fn (mixed $produto): bool =>
                is_array($produto)
                && isset($produto['produtoCodigo'], $produto['grupoCodigo'])
                && is_numeric($produto['produtoCodigo'])
                && is_numeric($produto['grupoCodigo'])
            )
            ->unique(fn (array $produto): int => (int) $produto['produtoCodigo'])
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
            ->filter(fn (array $produto): bool => isset($gruposLookup[(int) $produto['grupoCodigo']]))
            ->values();

        $subgruposExistentes = $comGrupo->isEmpty()
            ? collect()
            : $connection->table('produto_subgrupos')
                ->where('empresaCodigo', $empresaCodigo)
                ->get(['grupoCodigo', 'subGrupoCodigo'])
                ->mapWithKeys(fn (object $subgrupo): array => [
                    $subgrupo->grupoCodigo.'-'.$subgrupo->subGrupoCodigo => true,
                ]);
        $comRelacionamentos = $comGrupo
            ->filter(function (array $produto) use ($subgruposExistentes): bool {
                if (! is_numeric($produto['subGrupo1Codigo'] ?? null)) {
                    return true;
                }

                return $subgruposExistentes->has(
                    (int) $produto['grupoCodigo'].'-'.(int) $produto['subGrupo1Codigo'],
                );
            })
            ->values();

        $existentes = $comRelacionamentos->isEmpty()
            ? collect()
            : $connection->table('produtos')
                ->where('empresaCodigo', $empresaCodigo)
                ->whereIn('produtoCodigo', $comRelacionamentos->pluck('produtoCodigo')->map(fn (mixed $codigo): int => (int) $codigo))
                ->get()
                ->keyBy('produtoCodigo');

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $agora = now();

        foreach ($comRelacionamentos as $produto) {
            $dados = $this->map($produto, $empresaCodigo);
            $existente = $existentes->get($dados['produtoCodigo']);

            if ($existente === null) {
                $connection->table('produtos')->insert([
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

            $connection->table('produtos')
                ->where('empresaCodigo', $empresaCodigo)
                ->where('produtoCodigo', $dados['produtoCodigo'])
                ->update([...$dados, 'updated_at' => $agora]);
            $updated++;
        }

        $missingParent = $validos->count() - $comGrupo->count();
        $missingSubgroup = $comGrupo->count() - $comRelacionamentos->count();

        return [
            'database' => 'webposto',
            'table' => 'produtos',
            'sync_status' => $this->status($inserted, $updated, $unchanged, $comRelacionamentos->count()),
            'received' => count($resultados),
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'missing_parent_group' => $missingParent,
            'missing_parent_subgroup' => $missingSubgroup,
            'skipped' => count($resultados) - $validos->count() + $missingParent + $missingSubgroup,
        ];
    }

    private function map(array $produto, int $empresaCodigo): array
    {
        return [
            'empresaCodigo' => $empresaCodigo,
            'produtoCodigo' => (int) $produto['produtoCodigo'],
            'nome' => $this->string($produto['nome'] ?? null),
            'referenciaCodigo' => $this->string($produto['referenciaCodigo'] ?? null),
            'grupoCodigo' => (int) $produto['grupoCodigo'],
            'combustivel' => $this->boolean($produto['combustivel'] ?? null),
            'produtoLmcCodigo' => $this->integer($produto['produtoLmcCodigo'] ?? null),
            'tipoCombustivel' => $this->string($produto['tipoCombustivel'] ?? null),
            'unidadeCompra' => $this->string($produto['unidadeCompra'] ?? null),
            'unidadeVenda' => $this->string($produto['unidadeVenda'] ?? null),
            'subGrupo1Codigo' => $this->integer($produto['subGrupo1Codigo'] ?? null),
            'subGrupo2Codigo' => $this->integer($produto['subGrupo2Codigo'] ?? null),
            'subGrupo3Codigo' => $this->integer($produto['subGrupo3Codigo'] ?? null),
            'produtoCodigoExterno' => $this->string($produto['produtoCodigoExterno'] ?? null),
            'tributacaoAdRem' => $this->number($produto['tributacaoAdRem'] ?? null),
            'tipoProduto' => $this->string($produto['tipoProduto'] ?? null),
            'descricaoFabricante' => $this->string($produto['descricaoFabricante'] ?? null),
            'registraInventario' => $this->string($produto['registraInventario'] ?? null),
            'ncm' => $this->string($produto['ncm'] ?? null),
            'cest' => $this->string($produto['cest'] ?? null),
            'misturaBioCombustivel' => $this->number($produto['misturaBioCombustivel'] ?? null),
            'codigoAnp' => $this->string($produto['codigoAnp'] ?? null),
            'percUfOrigemScanc' => $this->number($produto['percUfOrigemScanc'] ?? null),
            'produtoCodigoBarra' => array_key_exists('produtoCodigoBarra', $produto)
                ? json_encode($produto['produtoCodigoBarra'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'codigo' => $this->integer($produto['codigo'] ?? null),
            'ativo' => $this->boolean($produto['ativo'] ?? null),
        ];
    }

    private function changed(object $existente, array $dados): bool
    {
        foreach ($dados as $campo => $valor) {
            if ($campo === 'produtoCodigoBarra' && $this->sameJson($existente->{$campo} ?? null, $valor)) {
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

    private function string(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function number(mixed $value): int|float|null
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function boolean(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
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
