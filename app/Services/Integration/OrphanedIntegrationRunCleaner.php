<?php

namespace App\Services\Integration;

use App\Models\IntegrationServiceRun;
use App\Models\WebPostoSyncEndpointRun;
use Illuminate\Support\Facades\DB;

class OrphanedIntegrationRunCleaner
{
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

        foreach ($runs as $run) {
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
