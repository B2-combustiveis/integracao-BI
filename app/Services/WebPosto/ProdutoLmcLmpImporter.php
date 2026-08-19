<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class ProdutoLmcLmpImporter
{
    public function import(mixed $payload, int $empresaCodigo): array
    {
        $resultados = is_array($payload) && array_is_list($payload) ? $payload : [];
        $validos = collect($resultados)
            ->filter(fn (mixed $produto): bool =>
                is_array($produto)
                && isset($produto['produtoLmcCodigo'])
                && is_numeric($produto['produtoLmcCodigo'])
            )
            ->unique(fn (array $produto): int => (int) $produto['produtoLmcCodigo'])
            ->values();

        $connection = DB::connection('webposto');
        $existentes = $validos->isEmpty()
            ? collect()
            : $connection->table('produto_lmc_lmp')
                ->where('empresaCodigo', $empresaCodigo)
                ->whereIn('produtoLmcCodigo', $validos->pluck('produtoLmcCodigo')->map(fn (mixed $codigo): int => (int) $codigo))
                ->get()
                ->keyBy('produtoLmcCodigo');

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $agora = now();

        foreach ($validos as $produto) {
            $dados = $this->map($produto, $empresaCodigo);
            $existente = $existentes->get($dados['produtoLmcCodigo']);

            if ($existente === null) {
                $connection->table('produto_lmc_lmp')->insert([
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

            $connection->table('produto_lmc_lmp')
                ->where('empresaCodigo', $empresaCodigo)
                ->where('produtoLmcCodigo', $dados['produtoLmcCodigo'])
                ->update([...$dados, 'updated_at' => $agora]);
            $updated++;
        }

        return [
            'database' => 'webposto',
            'table' => 'produto_lmc_lmp',
            'sync_status' => $this->status($inserted, $updated, $unchanged, $validos->count()),
            'received' => count($resultados),
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => count($resultados) - $validos->count(),
        ];
    }

    private function map(array $produto, int $empresaCodigo): array
    {
        return [
            'empresaCodigo' => $empresaCodigo,
            'produtoLmcCodigo' => (int) $produto['produtoLmcCodigo'],
            'sequencia' => is_numeric($produto['sequencia'] ?? null) ? (int) $produto['sequencia'] : null,
            'descricao' => is_scalar($produto['descricao'] ?? null) ? (string) $produto['descricao'] : null,
            'tipoCombustivel' => is_scalar($produto['tipoCombustivel'] ?? null) ? (string) $produto['tipoCombustivel'] : null,
            'geraLmcLmp' => is_scalar($produto['geraLmcLmp'] ?? null) ? (string) $produto['geraLmcLmp'] : null,
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
