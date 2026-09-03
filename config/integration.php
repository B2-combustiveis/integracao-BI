<?php

return [
    'webposto' => [
        'connect_timeout' => (int) env('WEBPOSTO_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('WEBPOSTO_TIMEOUT', 30),
        'request_interval_ms' => (int) env('WEBPOSTO_REQUEST_INTERVAL_MS', 350),
        'retry_delays_ms' => [500, 1500, 3000],
        'recent_base_initial_date' => env('WEBPOSTO_RECENT_BASE_INITIAL_DATE', '2024-01-01'),
    ],
];
