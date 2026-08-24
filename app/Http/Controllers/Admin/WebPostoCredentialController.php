<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\WebPosto\WebPostoCredentialRegistrationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class WebPostoCredentialController extends Controller
{
    public function store(Request $request, WebPostoCredentialRegistrationService $registration): RedirectResponse
    {
        $validated = $request->validate([
            'base_url' => ['required', 'url', 'max:500'],
            'token' => ['required', 'string', 'max:4000'],
        ]);

        try {
            $result = $registration->register($validated['base_url'], $validated['token']);
        } catch (Throwable $exception) {
            return back()->withInput($request->except('token'))->withErrors(['token' => $exception->getMessage()]);
        }

        return back()->with('status', count($result['companies']).' empresa(s) identificada(s) e credencial(is) salva(s).');
    }
}
