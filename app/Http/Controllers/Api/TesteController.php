<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class TesteController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $database = $this->databaseStatus();

        return response()->json([
            'status' => $database['connected'],
            'connection_status' => $database['connected'] ? 'ok' : 'error',
            'authenticated' => true,
            'connection' => [
                'method' => $request->method(),
                'url' => $request->url(),
                'ip' => $request->ip(),
                'protocol' => $request->getScheme(),
            ],
            'database' => $database,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    private function databaseStatus(): array
    {
        $connection = DB::connection();

        try {
            $connection->getPdo();

            return [
                'connected' => true,
                'driver' => $connection->getDriverName(),
                'database' => $connection->getDatabaseName(),
                'host' => config('database.connections.'.config('database.default').'.host'),
                'port' => config('database.connections.'.config('database.default').'.port'),
            ];
        } catch (Throwable $exception) {
            return [
                'connected' => false,
                'driver' => config('database.default'),
                'database' => config('database.connections.'.config('database.default').'.database'),
                'host' => config('database.connections.'.config('database.default').'.host'),
                'port' => config('database.connections.'.config('database.default').'.port'),
                'error' => 'Não foi possível conectar ao banco de dados.',
                'error_code' => (string) $exception->getCode(),
            ];
        }
    }
}
