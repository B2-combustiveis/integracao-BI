<?php

namespace App\Services\Alterdata;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AlterdataClient
{
    private function request(): PendingRequest
    {
        $token = trim((string) config('services.alterdata.token'));

        if ($token === '') {
            throw new RuntimeException('ALTER_DATA_TOKEN não está configurado.');
        }

        return Http::baseUrl(rtrim((string) config('services.alterdata.base_url'), '/'))
            ->withToken($token)
            ->accept('application/vnd.api+json')
            ->timeout((int) config('services.alterdata.timeout', 30))
            ->retry(3, 1000);
    }

    /** @return array<int, array<string, mixed>> */
    public function todos(string $recurso, array $parametros = [], int $limite = 100, array $incluir = []): array
    {
        $offset = 0;
        $todos = [];

        do {
            $query = [
                ...$parametros,
                'page' => ['limit' => $limite, 'offset' => $offset],
            ];
            if ($incluir !== []) {
                $query['include'] = implode(',', $incluir);
            }

            $response = $this->request()->get($recurso, $query)->throw()->json();

            $pagina = is_array($response['data'] ?? null) ? $response['data'] : [];
            $incluidos = collect(is_array($response['included'] ?? null) ? $response['included'] : [])
                ->keyBy(fn (array $item): string => ($item['type'] ?? '').':'.($item['id'] ?? ''));
            $pagina = array_map(function (array $item) use ($incluidos): array {
                foreach ($item['relationships'] ?? [] as $nome => $relacionamento) {
                    $referencia = $relacionamento['data'] ?? null;
                    if (! is_array($referencia) || ! isset($referencia['type'], $referencia['id'])) {
                        continue;
                    }

                    $incluido = $incluidos->get($referencia['type'].':'.$referencia['id']);
                    if ($incluido !== null) {
                        $item['_included'][$nome] = $incluido;
                    }
                }

                return $item;
            }, $pagina);
            array_push($todos, ...$pagina);
            $offset += count($pagina);
            $total = (int) data_get($response, 'meta.totalResourceCount', $offset);
        } while ($pagina !== [] && $offset < $total);

        return $todos;
    }
}
