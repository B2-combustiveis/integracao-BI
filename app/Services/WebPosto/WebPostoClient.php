<?php

namespace App\Services\WebPosto;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WebPostoClient
{
    public function __construct(
        private readonly WebPostoCredentialResolver $credentials,
    ) {
    }

    /**
     * @throws ConnectionException
     */
    public function get(string $endpoint, int $empresaCodigo, array $query = []): array
    {
        $credential = $this->credentials->resolve($empresaCodigo);
        $baseUrl = rtrim($credential->baseUrl, '/');
        $token = $credential->token;

        if ($baseUrl === '' || $token === '') {
            throw new RuntimeException("A credencial do WebPosto para a empresa {$empresaCodigo} está incompleta.");
        }

        $intervalMs = max(0, (int) config('integration.webposto.request_interval_ms', 350));
        if ($intervalMs > 0) usleep($intervalMs * 1000);
        $startedAt = hrtime(true);

        unset($query['chave']);

        $timeout = max(1, (int) config('integration.webposto.timeout', 30));
        $connectTimeout = max(1, (int) config('integration.webposto.connect_timeout', 5));
        $response = Http::acceptJson()
            ->connectTimeout($connectTimeout)
            ->timeout($timeout)
            ->withOptions(['curl' => [
                CURLOPT_CONNECTTIMEOUT_MS => $connectTimeout * 1000,
                CURLOPT_TIMEOUT_MS => $timeout * 1000,
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => $timeout,
            ]])
            ->retry(config('integration.webposto.retry_delays_ms', [500, 1500, 3000]), throw: false)
            ->get($baseUrl.'/'.ltrim($endpoint, '/'), [
                'chave' => $token,
                ...$query,
            ]);

        $this->credentials->markAsUsed($credential->id);
        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);
        return ['response' => $response, 'duration_ms' => $durationMs, 'payload' => $this->payload($response)];
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
