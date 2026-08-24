<?php

namespace App\Services\WebPosto;

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
     * @param array{type?: string, request_field?: string, response_field?: string, initial_value?: int, prefer_initial_value?: bool, single_page?: bool} $cursor
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
    ): array {
        $type = (string) ($cursor['type'] ?? 'ultimo_codigo');
        $requestField = (string) ($cursor['request_field'] ?? 'ultimoCodigo');
        $responseField = (string) ($cursor['response_field'] ?? 'ultimoCodigo');
        $initialValue = (int) ($cursor['initial_value'] ?? 0);
        $preferInitialValue = (bool) ($cursor['prefer_initial_value'] ?? false);
        $singlePage = (bool) ($cursor['single_page'] ?? false);
        $control = WebPostoSyncControl::query()->firstOrCreate(
            ['empresa_codigo' => $empresaCodigo, 'endpoint' => $controlKey ?? $endpoint],
            [
                'strategy' => $this->strategies->strategyFor($endpoint),
                'last_code' => $initialValue,
                'metadata' => ['cursor_type' => $type, 'cursor_value' => $initialValue],
            ],
        );
        $metadata = is_array($control->metadata) ? $control->metadata : [];
        $current = $preferInitialValue
            ? $initialValue
            : (is_numeric($metadata['cursor_value'] ?? null)
                ? (int) $metadata['cursor_value']
                : (int) $control->last_code);
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
            'metadata' => [...$metadata, 'cursor_type' => $type, 'cursor_value' => $current],
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
                $requestQuery = $page === 0 && $initialQuery !== null
                    && ($initialLoad || ($omitCursorWhenZero && $current === 0))
                    ? [...$query, ...$initialQuery]
                    : [...$query, $requestField => $current];
                $result = $this->client->get($endpoint, $empresaCodigo, $requestQuery);
                $totals['duration_ms'] += (int) $result['duration_ms'];

                if (! $result['response']->successful()) {
                    throw new RuntimeException("WebPosto respondeu HTTP {$result['response']->status()} em {$endpoint}.");
                }

                $payload = $result['payload'];
                $rows = is_array($payload) && is_array($payload['resultados'] ?? null)
                    ? $payload['resultados']
                    : [];
                if ($rows === []) {
                    $this->finish($control, $run, $totals);

                    return $totals;
                }

                $next = is_array($payload) && is_numeric($payload[$responseField] ?? null)
                    ? (int) $payload[$responseField]
                    : null;
                if ($next === null || $next <= $current) {
                    throw new RuntimeException("Cursor {$responseField} ausente ou sem avanco em {$endpoint}.");
                }

                $stored = DB::connection('webposto')->transaction(
                    fn (): array => $persist($payload, $requestQuery),
                );
                $totals['pages']++;
                $totals['received'] += count($rows);
                foreach (['inserted', 'updated', 'unchanged', 'skipped'] as $field) {
                    $totals[$field] += (int) ($stored[$field] ?? 0);
                }

                $current = $next;
                $metadata = [...$metadata, 'cursor_type' => $type, 'cursor_value' => $current];
                $control->update(['last_code' => $current, 'metadata' => $metadata]);
                if ($singlePage) {
                    $this->finish($control, $run, $totals);
                    return $totals;
                }
            }

            throw new RuntimeException("Limite de paginacao atingido em {$endpoint}.");
        } catch (Throwable $exception) {
            $message = mb_substr($exception->getMessage(), 0, 2000);
            $control->update([
                'status' => 'error',
                'last_completed_at' => now(),
                'consecutive_failures' => $control->consecutive_failures + 1,
                'last_error' => $message,
            ]);
            $run->update([...$totals, 'status' => 'failed', 'error' => $message, 'finished_at' => now()]);

            throw $exception;
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
        ]);
        $run->update([...$totals, 'status' => 'success', 'finished_at' => now()]);
    }
}
