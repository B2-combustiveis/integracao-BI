<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationService;
use App\Services\Integration\IntegrationServiceDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class IntegrationServiceController extends Controller
{
    public function pause(IntegrationService $service): RedirectResponse
    {
        $service->update(['active' => false, 'next_run_at' => null]);
        return back()->with('status', 'Serviço pausado.');
    }

    public function resume(IntegrationService $service): RedirectResponse
    {
        $service->update(['active' => true, 'next_run_at' => now()]);
        return back()->with('status', 'Serviço ativado.');
    }

    public function run(IntegrationService $service, IntegrationServiceDispatcher $dispatcher): RedirectResponse
    {
        $dispatcher->dispatch($service->id);
        return back()->with('status', 'Execução adicionada à fila.');
    }

    public function update(Request $request, IntegrationService $service): RedirectResponse
    {
        $validated = $request->validate([
            'frequency_minutes' => ['required', 'integer', 'min:1', 'max:10080'],
            'lookback_days' => ['required', 'integer', 'min:1', 'max:30'],
        ]);
        $service->update([
            ...$validated,
            'next_run_at' => $service->active ? now()->addMinutes((int) $validated['frequency_minutes']) : null,
        ]);
        return back()->with('status', 'Configuração do serviço atualizada.');
    }
}
