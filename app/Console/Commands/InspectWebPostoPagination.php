<?php

namespace App\Console\Commands;

use App\Services\WebPosto\WebPostoClient;
use Illuminate\Console\Command;
use RuntimeException;

class InspectWebPostoPagination extends Command
{
    protected $signature = 'webposto:inspect-pagination
        {empresa : Codigo da empresa}
        {endpoint : Endpoint WebPosto}
        {--key=codigo : Campo que identifica o registro}
        {--updated-field= : Campo de data de atualizacao}
        {--cursor= : ultimoCodigo enviado}
        {--index= : index enviado}
        {--limit= : limite enviado}
        {--data-inicial= : dataInicial enviada}
        {--data-final= : dataFinal enviada}';

    protected $description = 'Inspeciona uma pagina WebPosto sem persistir os registros';

    public function handle(WebPostoClient $client): int
    {
        $query = [];
        foreach ([
            'cursor' => 'ultimoCodigo',
            'index' => 'index',
            'limit' => 'limite',
            'data-inicial' => 'dataInicial',
            'data-final' => 'dataFinal',
        ] as $option => $parameter) {
            if ($this->option($option) !== null) $query[$parameter] = $this->option($option);
        }

        $result = $client->get(
            (string) $this->argument('endpoint'),
            (int) $this->argument('empresa'),
            $query,
        );
        if (! $result['response']->successful()) {
            throw new RuntimeException("WebPosto respondeu HTTP {$result['response']->status()}.");
        }

        $payload = $result['payload'];
        $rows = is_array($payload) && is_array($payload['resultados'] ?? null)
            ? $payload['resultados']
            : [];
        $key = (string) $this->option('key');
        $updatedField = $this->option('updated-field');
        $codes = collect($rows)->pluck($key)->filter(fn (mixed $value): bool => is_numeric($value))
            ->map(fn (mixed $value): int => (int) $value)->values();
        $updates = $updatedField === null
            ? collect()
            : collect($rows)->pluck((string) $updatedField)->filter()->values();

        $this->line(json_encode([
            'http' => $result['response']->status(),
            'request' => $query,
            'payload_fields' => is_array($payload) ? array_keys($payload) : [],
            'row_fields' => is_array($rows[0] ?? null) ? array_keys($rows[0]) : [],
            'index' => is_array($payload) ? ($payload['index'] ?? null) : null,
            'ultimoCodigo' => is_array($payload) ? ($payload['ultimoCodigo'] ?? null) : null,
            'received' => count($rows),
            'distinct_keys' => $codes->unique()->count(),
            'first_key' => $codes->first(),
            'last_key' => $codes->last(),
            'min_key' => $codes->min(),
            'max_key' => $codes->max(),
            'min_updated_at' => $updates->min(),
            'max_updated_at' => $updates->max(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
