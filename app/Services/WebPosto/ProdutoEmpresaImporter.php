<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class ProdutoEmpresaImporter
{
    public function import(mixed $payload): array
    {
        $resultados = is_array($payload) && is_array($payload['resultados'] ?? null)
            ? $payload['resultados']
            : [];

        $validos = collect($resultados)
            ->filter(fn (mixed $registro): bool =>
                is_array($registro)
                && isset($registro['empresaCodigo'], $registro['produtoCodigo'])
                && is_numeric($registro['empresaCodigo'])
                && is_numeric($registro['produtoCodigo'])
            )
            ->unique(fn (array $registro): string =>
                (int) $registro['empresaCodigo'].'-'.(int) $registro['produtoCodigo']
            )
            ->values();

        $connection = DB::connection('webposto');
        $produtosExistentes = $validos->isEmpty()
            ? collect()
            : $connection->table('produtos')
                ->whereIn('empresaCodigo', $validos->pluck('empresaCodigo')->map(fn (mixed $codigo): int => (int) $codigo)->unique())
                ->whereIn('produtoCodigo', $validos->pluck('produtoCodigo')->map(fn (mixed $codigo): int => (int) $codigo)->unique())
                ->get(['empresaCodigo', 'produtoCodigo'])
                ->mapWithKeys(fn (object $produto): array => [
                    $produto->empresaCodigo.'-'.$produto->produtoCodigo => true,
                ]);
        $comProduto = $validos
            ->filter(fn (array $registro): bool => $produtosExistentes->has(
                (int) $registro['empresaCodigo'].'-'.(int) $registro['produtoCodigo'],
            ))
            ->values();

        $existentes = $comProduto->isEmpty()
            ? collect()
            : $connection->table('produto_empresas')
                ->whereIn('empresaCodigo', $comProduto->pluck('empresaCodigo')->map(fn (mixed $codigo): int => (int) $codigo)->unique())
                ->whereIn('produtoCodigo', $comProduto->pluck('produtoCodigo')->map(fn (mixed $codigo): int => (int) $codigo)->unique())
                ->get()
                ->keyBy(fn (object $registro): string => $registro->empresaCodigo.'-'.$registro->produtoCodigo);

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $agora = now();

        foreach ($comProduto as $registro) {
            $dados = $this->map($registro);
            $chave = $dados['empresaCodigo'].'-'.$dados['produtoCodigo'];
            $existente = $existentes->get($chave);

            if ($existente === null) {
                $connection->table('produto_empresas')->insert([
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

            $connection->table('produto_empresas')
                ->where('empresaCodigo', $dados['empresaCodigo'])
                ->where('produtoCodigo', $dados['produtoCodigo'])
                ->update([...$dados, 'updated_at' => $agora]);
            $updated++;
        }

        $missingProduct = $validos->count() - $comProduto->count();

        return [
            'database' => 'webposto',
            'table' => 'produto_empresas',
            'sync_status' => $this->status($inserted, $updated, $unchanged, $comProduto->count()),
            'received' => count($resultados),
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'missing_parent_product' => $missingProduct,
            'skipped' => count($resultados) - $validos->count() + $missingProduct,
        ];
    }

    private function map(array $registro): array
    {
        return [
            'empresaCodigo' => (int) $registro['empresaCodigo'],
            'produtoCodigo' => (int) $registro['produtoCodigo'],
            'precoVenda' => $this->number($registro['precoVenda'] ?? null),
            'precoVendaB' => $this->number($registro['precoVendaB'] ?? null),
            'precoVendaC' => $this->number($registro['precoVendaC'] ?? null),
            'precoCusto' => $this->number($registro['precoCusto'] ?? null),
            'estoqueQtde' => $this->number($registro['estoqueQtde'] ?? null),
            'estoqueMin' => $this->number($registro['estoqueMin'] ?? null),
            'ultimaAlteracao' => $this->string($registro['ultimaAlteracao'] ?? null),
            'ultimoUsuarioAlteracao' => $this->string($registro['ultimoUsuarioAlteracao'] ?? null),
            'produtoLmcCodigo' => $this->integer($registro['produtoLmcCodigo'] ?? null),
            'ativo' => is_bool($registro['ativo'] ?? null) ? $registro['ativo'] : null,
            'estoquePadrao' => $this->integer($registro['estoquePadrao'] ?? null),
            'fatorConversao' => $this->number($registro['fatorConversao'] ?? null),
            'codigo' => $this->integer($registro['codigo'] ?? null),
        ];
    }

    private function changed(object $existente, array $dados): bool
    {
        foreach ($dados as $campo => $valor) {
            if (($existente->{$campo} ?? null) != $valor) {
                return true;
            }
        }

        return false;
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
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
