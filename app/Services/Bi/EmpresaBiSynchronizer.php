<?php

namespace App\Services\Bi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmpresaBiSynchronizer
{
    public function sync(mixed $payload): array
    {
        $resultados = is_array($payload) && isset($payload['resultados']) && is_array($payload['resultados'])
            ? $payload['resultados']
            : [];

        $validos = collect($resultados)
            ->filter(fn (mixed $empresa): bool =>
                is_array($empresa) && isset($empresa['empresaCodigo']) && is_numeric($empresa['empresaCodigo'])
            )
            ->unique(fn (array $empresa): int => (int) $empresa['empresaCodigo'])
            ->values();

        $connection = DB::connection('bi');
        $existentes = $validos->isEmpty()
            ? collect()
            : $connection->table('dim_empresas')
                ->whereIn('empresa_codigo', $validos->pluck('empresaCodigo')->map(fn (mixed $codigo): int => (int) $codigo))
                ->get()
                ->keyBy('empresa_codigo');

        $inserted = 0;
        $updated = 0;
        $unchanged = 0;
        $agora = now();

        foreach ($validos as $empresa) {
            $dados = $this->normalize($empresa);
            $codigo = $dados['empresa_codigo'];
            $existente = $existentes->get($codigo);

            if ($existente === null) {
                $connection->table('dim_empresas')->insert([
                    ...$dados,
                    'sincronizado_em' => $agora,
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

            $connection->table('dim_empresas')
                ->where('empresa_codigo', $codigo)
                ->update([
                    ...$dados,
                    'sincronizado_em' => $agora,
                    'updated_at' => $agora,
                ]);
            $updated++;
        }

        return [
            'database' => 'bi',
            'table' => 'dim_empresas',
            'sync_status' => $this->status($inserted, $updated, $unchanged, $validos->count()),
            'received' => count($resultados),
            'inserted' => $inserted,
            'updated' => $updated,
            'unchanged' => $unchanged,
            'skipped' => count($resultados) - $validos->count(),
        ];
    }

    private function normalize(array $empresa): array
    {
        return [
            'empresa_codigo' => (int) $empresa['empresaCodigo'],
            'cnpj' => $this->digits($empresa['cnpj'] ?? null, 14),
            'razao_social' => $this->text($empresa['razao'] ?? null),
            'nome_fantasia' => $this->text($empresa['fantasia'] ?? null),
            'logradouro' => $this->text($empresa['logradouro'] ?? $empresa['endereco'] ?? null),
            'numero' => $this->text($empresa['numero'] ?? null),
            'bairro' => $this->text($empresa['bairro'] ?? null),
            'cep' => $this->digits($empresa['cep'] ?? null, 8),
            'cidade' => $this->text($empresa['cidade'] ?? null),
            'estado' => $this->upper($empresa['estado'] ?? null, 2),
            'latitude' => is_numeric($empresa['latitude'] ?? null) ? (float) $empresa['latitude'] : null,
            'longitude' => is_numeric($empresa['longitude'] ?? null) ? (float) $empresa['longitude'] : null,
            'codigo_externo' => $this->text($empresa['empresaCodigoExterno'] ?? null),
            'sigla' => $this->upper($empresa['sigla'] ?? null, 50),
            'ativo' => true,
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

    private function text(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = Str::squish((string) $value);

        return $value === '' ? null : $value;
    }

    private function digits(mixed $value, int $length): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) ($value ?? ''));

        return strlen($digits) === $length ? $digits : null;
    }

    private function upper(mixed $value, int $maxLength): ?string
    {
        $value = $this->text($value);

        return $value === null ? null : mb_strtoupper(mb_substr($value, 0, $maxLength));
    }

    private function status(int $inserted, int $updated, int $unchanged, int $valid): string
    {
        if ($valid === 0) {
            return 'no_valid_records';
        }

        if ($inserted > 0 || $updated > 0) {
            return 'synchronized';
        }

        return $unchanged > 0 ? 'already_synchronized' : 'no_valid_records';
    }
}
