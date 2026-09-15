<?php

namespace App\Services\Alterdata;

use Illuminate\Support\Facades\DB;

class EmpresaImporter
{
    public function import(array $empresas): array
    {
        $validas = collect($empresas)
            ->filter(fn (mixed $empresa): bool => is_array($empresa) && filled($empresa['id'] ?? null))
            ->unique(fn (array $empresa): string => (string) $empresa['id'])
            ->values();
        $connection = DB::connection('alterdata');
        $existentes = $connection->table('empresas')
            ->whereIn('alterdata_id', $validas->pluck('id')->map(fn (mixed $id): string => (string) $id))
            ->get()
            ->keyBy('alterdata_id');
        $agora = now();
        $inseridas = 0;
        $atualizadas = 0;
        $inalteradas = 0;

        $connection->transaction(function () use (
            $validas, $existentes, $connection, $agora, &$inseridas, &$atualizadas, &$inalteradas
        ): void {
            foreach ($validas as $empresa) {
                $dados = $this->mapear($empresa, $agora->toDateTimeString());
                $existente = $existentes->get($dados['alterdata_id']);

                if ($existente === null) {
                    $connection->table('empresas')->insert([...$dados, 'created_at' => $agora, 'updated_at' => $agora]);
                    $inseridas++;

                    continue;
                }

                if (! $this->mudou($existente, $dados)) {
                    $connection->table('empresas')->where('alterdata_id', $dados['alterdata_id'])
                        ->update(['ultima_consulta_em' => $agora]);
                    $inalteradas++;

                    continue;
                }

                $connection->table('empresas')->where('alterdata_id', $dados['alterdata_id'])
                    ->update([...$dados, 'updated_at' => $agora]);
                $atualizadas++;
            }
        });

        return [
            'recebidas' => count($empresas),
            'validas' => $validas->count(),
            'inseridas' => $inseridas,
            'atualizadas' => $atualizadas,
            'inalteradas' => $inalteradas,
            'ignoradas' => count($empresas) - $validas->count(),
        ];
    }

    private function mapear(array $empresa, string $consultadaEm): array
    {
        $atributos = is_array($empresa['attributes'] ?? null) ? $empresa['attributes'] : [];

        return [
            'alterdata_id' => (string) $empresa['id'],
            'externo_id' => $this->texto($atributos['externoid'] ?? null),
            'nome' => $this->texto($atributos['nome'] ?? null),
            'ativa' => $this->booleano($atributos['ativa'] ?? null),
            'cpf_cnpj' => $this->texto($atributos['cpfcnpj'] ?? null),
            'cpf_cnpj_alfanumerico' => $this->texto($atributos['cpfCnpjAlfanumerico'] ?? null),
            'tipo_movimento_permitido' => $this->texto($atributos['tipoMovimentoPermitido'] ?? null),
            'endereco' => $this->texto($atributos['endereco'] ?? null),
            'is_cpf' => $this->booleano($atributos['isCpf'] ?? null),
            'controla_transferencia_tomadores' => $this->booleano($atributos['controlaTransferenciaTomadores'] ?? null),
            'dados_origem' => json_encode($empresa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'ultima_consulta_em' => $consultadaEm,
        ];
    }

    private function mudou(object $existente, array $dados): bool
    {
        foreach ($dados as $campo => $valor) {
            if ($campo === 'ultima_consulta_em') {
                continue;
            }

            if ($campo === 'dados_origem' && $this->mesmoJson($existente->{$campo} ?? null, $valor)) {
                continue;
            }

            if (($existente->{$campo} ?? null) != $valor) {
                return true;
            }
        }

        return false;
    }

    private function mesmoJson(mixed $existente, string $recebido): bool
    {
        if (! is_string($existente)) {
            return false;
        }

        return json_decode($existente, true) == json_decode($recebido, true);
    }

    private function texto(mixed $valor): ?string
    {
        return is_scalar($valor) ? (string) $valor : null;
    }

    private function booleano(mixed $valor): ?bool
    {
        return is_bool($valor) || is_numeric($valor) ? (bool) $valor : null;
    }
}
