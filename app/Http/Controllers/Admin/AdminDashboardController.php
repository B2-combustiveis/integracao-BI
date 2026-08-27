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
        $services = IntegrationService::query()->with(['runs' => fn ($query) => $query->latest()->limit(5)->withCount('changes')->with('companyRuns')])
            ->orderBy('category')->orderBy('name')->get();
        $runIds = $services->flatMap(fn (IntegrationService $service) => $service->runs)->pluck('id');
        $newRecordsByRun = $runIds->isEmpty()
            ? collect()
            : DB::table('integration_service_run_changes')
                ->selectRaw('integration_service_run_id, resource, COUNT(*) as total')
                ->whereIn('integration_service_run_id', $runIds)
                ->where('action', 'inserted')
                ->groupBy('integration_service_run_id', 'resource')
                ->get()
                ->groupBy('integration_service_run_id');

        foreach ($services as $service) {
            foreach ($service->runs as $run) {
                $run->setAttribute('new_records_by_resource', collect($newRecordsByRun->get($run->id, []))
                    ->mapWithKeys(fn (object $summary): array => [$summary->resource => (int) $summary->total])
                    ->all());
            }
        }

        return view('admin.services', compact('services'));
    }

    public function reload(Request $request, string $table): RedirectResponse
    {
        abort_unless(in_array($table, ['venda_itens', 'abastecimentos', 'bombas', 'bicos', 'tanques', 'cartoes', 'administradoras', 'produto_grupos', 'produto_subgrupos', 'produtos', 'produto_empresas', 'produto_lmc_lmp', 'lmcs', 'vales_funcionario', 'funcionario_funcoes', 'caixas', 'caixas_apresentados', 'planos_conta_gerencial', 'planos_conta_contabil', 'contas_bancarias', 'movimentos_conta', 'funcionarios', 'estoque_periodos', 'fornecedores', 'compras', 'compra_itens', 'titulos_pagar', 'clientes', 'vendas', 'venda_formas_pagamento', 'titulos_receber'], true), 404);
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
