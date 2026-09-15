<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\ReloadValidatedWebPostoTable;
use App\Models\IntegrationService;
use App\Models\WebPostoCredential;
use App\Models\WebPostoInitialSyncRun;
use App\Models\WebPostoReloadRun;
use App\Services\Admin\AdminOverviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard');
    }

    public function webposto(AdminOverviewService $overview): View
    {
        return view('admin.webposto', ['overview' => $overview->get()]);
    }

    public function alterdata(): View
    {
        return view('admin.alterdata');
    }

    public function alterdataCompanies(): JsonResponse
    {
        $companies = DB::connection('alterdata')->table('empresas')
            ->orderByDesc('ativa')
            ->orderBy('nome')
            ->get([
                'alterdata_id', 'externo_id', 'nome', 'ativa', 'cpf_cnpj',
                'endereco', 'ultima_consulta_em',
            ]);

        return response()->json([
            'companies' => $companies,
            'summary' => [
                'total' => $companies->count(),
                'active' => $companies->where('ativa', 1)->count(),
                'inactive' => $companies->where('ativa', 0)->count(),
            ],
        ]);
    }

    public function overview(Request $request, AdminOverviewService $overview): JsonResponse
    {
        $validated = $request->validate([
            'source' => ['required', 'in:webposto,alterdata'],
            'empresa_codigo' => ['nullable', 'integer', 'min:1'],
        ]);
        $empresa = $validated['empresa_codigo'] ?? null;

        return response()->json($overview->get(
            $validated['source'],
            $empresa ? (int) $empresa : null,
        ));
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
        abort_unless(in_array($table, ['venda_itens', 'abastecimentos', 'bombas', 'bicos', 'tanques', 'cartoes', 'administradoras', 'produto_grupos', 'produto_subgrupos', 'produtos', 'produto_empresas', 'produto_lmc_lmp', 'lmcs', 'vales_funcionario', 'funcionario_funcoes', 'caixas', 'caixas_apresentados', 'planos_conta_gerencial', 'planos_conta_contabil', 'contas_bancarias', 'movimentos_conta', 'funcionarios', 'estoque_periodos', 'fornecedores', 'compras', 'compra_itens', 'titulos_pagar', 'cliente_grupos', 'clientes', 'cliente_empresas', 'formas_pagamento', 'pdvs', 'vendas', 'venda_formas_pagamento', 'titulos_receber'], true), 404);
        $empresa = (int) $request->validate(['empresa_codigo' => ['required', 'integer', 'min:1']])['empresa_codigo'];
        $credential = WebPostoCredential::query()
            ->where('empresa_codigo', $empresa)
            ->where('ativo', true)
            ->first();
        abort_unless($credential, 422, 'A empresa nao possui credencial ativa.');
        abort_unless(
            $credential->implantacao_status === WebPostoCredential::STATUS_SINCRONIZADO,
            422,
            'A empresa precisa concluir a carga inicial antes de executar recargas.'
        );
        $initialLoadRunning = WebPostoInitialSyncRun::query()
            ->where('empresa_codigo', $empresa)
            ->whereIn('status', ['queued', 'running'])
            ->exists();
        abort_if($initialLoadRunning, 422, 'A carga inicial desta empresa esta em andamento.');
        $running = WebPostoReloadRun::query()->where('empresa_codigo', $empresa)
            ->where('resource', $table)->whereIn('status', ['queued', 'running'])->exists();
        if ($running) {
            return back()->with('status', 'A recarga de '.$table.' ja esta aguardando ou executando.');
        }
        $run = WebPostoReloadRun::query()->create([
            'empresa_codigo' => $empresa, 'resource' => $table, 'status' => 'queued', 'processed_tables' => [],
        ]);
        ReloadValidatedWebPostoTable::dispatch($run->id);

        return back()->with('status', 'Recarga de '.$table.' adicionada a fila.');
    }
}
