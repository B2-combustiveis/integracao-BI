<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TokenVerificationController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = $request->attributes->get('api_token');

        return response()->json([
            'status' => true,
            'token_status' => 'valid',
            'authenticated' => true,
            'token' => [
                'id' => $token->id,
                'name' => $token->nome,
            ],
            'verified_at' => now()->toIso8601String(),
        ]);
    }
}
