<?php

namespace App\Services\Alterdata;

use Illuminate\Support\Facades\DB;

class EstadoImporter
{
    public function import(array $estados): array
    {
        $validos = collect($estados)
            ->filter(fn (mixed $item): bool => is_array($item)
                && array_key_exists('id', $item)
                && is_scalar($item['id'])
                && filled(data_get($item, 'attributes.nome')))
            ->unique(fn (array $item): string => (string) $item['id'])
            ->values();
        $connection = DB::connection('alterdata');
        $existentes = $connection->table('estados')
            ->whereIn('alterdata_id', $validos->pluck('id')->map(fn (mixed $id): string => (string) $id))
            ->pluck('dados_hash', 'alterdata_id');
        $agora = now();
        $novos = [];
        $alterados = [];

        foreach ($validos as $estado) {
            $json = json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $dados = [
                'alterdata_id' => (string) $estado['id'],
                'sigla' => is_scalar(data_get($estado, 'attributes.sigla')) ? (string) data_get($estado, 'attributes.sigla') : null,
                'nome' => (string) data_get($estado, 'attributes.nome'),
                'dados_origem' => $json,
                'dados_hash' => hash('sha256', $json),
                'ultima_consulta_em' => $agora,
            ];
            $hashExistente = $existentes->get($dados['alterdata_id']);

            if ($hashExistente === null) {
                $novos[] = [...$dados, 'created_at' => $agora, 'updated_at' => $agora];
            } elseif (! hash_equals((string) $hashExistente, $dados['dados_hash'])) {
                $alterados[] = [...$dados, 'updated_at' => $agora];
            }
        }

        $connection->transaction(function () use ($connection, $validos, $novos, $alterados, $agora): void {
            $connection->table('estados')
                ->whereIn('alterdata_id', $validos->pluck('id')->map(fn (mixed $id): string => (string) $id))
                ->update(['ultima_consulta_em' => $agora]);
            if ($novos !== []) {
                $connection->table('estados')->insert($novos);
            }
            if ($alterados !== []) {
                $connection->table('estados')->upsert(
                    $alterados,
                    ['alterdata_id'],
                    ['sigla', 'nome', 'dados_origem', 'dados_hash', 'ultima_consulta_em', 'updated_at'],
                );
            }
        });

        return [
            'recebidos' => count($estados),
            'validos' => $validos->count(),
            'inseridos' => count($novos),
            'atualizados' => count($alterados),
            'inalterados' => $validos->count() - count($novos) - count($alterados),
            'ignorados' => count($estados) - $validos->count(),
        ];
    }
}
