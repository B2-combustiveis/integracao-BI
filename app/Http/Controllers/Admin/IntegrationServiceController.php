<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationService;
use App\Models\IntegrationServiceCompanyRun;
use App\Models\IntegrationServiceRun;
use App\Services\Integration\IntegrationRunXlsxExporter;
use App\Services\Integration\IntegrationServiceDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class IntegrationServiceController extends Controller
{
    public function pause(IntegrationService $service): RedirectResponse
    {
        $service->update(['active' => false, 'next_run_at' => null]);

        return back()->with('status', 'Servico pausado.');
    }

    public function resume(IntegrationService $service): RedirectResponse
    {
        $service->update(['active' => true, 'next_run_at' => now()]);

        return back()->with('status', 'Servico ativado.');
    }

    public function run(IntegrationService $service, IntegrationServiceDispatcher $dispatcher): RedirectResponse
    {
        $dispatched = $dispatcher->dispatch($service->id);

        return back()->with('status', $dispatched
            ? 'Execucao manual adicionada a fila.'
            : 'Execucao solicitada e aguardando a rotina atual liberar os workers.');
    }

    public function status(): JsonResponse
    {
        $serviceQuery = IntegrationService::query()
            ->whereIn('resource', ['webposto-new-records', 'webposto-database-changes', 'webposto-chimba-reconciliation', 'webposto-b1-reconciliation', 'webposto-b2-reconciliation', 'clickhouse-incremental-sync', 'alterdata-sync']);
        $serviceIds = (clone $serviceQuery)->pluck('id');

        $serviceModels = $serviceQuery
            ->with(['runs' => fn ($query) => $query->latest()->limit(1)->withCount('changes')->with('companyRuns')])
            ->get();

        $completedRuns = IntegrationServiceRun::query()
            ->whereIn('integration_service_id', $serviceIds)
            ->whereIn('status', ['success', 'partial', 'failed'])
            ->with(['service', 'companyRuns'])->withCount('changes')
            ->latest('finished_at')->limit(20)->get();

        $runIds = $serviceModels->flatMap(fn (IntegrationService $service) => $service->runs)
            ->pluck('id')->merge($completedRuns->pluck('id'))->unique()->values();
        $newRecordsByRun = $runIds->isEmpty() ? [] : DB::table('integration_service_run_changes')
            ->selectRaw('integration_service_run_id, resource, COUNT(*) as total')
            ->whereIn('integration_service_run_id', $runIds)
            ->where('action', 'inserted')
            ->groupBy('integration_service_run_id', 'resource')
            ->get()->groupBy('integration_service_run_id')
            ->map(fn ($rows): array => $rows->mapWithKeys(
                fn (object $row): array => [$row->resource => (int) $row->total],
            )->all())->all();

        $services = $serviceModels->map(function (IntegrationService $service) use ($newRecordsByRun): array {
            $run = $service->runs->first();

            return [
                'id' => $service->id,
                'name' => $service->name,
                'resource' => $service->resource,
                'active' => $service->active,
                'frequency_minutes' => $service->frequency_minutes,
                'next_run_at' => $service->next_run_at?->toIso8601String(),
                'run' => $run ? $this->runData($run, $newRecordsByRun[$run->id] ?? []) : null,
            ];
        });

        $completed = $completedRuns->map(fn (IntegrationServiceRun $run): array => [
            ...$this->runData($run, $newRecordsByRun[$run->id] ?? []),
            'service_id' => $run->integration_service_id,
            'service_name' => $run->service->name,
            'service_resource' => $run->service->resource,
            'export_url' => route('admin.services.runs.export', [$run->service, $run]),
        ]);

        $jobs = Schema::hasTable('jobs') ? DB::table('jobs')->orderBy('id')->get()
            ->map(function (object $job): array {
                $payload = json_decode($job->payload, true);

                return [
                    'id' => $job->id,
                    'queue' => $job->queue,
                    'name' => class_basename($payload['displayName'] ?? 'Job'),
                    'status' => $job->reserved_at ? 'running' : 'waiting',
                    'waiting_seconds' => max(0, now()->timestamp - (int) $job->created_at),
                ];
            })->values() : collect();

        return response()->json([
            'services' => $services,
            'completed' => $completed,
            'queue' => [
                'total' => $jobs->count(),
                'running' => $jobs->where('status', 'running')->count(),
                'waiting' => $jobs->where('status', 'waiting')->count(),
                'jobs' => $jobs,
            ],
            'generated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function clearCompleted(): RedirectResponse
    {
        $serviceIds = IntegrationService::query()
            ->whereIn('resource', ['webposto-new-records', 'webposto-database-changes', 'webposto-chimba-reconciliation', 'webposto-b1-reconciliation', 'webposto-b2-reconciliation', 'clickhouse-incremental-sync', 'alterdata-sync'])
            ->pluck('id');
        $deleted = IntegrationServiceRun::query()
            ->whereIn('integration_service_id', $serviceIds)
            ->whereIn('status', ['success', 'partial', 'failed'])
            ->delete();

        return back()->with('status', $deleted.' execucoes concluidas removidas do historico.');
    }

    public function clearServiceRuns(IntegrationService $service): RedirectResponse
    {
        $deleted = $service->runs()
            ->whereIn('status', ['success', 'partial', 'failed'])
            ->delete();

        return back()->with('status', $deleted.' relatórios concluídos removidos deste serviço.');
    }

    public function export(
        IntegrationService $service,
        IntegrationServiceRun $run,
        IntegrationRunXlsxExporter $exporter,
    ): BinaryFileResponse {
        abort_unless($run->integration_service_id === $service->id, 404);
        $filename = sprintf('service-%d-execucao-%d-%s.xlsx', $service->id, $run->id, $run->started_at?->format('Ymd-His') ?? 'sem-data');

        return response()->download(
            $exporter->create($run),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    /** @return array<string, mixed> */
    private function runData(IntegrationServiceRun $run, array $newRecordsByResource = []): array
    {
        return [
            'id' => $run->id,
            'status' => $run->status,
            'received' => $run->received,
            'inserted' => $run->inserted,
            'updated' => $run->updated,
            'unchanged' => $run->unchanged,
            'skipped' => $run->skipped,
            'changes_count' => $run->changes_count ?? $run->changes()->count(),
            'new_records_by_resource' => $newRecordsByResource,
            'duration_seconds' => $run->started_at
                ? (int) floor($run->started_at->diffInSeconds($run->finished_at ?? now())) : null,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'error' => $run->error,
            'companies' => $run->companyRuns->groupBy(
                fn ($block): string => str_starts_with((string) $block->block_key, 'shared-')
                    ? (string) $block->block_key
                    : 'company-'.$block->empresa_codigo,
            )
                ->map(fn ($blocks) => $this->aggregateCompanyBlocks($blocks))
                ->sortBy('position')->values()->all(),
        ];
    }

    /**
     * Um posto pode ter sido dividido em varios blocos de recursos (worker_blocks)
     * para paralelizar e isolar falhas por bloco, mas a tela so deve mostrar
     * progresso por posto, nao por bloco/tabela.
     *
     * @param  Collection<int, IntegrationServiceCompanyRun>  $blocks
     * @return array<string, mixed>
     */
    private function aggregateCompanyBlocks($blocks): array
    {
        $first = $blocks->sortBy('position')->first();
        $shared = str_starts_with((string) $first->block_key, 'shared-');
        $multiBlock = $blocks->count() > 1;
        $statuses = $blocks->pluck('status');
        $status = match (true) {
            $statuses->contains('running') => 'running',
            $statuses->contains('pending') => 'pending',
            $statuses->every(fn ($s) => $s === 'failed') => 'failed',
            $statuses->contains('failed') || $statuses->contains('partial') => 'partial',
            default => 'success',
        };
        $allFinished = $blocks->every(fn ($block) => $block->finished_at !== null);
        $startedAt = $blocks->pluck('started_at')->filter()->min();
        $finishedAt = $allFinished ? $blocks->pluck('finished_at')->filter()->max() : null;
        $errors = $blocks->pluck('error')->filter()->values();
        $currentResource = $multiBlock
            ? $this->summarizeActiveBlocks($blocks)
            : $first->current_resource;

        return [
            'empresa_codigo' => $shared ? null : $first->empresa_codigo,
            'empresa_nome' => $shared
                ? Str::before((string) $first->empresa_nome, ' ·').' compartilhado'
                : ($multiBlock ? Str::beforeLast($first->empresa_nome, ' · ') : $first->empresa_nome),
            'position' => $blocks->min('position'),
            'status' => $status,
            'current_resource' => $currentResource,
            'current_page' => $multiBlock ? null : $first->current_page,
            'current_cursor' => $multiBlock ? null : $first->current_cursor,
            'heartbeat_at' => $blocks->pluck('heartbeat_at')->filter()->max()?->toIso8601String(),
            'received' => $blocks->sum('received'),
            'inserted' => $blocks->sum('inserted'),
            'skipped' => $blocks->sum('skipped'),
            'resource_results' => $blocks->reduce(
                fn (array $carry, $block): array => [...$carry, ...(array) ($block->resource_results ?? [])],
                [],
            ),
            'duration_seconds' => $startedAt
                ? (int) floor($startedAt->diffInSeconds($finishedAt ?? now())) : null,
            'started_at' => $startedAt?->toIso8601String(),
            'finished_at' => $finishedAt?->toIso8601String(),
            'error' => $errors->isEmpty() ? null : $errors->implode("\n"),
        ];
    }

    /**
     * Junta a tabela/ultimo-codigo de cada bloco em andamento numa unica string,
     * pra nao perder a visibilidade granular so porque os blocos viraram 1 linha.
     *
     * @param  Collection<int, IntegrationServiceCompanyRun>  $blocks
     */
    private function summarizeActiveBlocks($blocks): ?string
    {
        $parts = $blocks
            ->filter(fn ($block) => $block->status === 'running' && $block->current_resource !== null)
            ->map(function ($block): string {
                $marker = $block->current_cursor !== null
                    ? ' (código '.$block->current_cursor.')'
                    : ($block->current_page !== null ? ' (pág. '.$block->current_page.')' : '');

                return $block->current_resource.$marker;
            });

        return $parts->isEmpty() ? null : $parts->implode(' · ');
    }
}
