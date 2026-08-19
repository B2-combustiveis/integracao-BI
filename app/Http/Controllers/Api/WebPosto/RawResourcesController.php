<?php

namespace App\Http\Controllers\Api\WebPosto;

use App\Http\Controllers\Controller;
use App\Services\WebPosto\RawResourceImporter;
use App\Services\WebPosto\WebPostoClient;
use App\Services\WebPosto\WebPostoResourceCatalog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class RawResourcesController extends Controller
{
    public function __invoke(Request $request, string $resource, WebPostoResourceCatalog $catalog, WebPostoClient $client, RawResourceImporter $importer): JsonResponse
    {
        $definition = $catalog->get($resource);
        $validated = $request->validate(['empresa_codigo' => ['required', 'integer', 'min:1'], ...$definition['rules']]);
        $empresaCodigo = (int) $validated['empresa_codigo'];
        $endpoint = $definition['endpoint'];
        foreach ($definition['pathParameters'] as $parameter) {
            $endpoint = str_replace('{'.$parameter.'}', rawurlencode((string) $validated[$parameter]), $endpoint);
        }
        $query = [];
        foreach ($definition['queryMap'] as $local => $upstream) {
            if (array_key_exists($local, $validated) && $validated[$local] !== null && $validated[$local] !== '') $query[$upstream] = $validated[$local];
        }

        try {
            $result = $client->get($endpoint, $empresaCodigo, $query);
        } catch (ConnectionException) {
            return response()->json(['status' => false, 'service' => 'webposto', 'endpoint' => $endpoint, 'connection_status' => 'unavailable', 'message' => 'Não foi possível conectar ao WebPosto.'], Response::HTTP_GATEWAY_TIMEOUT);
        } catch (RuntimeException $exception) {
            return response()->json(['status' => false, 'service' => 'webposto', 'endpoint' => $endpoint, 'connection_status' => 'not_configured', 'message' => $exception->getMessage()], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $response = $result['response'];
        $payload = $result['payload'];
        $storage = $response->successful() ? $importer->import($payload, $empresaCodigo, $definition['table'], $query) : null;
        $count = is_array($payload['resultados'] ?? null) ? count($payload['resultados']) : (is_array($payload) && array_is_list($payload) ? count($payload) : null);

        return response()->json([
            'status' => $response->successful(), 'service' => 'webposto', 'resource' => $resource,
            'endpoint' => $endpoint, 'empresa_codigo' => $empresaCodigo,
            'upstream' => ['http_status' => $response->status(), 'successful' => $response->successful(), 'response_time_ms' => $result['duration_ms'], 'content_type' => $response->header('Content-Type')],
            'records_count' => $count, 'storage' => $storage, 'data' => $payload, 'received_at' => now()->toIso8601String(),
        ], $response->successful() ? Response::HTTP_OK : Response::HTTP_BAD_GATEWAY);
    }
}
