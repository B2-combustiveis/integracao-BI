<?php

namespace App\Http\Controllers\Api\WebPosto;

use App\Http\Controllers\Controller;
use App\Services\Bi\EmpresaBiSynchronizer;
use App\Services\WebPosto\EmpresaImporter;
use App\Services\WebPosto\WebPostoClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class EmpresasController extends Controller
{
    private const ENDPOINT = '/INTEGRACAO/EMPRESAS';

    public function __invoke(
        Request $request,
        WebPostoClient $webPosto,
        EmpresaImporter $importer,
        EmpresaBiSynchronizer $biSynchronizer,
    ): JsonResponse
    {
        $validated = $request->validate([
            'empresa_codigo' => ['required', 'integer', 'min:1'],
        ]);

        $empresaCodigo = (int) $validated['empresa_codigo'];

        try {
            $result = $webPosto->get(self::ENDPOINT, $empresaCodigo);
        } catch (ConnectionException $exception) {
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
        $storage = null;

        if ($response->successful()) {
            $storage = [
                'raw' => $importer->import($payload),
                'bi' => $biSynchronizer->sync($payload),
            ];
        }

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
            'records_count' => $this->recordsCount($payload),
            'storage' => $storage,
            'data' => $payload,
            'received_at' => now()->toIso8601String(),
        ], $response->successful() ? Response::HTTP_OK : Response::HTTP_BAD_GATEWAY);
    }

    private function recordsCount(mixed $payload): ?int
    {
        if (! is_array($payload)) {
            return null;
        }

        if (array_is_list($payload)) {
            return count($payload);
        }

        foreach (['data', 'empresas', 'resultado', 'resultados', 'items'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key]) && array_is_list($payload[$key])) {
                return count($payload[$key]);
            }
        }

        return null;
    }
}
