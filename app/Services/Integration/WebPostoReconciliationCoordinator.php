<?php

namespace App\Services\Integration;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use Illuminate\Support\Facades\DB;

class WebPostoReconciliationCoordinator
{
    private const RECONCILIATION_RESOURCES = [
        'webposto-chimba-reconciliation',
        'webposto-b2-reconciliation',
    ];

    private const NEW_RECORDS_RESOURCE = 'webposto-new-records';

    public function isReconciliationResource(?string $resource): bool
    {
        return in_array($resource, self::RECONCILIATION_RESOURCES, true);
    }

    public function pauseNewRecordsForReconciliation(?string $resource = null): void
    {
        DB::transaction(function () use ($resource): void {
            $newRecords = IntegrationService::query()->lockForUpdate()
                ->where('resource', self::NEW_RECORDS_RESOURCE)->first();
            if ($newRecords === null) {
                return;
            }

            $settings = $newRecords->settings ?? [];
            $holds = array_values(array_unique([...($settings['reconciliation_holds'] ?? []), $resource ?? 'reconciliation']));
            $newRecords->update(['settings' => [
                ...$settings,
                'suspended_by_reconciliation' => true,
                'reconciliation_holds' => $holds,
            ]]);
        });
    }

    public function newRecordsAreSuspended(): bool
    {
        $service = IntegrationService::query()->where('resource', self::NEW_RECORDS_RESOURCE)->first();

        return ($service?->settings['suspended_by_reconciliation'] ?? false) === true
            || ($service?->settings['reconciliation_holds'] ?? []) !== []
            || $this->hasRunningReconciliation();
    }

    public function hasRunningNewRecords(): bool
    {
        $serviceId = IntegrationService::query()->where('resource', self::NEW_RECORDS_RESOURCE)->value('id');
        if ($serviceId === null) {
            return false;
        }

        return IntegrationServiceRun::query()->where('integration_service_id', $serviceId)
            ->whereIn('status', ['running', 'finalizing'])->exists();
    }

    public function resumeNewRecordsIfNoReconciliationRunning(?string $resource = null): void
    {
        $newRecords = IntegrationService::query()->where('resource', self::NEW_RECORDS_RESOURCE)->first();
        if ($newRecords === null || ($newRecords->settings['suspended_by_reconciliation'] ?? false) !== true) {
            return;
        }

        $settings = $newRecords->settings ?? [];
        $holds = (array) ($settings['reconciliation_holds'] ?? []);
        if ($resource !== null) {
            $holds = array_values(array_filter($holds, fn (string $hold): bool => $hold !== $resource));
        } elseif (! $this->hasRunningReconciliation()) {
            $holds = [];
        }
        if ($holds !== [] || $this->hasRunningReconciliation()) {
            $newRecords->update(['settings' => [...$settings, 'reconciliation_holds' => $holds]]);

            return;
        }

        $newRecords->update([
            'next_run_at' => $newRecords->active ? now() : null,
            'settings' => collect($settings)->except('suspended_by_reconciliation', 'reconciliation_holds')->all(),
        ]);
    }

    /**
     * Reconciliacoes fazem varreduras sequenciais pesadas nas mesmas tabelas do
     * webposto que o sync do ClickHouse le. Nao ha risco de corrupcao (o
     * ClickHouse sync so le), mas rodar junto competiria por I/O a toa - por
     * isso o sync do ClickHouse consulta este metodo antes de comecar e adia
     * para o proximo tick se alguma reconciliacao pesada estiver rodando.
     */
    public function heavyReadIsRunning(): bool
    {
        return $this->hasRunningReconciliation();
    }

    private function hasRunningReconciliation(): bool
    {
        $serviceIds = IntegrationService::query()->whereIn('resource', self::RECONCILIATION_RESOURCES)->pluck('id');

        return IntegrationServiceRun::query()->whereIn('integration_service_id', $serviceIds)
            ->whereIn('status', ['running', 'finalizing'])->exists();
    }
}
