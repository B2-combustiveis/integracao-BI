<?php

namespace App\Console\Commands;

use App\Services\WebPosto\RawNaturalKeyResolver;
use App\Services\WebPosto\WebPostoResourceCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DeduplicateWebPostoTables extends Command
{
    protected $signature = 'webposto:deduplicate-normalized';
    protected $description = 'Remove versões repetidas usando as chaves naturais do WebPosto';

    public function handle(WebPostoResourceCatalog $catalog, RawNaturalKeyResolver $keys): int
    {
        $technical = ['id', 'credencialEmpresaCodigo', 'record_hash', 'payload', 'request_parameters', 'created_at', 'updated_at'];
        foreach (array_unique(array_column($catalog->all(), 'table')) as $table) {
            $columns = array_values(array_diff(Schema::connection('webposto')->getColumnListing($table), $technical));
            $seen = [];
            $deleted = 0;
            foreach (DB::connection('webposto')->table($table)->orderByDesc('id')->get(['id', ...$columns]) as $record) {
                $mapped = [];
                foreach ($columns as $column) $mapped[$column] = $record->{$column};
                $criteria = $keys->criteria($table, $mapped);
                $signature = json_encode($criteria, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (! isset($seen[$signature])) { $seen[$signature] = true; continue; }
                DB::connection('webposto')->table($table)->where('id', $record->id)->delete();
                $deleted++;
            }
            $this->line("{$table}: duplicados_removidos={$deleted}");
        }
        return self::SUCCESS;
    }
}
