<?php

namespace App\Services\WebPosto;

use App\Services\Integration\IntegrationRunChangeRecorder;
use Illuminate\Support\Facades\DB;

class WebPostoSourceDeletionService
{
    private const ENABLED_RESOURCES = ['cliente_empresas', 'abastecimentos'];

    public function __construct(private readonly IntegrationRunChangeRecorder $changeRecorder) {}

    /**
     * @param array<string, mixed> $definition
     * @param array<int, array<string, true>> $seenByCompany
     * @param array<string, mixed> $query
     * @return array{missing_first_confirmation:int, deleted:int}
     */
    public function confirm(array $definition, string $resource, array $seenByCompany, array $companies, array $query, int $runId): array
    {
        if (! in_array($resource, self::ENABLED_RESOURCES, true)
            || ($definition['reconciliation_updated_period'] ?? false) === true) {
            return ['missing_first_confirmation' => 0, 'deleted' => 0];
        }

        $totals = ['missing_first_confirmation' => 0, 'deleted' => 0];
        foreach (array_values(array_unique(array_map('intval', $companies))) as $empresa) {
            $result = $this->confirmCompany($definition, $resource, $seenByCompany[$empresa] ?? [], $empresa, $query, $runId);
            $totals['missing_first_confirmation'] += $result['missing_first_confirmation'];
            $totals['deleted'] += $result['deleted'];
        }

        return $totals;
    }

    /** @param array<string, mixed> $definition @param array<string, true> $seen */
    private function confirmCompany(array $definition, string $resource, array $seen, int $empresa, array $query, int $runId): array
    {
        $table = (string) $definition['table'];
        $companyField = (string) ($definition['company_field'] ?? 'empresaCodigo');
        $naturalKeys = (array) ($definition['natural_keys'] ?? [$definition['key']]);
        $local = DB::connection('webposto')->table($table)->where($companyField, $empresa);

        if (isset($query['dataInicial'])) {
            $dateField = (string) ($definition['updated_field'] ?? '');
            if ($dateField === '') {
                return ['missing_first_confirmation' => 0, 'deleted' => 0];
            }
            $local->where($dateField, '>=', $query['dataInicial']);
            if (isset($query['dataFinal'])) {
                $local->where($dateField, '<=', $query['dataFinal'].' 23:59:59');
            }
        }

        // Keep memory bounded even for abastecimentos: scan only identity columns
        // and fetch the complete payload solely for a confirmed deletion.
        $rows = $local->get($naturalKeys);
        $presentHashes = array_keys($seen);
        foreach (array_chunk($presentHashes, 1000) as $hashes) {
            DB::table('webposto_source_absences')
                ->where('resource', $resource)->where('empresa_codigo', $empresa)
                ->whereIn('natural_key_hash', $hashes)->delete();
        }

        $first = 0;
        $deleted = 0;
        foreach ($rows as $rowObject) {
            $row = (array) $rowObject;
            $naturalKey = collect($naturalKeys)->mapWithKeys(fn (string $field): array => [$field => $row[$field] ?? null])->all();
            $hash = $this->keyHash($naturalKey);
            if (isset($seen[$hash])) {
                continue;
            }

            $absence = DB::table('webposto_source_absences')
                ->where('resource', $resource)->where('empresa_codigo', $empresa)
                ->where('natural_key_hash', $hash)->first();
            if ($absence === null) {
                DB::table('webposto_source_absences')->insert([
                    'resource' => $resource, 'table_name' => $table, 'empresa_codigo' => $empresa,
                    'natural_key' => json_encode($naturalKey), 'natural_key_hash' => $hash,
                    'consecutive_absences' => 1, 'first_missing_run_id' => $runId,
                    'last_checked_run_id' => $runId, 'first_missing_at' => now(),
                    'last_missing_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $first++;
                continue;
            }
            if ((int) $absence->last_checked_run_id === $runId) {
                continue;
            }

            $count = (int) $absence->consecutive_absences + 1;
            DB::table('webposto_source_absences')->where('id', $absence->id)->update([
                'consecutive_absences' => $count, 'last_checked_run_id' => $runId,
                'last_missing_at' => now(), 'updated_at' => now(),
            ]);
            if ($count < 2) {
                continue;
            }

            $fullRowQuery = DB::connection('webposto')->table($table)->where($companyField, $empresa);
            foreach ($naturalKey as $field => $value) {
                $fullRowQuery->where($field, $value);
            }
            $fullRow = $fullRowQuery->first();
            if ($fullRow === null) {
                DB::table('webposto_source_absences')->where('id', $absence->id)->delete();
                continue;
            }
            $payload = (array) $fullRow;
            DB::table('webposto_source_deleted_records')->updateOrInsert(
                ['resource' => $resource, 'empresa_codigo' => $empresa, 'natural_key_hash' => $hash],
                ['table_name' => $table, 'natural_key' => json_encode($naturalKey),
                    'payload' => json_encode($payload), 'confirmed_run_id' => $runId,
                    'archived_at' => now(), 'created_at' => now(), 'updated_at' => now()],
            );
            $delete = DB::connection('webposto')->table($table)->where($companyField, $empresa);
            foreach ($naturalKey as $field => $value) {
                $delete->where($field, $value);
            }
            if ($delete->delete() === 0) {
                continue;
            }
            $this->changeRecorder->record($runId, $resource, $table, [[
                'action' => 'deleted', 'natural_key' => ['empresaCodigo' => $empresa, ...$naturalKey],
                'payload' => $payload, 'before_payload' => $payload, 'after_payload' => null,
                'changed_fields' => array_keys($payload),
            ]]);
            DB::table('webposto_source_absences')->where('id', $absence->id)->delete();
            $deleted++;
        }

        return ['missing_first_confirmation' => $first, 'deleted' => $deleted];
    }

    /** @param array<string, mixed> $naturalKey */
    private function keyHash(array $naturalKey): string
    {
        ksort($naturalKey);
        return hash('sha256', json_encode($naturalKey, JSON_UNESCAPED_UNICODE));
    }
}
