<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class ProdutoGrupoImporter
{
    public function import(mixed $payload, int $empresaCodigo): array
    {
        $resultados = is_array($payload) && isset($payload['resultados']) && is_array($payload['resultados'])
            ? $payload['resultados']
            : [];

        $validos = collect($resultados)
            ->filter(fn (mixed $grupo): bool =>
                is_array($grupo) && isset($grupo['grupoCodigo']) && is_numeric($grupo['grupoCodigo'])
            )
            ->unique(fn (array $grupo): int => (int) $grupo['grupoCodigo'])
            ->values();

        $connection = DB::connection('webposto');
        $existentes = $validos->isEmpty()
            ? collect()
            : $connection->table('produto_grupos')
                ->where('empresaCodigo', $empresaCodigo)
                ->whereIn('grupoCodigo', $validos->pluck('grupoCodigo')->map(fn (mixed $codigo): int => (int) $codigo))
                ->get()
                ->keyBy('grupoCodigo');

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $agora = now();

        foreach ($validos as $grupo) {
            $dados = $this->map($grupo, $empresaCodigo);
            $codigo = $dados['grupoCodigo'];
            $existente = $existentes->get($codigo);

            if ($existente === null) {
                $connection->table('produto_grupos')->insert([
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

            $connection->table('produto_grupos')
                ->where('empresaCodigo', $empresaCodigo)
                ->where('grupoCodigo', $codigo)
                ->update([...$dados, 'updated_at' => $agora]);
            $updated++;
        }

        return [
            'database' => 'webposto',
            'table' => 'produto_grupos',
            'sync_status' => $this->status($inserted, $updated, $unchanged, $validos->count()),
            'received' => count($resultados),
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => count($resultados) - $validos->count(),
        ];
    }

    private function map(array $grupo, int $empresaCodigo): array
    {
        return [
            'empresaCodigo' => $empresaCodigo,
            'grupoCodigo' => (int) $grupo['grupoCodigo'],
            'nome' => $this->string($grupo['nome'] ?? null),
            'ultimoUsuarioAlteracao' => $this->string($grupo['ultimoUsuarioAlteracao'] ?? null),
            'grupoCodigoExterno' => $this->string($grupo['grupoCodigoExterno'] ?? null),
            'codigoTributoIcms' => $this->string($grupo['codigoTributoIcms'] ?? null),
            'codigoTributoPisCofins' => $this->string($grupo['codigoTributoPisCofins'] ?? null),
            'descricaoTributoIcms' => $this->string($grupo['descricaoTributoIcms'] ?? null),
            'descricaoTributoPisCofins' => $this->string($grupo['descricaoTributoPisCofins'] ?? null),
            'tipoGrupo' => $this->string($grupo['tipoGrupo'] ?? null),
            'codigo' => is_numeric($grupo['codigo'] ?? null) ? (int) $grupo['codigo'] : null,
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
