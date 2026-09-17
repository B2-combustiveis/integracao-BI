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
use Illuminate\Support\Str;
use Throwable;

class WebPostoCredentialController extends Controller
{
    public function store(Request $request, WebPostoCredentialRegistrationService $registration): RedirectResponse
    {
        $validated = $request->validate([
            'base_url' => ['required', 'url', 'max:500'],
            'token' => ['required', 'string', 'max:4000'],
            'base' => ['required', 'in:b1,b2,chimba'],
        ]);

        try {
            $result = $registration->register($validated['base_url'], $validated['token'], $validated['base']);
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

        if ($credential->base === WebPostoCredential::BASE_B1) {
            $b1CompanyCodes = WebPostoCredential::query()
                ->where('base', WebPostoCredential::BASE_B1)
                ->pluck('empresa_codigo');
            $b1Running = WebPostoInitialSyncRun::query()
                ->whereIn('empresa_codigo', $b1CompanyCodes)
                ->whereIn('status', ['queued', 'running'])
                ->count();
            if ($b1Running >= 5) {
                return back()->with('status', 'O limite de cinco postos B1 em sincronização foi atingido. Aguarde uma vaga.');
            }
        }

        $running = WebPostoInitialSyncRun::query()
            ->where('empresa_codigo', $empresa)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        if ($running) {
            return back()->with('status', 'A carga inicial desta empresa já está em andamento.');
        }

        WebPostoReloadRun::query()
            ->where('empresa_codigo', $empresa)
            ->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '<=', now()->subHours(12))
            ->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => 'Recarga residual encerrada automaticamente antes de uma nova tentativa de carga inicial.',
            ]);

        $reloadRunning = WebPostoReloadRun::query()
            ->where('empresa_codigo', $empresa)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        if ($reloadRunning) {
            return back()->withErrors([
                'sync' => 'Existe uma recarga de dados em andamento para esta empresa. Aguarde a conclusão.',
            ]);
        }

        $wasSynchronized = $credential->implantacao_status === WebPostoCredential::STATUS_SINCRONIZADO;
        $previous = $wasSynchronized ? null : WebPostoInitialSyncRun::query()
            ->where('empresa_codigo', $empresa)
            ->whereIn('status', ['failed', 'cancelled'])
            ->latest('id')
            ->first();
        $run = WebPostoInitialSyncRun::query()->create([
            'empresa_codigo' => $empresa,
            'batch_key' => $credential->base === WebPostoCredential::BASE_B1 ? (string) Str::uuid() : null,
            'status' => 'queued',
            'total_resources' => count(SyncWebPostoCompanyInitialLoad::requiredResourcesFor((string) $credential->base)),
            'completed_resources' => array_values(array_unique($previous?->completed_resources ?? [])),
            'was_synchronized' => $wasSynchronized,
        ]);
        SyncWebPostoCompanyInitialLoad::dispatch($run->id);

        return back()->with('status', $run->was_synchronized
            ? 'Ressincronização completa adicionada à fila.'
            : 'Carga inicial adicionada à fila.');
    }
}
