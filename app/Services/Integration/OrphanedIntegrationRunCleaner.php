<?php

namespace App\Services\Integration;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Models\WebPostoSyncEndpointRun;
use Illuminate\Support\Facades\DB;

class OrphanedIntegrationRunCleaner
{
    /**
     * Cancela empresas cujo posto parou de responder (heartbeat parado),
     * mesmo com outras empresas do mesmo run ainda ativas na fila.
     */
    public function cleanStaleCompanyRuns(int $staleMinutes = 10): int
    {
        $threshold = now()->subMinutes(max(1, $staleMinutes));
        $message = 'Posto sem resposta ha mais de '.max(1, $staleMinutes).' minuto(s). Cancelado automaticamente; dados ja sincronizados foram mantidos.';

        $ids = IntegrationServiceCompanyRun::query()
            ->where('status', 'running')
            ->whereNotNull('heartbeat_at')
            ->where('heartbeat_at', '<=', $threshold)
            ->pluck('id');

        $cleaned = 0;
        $affectedRunIds = [];

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $threshold, $message, &$cleaned, &$affectedRunIds): void {
                $locked = IntegrationServiceCompanyRun::query()->lockForUpdate()->find($id);
                if ($locked === null || $locked->status !== 'running'
                    || $locked->heartbeat_at === null || $locked->heartbeat_at->gt($threshold)) {
                    return;
                }

                $locked->update([
                    'status' => 'failed',
                    'current_resource' => null,
                    'current_page' => null,
                    'current_cursor' => null,
                    'heartbeat_at' => null,
                    'error' => $message,
                    'finished_at' => now(),
                ]);
                $cleaned++;
                $affectedRunIds[] = $locked->integration_service_run_id;
            });
        }

        foreach (array_unique($affectedRunIds) as $runId) {
            $this->finalizeRunIfComplete($runId);
        }

        return $cleaned;
    }

    private function finalizeRunIfComplete(int $runId): void
    {
        $stillWorking = IntegrationServiceCompanyRun::query()
            ->where('integration_service_run_id', $runId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
        if ($stillWorking) {
            return;
        }

        $claimed = IntegrationServiceRun::query()
            ->whereKey($runId)
            ->where('status', 'running')
            ->update(['status' => 'finalizing']);
        if ($claimed === 0) {
            return;
        }

        $blockRuns = IntegrationServiceCompanyRun::query()->where('integration_service_run_id', $runId)->get();
        $failures = $blockRuns
            ->filter(fn (IntegrationServiceCompanyRun $blockRun): bool => in_array($blockRun->status, ['partial', 'failed'], true))
            ->map(fn (IntegrationServiceCompanyRun $blockRun): string => $blockRun->empresa_nome.': '.($blockRun->error ?? 'Falha sem mensagem.'));
        $status = $failures->isEmpty()
            ? 'success'
            : ($failures->count() === $blockRuns->count() ? 'failed' : 'partial');
        $error = $failures->isEmpty() ? null : $failures->implode("\n");

        $run = IntegrationServiceRun::query()->whereKey($runId)->first();
        IntegrationServiceRun::query()->whereKey($runId)->update([
            'status' => $status,
            'error' => $error,
            'finished_at' => now(),
        ]);
        $service = $run !== null ? IntegrationService::query()->find($run->integration_service_id) : null;
        $service?->update([
            'last_completed_at' => now(),
            'next_run_at' => $service->active ? app(IntegrationServiceSchedule::class)->nextRunAt($service) : null,
            'last_error' => $error,
        ]);
        $coordinator = app(WebPostoReconciliationCoordinator::class);
        if ($coordinator->isReconciliationResource($service?->resource)) {
            $coordinator->resumeNewRecordsIfNoReconciliationRunning($service?->resource);
        }
    }
    public function clean(int $staleMinutes = 10): int
    {
        if (DB::table('jobs')->exists()) {
            return 0;
        }

        $runs = IntegrationServiceRun::query()
            ->where('status', 'running')
            ->where('updated_at', '<=', now()->subMinutes(max(1, $staleMinutes)))
            ->get();
        $cleaned = 0;
        $coordinator = app(WebPostoReconciliationCoordinator::class);
        $affectedReconciliation = false;

        foreach ($runs as $run) {
            if ($coordinator->isReconciliationResource($run->service?->resource)) {
                $affectedReconciliation = true;
            }
            DB::transaction(function () use ($run, &$cleaned): void {
                $locked = IntegrationServiceRun::query()->lockForUpdate()->find($run->id);
                if (! $locked || $locked->status !== 'running' || DB::table('jobs')->exists()) {
                    return;
                }

                $now = now();
                $message = 'Execucao interrompida sem job ativo; estado residual encerrado automaticamente.';
                $locked->update([
                    'status' => 'failed',
                    'finished_at' => $now,
                    'error' => $message,
                ]);
                $locked->companyRuns()->whereIn('status', ['pending', 'running'])->update([
                    'status' => 'failed',
                    'current_resource' => null,
                    'current_page' => null,
                    'current_cursor' => null,
                    'heartbeat_at' => null,
                    'finished_at' => $now,
                    'error' => $message,
                    'updated_at' => $now,
                ]);

                WebPostoSyncEndpointRun::query()
                    ->where('integration_service_run_id', $locked->id)
                    ->where('status', 'running')
                    ->get()
                    ->each(function (WebPostoSyncEndpointRun $endpoint) use ($now, $message): void {
                        $endpoint->update([
                            'status' => 'failed',
                            'finished_at' => $now,
                            'error' => $message,
                        ]);
                        $control = $endpoint->control;
                        if ($control) {
                            $metadata = is_array($control->metadata) ? $control->metadata : [];
                            $control->update([
                                'status' => 'error',
                                'last_completed_at' => $now,
                                'last_error' => $message,
                                'metadata' => [...$metadata, 'resume_available' => true],
                            ]);
                        }
                    });
                $cleaned++;
            });
        }

        if ($affectedReconciliation) {
            $coordinator->resumeNewRecordsIfNoReconciliationRunning();
        }

        $orphanEndpoints = WebPostoSyncEndpointRun::query()
            ->where('status', 'running')
            ->whereNull('integration_service_run_id')
            ->where('updated_at', '<=', now()->subMinutes(max(1, $staleMinutes)))
            ->get();
        $message = 'Endpoint manual interrompido sem job ativo; historico residual encerrado automaticamente.';
        $now = now();
        foreach ($orphanEndpoints as $endpoint) {
            $endpoint->update([
                'status' => 'failed',
                'finished_at' => $now,
                'error' => $message,
            ]);
            $cleaned++;
        }

        return $cleaned;
    }
}
