<?php

namespace App\Services\WebPosto;

use App\Jobs\ReloadValidatedWebPostoTable;
use App\Models\WebPostoReloadRun;
use RuntimeException;

class WebPostoInitialLoadRunner
{
    /** @return array<int, string> */
    public function run(int $empresa, string $resource): array
    {
        $reload = WebPostoReloadRun::query()->create([
            'empresa_codigo' => $empresa,
            'resource' => $resource,
            'status' => 'queued',
            'processed_tables' => [],
        ]);
        (new ReloadValidatedWebPostoTable($reload->id))->handle();
        $reload->refresh();
        if ($reload->status !== 'success') {
            throw new RuntimeException($reload->error ?: 'Falha na carga de '.$resource.'.');
        }

        return $reload->processed_tables ?? [$resource];
    }
}
