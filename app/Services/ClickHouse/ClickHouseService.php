<?php

namespace App\Services\ClickHouse;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClickHouseService
{
    private string $host;
    private int $port;
    private string $database;
    private string $username;
    private string $password;
    private int $timeout;

    public function __construct()
    {
        $this->host = config('clickhouse.host', 'clickhouse');
        $this->port = (int) config('clickhouse.port', 8123);
        $this->database = config('clickhouse.database', 'bi');
        $this->username = config('clickhouse.username', 'bi_user');
        $this->password = config('clickhouse.password', 'bi_password');
        $this->timeout = (int) config('clickhouse.timeout', 10);
    }

    public function isEnabled(): bool
    {
        return (bool) config('clickhouse.enabled', false);
    }

    public function ping(): bool
    {
        try {
            $response = $this->request('SELECT 1');

            return $response->successful() && trim($response->body()) === '1';
        } catch (\Throwable $e) {
            Log::debug('ClickHouse ping falhou: '.$e->getMessage());

            return false;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function query(string $sql): array
    {
        if (! str_contains($sql, 'FORMAT')) {
            $sql .= ' FORMAT JSON';
        }

        $response = $this->request($sql, [
            'Accept' => 'application/json',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('ClickHouse query failed: '.$response->body());
        }

        $data = $response->json();

        if (isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }

        return $data ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function queryOne(string $sql): ?array
    {
        $rows = $this->query($sql);

        return $rows[0] ?? null;
    }

    public function queryScalar(string $sql): mixed
    {
        $row = $this->queryOne($sql);
        if (! $row) {
            return null;
        }

        return array_values($row)[0] ?? null;
    }

    /**
     * Executa DDL/DML (CREATE, INSERT, DROP, ALTER, etc.) — sem retorno de dados.
     * Usa POST porque o ClickHouse exige POST para queries que modificam dados/estrutura.
     */
    public function execute(string $sql): bool
    {
        $response = Http::withBasicAuth($this->username, $this->password)
            ->timeout($this->timeout)
            ->withQueryParameters(['query' => $sql])
            ->withBody('', 'text/plain')
            ->post($this->baseUrl());

        if (! $response->successful()) {
            throw new \RuntimeException('ClickHouse execute failed: '.$response->body());
        }

        return true;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function insert(string $table, array $rows): bool
    {
        if (empty($rows)) {
            return true;
        }

        $sql = "INSERT INTO {$this->database}.{$table} SETTINGS max_partitions_per_insert_block=0 FORMAT JSONEachRow";
        $body = implode("\n", array_map(fn ($row) => json_encode($row, JSON_UNESCAPED_UNICODE), $rows));

        $response = $this->rawRequest($sql, $body, [
            'Content-Type' => 'text/plain',
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException("ClickHouse insert into {$table} failed: ".$response->body());
        }

        return true;
    }

    public function count(string $table): int
    {
        return (int) $this->queryScalar("SELECT count() FROM {$this->database}.{$table}");
    }

    public function tableExists(string $table): bool
    {
        $result = $this->query(
            "SELECT count() as c FROM system.tables WHERE database = '{$this->database}' AND name = '{$table}'"
        );

        return (int) ($result[0]['c'] ?? 0) > 0;
    }

    private function baseUrl(): string
    {
        return "http://{$this->host}:{$this->port}";
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $sql, array $headers = []): Response
    {
        $defaultHeaders = [
            'Connection' => 'keep-alive',
            'Accept-Encoding' => 'gzip, deflate',
        ];

        return Http::withBasicAuth($this->username, $this->password)
            ->timeout($this->timeout)
            ->withHeaders(array_merge($defaultHeaders, $headers))
            ->get($this->baseUrl(), ['query' => $sql]);
    }

    /**
     * @param array<string, string> $headers
     */
    private function rawRequest(string $sql, string $body, array $headers = []): Response
    {
        $defaultHeaders = [
            'Connection' => 'keep-alive',
            'Accept-Encoding' => 'gzip, deflate',
        ];

        return Http::withBasicAuth($this->username, $this->password)
            ->timeout($this->timeout)
            ->withHeaders(array_merge($defaultHeaders, $headers))
            ->withQueryParameters(['query' => $sql])
            ->withBody($body, 'text/plain')
            ->post($this->baseUrl());
    }
}
