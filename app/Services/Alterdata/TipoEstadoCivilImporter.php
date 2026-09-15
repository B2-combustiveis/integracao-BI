<?php

namespace App\Services\Alterdata;

use Illuminate\Support\Facades\DB;

class TipoEstadoCivilImporter
{
    public function import(array $tipos): array
    {
        $validos = collect($tipos)
            ->filter(fn (mixed $item): bool => is_array($item)
                && array_key_exists('id', $item)
                && is_scalar($item['id'])
                && filled(data_get($item, 'attributes.descricao')))
            ->unique(fn (array $item): string => (string) $item['id'])
            ->values();
        $connection = DB::connection('alterdata');
        $existentes = $connection->table('tipos_estado_civil')
            ->whereIn('alterdata_id', $validos->pluck('id')->map(fn (mixed $id): string => (string) $id))
            ->pluck('dados_hash', 'alterdata_id');
        $agora = now();
        $novos = [];
        $alterados = [];

        foreach ($validos as $tipo) {
            $json = json_encode($tipo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $dados = [
                'alterdata_id' => (string) $tipo['id'],
                'descricao' => (string) data_get($tipo, 'attributes.descricao'),
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
            $connection->table('tipos_estado_civil')
                ->whereIn('alterdata_id', $validos->pluck('id')->map(fn (mixed $id): string => (string) $id))
                ->update(['ultima_consulta_em' => $agora]);
            if ($novos !== []) {
                $connection->table('tipos_estado_civil')->insert($novos);
            }
            if ($alterados !== []) {
                $connection->table('tipos_estado_civil')->upsert(
                    $alterados,
                    ['alterdata_id'],
                    ['descricao', 'dados_origem', 'dados_hash', 'ultima_consulta_em', 'updated_at'],
                );
            }
        });

        return [
            'recebidos' => count($tipos),
            'validos' => $validos->count(),
            'inseridos' => count($novos),
            'atualizados' => count($alterados),
            'inalterados' => $validos->count() - count($novos) - count($alterados),
            'ignorados' => count($tipos) - $validos->count(),
        ];
    }
}
