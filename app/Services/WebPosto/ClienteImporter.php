<?php

namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class ClienteImporter
{
    private const JSON_FIELDS = ['centroCustoVeiculo', 'clienteContato', 'frota', 'faturamento', 'limitesBloqueios'];

    public function import(mixed $payload, int $empresaCodigo): array
    {
        $resultados = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
        $validos = collect($resultados)
            ->filter(fn (mixed $cliente): bool => is_array($cliente) && isset($cliente['clienteCodigo']) && is_numeric($cliente['clienteCodigo']))
            ->unique(fn (array $cliente): int => (int) $cliente['clienteCodigo'])
            ->values();
        $connection = DB::connection('webposto');
        $grupos = $connection->table('cliente_grupos')->where('empresaCodigo', $empresaCodigo)
            ->pluck('grupoCodigo')->map(fn (mixed $codigo): int => (int) $codigo)->flip();
        $comGrupoValido = $validos->filter(function (array $cliente) use ($grupos): bool {
            $codigo = is_numeric($cliente['clienteGrupoCodigo'] ?? null) ? (int) $cliente['clienteGrupoCodigo'] : 0;
            return $codigo <= 0 || $grupos->has($codigo);
        })->values();
        $existentes = $comGrupoValido->isEmpty() ? collect() : $connection->table('clientes')
            ->where('empresaCodigo', $empresaCodigo)
            ->whereIn('clienteCodigo', $comGrupoValido->pluck('clienteCodigo')->map(fn (mixed $codigo): int => (int) $codigo))
            ->get()->keyBy('clienteCodigo');

        $inserted = $updated = $unchanged = 0;
        $agora = now();
        foreach ($comGrupoValido as $cliente) {
            $dados = $this->map($cliente, $empresaCodigo);
            $existente = $existentes->get($dados['clienteCodigo']);
            if ($existente === null) {
                $connection->table('clientes')->insert([...$dados, 'created_at' => $agora, 'updated_at' => $agora]);
                $inserted++;
                continue;
            }
            if (! $this->changed($existente, $dados)) {
                $unchanged++;
                continue;
            }
            $connection->table('clientes')->where('empresaCodigo', $empresaCodigo)
                ->where('clienteCodigo', $dados['clienteCodigo'])->update([...$dados, 'updated_at' => $agora]);
            $updated++;
        }
        $missingGroup = $validos->count() - $comGrupoValido->count();

        return [
            'database' => 'webposto', 'table' => 'clientes',
            'sync_status' => $this->status($inserted, $updated, $unchanged, $comGrupoValido->count()),
            'received' => count($resultados), 'inserted' => $inserted, 'updated' => $updated,
            'unchanged' => $unchanged, 'missing_parent_group' => $missingGroup,
            'skipped' => count($resultados) - $validos->count() + $missingGroup,
        ];
    }

    private function map(array $c, int $empresaCodigo): array
    {
        $grupo = is_numeric($c['clienteGrupoCodigo'] ?? null) && (int) $c['clienteGrupoCodigo'] > 0
            ? (int) $c['clienteGrupoCodigo'] : null;
        $data = ['empresaCodigo' => $empresaCodigo, 'clienteCodigo' => (int) $c['clienteCodigo']];
        foreach (['clienteReferencia','razao','fantasia','cnpjCpf','dataCadastro','cidade','bairro','numero','logradouro','tipoLogradouro','uf','ultimoUsuarioAlteracao','dataHoraAtualizacao','clienteCodigoExterno','telefone','celular','outroTelefone','observacoes','website','complemento','cep','pais','inscricaoEstadual','inscricaoMunicipal','rg'] as $field) {
            $data[$field] = is_scalar($c[$field] ?? null) ? (string) $c[$field] : null;
        }
        $data['codigoCidade'] = is_numeric($c['codigoCidade'] ?? null) ? (int) $c['codigoCidade'] : null;
        foreach (['usaLimiteLitros','usaLimiteReais','bloqueado'] as $field) {
            $data[$field] = is_bool($c[$field] ?? null) ? $c[$field] : null;
        }
        foreach (['limiteLitros','limiteReais'] as $field) {
            $data[$field] = is_numeric($c[$field] ?? null) ? (float) $c[$field] : null;
        }
        $data['clienteGrupoCodigo'] = $grupo;
        foreach (self::JSON_FIELDS as $field) {
            $data[$field] = array_key_exists($field, $c) && $c[$field] !== null
                ? json_encode($c[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }
        $data['codigo'] = is_numeric($c['codigo'] ?? null) ? (int) $c['codigo'] : null;
        return $data;
    }

    private function changed(object $existing, array $data): bool
    {
        foreach ($data as $field => $value) {
            if (in_array($field, self::JSON_FIELDS, true) && $this->sameJson($existing->{$field} ?? null, $value)) continue;
            if (($existing->{$field} ?? null) != $value) return true;
        }
        return false;
    }

    private function sameJson(mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) return $a === $b;
        return json_decode((string) $a, true) == json_decode((string) $b, true);
    }

    private function status(int $i, int $u, int $n, int $valid): string
    {
        if ($valid === 0) return 'no_valid_records';
        return $i > 0 || $u > 0 ? 'synchronized' : ($n > 0 ? 'already_synchronized' : 'no_valid_records');
    }
}
