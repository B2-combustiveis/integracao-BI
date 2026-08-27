<?php

namespace App\Services\Integration;

use App\Models\IntegrationServiceRun;
use OpenSpout\Common\Entity\Row;
use Illuminate\Support\Facades\DB;
use OpenSpout\Writer\XLSX\Writer;

class IntegrationRunXlsxExporter
{
    public function create(IntegrationServiceRun $run): string
    {
        $run->loadMissing(['service', 'changes']);
        $path = tempnam(sys_get_temp_dir(), 'webposto-report-').'.xlsx';
        $writer = new Writer();
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Resumo');
        $isReconciliation = $run->service->resource === 'webposto-full-reconciliation';
        $duration = $run->started_at
            ? $run->started_at->diffInSeconds($run->finished_at ?? now())
            : null;
        $writer->addRow(Row::fromValues(['campo', 'valor']));
        foreach ([
            'execucao_id' => $run->id,
            'servico' => $run->service->name,
            'empresa' => $run->service->empresa_codigo,
            'status' => $run->status,
            'inicio_execucao' => $run->started_at?->format('Y-m-d H:i:s'),
            'fim_execucao' => $run->finished_at?->format('Y-m-d H:i:s'),
            'duracao_segundos' => $duration,
            $isReconciliation ? 'atualizados' : 'novos' => $isReconciliation ? $run->updated : $run->inserted,
        ] as $field => $value) {
            $writer->addRow(Row::fromValues([$field, $value]));
        }

        $changesByResource = $run->changes
            ->filter(fn ($change): bool => $isReconciliation
                ? $change->action === 'updated'
                : ($change->action === 'inserted'
                    || ($change->resource === 'tanques' && $change->action === 'updated')))
            ->groupBy('resource');
        foreach ($changesByResource->keys() as $resource) {
            $changes = $changesByResource->get($resource, collect());
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($this->sheetName((string) $resource));
            if ($isReconciliation) {
                $this->writeComparisonSheet($writer, (string) $resource, $changes);

                continue;
            }
            $headers = $changes->reduce(function (array $headers, $change): array {
                foreach (array_keys($change->payload ?? []) as $field) {
                    if (! in_array($field, $headers, true)) $headers[] = $field;
                }

                return $headers;
            }, []);
            $writer->addRow(Row::fromValues(['_detectadoEm', ...$headers]));
            foreach ($changes as $change) {
                $payload = $change->payload ?? [];
                $values = [$change->detected_at?->format('Y-m-d H:i:s')];
                foreach ($headers as $field) $values[] = $this->cell($payload[$field] ?? null);
                $writer->addRow(Row::fromValues($values));
            }
        }
        $writer->close();

        return $path;
    }

    private function writeComparisonSheet(Writer $writer, string $resource, $changes): void
    {
        $fields = $changes->reduce(function (array $fields, $change): array {
            foreach ($change->changed_fields ?? [] as $field) {
                if (! in_array($field, $fields, true)) $fields[] = $field;
            }

            return $fields;
        }, []);
        $names = $this->comparisonNames($resource, $changes);
        $headers = ['_empresaCodigo', '_nome', '_acao', '_camposAlterados', '_detectadoEm'];
        foreach ($fields as $field) {
            $headers[] = $field.'_anterior';
            $headers[] = $field.'_novo';
        }
        $writer->addRow(Row::fromValues($headers));
        foreach ($changes as $change) {
            $before = $change->before_payload ?? [];
            $after = $change->after_payload ?? [];
            $changedFields = $change->changed_fields ?? [];
            $values = [
                $change->natural_key['empresaCodigo'] ?? null,
                $names[$this->changeKey($change)] ?? null,
                'atualizado',
                implode(', ', $changedFields),
                $change->detected_at?->format('Y-m-d H:i:s'),
            ];
            foreach ($fields as $field) {
                $values[] = in_array($field, $changedFields, true) ? $this->cell($before[$field] ?? null) : null;
                $values[] = in_array($field, $changedFields, true) ? $this->cell($after[$field] ?? null) : null;
            }
            $writer->addRow(Row::fromValues($values));
        }
    }

    private function comparisonNames(string $resource, $changes): array
    {
        if ($resource === 'produtos') {
            return $changes->mapWithKeys(fn ($change): array => [
                $this->changeKey($change) => $change->after_payload['nome']
                    ?? $change->before_payload['nome']
                    ?? $change->payload['nome']
                    ?? null,
            ])->all();
        }
        if ($resource !== 'produto_empresas') {
            return [];
        }

        $companies = $changes->pluck('natural_key.empresaCodigo')->filter()->unique()->values();
        $products = $changes->pluck('natural_key.produtoCodigo')->filter()->unique()->values();

        return DB::connection('webposto')->table('produtos')
            ->whereIn('empresaCodigo', $companies)
            ->whereIn('produtoCodigo', $products)
            ->get(['empresaCodigo', 'produtoCodigo', 'nome'])
            ->mapWithKeys(fn (object $product): array => [
                $product->empresaCodigo.':'.$product->produtoCodigo => $product->nome,
            ])->all();
    }

    private function changeKey($change): string
    {
        return ($change->natural_key['empresaCodigo'] ?? '').':'
            .($change->natural_key['produtoCodigo'] ?? '');
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
