<?php
namespace App\Services\WebPosto;

use App\Models\IntegrationService;
use App\Models\WebPostoCredential;
use App\Services\Bi\EmpresaBiSynchronizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WebPostoCredentialRegistrationService
{
    private const ENDPOINT = '/INTEGRACAO/EMPRESAS';

    public function __construct(
        private readonly EmpresaImporter $empresaImporter,
        private readonly EmpresaBiSynchronizer $biSynchronizer,
    ) {
    }

    /** @return array{companies: array<int, int>, storage: array<string, mixed>} */
    public function register(string $baseUrl, string $token): array
    {
        $baseUrl = rtrim(trim($baseUrl), '/').'/';
        $token = trim($token);

        try {
            $response = Http::acceptJson()
                ->connectTimeout((int) config('integration.webposto.connect_timeout', 5))
                ->timeout((int) config('integration.webposto.timeout', 30))
                ->retry(config('integration.webposto.retry_delays_ms', [250, 750, 1500]), throw: false)
                ->get($baseUrl.ltrim(self::ENDPOINT, '/'), ['chave' => $token]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Nao foi possivel conectar ao WebPosto.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('O WebPosto recusou a credencial. HTTP '.$response->status().'.');
        }

        $payload = $response->json();
        $companies = collect(is_array($payload) ? ($payload['resultados'] ?? []) : [])
            ->filter(fn (mixed $row): bool => is_array($row) && is_numeric($row['empresaCodigo'] ?? null))
            ->unique(fn (array $row): int => (int) $row['empresaCodigo'])
            ->values();

        if ($companies->isEmpty()) {
            throw new RuntimeException('A credencial foi aceita, mas nenhuma empresa valida foi retornada.');
        }

        $raw = DB::connection('webposto')->transaction(function () use ($payload, $companies, $baseUrl, $token): array {
            $storage = $this->empresaImporter->import($payload);
            foreach ($companies as $company) {
                WebPostoCredential::query()->updateOrCreate(
                    ['empresa_codigo' => (int) $company['empresaCodigo']],
                    ['base_url' => $baseUrl, 'token' => $token, 'ativo' => true, 'ultimo_uso_em' => now()],
                );
            }
            return $storage;
        });

        $bi = $this->biSynchronizer->sync($payload);
        foreach ($companies as $company) {
            $this->createPausedService((int) $company['empresaCodigo']);
        }

        return [
            'companies' => $companies->pluck('empresaCodigo')->map(fn (mixed $code): int => (int) $code)->all(),
            'storage' => ['webposto' => $raw, 'bi' => $bi],
        ];
    }

    private function createPausedService(int $empresaCodigo): void
    {
        IntegrationService::query()->firstOrCreate([
            'slug' => 'webposto-atualizacoes-por-data',
            'empresa_codigo' => $empresaCodigo,
        ], [
            'name' => 'Atualizacoes WebPosto por data',
            'category' => 'cadastros',
            'resource' => 'webposto-modified-records',
            'frequency_minutes' => 5,
            'lookback_days' => 1,
            'active' => false,
            'settings' => [
                'strategy' => 'modified_at',
                'onboarding_status' => 'pending_initial_load',
                'resources' => ['caixas','caixas-apresentados','clientes','fornecedores','lmcs','produto-empresas','titulos-pagar','titulos-receber'],
            ],
            'next_run_at' => null,
            'last_error' => null,
        ]);
    }
}
