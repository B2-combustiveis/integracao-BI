<?php

namespace App\Services\Alterdata;

use App\Models\AlterdataSyncControl;
use Carbon\CarbonImmutable;

class AlterdataSynchronizationService
{
    private const EMPLOYEE_INCLUDES = [
        'empresa', 'departamento', 'sexo', 'estadocivil', 'formadepagamento',
        'nacionalidade', 'naturalidade', 'estado', 'tipoDeConta', 'tipoDeChavePix',
    ];

    public function __construct(
        private readonly AlterdataClient $client,
        private readonly EmpresaImporter $empresas,
        private readonly DepartamentoImporter $departamentos,
        private readonly TipoSexoImporter $sexos,
        private readonly TipoEstadoCivilImporter $estadosCivis,
        private readonly TipoFormaPagamentoImporter $formasPagamento,
        private readonly PaisImporter $paises,
        private readonly EstadoImporter $estados,
        private readonly CatalogoDescricaoImporter $catalogos,
        private readonly FuncionarioImporter $funcionarios,
    ) {}

    /** @return array{results: array<string, array<string, int|string|null>>, totals: array<string, int>, watermark: ?string, incremental: bool} */
    public function sync(?callable $progress = null): array
    {
        $results = [];
        $run = function (string $resource, callable $callback) use (&$results, $progress): void {
            $progress?->__invoke($resource);
            $results[$resource] = $this->normalize($callback());
        };

        // Dependências primeiro: os funcionários nunca apontam para catálogos ausentes.
        $run('empresas', fn () => $this->empresas->import($this->client->todos('empresas', [], 1000)));
        $run('paises', fn () => $this->paises->import($this->client->todos('paises', ['sort' => 'id'], 1000)));
        $run('estados', fn () => $this->estados->import($this->client->todos('estados', ['sort' => 'id'], 1000)));
        $run('tipos_sexo', fn () => $this->sexos->import($this->client->todos('tipos-sexo', ['sort' => 'id'], 1000)));
        $run('tipos_estado_civil', fn () => $this->estadosCivis->import($this->client->todos('tipos-estado-civil', ['sort' => 'id'], 1000)));
        $run('tipos_forma_pagamento', fn () => $this->formasPagamento->import($this->client->todos('tipos-forma-de-pagamento', ['sort' => 'id'], 1000)));
        $run('tipos_conta', fn () => $this->catalogos->import('tipos_conta', $this->client->todos('tipo-conta', ['sort' => 'id'], 1000)));
        $run('tipos_chave_pix', fn () => $this->catalogos->import('tipos_chave_pix', $this->client->todos('tipo-chave-pix', ['sort' => 'id'], 1000)));
        $run('departamentos', fn () => $this->departamentos->import($this->client->todos('departamentos', ['sort' => 'id'], 1000, ['empresa'])));

        $control = AlterdataSyncControl::query()->firstOrCreate(['resource' => 'funcionarios']);
        $incremental = $control->last_watermark !== null;
        $parameters = ['sort' => 'id'];
        if ($incremental) {
            $overlapStart = CarbonImmutable::instance($control->last_watermark)->subMinutes(5);
            $parameters['filter'] = ['dataAtualizacao' => ['ge' => $overlapStart->format('Y-m-d\TH:i:s.u')]];
        }

        $progress?->__invoke('funcionarios');
        $employeePayload = $this->client->todos('funcionarios', $parameters, 100, self::EMPLOYEE_INCLUDES);
        $results['funcionarios'] = $this->normalize($this->funcionarios->import($employeePayload));
        $watermark = collect($employeePayload)
            ->pluck('attributes.dataAtualizacao')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->max();

        if ($watermark !== null) {
            $candidate = CarbonImmutable::parse($watermark);
            if ($control->last_watermark === null || $candidate->greaterThan($control->last_watermark)) {
                $control->last_watermark = $candidate;
            }
        }
        if (! $incremental) {
            $control->last_full_sync_at = now();
        }
        $control->save();

        $totals = ['received' => 0, 'inserted' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];
        foreach ($results as $result) {
            foreach (array_keys($totals) as $field) {
                $totals[$field] += (int) ($result[$field] ?? 0);
            }
        }

        return [
            'results' => $results,
            'totals' => $totals,
            'watermark' => $control->last_watermark?->format('Y-m-d H:i:s.u'),
            'incremental' => $incremental,
        ];
    }

    /** @return array<string, int> */
    private function normalize(array $result): array
    {
        return [
            'received' => (int) ($result['recebidos'] ?? $result['recebidas'] ?? 0),
            'inserted' => (int) ($result['inseridos'] ?? $result['inseridas'] ?? 0),
            'updated' => (int) ($result['atualizados'] ?? $result['atualizadas'] ?? 0),
            'unchanged' => (int) ($result['inalterados'] ?? $result['inalteradas'] ?? 0),
            'skipped' => (int) ($result['ignorados'] ?? $result['ignoradas'] ?? 0),
        ];
    }
}
