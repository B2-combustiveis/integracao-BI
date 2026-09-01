<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncWebPostoCompanyInitialLoad;
use App\Models\WebPostoCredential;
use App\Models\WebPostoInitialSyncRun;
use App\Models\WebPostoReloadRun;
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

        $count = count($result['companies']);

        return back()->with('status', $count === 1
            ? 'Posto identificado e credencial salva.'
            : $count.' postos identificados e credencial salva.');
    }

    public function synchronize(int $empresa): RedirectResponse
    {
        $credential = WebPostoCredential::query()
            ->where('empresa_codigo', $empresa)
            ->firstOrFail();

        if ($credential->implantacao_status === WebPostoCredential::STATUS_SINCRONIZADO) {
            return back()->with('status', 'Esta empresa já está sincronizada.');
        }

        $running = WebPostoInitialSyncRun::query()
            ->where('empresa_codigo', $empresa)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        if ($running) {
            return back()->with('status', 'A carga inicial desta empresa já está em andamento.');
        }

        $reloadRunning = WebPostoReloadRun::query()
            ->where('empresa_codigo', $empresa)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        if ($reloadRunning) {
            return back()->withErrors([
                'sync' => 'Existe uma recarga de dados em andamento para esta empresa. Aguarde a conclusão.',
            ]);
        }

        $run = WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => $empresa,
            'status' => 'queued',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::RESOURCES),
            'completed_resources' => [],
        ]);
        SyncWebPostoCompanyInitialLoad::dispatch($run->id);

        return back()->with('status', 'Carga inicial adicionada à fila.');
    }
}
