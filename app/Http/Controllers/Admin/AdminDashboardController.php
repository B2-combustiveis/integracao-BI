<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;
use App\Models\IntegrationService;
use App\Jobs\ReloadValidatedWebPostoTable;
use App\Models\WebPostoReloadRun;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(AdminOverviewService $overview): View
    {
        return view('admin.dashboard', ['overview' => $overview->get()]);
    }

    public function overview(AdminOverviewService $overview): JsonResponse
    {
        return response()->json($overview->get());
    }

    public function services(): View
    {
        $services = IntegrationService::query()->with(['runs' => fn ($query) => $query->latest()->limit(5)->withCount('changes')])
            ->orderBy('category')->orderBy('name')->get();
        return view('admin.services', compact('services'));
    }

    public function reload(Request $request, string $table): RedirectResponse
    {
        abort_unless(in_array($table, ['venda_itens', 'abastecimentos', 'bombas', 'bicos'], true), 404);
        $empresa = (int) $request->validate(['empresa_codigo' => ['required', 'integer', 'min:1']])['empresa_codigo'];
        $active = DB::connection('webposto')->table('webposto_credentials')
            ->where('empresa_codigo', $empresa)->where('ativo', true)->exists();
        abort_unless($active, 422, 'A empresa nao possui credencial ativa.');
        $running = WebPostoReloadRun::query()->where('empresa_codigo', $empresa)
            ->where('resource', $table)->whereIn('status', ['queued', 'running'])->exists();
        if ($running) return back()->with('status', 'A recarga de '.$table.' ja esta aguardando ou executando.');
        $run = WebPostoReloadRun::query()->create([
            'empresa_codigo' => $empresa, 'resource' => $table, 'status' => 'queued', 'processed_tables' => [],
        ]);
        ReloadValidatedWebPostoTable::dispatch($run->id);
        return back()->with('status', 'Recarga de '.$table.' adicionada a fila.');
    }
}
