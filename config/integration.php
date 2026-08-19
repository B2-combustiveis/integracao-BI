<?php

return [
    'webposto' => [
        'connect_timeout' => (int) env('WEBPOSTO_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('WEBPOSTO_TIMEOUT', 30),
    ],
];
