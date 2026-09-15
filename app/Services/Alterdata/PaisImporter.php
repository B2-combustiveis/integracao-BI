<?php

namespace App\Services\Alterdata;

use Illuminate\Support\Facades\DB;

class PaisImporter
{
    public function import(array $paises): array
    {
        $validos = collect($paises)
            ->filter(fn (mixed $item): bool => is_array($item)
                && array_key_exists('id', $item)
                && is_scalar($item['id'])
                && filled(data_get($item, 'attributes.nome')))
            ->unique(fn (array $item): string => (string) $item['id'])
            ->values();
        $connection = DB::connection('alterdata');
        $existentes = $connection->table('paises')
            ->whereIn('alterdata_id', $validos->pluck('id')->map(fn (mixed $id): string => (string) $id))
            ->pluck('dados_hash', 'alterdata_id');
        $agora = now();
        $novos = [];
        $alterados = [];

        foreach ($validos as $pais) {
            $json = json_encode($pais, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $dados = [
                'alterdata_id' => (string) $pais['id'],
                'sigla' => is_scalar(data_get($pais, 'attributes.sigla')) ? (string) data_get($pais, 'attributes.sigla') : null,
                'nome' => (string) data_get($pais, 'attributes.nome'),
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
            $connection->table('paises')
                ->whereIn('alterdata_id', $validos->pluck('id')->map(fn (mixed $id): string => (string) $id))
                ->update(['ultima_consulta_em' => $agora]);
            if ($novos !== []) {
                $connection->table('paises')->insert($novos);
            }
            if ($alterados !== []) {
                $connection->table('paises')->upsert(
                    $alterados,
                    ['alterdata_id'],
                    ['sigla', 'nome', 'dados_origem', 'dados_hash', 'ultima_consulta_em', 'updated_at'],
                );
            }
        });

        return [
            'recebidos' => count($paises),
            'validos' => $validos->count(),
            'inseridos' => count($novos),
            'atualizados' => count($alterados),
            'inalterados' => $validos->count() - count($novos) - count($alterados),
            'ignorados' => count($paises) - $validos->count(),
        ];
    }
}
