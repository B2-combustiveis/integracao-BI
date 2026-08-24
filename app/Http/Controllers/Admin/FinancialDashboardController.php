<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\FinancialDashboardService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FinancialDashboardController extends Controller
{
    public function index(Request $request, FinancialDashboardService $dashboard): View
    {
        [$start, $end, $company] = $this->filters($request, $dashboard);
        return view('admin.financial', ['dashboard' => $dashboard->get($start, $end, $company)]);
    }

    public function export(Request $request, FinancialDashboardService $dashboard): StreamedResponse
    {
        [$start, $end, $company] = $this->filters($request, $dashboard);
        $data = $dashboard->get($start, $end, $company);
        $filename = "relatorio-financeiro-{$start->toDateString()}-{$end->toDateString()}.csv";

        return response()->streamDownload(function () use ($data): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Relatório financeiro', 'Valor'], ';');
            foreach ($data['summary'] as $label => $value) fputcsv($output, [$label, number_format($value, 2, ',', '.')], ';');
            fputcsv($output, [], ';');
            fputcsv($output, ['Data', 'Vendas', 'Quantidade'], ';');
            foreach ($data['daily'] as $row) fputcsv($output, [$row['day'], number_format($row['total'], 2, ',', '.'), $row['quantity']], ';');
            fputcsv($output, [], ';');
            fputcsv($output, ['Forma de pagamento', 'Valor', 'Quantidade'], ';');
            foreach ($data['payment_methods'] as $row) fputcsv($output, [$row['label'], number_format($row['total'], 2, ',', '.'), $row['quantity']], ';');
            fclose($output);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filters(Request $request, FinancialDashboardService $dashboard): array
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date'], 'end' => ['nullable', 'date'], 'company' => ['nullable', 'integer', 'min:1'],
        ]);
        [$defaultStart, $defaultEnd] = $dashboard->defaultPeriod();
        $end = isset($validated['end']) ? Carbon::parse($validated['end']) : $defaultEnd;
        $start = isset($validated['start']) ? Carbon::parse($validated['start']) : $defaultStart;
        if ($start->gt($end)) [$start, $end] = [$end, $start];
        return [$start, $end, isset($validated['company']) ? (int) $validated['company'] : null];
    }
}
