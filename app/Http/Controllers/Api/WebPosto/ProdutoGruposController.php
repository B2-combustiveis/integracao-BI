<?php

namespace App\Http\Controllers\Api\WebPosto;

use App\Http\Controllers\Controller;
use App\Services\WebPosto\ProdutoGrupoImporter;
use App\Services\WebPosto\WebPostoClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ProdutoGruposController extends Controller
{
    private const ENDPOINT = '/INTEGRACAO/GRUPO';

    public function __invoke(
        Request $request,
        WebPostoClient $webPosto,
        ProdutoGrupoImporter $importer,
    ): JsonResponse {
        $validated = $request->validate([
            'empresa_codigo' => ['required', 'integer', 'min:1'],
        ]);
        $empresaCodigo = (int) $validated['empresa_codigo'];

        try {
            $result = $webPosto->get(self::ENDPOINT, $empresaCodigo);
        } catch (ConnectionException) {
            return response()->json([
                'status' => false,
                'service' => 'webposto',
                'endpoint' => self::ENDPOINT,
                'connection_status' => 'unavailable',
                'message' => 'Não foi possível conectar ao WebPosto.',
            ], Response::HTTP_GATEWAY_TIMEOUT);
        } catch (RuntimeException $exception) {
            return response()->json([
                'status' => false,
                'service' => 'webposto',
                'endpoint' => self::ENDPOINT,
                'connection_status' => 'not_configured',
                'message' => $exception->getMessage(),
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $response = $result['response'];
        $payload = $result['payload'];
        $storage = $response->successful()
            ? $importer->import($payload, $empresaCodigo)
            : null;

        return response()->json([
            'status' => $response->successful(),
            'service' => 'webposto',
            'endpoint' => self::ENDPOINT,
            'empresa_codigo' => $empresaCodigo,
            'upstream' => [
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'response_time_ms' => $result['duration_ms'],
                'content_type' => $response->header('Content-Type'),
            ],
            'records_count' => is_array($payload['resultados'] ?? null) ? count($payload['resultados']) : null,
            'storage' => $storage,
            'data' => $payload,
            'received_at' => now()->toIso8601String(),
        ], $response->successful() ? Response::HTTP_OK : Response::HTTP_BAD_GATEWAY);
    }
}
