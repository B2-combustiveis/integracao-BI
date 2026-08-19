<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminSessionController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($request->session()->has('admin_api_token_id')) return redirect()->route('admin.dashboard');
        return view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['token' => ['required', 'string', 'max:255']]);
        $token = DB::table('api_tokens')->select(['id', 'nome'])->where('token', $validated['token'])->where('ativo', true)->first();
        if ($token === null) return back()->withInput()->withErrors(['token' => 'Token inválido ou inativo.']);
        $request->session()->regenerate();
        $request->session()->put(['admin_api_token_id' => $token->id, 'admin_api_token_name' => $token->nome]);
        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        return redirect()->route('admin.login');
    }
}
