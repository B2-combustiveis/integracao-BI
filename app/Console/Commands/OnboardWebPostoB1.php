<?php

namespace App\Console\Commands;

use App\Jobs\SyncWebPostoCompanyInitialLoad;
use App\Models\WebPostoCredential;
use App\Models\WebPostoInitialSyncRun;
use App\Services\WebPosto\WebPostoCredentialRegistrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class OnboardWebPostoB1 extends Command
{
    protected $signature = 'webposto:b1-onboard
        {--register : Consulta a API e cadastra as empresas da B1 sem iniciar cargas}
        {--start-next=0 : Inicia o próximo lote de empresas aguardando sincronização (máximo 5)}
        {--url= : URL base; por padrão reutiliza a URL de uma credencial ativa}';

    protected $description = 'Cadastra a B1 e libera sua carga inicial em lotes controlados';

    public function handle(WebPostoCredentialRegistrationService $registration): int
    {
        $register = (bool) $this->option('register');
        $batchSize = filter_var($this->option('start-next'), FILTER_VALIDATE_INT);
        if ($batchSize === false || $batchSize < 0 || $batchSize > 5) {
            $this->error('--start-next deve ser um número entre 0 e 5.');

            return self::INVALID;
        }
        if (! $register && $batchSize === 0) {
            $this->error('Use --register e/ou --start-next=N.');

            return self::INVALID;
        }

        if ($register) {
            $token = trim((string) config('integration.webposto.b1_token'));
            $baseUrl = trim((string) ($this->option('url') ?: WebPostoCredential::query()
                ->where('ativo', true)->value('base_url')));
            if ($token === '') {
                $this->error('B1_TOKEN não está configurado.');

                return self::FAILURE;
            }
            if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
                $this->error('Não foi possível determinar uma URL base válida do WebPosto.');

                return self::FAILURE;
            }

            $result = $registration->register($baseUrl, $token, WebPostoCredential::BASE_B1);
            $this->info(count($result['companies']).' empresas B1 cadastradas sem iniciar a carga inicial.');
        }

        if ($batchSize > 0) {
            return $this->startNextBatch($batchSize);
        }

        return self::SUCCESS;
    }

    private function startNextBatch(int $batchSize): int
    {
        $companyCodes = WebPostoCredential::query()
            ->where('base', WebPostoCredential::BASE_B1)
            ->where('ativo', true)
            ->pluck('empresa_codigo');
        if ($companyCodes->isEmpty()) {
            $this->error('Nenhuma empresa B1 foi cadastrada.');

            return self::FAILURE;
        }

        $running = WebPostoInitialSyncRun::query()
            ->whereIn('empresa_codigo', $companyCodes)
            ->whereIn('status', ['queued', 'running'])
            ->count();
        $availableSlots = max(0, 5 - $running);
        if ($availableSlots === 0) {
            $this->error('O limite de cinco cargas B1 simultâneas foi atingido.');

            return self::FAILURE;
        }

        $batchSize = min($batchSize, $availableSlots);

        $candidates = WebPostoCredential::query()
            ->where('base', WebPostoCredential::BASE_B1)
            ->where('ativo', true)
            ->where('implantacao_status', WebPostoCredential::STATUS_AGUARDANDO_SINCRONIZACAO)
            ->orderBy('empresa_codigo')
            ->limit($batchSize)
            ->get();
        if ($candidates->isEmpty()) {
            $this->info('Não há empresas B1 aguardando carga inicial.');

            return self::SUCCESS;
        }

        $batchKey = (string) Str::uuid();
        foreach ($candidates as $credential) {
            $previous = WebPostoInitialSyncRun::query()
                ->where('empresa_codigo', $credential->empresa_codigo)
                ->whereIn('status', ['failed', 'cancelled', 'success'])
                ->latest('id')
                ->first();
            $run = WebPostoInitialSyncRun::query()->create([
                'empresa_codigo' => $credential->empresa_codigo,
                'batch_key' => $batchKey,
                'status' => 'queued',
                'total_resources' => count(SyncWebPostoCompanyInitialLoad::requiredResourcesFor(WebPostoCredential::BASE_B1)),
                'completed_resources' => array_values(array_unique($previous?->completed_resources ?? [])),
                'was_synchronized' => false,
            ]);
            SyncWebPostoCompanyInitialLoad::dispatch($run->id);
        }

        $this->info($candidates->count().' cargas B1 adicionadas à fila.');
        $this->table(['Empresa', 'Status'], $candidates->map(
            fn (WebPostoCredential $credential): array => [$credential->empresa_codigo, 'queued'],
        )->all());

        return self::SUCCESS;
    }
}
