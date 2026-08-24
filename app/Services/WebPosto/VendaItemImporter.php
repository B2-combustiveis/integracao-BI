<?php
namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class VendaItemImporter
{
    public function __construct(private readonly RawResourceImporter $rawImporter)
    {
    }

    /** @return array<string, mixed> */
    public function import(mixed $payload, int $empresaCodigo, array $parameters): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
        $valid = collect($rows)->filter(fn (mixed $row): bool =>
            is_array($row)
            && is_numeric($row['empresaCodigo'] ?? null)
            && is_numeric($row['vendaCodigo'] ?? null)
            && is_numeric($row['vendaItemCodigo'] ?? null)
        )->unique(fn (array $row): string => (int) $row['empresaCodigo'].'-'.(int) $row['vendaItemCodigo'])->values();

        $sales = $valid->groupBy(fn (array $row): int => (int) $row['empresaCodigo'])
            ->flatMap(function ($items, int $company) {
                $codes = $items->pluck('vendaCodigo')->map(fn (mixed $code): int => (int) $code)->unique();
                return DB::connection('webposto')->table('vendas')
                    ->where('empresaCodigo', $company)->whereIn('vendaCodigo', $codes)
                    ->get(['empresaCodigo','vendaCodigo'])
                    ->mapWithKeys(fn (object $sale): array => [$sale->empresaCodigo.'-'.$sale->vendaCodigo => true]);
            });

        $linked = $valid->filter(fn (array $row): bool =>
            $sales->has((int) $row['empresaCodigo'].'-'.(int) $row['vendaCodigo'])
        )->values();
        $filtered = is_array($payload) ? $payload : [];
        $filtered['resultados'] = $linked->all();
        $stored = $this->rawImporter->import($filtered, $empresaCodigo, 'venda_itens', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] = (int) ($stored['skipped'] ?? 0) + count($rows) - $linked->count();
        $stored['missing_parent_sale'] = $valid->count() - $linked->count();

        return $stored;
    }
}
