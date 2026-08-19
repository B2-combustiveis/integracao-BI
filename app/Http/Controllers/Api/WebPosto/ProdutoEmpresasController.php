<?php

namespace App\Http\Controllers\Api\WebPosto;

use App\Http\Controllers\Controller;
use App\Services\WebPosto\ProdutoEmpresaImporter;
use App\Services\WebPosto\WebPostoClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ProdutoEmpresasController extends Controller
{
    private const ENDPOINT = '/INTEGRACAO/PRODUTO_EMPRESA';

    public function __invoke(
        Request $request,
        WebPostoClient $webPosto,
        ProdutoEmpresaImporter $importer,
    ): JsonResponse {
        $validated = $request->validate([
            'empresa_codigo' => ['required', 'integer', 'min:1'],
            'limite' => ['required', 'integer', 'min:1', 'max:2000'],
        ]);
        $empresaCodigo = (int) $validated['empresa_codigo'];
        $limite = (int) $validated['limite'];

        try {
            $result = $webPosto->get(self::ENDPOINT, $empresaCodigo, ['limite' => $limite]);
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
        $storage = $response->successful() ? $importer->import($payload) : null;

        return response()->json([
            'status' => $response->successful(),
            'service' => 'webposto',
            'endpoint' => self::ENDPOINT,
            'empresa_codigo' => $empresaCodigo,
            'limite' => $limite,
            'upstream' => [
                'http_status' => $response->status(),
                'successful' => $response->successful(),
                'response_time_ms' => $result['duration_ms'],
                'content_type' => $response->header('Content-Type'),
            ],
            'records_count' => is_array($payload['resultados'] ?? null) ? count($payload['resultados']) : null,
            'ultimo_codigo' => is_numeric($payload['ultimoCodigo'] ?? null) ? (int) $payload['ultimoCodigo'] : null,
            'storage' => $storage,
            'data' => $payload,
            'received_at' => now()->toIso8601String(),
        ], $response->successful() ? Response::HTTP_OK : Response::HTTP_BAD_GATEWAY);
    }
}
