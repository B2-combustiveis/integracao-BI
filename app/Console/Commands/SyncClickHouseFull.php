<?php

namespace App\Console\Commands;

use App\Services\ClickHouse\ClickHouseTableCatalog;
use App\Services\ClickHouse\ClickHouseTableSynchronizer;
use Illuminate\Console\Command;

class SyncClickHouseFull extends Command
{
    protected $signature = 'clickhouse:sync-full {table? : Nome da tabela; sem argumento roda todas}';

    protected $description = 'Dropa, recria e recarrega do zero uma (ou todas) as tabelas do ClickHouse. So para bootstrap/disaster recovery - nunca agendado.';

    public function handle(ClickHouseTableSynchronizer $synchronizer, ClickHouseTableCatalog $catalog): int
    {
        $table = $this->argument('table');
        $tables = $table !== null ? [$table] : $catalog->tables();

        foreach ($tables as $name) {
            $this->info("Sincronizando {$name}...");
            $started = microtime(true);
            $result = $synchronizer->syncFull($name);
            $elapsed = round(microtime(true) - $started, 1);
            $this->info("  {$name}: {$result['inserted']} linhas em {$elapsed}s");
        }

        return self::SUCCESS;
    }
}
