<?php

namespace App\Services\Admin;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinancialDashboardService
{
    public function defaultPeriod(): array
    {
        $latest = Schema::connection('webposto')->hasTable('vendas')
            ? DB::connection('webposto')->table('vendas')->max('dataHora') : null;
        $end = $latest ? Carbon::parse($latest) : today();
        return [$end->copy()->startOfMonth(), $end->copy()->endOfDay()];
    }

    public function get(Carbon $start, Carbon $end, ?int $company = null): array
    {
        $sales = $this->query('vendas', 'dataHora', $start, $end, $company);
        $receivables = $this->query('titulos_receber', 'dataMovimento', $start, $end, $company);
        $payables = $this->query('titulos_pagar', 'dataMovimento', $start, $end, $company);

        $salesTotal = $sales ? (float) (clone $sales)->where('cancelada', false)->sum('totalVenda') : 0;
        $salesCount = $sales ? (int) (clone $sales)->where('cancelada', false)->count() : 0;
        $receivableTotal = $receivables ? (float) (clone $receivables)->sum('valor') : 0;
        $receivableOpen = $receivables ? (float) (clone $receivables)->where('pendente', true)->sum('valor') : 0;
        $payableTotal = $payables ? (float) (clone $payables)->sum('valor') : 0;
        $payablePaid = $payables && $this->hasColumn('titulos_pagar', 'valorPago')
            ? (float) (clone $payables)->sum('valorPago') : 0;

        return [
            'filters' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'company' => $company],
            'companies' => $this->companies(),
            'summary' => [
                'sales' => $salesTotal,
                'sales_count' => $salesCount,
                'average_ticket' => $salesCount > 0 ? $salesTotal / $salesCount : 0,
                'receivable' => $receivableTotal,
                'receivable_open' => $receivableOpen,
                'payable' => $payableTotal,
                'payable_paid' => $payablePaid,
                'balance' => $receivableTotal - $payableTotal,
            ],
            'daily' => $this->daily($start, $end, $company),
            'payment_methods' => $this->paymentMethods($start, $end, $company),
            'expenses' => $this->expenses($start, $end, $company),
            'receivables' => $this->receivables($start, $end, $company),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function query(string $table, string $dateColumn, Carbon $start, Carbon $end, ?int $company): ?Builder
    {
        if (! Schema::connection('webposto')->hasTable($table) || ! $this->hasColumn($table, $dateColumn)) return null;
        return DB::connection('webposto')->table($table)
            ->whereBetween($dateColumn, [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->when($company, fn (Builder $query) => $query->where('empresaCodigo', $company));
    }

    private function daily(Carbon $start, Carbon $end, ?int $company): array
    {
        $rows = $this->query('vendas', 'dataHora', $start, $end, $company);
        if (! $rows) return [];
        return $rows->where('cancelada', false)->selectRaw('DATE(dataHora) as day, SUM(totalVenda) as total, COUNT(*) as quantity')
            ->groupByRaw('DATE(dataHora)')->orderBy('day')->get()
            ->map(fn ($row) => ['day' => $row->day, 'total' => (float) $row->total, 'quantity' => (int) $row->quantity])->all();
    }

    private function paymentMethods(Carbon $start, Carbon $end, ?int $company): array
    {
        $rows = $this->query('venda_formas_pagamento', 'dataMovimento', $start, $end, $company);
        if (! $rows) return [];
        return $rows->selectRaw("COALESCE(NULLIF(nomeFormaPagamento, ''), 'Não informado') as label, SUM(valorPagamento) as total, COUNT(*) as quantity")
            ->groupBy('label')->orderByDesc('total')->limit(8)->get()
            ->map(fn ($row) => ['label' => $row->label, 'total' => (float) $row->total, 'quantity' => (int) $row->quantity])->all();
    }

    private function expenses(Carbon $start, Carbon $end, ?int $company): array
    {
        $rows = $this->query('titulos_pagar', 'dataMovimento', $start, $end, $company);
        if (! $rows) return [];
        return $rows->selectRaw("COALESCE(NULLIF(planoContaGerencialDescricao, ''), 'Sem classificação') as label, SUM(valor) as total, COUNT(*) as quantity")
            ->groupBy('label')->orderByDesc('total')->limit(8)->get()
            ->map(fn ($row) => ['label' => $row->label, 'total' => (float) $row->total, 'quantity' => (int) $row->quantity])->all();
    }

    private function receivables(Carbon $start, Carbon $end, ?int $company): array
    {
        $rows = $this->query('titulos_receber', 'dataMovimento', $start, $end, $company);
        if (! $rows) return [];
        return $rows->selectRaw("COALESCE(NULLIF(nomeCliente, ''), 'Cliente não informado') as customer, SUM(valor) as total, SUM(CASE WHEN pendente = 1 THEN valor ELSE 0 END) as open_total, COUNT(*) as quantity")
            ->groupBy('customer')->orderByDesc('open_total')->limit(10)->get()
            ->map(fn ($row) => ['customer' => $row->customer, 'total' => (float) $row->total,
                'open_total' => (float) $row->open_total, 'quantity' => (int) $row->quantity])->all();
    }

    private function companies(): array
    {
        if (! Schema::connection('webposto')->hasTable('empresas')) return [];
        return DB::connection('webposto')->table('empresas')->orderBy('fantasia')
            ->get(['empresaCodigo', 'fantasia'])->map(fn ($row) => ['code' => (int) $row->empresaCodigo,
                'name' => $row->fantasia ?: "Empresa {$row->empresaCodigo}"])->all();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return Schema::connection('webposto')->hasColumn($table, $column);
    }
}
