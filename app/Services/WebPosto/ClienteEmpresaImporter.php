<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class ClienteEmpresaImporter
{
    public function import(mixed $payload): array
    {
        $resultados = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
        $validos = collect($resultados)->filter(fn (mixed $item): bool =>
            is_array($item) && isset($item['empresaCodigo'], $item['clienteCodigo'])
            && is_numeric($item['empresaCodigo']) && is_numeric($item['clienteCodigo'])
        )->unique(fn (array $item): string => (int) $item['empresaCodigo'].'-'.(int) $item['clienteCodigo'])->values();

        $connection = DB::connection('webposto');
        $clientes = $validos->isEmpty() ? collect() : $connection->table('clientes')
            ->whereIn('empresaCodigo', $validos->pluck('empresaCodigo')->map(fn (mixed $v): int => (int) $v)->unique())
            ->whereIn('clienteCodigo', $validos->pluck('clienteCodigo')->map(fn (mixed $v): int => (int) $v)->unique())
            ->get(['empresaCodigo', 'clienteCodigo'])->mapWithKeys(fn (object $item): array => [
                $item->empresaCodigo.'-'.$item->clienteCodigo => true,
            ]);
        $comCliente = $validos->filter(fn (array $item): bool => $clientes->has(
            (int) $item['empresaCodigo'].'-'.(int) $item['clienteCodigo']
        ))->values();
        $existentes = $comCliente->isEmpty() ? collect() : $connection->table('cliente_empresas')
            ->whereIn('empresaCodigo', $comCliente->pluck('empresaCodigo')->map(fn (mixed $v): int => (int) $v)->unique())
            ->whereIn('clienteCodigo', $comCliente->pluck('clienteCodigo')->map(fn (mixed $v): int => (int) $v)->unique())
            ->get()->keyBy(fn (object $item): string => $item->empresaCodigo.'-'.$item->clienteCodigo);

        $inserted = $updated = $unchanged = 0;
        $agora = now();
        foreach ($comCliente as $item) {
            $dados = [
                'empresaCodigo' => (int) $item['empresaCodigo'],
                'clienteCodigo' => (int) $item['clienteCodigo'],
                'ativoInativo' => is_bool($item['ativoInativo'] ?? null) ? $item['ativoInativo'] : null,
                'usaPrazo' => is_bool($item['usaPrazo'] ?? null) ? $item['usaPrazo'] : null,
                'codigo' => is_numeric($item['codigo'] ?? null) ? (int) $item['codigo'] : null,
            ];
            $chave = $dados['empresaCodigo'].'-'.$dados['clienteCodigo'];
            $existente = $existentes->get($chave);
            if ($existente === null) {
                $connection->table('cliente_empresas')->insert([...$dados, 'created_at' => $agora, 'updated_at' => $agora]);
                $inserted++;
                continue;
            }
            if (! $this->changed($existente, $dados)) {
                $unchanged++;
                continue;
            }
            $connection->table('cliente_empresas')->where('empresaCodigo', $dados['empresaCodigo'])
                ->where('clienteCodigo', $dados['clienteCodigo'])->update([...$dados, 'updated_at' => $agora]);
            $updated++;
        }
        $missing = $validos->count() - $comCliente->count();
        return [
            'database' => 'webposto', 'table' => 'cliente_empresas',
            'sync_status' => $this->status($inserted, $updated, $unchanged, $comCliente->count()),
            'received' => count($resultados), 'inserted' => $inserted, 'updated' => $updated,
            'unchanged' => $unchanged, 'missing_parent_client' => $missing,
            'skipped' => count($resultados) - $validos->count() + $missing,
        ];
    }

    private function changed(object $existing, array $data): bool
    {
        foreach ($data as $field => $value) if (($existing->{$field} ?? null) != $value) return true;
        return false;
    }

    private function status(int $i, int $u, int $n, int $valid): string
    {
        if ($valid === 0) return 'no_valid_records';
        return $i > 0 || $u > 0 ? 'synchronized' : ($n > 0 ? 'already_synchronized' : 'no_valid_records');
    }
}
