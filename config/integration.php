<?php

return [
    'webposto' => [
        'b1_token' => env('B1_TOKEN'),
        'connect_timeout' => (int) env('WEBPOSTO_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('WEBPOSTO_TIMEOUT', 90),
        'request_interval_ms' => (int) env('WEBPOSTO_REQUEST_INTERVAL_MS', 350),
        'retry_delays_ms' => [500, 1500, 3000],
        'recent_base_initial_date' => env('WEBPOSTO_RECENT_BASE_INITIAL_DATE', '2024-01-01'),
        'chimba_max_lookback_months' => (int) env('WEBPOSTO_CHIMBA_MAX_LOOKBACK_MONTHS', 2),
        'reconciliation_max_lookback_months' => (int) env('WEBPOSTO_RECONCILIATION_MAX_LOOKBACK_MONTHS', 2),
    ],
];
