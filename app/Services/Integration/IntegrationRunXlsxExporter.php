<?php

namespace App\Services\Integration;

use App\Models\IntegrationServiceRun;
use OpenSpout\Common\Entity\Row;
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
            'novos' => $run->inserted,
        ] as $field => $value) {
            $writer->addRow(Row::fromValues([$field, $value]));
        }

        $changesByResource = $run->changes
            ->where('action', 'inserted')
            ->groupBy('resource');
        $resources = $changesByResource->keys();
        foreach ($resources as $resource) {
            $changes = $changesByResource->get($resource, collect());
            $sheet = $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName($this->sheetName((string) $resource));
            if ($changes->isEmpty()) {
                $writer->addRow(Row::fromValues(['mensagem']));
                $writer->addRow(Row::fromValues(['Nenhum registro novo nesta execução.']));
                continue;
            }
            $headers = $changes->reduce(function (array $headers, $change): array {
                foreach (array_keys($change->payload ?? []) as $field) {
                    if (! in_array($field, $headers, true)) $headers[] = $field;
                }
                return $headers;
            }, []);
            $metadata = ['_detectadoEm'];
            $writer->addRow(Row::fromValues([...$metadata, ...$headers]));
            foreach ($changes as $change) {
                $payload = $change->payload ?? [];
                $values = [
                    $change->detected_at?->format('Y-m-d H:i:s'),
                ];
                foreach ($headers as $field) $values[] = $this->cell($payload[$field] ?? null);
                $writer->addRow(Row::fromValues($values));
            }
        }
        $writer->close();
        return $path;
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
