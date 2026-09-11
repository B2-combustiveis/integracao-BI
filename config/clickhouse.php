<?php

return [

    /*
    |--------------------------------------------------------------------------
    | ClickHouse Analytics Configuration
    |--------------------------------------------------------------------------
    |
    | Configuracao do banco analitico OLAP ClickHouse, usado para acelerar
    | agregacoes de BI (venda_itens, abastecimentos, vendas, etc.).
    | A conexao e feita via HTTP (porta 8123) usando o Http facade do Laravel.
    |
    | A lista de tabelas sincronizadas vive em App\Services\ClickHouse\ClickHouseTableCatalog,
    | nao aqui.
    |
    */

    'enabled' => (bool) env('CLICKHOUSE_ENABLED', false),

    'host' => env('CLICKHOUSE_HOST', 'clickhouse'),
    'port' => (int) env('CLICKHOUSE_PORT', 8123),
    'database' => env('CLICKHOUSE_DATABASE', 'bi'),
    'username' => env('CLICKHOUSE_USERNAME', 'bi_user'),
    'password' => env('CLICKHOUSE_PASSWORD', 'bi_password'),

    'timeout' => (int) env('CLICKHOUSE_TIMEOUT', 30),

    // Tamanho do batch para sync (linhas por INSERT)
    'sync_batch_size' => (int) env('CLICKHOUSE_SYNC_BATCH', 10000),
];
