<?php

namespace App\Services\Alterdata;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

class FuncionarioImporter
{
    public function import(array $funcionarios): array
    {
        $validos = collect($funcionarios)
            ->filter(fn (mixed $item): bool => is_array($item)
                && filled($item['id'] ?? null)
                && filled(data_get($item, 'relationships.empresa.data.id')))
            ->unique(fn (array $item): string => (string) $item['id'])
            ->values();
        $connection = DB::connection('alterdata');
        $existentes = $connection->table('funcionarios')
            ->whereIn('alterdata_id', $validos->pluck('id')->map(fn (mixed $id): string => (string) $id))
            ->pluck('dados_hash', 'alterdata_id');
        $agora = now();
        $novos = [];
        $alterados = [];

        foreach ($validos as $funcionario) {
            $dados = $this->mapear($funcionario, $agora->toDateTimeString());
            $hashExistente = $existentes->get($dados['alterdata_id']);
            if ($hashExistente === null) {
                $novos[] = [...$dados, 'created_at' => $agora, 'updated_at' => $agora];
            } elseif (! hash_equals((string) $hashExistente, $dados['dados_hash'])) {
                $alterados[] = [...$dados, 'updated_at' => $agora];
            }
        }

        $connection->transaction(function () use ($connection, $validos, $novos, $alterados, $agora): void {
            $validos->pluck('id')->chunk(500)->each(fn ($ids) => $connection->table('funcionarios')
                ->whereIn('alterdata_id', $ids->map(fn (mixed $id): string => (string) $id))
                ->update(['ultima_consulta_em' => $agora]));

            collect($novos)->chunk(250)->each(fn ($linhas) => $connection->table('funcionarios')->insert($linhas->all()));
            collect($alterados)->chunk(250)->each(fn ($linhas) => $connection->table('funcionarios')->upsert(
                $linhas->all(),
                ['alterdata_id'],
                array_keys(collect($linhas->first())->except(['id', 'alterdata_id', 'created_at'])->all()),
            ));
        });

        return [
            'recebidos' => count($funcionarios),
            'validos' => $validos->count(),
            'inseridos' => count($novos),
            'atualizados' => count($alterados),
            'inalterados' => $validos->count() - count($novos) - count($alterados),
            'ignorados' => count($funcionarios) - $validos->count(),
        ];
    }

    private function mapear(array $item, string $consultadoEm): array
    {
        $a = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];
        $json = json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'alterdata_id' => (string) $item['id'],
            'empresa_alterdata_id' => (string) data_get($item, 'relationships.empresa.data.id'),
            'externo_id' => $this->texto($a['externoid'] ?? null),
            'codigo' => $this->texto($a['codigo'] ?? null),
            'nome' => $this->texto($a['nome'] ?? null),
            'status' => $this->texto($a['status'] ?? null),
            'cpf' => $this->texto($a['cpf'] ?? null),
            'pis' => $this->texto($a['pis'] ?? null),
            'matricula_esocial' => $this->texto($a['matriculaESocial'] ?? null),
            'nascimento' => $this->data($a['nascimento'] ?? null),
            'admissao' => $this->data($a['admissao'] ?? null),
            'demissao' => $this->data($a['demissao'] ?? null),
            'afastamento' => $this->data($a['afastamento'] ?? null),
            'retorno' => $this->data($a['retorno'] ?? null),
            'data_atualizacao' => $this->dataHora($a['dataAtualizacao'] ?? null),
            'salario_base' => is_numeric($a['salarioBase'] ?? null) ? $a['salarioBase'] : null,
            'nome_funcao' => $this->texto($a['nomefuncao'] ?? null),
            'departamento_id' => $this->relacaoId($item, 'departamento'),
            'departamento_nome' => $this->texto(data_get($item, '_included.departamento.attributes.nome')),
            'sexo_id' => $this->relacaoId($item, 'sexo'),
            'sexo_descricao' => $this->texto(data_get($item, '_included.sexo.attributes.descricao')),
            'estado_civil_id' => $this->relacaoId($item, 'estadocivil'),
            'estado_civil_descricao' => $this->texto(data_get($item, '_included.estadocivil.attributes.descricao')),
            'nacionalidade_pais_id' => $this->relacaoId($item, 'nacionalidade'),
            'nacionalidade_pais_nome' => $this->texto(data_get($item, '_included.nacionalidade.attributes.nome')),
            'naturalidade_estado_id' => $this->relacaoId($item, 'naturalidade'),
            'naturalidade_estado_nome' => $this->texto(data_get($item, '_included.naturalidade.attributes.nome')),
            'forma_pagamento_id' => $this->relacaoId($item, 'formadepagamento'),
            'forma_pagamento_descricao' => $this->texto(data_get($item, '_included.formadepagamento.attributes.descricao')),
            'tipo_conta_id' => $this->relacaoId($item, 'tipoDeConta'),
            'tipo_conta_descricao' => $this->texto(data_get($item, '_included.tipoDeConta.attributes.descricao')),
            'tipo_chave_pix_id' => $this->relacaoId($item, 'tipoDeChavePix'),
            'tipo_chave_pix_descricao' => $this->texto(data_get($item, '_included.tipoDeChavePix.attributes.descricao')),
            'email' => $this->texto($a['email'] ?? null),
            'telefone' => $this->texto($a['telefone'] ?? null),
            'telefone_celular' => $this->texto($a['telefonecelular'] ?? null),
            'cidade' => $this->texto($a['cidade'] ?? null),
            'bairro' => $this->texto($a['bairro'] ?? null),
            'rua' => $this->texto($a['rua'] ?? null),
            'numero' => $this->texto($a['numero'] ?? null),
            'complemento' => $this->texto($a['complemento'] ?? null),
            'cep' => $this->texto($a['cep'] ?? null),
            'endereco_estado_id' => $this->relacaoId($item, 'estado'),
            'endereco_estado_nome' => $this->texto(data_get($item, '_included.estado.attributes.nome')),
            'dados_origem' => $json,
            'dados_hash' => hash('sha256', $json),
            'ultima_consulta_em' => $consultadoEm,
        ];
    }

    private function relacaoId(array $item, string $nome): ?string
    {
        return $this->texto(data_get($item, "relationships.{$nome}.data.id"));
    }

    private function data(mixed $valor): ?string
    {
        return $this->converterData($valor, 'Y-m-d');
    }

    private function dataHora(mixed $valor): ?string
    {
        return $this->converterData($valor, 'Y-m-d H:i:s.u');
    }

    private function converterData(mixed $valor, string $formato): ?string
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($valor)->utc()->format($formato);
        } catch (Throwable) {
            return null;
        }
    }

    private function texto(mixed $valor): ?string
    {
        return is_scalar($valor) ? (string) $valor : null;
    }
}
