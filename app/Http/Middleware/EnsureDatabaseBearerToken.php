<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureDatabaseBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $providedToken = $request->bearerToken();

        if (! is_string($providedToken) || $providedToken === '') {
            return response()->json([
                'status' => false,
                'token_status' => 'missing',
                'message' => 'Bearer Token não informado.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $token = DB::table('api_tokens')
                ->select(['id', 'nome'])
                ->where('token', $providedToken)
                ->where('ativo', true)
                ->first();
        } catch (QueryException) {
            return response()->json([
                'status' => false,
                'token_status' => 'unavailable',
                'message' => 'Não foi possível consultar o token no banco de dados.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        if ($token === null) {
            return response()->json([
                'status' => false,
                'token_status' => 'invalid',
                'message' => 'Bearer Token inválido ou inativo.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
