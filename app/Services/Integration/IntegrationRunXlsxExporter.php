<?php

namespace App\Services\Integration;

use App\Models\IntegrationServiceRun;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class IntegrationRunXlsxExporter
{
    public function create(IntegrationServiceRun $run): string
    {
        $run->loadMissing(['service', 'changes', 'companyRuns']);
        $path = tempnam(sys_get_temp_dir(), 'webposto-report-').'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Resumo');
        $duration = $run->started_at
            ? $run->started_at->diffInSeconds($run->finished_at ?? now())
            : null;
        $writer->addRow(Row::fromValues(['campo', 'valor']));
        $summary = [
            'execucao_id' => $run->id,
            'servico' => $run->service->name,
            'empresas_processadas' => $run->companyRuns->count(),
            'empresas_com_sucesso' => $run->companyRuns->where('status', 'success')->count(),
            'empresas_com_falha' => $run->companyRuns->whereIn('status', ['partial', 'failed'])->count(),
            'status' => $run->status,
            'inicio_execucao' => $run->started_at?->format('Y-m-d H:i:s'),
            'fim_execucao' => $run->finished_at?->format('Y-m-d H:i:s'),
            'duracao_segundos' => $duration,
            'novos' => $run->inserted,
        ];
        if ($run->service->resource !== 'webposto-new-records') {
            $summary['atualizados'] = $run->updated;
        }
        foreach ($summary as $field => $value) {
            $writer->addRow(Row::fromValues([$field, $value]));
        }

        $this->writeCompaniesSheet($writer, $run);

        $changesByResource = $run->changes
            ->when(
                $run->service->resource === 'webposto-new-records',
                fn ($changes) => $changes->filter(fn ($change): bool => $change->action === 'inserted'),
            )->groupBy('resource');
        foreach ($changesByResource->keys() as $resource) {
            $changes = $changesByResource->get($resource, collect());
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($this->sheetName((string) $resource));
            $headers = $changes->reduce(function (array $headers, $change): array {
                foreach (array_keys($change->payload ?? []) as $field) {
                    if (! in_array($field, $headers, true)) $headers[] = $field;
                }

                return $headers;
            }, []);
            $companyNames = $run->companyRuns->mapWithKeys(
                fn ($company): array => [(int) $company->empresa_codigo => $company->empresa_nome],
            );
            $includeUpdateMetadata = $run->service->resource !== 'webposto-new-records';
            $metadataHeaders = $includeUpdateMetadata ? ['_acao', '_camposAlterados'] : [];
            $writer->addRow(Row::fromValues(['_empresaCodigo', '_empresaNome', ...$metadataHeaders, '_detectadoEm', ...$headers]));
            foreach ($changes as $change) {
                $payload = $change->payload ?? [];
                $companyCode = $change->natural_key['empresaCodigo'] ?? $payload['empresaCodigo'] ?? null;
                $values = [
                    $companyCode,
                    $companyCode === null ? null : $companyNames->get((int) $companyCode),
                ];
                if ($includeUpdateMetadata) {
                    $values[] = $change->action;
                    $values[] = implode(', ', $change->changed_fields ?? []);
                }
                $values[] = $change->detected_at?->format('Y-m-d H:i:s');
                foreach ($headers as $field) $values[] = $this->cell($payload[$field] ?? null);
                $writer->addRow(Row::fromValues($values));
            }
        }
        $writer->close();

        return $path;
    }

    private function writeCompaniesSheet(Writer $writer, IntegrationServiceRun $run): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName('Empresas');
        $writer->addRow(Row::fromValues([
            'empresaCodigo', 'empresaNome', 'status', 'recebidos', 'novos', 'atualizados',
            'inalterados', 'ignorados', 'inicio', 'fim', 'erro',
        ]));
        foreach ($run->companyRuns->sortBy('position') as $company) {
            $writer->addRow(Row::fromValues([
                $company->empresa_codigo,
                $company->empresa_nome,
                $company->status,
                $company->received,
                $company->inserted,
                $company->updated,
                $company->unchanged,
                $company->skipped,
                $company->started_at?->format('Y-m-d H:i:s'),
                $company->finished_at?->format('Y-m-d H:i:s'),
                $company->error,
            ]));
        }
    }

    private function sheetName(string $resource): string
    {
        $name = str_replace(['\\', '/', '?', '*', '[', ']', ':'], '_', $resource) ?: 'dados';

        return mb_substr($name, 0, 31);
    }

    private function cell(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return is_bool($value) ? ($value ? 'true' : 'false') : $value;
    }
}
