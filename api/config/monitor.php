<?php

declare(strict_types=1);

return [

    'interval_seconds' => (int) env('MONITOR_INTERVAL', 300),

    'reminder_seconds' => (int) env('MONITOR_REMINDER', 3600),

    'request_timeout' => (int) env('MONITOR_HTTP_TIMEOUT', 15),

    'autostart' => filter_var(env('MONITOR_AUTOSTART', true), FILTER_VALIDATE_BOOLEAN),

    'git_pull' => filter_var(env('MONITOR_GIT_PULL', true), FILTER_VALIDATE_BOOLEAN),

    'targets' => [
        [
            'name'   => 'Public site',
            'url'    => env('MONITOR_URL_ROOT', 'https://poes.health.go.ug/'),
            'expect' => 200,
        ],
        [
            'name'   => 'Admin dashboard',
            'url'    => env('MONITOR_URL_ADMIN', 'https://poes.health.go.ug/admin'),
            'expect' => 200,
        ],
        [
            'name'   => 'API health',
            'url'    => env('MONITOR_URL_API', 'https://poes.health.go.ug/api/health'),
            'expect' => 200,
        ],
    ],

    'recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'MONITOR_RECIPIENTS',
        'vexa256@gmail.com'
    ))))),

    'sender' => [
        'name'  => env('MONITOR_SENDER_NAME', 'ECSAHC — AYEBARE Timothy'),
        'email' => env('MONITOR_SENDER_EMAIL', 'ayebare.k.timothy@gmail.com'),
    ],
];
