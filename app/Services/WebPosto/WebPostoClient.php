<?php

namespace App\Services\WebPosto;

use App\Models\WebPostoSyncControl;
use App\Models\WebPostoSyncEndpointRun;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class WebPostoClient
{
    public function __construct(
        private readonly WebPostoCredentialResolver $credentials,
        private readonly WebPostoSyncStrategyCatalog $strategies,
    ) {
    }

    /**
     * @throws ConnectionException
     */
    public function get(string $endpoint, int $empresaCodigo, array $query = []): array
    {
        $credential = $this->credentials->resolve($empresaCodigo);
        $control = null;
        $endpointRun = null;

        if (! app()->runningUnitTests()
            && Schema::hasTable('webposto_sync_controls')
            && Schema::hasTable('webposto_sync_endpoint_runs')) {
            $control = WebPostoSyncControl::query()->firstOrCreate(
                ['empresa_codigo' => $empresaCodigo, 'endpoint' => $endpoint],
                ['strategy' => $this->strategies->strategyFor($endpoint)]
            );
            $control->update(['status' => 'running', 'last_started_at' => now(), 'last_error' => null]);
            $endpointRun = WebPostoSyncEndpointRun::query()->create([
                'webposto_sync_control_id' => $control->id,
                'mode' => 'request',
                'status' => 'running',
                'started_at' => now(),
            ]);
        }
        $baseUrl = rtrim($credential->baseUrl, '/');
        $token = $credential->token;

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException("A credencial do WebPosto para a empresa {$empresaCodigo} está incompleta.");
        }

        $intervalMs = max(0, (int) config('integration.webposto.request_interval_ms', 350));
        if ($intervalMs > 0) usleep($intervalMs * 1000);
        $startedAt = hrtime(true);

        unset($query['chave']);

        $response = Http::acceptJson()
            ->connectTimeout((int) config('integration.webposto.connect_timeout', 5))
            ->timeout((int) config('integration.webposto.timeout', 30))
            ->retry(config('integration.webposto.retry_delays_ms', [250, 750, 1500]), throw: false)
            ->get($baseUrl.'/'.ltrim($endpoint, '/'), [
                'chave' => $token,
                ...$query,
            ]);

        $this->credentials->markAsUsed($credential->id);
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        $payload = $this->payload($response);
        $received = is_array($payload['resultados'] ?? null) ? count($payload['resultados']) : 0;
        $lastCode = is_numeric($payload['ultimoCodigo'] ?? null) ? (int) $payload['ultimoCodigo'] : null;
        $ok = $response->successful();
        $control?->update([
            'status' => $ok ? 'ok' : 'error',
            'last_code' => $lastCode ?? $control?->last_code ?? 0,
            'last_completed_at' => now(),
            'consecutive_failures' => $ok ? 0 : ($control?->consecutive_failures ?? 0) + 1,
            'last_error' => $ok ? null : "HTTP {$response->status()}",
        ]);
        $endpointRun?->update([
            'status' => $ok ? 'success' : 'failed',
            'received' => $received,
            'duration_ms' => $durationMs, 'error' => $ok ? null : "HTTP {$response->status()}", 'finished_at' => now()]);

        return ['response' => $response, 'duration_ms' => $durationMs, 'payload' => $payload];
    }

    private function payload(Response $response): mixed
    {
        $body = $response->body();

        if ($body === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $body;
    }
}
