<?php

namespace App\Console\Commands;

use App\Jobs\SyncWebPostoNewRecords;
use Illuminate\Console\Command;

class SyncWebPostoNewRecordsNow extends Command
{
    protected $signature = 'webposto:sync-new-records
        {service=2 : ID do servico de integracao}
        {--queue : Enfileira a sincronizacao no worker permanente}';

    protected $description = 'Sincroniza imediatamente os recursos WebPosto configurados usando cursores persistidos';

    public function handle(): int
    {
        if ($this->option('queue')) {
            SyncWebPostoNewRecords::dispatch((int) $this->argument('service'));
            $this->info('Sincronizacao enfileirada.');

            return self::SUCCESS;
        }

        $job = new SyncWebPostoNewRecords((int) $this->argument('service'));
        app()->call([$job, 'handle']);

        $this->info('Sincronizacao concluida.');

        return self::SUCCESS;
    }
}
