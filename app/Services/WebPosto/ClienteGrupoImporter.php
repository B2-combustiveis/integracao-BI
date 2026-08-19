<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class ClienteGrupoImporter
{
    public function import(mixed $payload, int $empresaCodigo): array
    {
        $resultados = is_array($payload) && is_array($payload['resultados'] ?? null)
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
            : $connection->table('cliente_grupos')
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
            $existente = $existentes->get($dados['grupoCodigo']);

            if ($existente === null) {
                $connection->table('cliente_grupos')->insert([
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

            $connection->table('cliente_grupos')
                ->where('empresaCodigo', $empresaCodigo)
                ->where('grupoCodigo', $dados['grupoCodigo'])
                ->update([...$dados, 'updated_at' => $agora]);
            $updated++;
        }

        return [
            'database' => 'webposto',
            'table' => 'cliente_grupos',
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
            'grupoCodigoExterno' => $this->string($grupo['grupoCodigoExterno'] ?? null),
            'descricao' => $this->string($grupo['descricao'] ?? null),
            'usaLimiteLitros' => $this->boolean($grupo['usaLimiteLitros'] ?? null),
            'limiteLitros' => $this->number($grupo['limiteLitros'] ?? null),
            'limiteLitrosDisponivel' => $this->number($grupo['limiteLitrosDisponivel'] ?? null),
            'usaLimiteReais' => $this->boolean($grupo['usaLimiteReais'] ?? null),
            'limiteReais' => $this->number($grupo['limiteReais'] ?? null),
            'limiteReaisDisponivel' => $this->number($grupo['limiteReaisDisponivel'] ?? null),
            'bloqueadoFinanceiroVencido' => $this->boolean($grupo['bloqueadoFinanceiroVencido'] ?? null),
            'diasTolerancia' => is_numeric($grupo['diasTolerancia'] ?? null) ? (int) $grupo['diasTolerancia'] : null,
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

    private function number(mixed $value): ?float
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
