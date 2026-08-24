<?php
namespace App\Services\WebPosto;

use Illuminate\Support\Facades\DB;

class AbastecimentoImporter
{
    public function __construct(private readonly RawResourceImporter $rawImporter) {}

    public function import(mixed $payload, int $empresaCodigo, array $parameters): array
    {
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null) ? $payload['resultados'] : [];
        $valid = collect($rows)->filter(fn (mixed $row): bool =>
            is_array($row) && is_numeric($row['empresaCodigo'] ?? null)
            && is_numeric($row['abastecimentoCodigo'] ?? null)
        )->unique(fn (array $row): string => (int) $row['empresaCodigo'].'-'.(int) $row['abastecimentoCodigo'])->values();

        $withItem = $valid->filter(fn (array $row): bool => is_numeric($row['vendaItemCodigo'] ?? null));
        $items = $withItem->groupBy(fn (array $row): int => (int) $row['empresaCodigo'])->flatMap(function ($rows, int $company) {
            $codes = $rows->pluck('vendaItemCodigo')->map(fn (mixed $code): int => (int) $code)->unique();
            return DB::connection('webposto')->table('venda_itens')->where('empresaCodigo', $company)
                ->whereIn('vendaItemCodigo', $codes)->get(['empresaCodigo','vendaItemCodigo'])
                ->mapWithKeys(fn (object $item): array => [$item->empresaCodigo.'-'.$item->vendaItemCodigo => true]);
        });

        $linked = $valid->filter(fn (array $row): bool =>
            ! is_numeric($row['vendaItemCodigo'] ?? null)
            || $items->has((int) $row['empresaCodigo'].'-'.(int) $row['vendaItemCodigo'])
        )->values();
        $filtered = is_array($payload) ? $payload : [];
        $filtered['resultados'] = $linked->all();
        $stored = $this->rawImporter->import($filtered, $empresaCodigo, 'abastecimentos', $parameters);
        $stored['received'] = count($rows);
        $stored['skipped'] = (int) ($stored['skipped'] ?? 0) + count($rows) - $linked->count();
        $stored['missing_parent_item'] = $valid->count() - $linked->count();
        return $stored;
    }
}
