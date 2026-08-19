<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class EmpresaImporter
{
    public function import(mixed $payload): array
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

        if ($validos->isEmpty()) {
            return [
                'database' => 'webposto',
                'table' => 'empresas',
                'sync_status' => 'no_valid_records',
                'received' => count($resultados),
                'inserted' => 0,
                'updated' => 0,
                'unchanged' => 0,
                'skipped' => count($resultados),
            ];
        }

        $connection = DB::connection('webposto');
        $codigos = $validos->pluck('empresaCodigo')->map(fn (mixed $codigo): int => (int) $codigo);

        $existentes = $connection->table('empresas')
            ->whereIn('empresaCodigo', $codigos)
            ->get()
            ->keyBy('empresaCodigo');
        $agora = now();
        $inseridos = 0;
        $atualizados = 0;
        $inalterados = 0;

        foreach ($validos as $empresa) {
            $dados = $this->mapEmpresa($empresa);
            $codigo = $dados['empresaCodigo'];
            $existente = $existentes->get($codigo);

            if ($existente === null) {
                $connection->table('empresas')->insert([
                    ...$dados,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
                $inseridos++;

                continue;
            }

            if (! $this->changed($existente, $dados)) {
                $inalterados++;

                continue;
            }

            $connection->table('empresas')
                ->where('empresaCodigo', $codigo)
                ->update([...$dados, 'updated_at' => $agora]);
            $atualizados++;
        }

        return [
            'database' => 'webposto',
            'table' => 'empresas',
            'sync_status' => $this->syncStatus($inseridos, $atualizados, $inalterados),
            'received' => count($resultados),
            'inserted' => $inseridos,
            'updated' => $atualizados,
            'unchanged' => $inalterados,
            'skipped' => count($resultados) - $validos->count(),
        ];
    }

    private function syncStatus(int $inserted, int $updated, int $unchanged): string
    {
        if ($inserted > 0 || $updated > 0) {
            return 'synchronized';
        }

        return $unchanged > 0 ? 'already_synchronized' : 'no_valid_records';
    }

    private function changed(object $existente, array $dados): bool
    {
        foreach ($dados as $campo => $valor) {
            if ($campo === 'centroCustoPrincipal' && $this->sameJson($existente->{$campo} ?? null, $valor)) {
                continue;
            }

            if (($existente->{$campo} ?? null) != $valor) {
                return true;
            }
        }

        return false;
    }

    private function sameJson(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        return json_decode((string) $left, true) == json_decode((string) $right, true);
    }

    private function mapEmpresa(array $empresa): array
    {
        return [
            'empresaCodigo' => (int) $empresa['empresaCodigo'],
            'cnpj' => $this->string($empresa['cnpj'] ?? null),
            'razao' => $this->string($empresa['razao'] ?? null),
            'fantasia' => $this->string($empresa['fantasia'] ?? null),
            'tipoLogradouro' => $this->string($empresa['tipoLogradouro'] ?? null),
            'logradouro' => $this->string($empresa['logradouro'] ?? null),
            'endereco' => $this->string($empresa['endereco'] ?? null),
            'bairro' => $this->string($empresa['bairro'] ?? null),
            'numero' => $this->string($empresa['numero'] ?? null),
            'cep' => $this->string($empresa['cep'] ?? null),
            'cidade' => $this->string($empresa['cidade'] ?? null),
            'estado' => $this->string($empresa['estado'] ?? null),
            'latitude' => is_numeric($empresa['latitude'] ?? null) ? $empresa['latitude'] : null,
            'longitude' => is_numeric($empresa['longitude'] ?? null) ? $empresa['longitude'] : null,
            'ultimoUsuarioAlteracao' => $this->string($empresa['ultimoUsuarioAlteracao'] ?? null),
            'centroCustoPrincipal' => isset($empresa['centroCustoPrincipal'])
                ? json_encode($empresa['centroCustoPrincipal'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null,
            'empresaCodigoExterno' => $this->string($empresa['empresaCodigoExterno'] ?? null),
            'sigla' => $this->string($empresa['sigla'] ?? null),
            'tipoImposto' => $this->string($empresa['tipoImposto'] ?? null),
            'codigo' => is_numeric($empresa['codigo'] ?? null) ? (int) $empresa['codigo'] : null,
        ];
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }
}
