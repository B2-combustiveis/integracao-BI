<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationService;
use App\Models\IntegrationServiceRun;
use App\Services\Integration\IntegrationServiceDispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Services\Integration\IntegrationRunXlsxExporter;
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
        $dispatcher->dispatch($service->id);
        return back()->with('status', 'Execucao manual adicionada a fila.');
    }

    public function status(): JsonResponse
    {
        $serviceQuery = IntegrationService::query()
            ->whereIn('resource', ['webposto-new-records', 'webposto-database-changes']);
        $serviceIds = (clone $serviceQuery)->pluck('id');

        $serviceModels = $serviceQuery
            ->with(['runs' => fn ($query) => $query->latest()->limit(1)->withCount('changes')])
            ->get();

        $completedRuns = IntegrationServiceRun::query()
            ->whereIn('integration_service_id', $serviceIds)
            ->whereIn('status', ['success', 'failed'])
            ->with('service')->withCount('changes')
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
            ->whereIn('resource', ['webposto-new-records', 'webposto-database-changes'])
            ->pluck('id');
        $deleted = IntegrationServiceRun::query()
            ->whereIn('integration_service_id', $serviceIds)
            ->whereIn('status', ['success', 'failed'])
            ->delete();

        return back()->with('status', $deleted.' execucoes concluidas removidas do historico.');
    }

    public function clearServiceRuns(IntegrationService $service): RedirectResponse
    {
        $deleted = $service->runs()
            ->whereIn('status', ['success', 'failed'])
            ->delete();

        return back()->with('status', $deleted.' relatórios concluídos removidos deste serviço.');
    }

    public function export(
        IntegrationService $service,
        IntegrationServiceRun $run,
        IntegrationRunXlsxExporter $exporter,
    ): BinaryFileResponse
    {
        abort_unless($run->integration_service_id === $service->id, 404);
        $filename = sprintf('service-%d-execucao-%d-%s.xlsx', $service->id, $run->id, $run->started_at?->format('Ymd-His') ?? 'sem-data');
        return response()->download(
            $exporter->create($run),
            $filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        )->deleteFileAfterSend(true);
    }

    public function update(Request $request, IntegrationService $service): RedirectResponse
    {
        $validated = $request->validate([
            'frequency_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'lookback_days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);
        $service->update([
            ...$validated,
            'next_run_at' => $service->active ? now()->addMinutes((int) $validated['frequency_minutes']) : null,
        ]);
        return back()->with('status', 'Intervalo do servico atualizado.');
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
                ? $run->started_at->diffInSeconds($run->finished_at ?? now()) : null,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'error' => $run->error,
        ];
    }
}
