<?php

namespace App\Services\WebPosto;

use App\Exceptions\WebPostoSynchronizationCancelled;
use App\Models\WebPostoInitialSyncRun;
use App\Models\WebPostoSyncControl;
use App\Models\WebPostoSyncEndpointRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class WebPostoCursorSynchronizer
{
    public function __construct(
        private readonly WebPostoClient $client,
        private readonly WebPostoSyncStrategyCatalog $strategies,
    ) {
    }

    /**
     * @param callable(mixed, array<string, mixed>): array<string, mixed> $persist
     * @param array<string, mixed> $query
     * @param array{type?: string, request_field?: string, response_field?: string, initial_value?: int, prefer_initial_value?: bool, single_page?: bool, direct_list?: bool, request_overlap?: int} $cursor
     * @param array<string, mixed>|null $initialQuery
     * @return array<string, int>
     */
    public function synchronize(
        string $endpoint,
        int $empresaCodigo,
        callable $persist,
        array $query = [],
        array $cursor = [],
        ?array $initialQuery = null,
        int $maxPages = 100000,
        ?int $integrationServiceRunId = null,
        ?string $controlKey = null,
        bool $omitCursorWhenZero = false,
        bool $resumeFromCheckpoint = false,
        ?callable $onPageProgress = null,
        ?callable $shouldContinue = null,
    ): array {
        $this->ensureActive($shouldContinue);
        $type = (string) ($cursor['type'] ?? 'ultimo_codigo');
        $requestField = (string) ($cursor['request_field'] ?? 'ultimoCodigo');
        $responseField = (string) ($cursor['response_field'] ?? 'ultimoCodigo');
        $initialValue = (int) ($cursor['initial_value'] ?? 0);
        $preferInitialValue = (bool) ($cursor['prefer_initial_value'] ?? false);
        $singlePage = (bool) ($cursor['single_page'] ?? false);
        $directList = (bool) ($cursor['direct_list'] ?? false);
        $requestOverlap = max(0, (int) ($cursor['request_overlap'] ?? 0));
        $control = WebPostoSyncControl::query()->firstOrCreate(
            ['empresa_codigo' => $empresaCodigo, 'endpoint' => $controlKey ?? $endpoint],
            [
                'strategy' => $this->strategies->strategyFor($endpoint),
                'last_code' => $initialValue,
                'metadata' => ['cursor_type' => $type, 'cursor_value' => $initialValue],
            ],
        );
        $metadata = is_array($control->metadata) ? $control->metadata : [];
        $canResume = $resumeFromCheckpoint
            && ! $control->wasRecentlyCreated
            && in_array($control->status, ['error', 'idle', 'cancelled'], true)
            && ($metadata['resume_available'] ?? true) === true
            && (int) $control->last_code > $initialValue;
        $pageOffset = $canResume ? (int) ($metadata['checkpoint_page'] ?? 0) : 0;
        $persistedCursor = is_numeric($metadata['cursor_value'] ?? null)
            ? (int) $metadata['cursor_value']
            : (int) $control->last_code;
        $current = $canResume ? (int) $control->last_code : ($preferInitialValue
            ? $initialValue
            : max($initialValue, $persistedCursor));
        $initialLoad = $control->wasRecentlyCreated;
        $totals = [
            'pages' => 0,
            'received' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'duration_ms' => 0,
        ];

        $control->update([
            'status' => 'running',
            'last_code' => $current,
            'last_started_at' => now(),
            'last_error' => null,
            'metadata' => [...$metadata,
                'cursor_type' => $type,
                'cursor_value' => $current,
                'resumed' => $canResume,
                'resume_available' => $canResume,
                'heartbeat_at' => now()->toIso8601String(),
            ],
        ]);
        $run = WebPostoSyncEndpointRun::query()->create([
            'webposto_sync_control_id' => $control->id,
            'integration_service_run_id' => $integrationServiceRunId,
            'mode' => $initialLoad ? 'initial' : 'incremental',
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            for ($page = 0; $page < $maxPages; $page++) {
                $this->ensureActive($shouldContinue);
                $visiblePage = $pageOffset + $page + 1;
                $heartbeat = now();
                $metadata = [...$metadata,
                    'cursor_type' => $type,
                    'cursor_value' => $current,
                    'requesting_page' => $visiblePage,
                    'heartbeat_at' => $heartbeat->toIso8601String(),
                    'resume_available' => $totals['pages'] > 0 || $canResume,
                ];
                $control->update(['last_code' => $current, 'metadata' => $metadata]);
                if ($onPageProgress !== null) {
                    $onPageProgress(['state' => 'requesting', 'page' => $visiblePage, 'cursor' => $current, 'heartbeat_at' => $heartbeat]);
                }
                $requestQuery = $directList
                    ? $query
                    : ($page === 0 && $initialQuery !== null
                    && ($initialLoad || ($omitCursorWhenZero && $current === 0))
                    ? [...$query, ...$initialQuery]
                    : [...$query, $requestField => max(0, $current - $requestOverlap)]);
                $result = $this->client->get($endpoint, $empresaCodigo, $requestQuery);
                $totals['duration_ms'] += (int) $result['duration_ms'];
                $this->ensureActive($shouldContinue);

                if (! $result['response']->successful()) {
                    throw new RuntimeException("WebPosto respondeu HTTP {$result['response']->status()} em {$endpoint}.");
                }

                $payload = $result['payload'];
                $directRows = $directList && is_array($payload) && array_is_list($payload)
                    ? $payload
                    : ($directList && is_array($payload) && is_array($payload['resultados'] ?? null)
                        ? $payload['resultados']
                        : null);
                if ($directList && $directRows === null) {
                    throw new RuntimeException('Formato de lista direta inesperado em '.$endpoint.'.');
                }
                $rows = $directList
                    ? $directRows
                    : (is_array($payload) && is_array($payload['resultados'] ?? null)
                        ? $payload['resultados']
                        : []);
                if ($rows === []) {
                    $this->finish($control, $run, $totals);

                    return $totals;
                }

                $next = is_array($payload) && ! $directList && is_numeric($payload[$responseField] ?? null)
                    ? (int) $payload[$responseField]
                    : null;
                $terminalOverlapPage = ! $directList
                    && $requestOverlap > 0
                    && $next === $current;
                if (! $directList && ($next === null || $next < $current
                    || ($next === $current && ! $terminalOverlapPage))) {
                    throw new RuntimeException("Cursor {$responseField} ausente ou sem avanco em {$endpoint}.");
                }

                $persistPayload = $directList ? ['resultados' => $rows] : $payload;
                $stored = DB::connection('webposto')->transaction(
                    fn (): array => $persist($persistPayload, $requestQuery),
                );
                $totals['pages']++;
                $totals['received'] += array_key_exists('received', $stored)
                    ? (int) $stored['received']
                    : count($rows);
                foreach (['inserted', 'updated', 'unchanged', 'skipped'] as $field) {
                    $totals[$field] += (int) ($stored[$field] ?? 0);
                }

                // A API pode devolver novamente o codigo da borda na pagina final.
                // Persistimos essa pagina (ela pode conter outros vinculos do mesmo
                // codigo) e encerramos, em vez de tratar o empate como travamento.
                if ($directList || $terminalOverlapPage) {
                    $this->finish($control, $run, $totals);

                    return $totals;
                }

                $current = $next;
                $heartbeat = now();
                $metadata = [...$metadata,
                    'cursor_type' => $type,
                    'cursor_value' => $current,
                    'checkpoint_cursor' => $current,
                    'checkpoint_page' => $visiblePage,
                    'heartbeat_at' => $heartbeat->toIso8601String(),
                    'resume_available' => true,
                ];
                $control->update(['last_code' => $current, 'metadata' => $metadata]);
                $run->update([...$totals]);
                if ($onPageProgress !== null) {
                    $onPageProgress(['state' => 'persisted', 'page' => $visiblePage, 'cursor' => $current, 'heartbeat_at' => $heartbeat, ...$totals]);
                }
                if ($singlePage) {
                    $this->finish($control, $run, $totals);
                    return $totals;
                }
            }

            throw new RuntimeException("Limite de paginacao atingido em {$endpoint}.");
        } catch (WebPostoSynchronizationCancelled $exception) {
            $control->update([
                'status' => 'idle',
                'last_completed_at' => now(),
                'last_error' => null,
                'metadata' => [...$metadata,
                    'resume_available' => (int) $control->last_code > $initialValue,
                    'heartbeat_at' => now()->toIso8601String(),
                ],
            ]);
            $run->update([...$totals, 'status' => 'failed', 'error' => $exception->getMessage(), 'finished_at' => now()]);

            throw $exception;
        } catch (Throwable $exception) {
            $message = mb_substr((string) preg_replace('/([?&]chave=)[^&\s]+/i', '$1SEU_TOKEN_AQUI', $exception->getMessage()), 0, 2000);
            $control->update([
                'status' => 'error',
                'last_completed_at' => now(),
                'consecutive_failures' => $control->consecutive_failures + 1,
                'last_error' => $message,
                'metadata' => [...$metadata,
                    'resume_available' => $resumeFromCheckpoint && (int) $control->last_code > $initialValue,
                    'heartbeat_at' => now()->toIso8601String(),
                ],
            ]);
            $run->update([...$totals, 'status' => 'failed', 'error' => $message, 'finished_at' => now()]);

            throw $exception;
        }
    }

    private function ensureActive(?callable $shouldContinue): void
    {
        if ($shouldContinue !== null && $shouldContinue() !== true) {
            throw new WebPostoSynchronizationCancelled;
        }

        // Cargas iniciais executam comandos Artisan no mesmo processo do job.
        // O contexto abaixo permite que toda paginacao pare assim que a carga
        // for cancelada, inclusive antes de persistir a resposta da API.
        $initialRunId = config('integration.runtime.webposto_initial_run_id');
        if ($initialRunId !== null && ! WebPostoInitialSyncRun::query()
            ->whereKey((int) $initialRunId)
            ->where('status', 'running')
            ->exists()) {
            throw new WebPostoSynchronizationCancelled;
        }
    }

    /** @param array<string, int> $totals */
    private function finish(
        WebPostoSyncControl $control,
        WebPostoSyncEndpointRun $run,
        array $totals,
    ): void {
        $control->update([
            'status' => 'ok',
            'last_completed_at' => now(),
            'consecutive_failures' => 0,
            'last_error' => null,
            'metadata' => [
                ...(is_array($control->metadata) ? $control->metadata : []),
                'resume_available' => false,
                'heartbeat_at' => now()->toIso8601String(),
            ],
        ]);
        $run->update([...$totals, 'status' => 'success', 'finished_at' => now()]);
    }
}
