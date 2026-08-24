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
use Symfony\Component\HttpFoundation\StreamedResponse;

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
            ->whereIn('resource', ['webposto-new-records', 'webposto-database-changes', 'webposto-modified-records']);
        $serviceIds = (clone $serviceQuery)->pluck('id');

        $services = $serviceQuery
            ->with(['runs' => fn ($query) => $query->latest()->limit(1)->withCount('changes')])
            ->get()->map(function (IntegrationService $service): array {
                $run = $service->runs->first();
                return [
                    'id' => $service->id,
                    'name' => $service->name,
                    'active' => $service->active,
                    'frequency_minutes' => $service->frequency_minutes,
                    'next_run_at' => $service->next_run_at?->toIso8601String(),
                    'run' => $run ? $this->runData($run) : null,
                ];
            });

        $completed = IntegrationServiceRun::query()
            ->whereIn('integration_service_id', $serviceIds)
            ->whereIn('status', ['success', 'failed'])
            ->with('service')->withCount('changes')
            ->latest('finished_at')->limit(20)->get()
            ->map(fn (IntegrationServiceRun $run): array => [
                ...$this->runData($run),
                'service_id' => $run->integration_service_id,
                'service_name' => $run->service->name,
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
        ]);
    }

    public function clearCompleted(): RedirectResponse
    {
        $serviceIds = IntegrationService::query()
            ->whereIn('resource', ['webposto-new-records', 'webposto-database-changes', 'webposto-modified-records'])
            ->pluck('id');
        $deleted = IntegrationServiceRun::query()
            ->whereIn('integration_service_id', $serviceIds)
            ->whereIn('status', ['success', 'failed'])
            ->delete();

        return back()->with('status', $deleted.' execucoes concluidas removidas do historico.');
    }

    public function export(IntegrationService $service, IntegrationServiceRun $run): StreamedResponse
    {
        abort_unless($run->integration_service_id === $service->id, 404);
        $run->load('service');
        $filename = sprintf('service-%d-execucao-%d-%s.csv', $service->id, $run->id, $run->started_at?->format('Ymd-His') ?? 'sem-data');

        return response()->streamDownload(function () use ($run): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'execucao_id', 'servico', 'empresa', 'status', 'inicio_execucao', 'fim_execucao',
                'duracao_segundos', 'recebidos', 'novos_total', 'atualizados_total', 'inalterados',
                'ignorados', 'recurso', 'tabela', 'acao', 'chave_natural',
                'data_hora_atualizacao_origem', 'detectado_em', 'dados',
            ], ';');

            $duration = $run->started_at ? $run->started_at->diffInSeconds($run->finished_at ?? now()) : null;
            $base = [
                $run->id, $run->service->name, $run->service->empresa_codigo, $run->status,
                $run->started_at?->format('Y-m-d H:i:s'), $run->finished_at?->format('Y-m-d H:i:s'),
                $duration, $run->received, $run->inserted, $run->updated, $run->unchanged, $run->skipped,
            ];

            $hasChanges = false;
            $run->changes()->orderBy('table_name')->orderBy('action')->orderBy('id')
                ->chunkById(500, function ($changes) use ($output, $base, &$hasChanges): void {
                    foreach ($changes as $change) {
                        $hasChanges = true;
                        fputcsv($output, [
                            ...$base, $change->resource, $change->table_name,
                            $change->action === 'inserted' ? 'novo' : 'atualizado',
                            json_encode($change->natural_key, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            $change->source_updated_at?->format('Y-m-d H:i:s'),
                            $change->detected_at?->format('Y-m-d H:i:s'),
                            json_encode($change->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ], ';');
                    }
                });

            if (! $hasChanges) {
                fputcsv($output, [...$base, '', '', '', '', '', '', 'Nenhum registro novo ou atualizado nesta execucao'], ';');
            }
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
    private function runData(IntegrationServiceRun $run): array
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
            'duration_seconds' => $run->started_at
                ? $run->started_at->diffInSeconds($run->finished_at ?? now()) : null,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'error' => $run->error,
        ];
    }
}
