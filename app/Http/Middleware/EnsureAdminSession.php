<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class EnsureAdminSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $tokenId = $request->session()->get('admin_api_token_id');
        try {
            $valid = is_numeric($tokenId) && DB::table('api_tokens')->where('id', $tokenId)->where('ativo', true)->exists();
        } catch (Throwable) {
            $valid = false;
        }
        if (! $valid) {
            $request->session()->forget(['admin_api_token_id', 'admin_api_token_name']);
            if ($request->expectsJson()) return response()->json(['message' => 'Sessão administrativa expirada.'], 401);
            return redirect()->route('admin.login')->with('error', 'Informe um token ativo para acessar o painel.');
        }
        return $next($request);
    }
}
