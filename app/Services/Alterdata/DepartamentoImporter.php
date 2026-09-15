<?php

namespace App\Services\Alterdata;

use Illuminate\Support\Facades\DB;

class DepartamentoImporter
{
    public function import(array $departamentos): array
    {
        $validos = collect($departamentos)
            ->filter(fn (mixed $item): bool => is_array($item)
                && filled($item['id'] ?? null)
                && filled(data_get($item, 'relationships.empresa.data.id')))
            ->unique(fn (array $item): string => (string) $item['id'])
            ->values();
        $connection = DB::connection('alterdata');
        $existentes = $connection->table('departamentos')
            ->whereIn('alterdata_id', $validos->pluck('id')->map(fn (mixed $id): string => (string) $id))
            ->pluck('dados_hash', 'alterdata_id');
        $agora = now();
        $novos = [];
        $alterados = [];

        foreach ($validos as $departamento) {
            $dados = $this->mapear($departamento, $agora->toDateTimeString());
            $hashExistente = $existentes->get($dados['alterdata_id']);

            if ($hashExistente === null) {
                $novos[] = [...$dados, 'created_at' => $agora, 'updated_at' => $agora];
            } elseif (! hash_equals((string) $hashExistente, $dados['dados_hash'])) {
                $alterados[] = [...$dados, 'updated_at' => $agora];
            }
        }

        $connection->transaction(function () use ($connection, $validos, $novos, $alterados, $agora): void {
            $ids = $validos->pluck('id')->map(fn (mixed $id): string => (string) $id);
            $connection->table('departamentos')->whereIn('alterdata_id', $ids)
                ->update(['ultima_consulta_em' => $agora]);
            if ($novos !== []) {
                $connection->table('departamentos')->insert($novos);
            }
            if ($alterados !== []) {
                $connection->table('departamentos')->upsert(
                    $alterados,
                    ['alterdata_id'],
                    array_keys(collect($alterados[0])->except(['id', 'alterdata_id', 'created_at'])->all()),
                );
            }
        });

        return [
            'recebidos' => count($departamentos),
            'validos' => $validos->count(),
            'inseridos' => count($novos),
            'atualizados' => count($alterados),
            'inalterados' => $validos->count() - count($novos) - count($alterados),
            'ignorados' => count($departamentos) - $validos->count(),
        ];
    }

    private function mapear(array $item, string $consultadoEm): array
    {
        $atributos = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
        $json = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'alterdata_id' => (string) $item['id'],
            'empresa_alterdata_id' => (string) data_get($item, 'relationships.empresa.data.id'),
            'externo_id' => $this->texto($atributos['externoid'] ?? null),
            'nome' => $this->texto($atributos['nome'] ?? null),
            'cei' => $this->texto($atributos['cei'] ?? null),
            'dados_origem' => $json,
            'dados_hash' => hash('sha256', $json),
            'ultima_consulta_em' => $consultadoEm,
        ];
    }

    private function texto(mixed $valor): ?string
    {
        return is_scalar($valor) ? (string) $valor : null;
    }
}
