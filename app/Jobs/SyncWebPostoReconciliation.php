<?php

namespace App\Jobs;

use App\Models\IntegrationService;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Models\WebPostoCredential;
use App\Services\Integration\WebPostoReconciliationCoordinator;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncWebPostoReconciliation implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const CHIMBA_QUEUE = 'chimba-reconciliation';

    private const B2_SHARED_RESOURCES = ['cliente_empresas', 'abastecimentos'];

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(public readonly int $serviceId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return "webposto-reconciliation:{$this->serviceId}";
    }

    public function handle(): void
    {
        $service = IntegrationService::query()->findOrFail($this->serviceId);
        $run = IntegrationServiceRun::query()->create([
            'integration_service_id' => $service->id,
            'status' => 'running',
            'period_start' => today(),
            'period_end' => today(),
            'started_at' => now(),
        ]);
        $service->update(['last_started_at' => now(), 'last_error' => null]);

        $settings = $service->settings ?? [];
        $companies = DB::connection('webposto')->table('webposto_credentials as credentials')
            ->leftJoin('empresas', 'empresas.empresaCodigo', '=', 'credentials.empresa_codigo')
            ->where('credentials.ativo', true)
            ->where('credentials.implantacao_status', WebPostoCredential::STATUS_SINCRONIZADO)
            ->when(
                ! empty($settings['empresa_codigos']),
                fn ($query) => $query->whereIn('credentials.empresa_codigo', $settings['empresa_codigos']),
            )
            ->when(
                ! empty($settings['bases']),
                fn ($query) => $query->whereIn('credentials.base', $settings['bases']),
            )
            ->orderBy('credentials.empresa_codigo')
            ->get(['credentials.empresa_codigo', 'empresas.fantasia', 'empresas.razao']);

        if ($companies->isEmpty()) {
            $message = 'Nenhum posto sincronizado e ativo foi encontrado para esta reconciliação.';
            $run->update(['status' => 'failed', 'error' => $message, 'finished_at' => now()]);
            $service->update(['last_error' => $message]);
            app(WebPostoReconciliationCoordinator::class)->resumeNewRecordsIfNoReconciliationRunning($service->resource);

            return;
        }

        app(WebPostoReconciliationCoordinator::class)->pauseNewRecordsForReconciliation($service->resource);

        $queue = $service->resource === 'webposto-chimba-reconciliation' ? self::CHIMBA_QUEUE : 'default';
        $resources = (array) ($settings['resources'] ?? []);
        $sharedResources = $service->resource === 'webposto-b2-reconciliation'
            ? array_values(array_intersect(self::B2_SHARED_RESOURCES, $resources))
            : [];
        $configuredBlocks = collect($settings['worker_blocks'] ?? [])
            ->map(fn (array $block): array => [
                'name' => (string) ($block['name'] ?? 'Bloco'),
                'resources' => array_values(array_diff(
                    array_intersect($resources, (array) ($block['resources'] ?? [])),
                    $sharedResources,
                )),
            ])
            ->filter(fn (array $block): bool => $block['resources'] !== [])
            ->values();
        $position = 0;
        foreach ($companies as $company) {
            $blocks = $configuredBlocks->isEmpty()
                ? collect([['name' => null, 'resources' => $resources]])
                : $configuredBlocks;
            foreach ($blocks as $blockIndex => $block) {
                $name = $company->fantasia ?: ($company->razao ?: 'Empresa '.$company->empresa_codigo);
                if ($block['name'] !== null) {
                    $name .= ' · '.$block['name'];
                }
                $companyRun = IntegrationServiceCompanyRun::query()->create([
                    'integration_service_run_id' => $run->id,
                    'empresa_codigo' => (int) $company->empresa_codigo,
                    'empresa_nome' => $name,
                    'block_key' => $block['name'] === null ? 'company' : 'block-'.($blockIndex + 1),
                    'position' => ++$position,
                    'status' => 'pending',
                    'resource_results' => [],
                ]);

                SyncWebPostoCompanyReconciliation::dispatch(
                    $run->id,
                    $companyRun->id,
                    $service->id,
                    $block['resources'],
                    $queue,
                );
            }
        }

        if ($sharedResources !== []) {
            $companyCodes = $companies->pluck('empresa_codigo')->map(fn ($code): int => (int) $code)->all();
            $credentialCompany = (int) $companyCodes[0];
            foreach ($sharedResources as $resource) {
                $companyRun = IntegrationServiceCompanyRun::query()->create([
                    'integration_service_run_id' => $run->id,
                    'empresa_codigo' => $credentialCompany,
                    'empresa_nome' => 'B2 · '.str_replace('_', ' ', $resource).' (compartilhado)',
                    'block_key' => 'shared-'.substr($resource, 0, 30),
                    'position' => ++$position,
                    'status' => 'pending',
                    'resource_results' => [],
                ]);

                SyncWebPostoCompanyReconciliation::dispatch(
                    $run->id,
                    $companyRun->id,
                    $service->id,
                    [$resource],
                    $queue,
                    $companyCodes,
                );
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        $service = IntegrationService::query()->find($this->serviceId);
        app(WebPostoReconciliationCoordinator::class)
            ->resumeNewRecordsIfNoReconciliationRunning($service?->resource);
    }
}
