<?php
namespace App\Services\Integration;
use App\Models\IntegrationServiceRunChange;
use Carbon\Carbon;
class IntegrationRunChangeRecorder {
 /** @param array<int, array<string, mixed>> $changes */
 public function record(int $runId, string $resource, string $table, array $changes): void {
  foreach ($changes as $change) {
   $naturalKey = $change['natural_key'];
   ksort($naturalKey);
   IntegrationServiceRunChange::query()->updateOrCreate([
    'integration_service_run_id'=>$runId,
    'table_name'=>$table,
    'natural_key_hash'=>hash('sha256', json_encode($naturalKey, JSON_UNESCAPED_UNICODE)),
   ],[
    'resource'=>$resource,
    'action'=>$change['action'],
    'natural_key'=>$naturalKey,
    'source_updated_at'=>$this->date($change['source_updated_at'] ?? null),
    'payload'=>$change['payload'],
    'detected_at'=>now(),
   ]);
  }
 }
 private function date(mixed $value): ?Carbon {
  if (! is_string($value) || $value === '') return null;
  try { return Carbon::parse($value)->setMicrosecond(0); } catch (\Throwable) { return null; }
 }
}
