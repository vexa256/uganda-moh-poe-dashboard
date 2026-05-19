<?php

declare(strict_types=1);

return [

    'enabled' => filter_var(env('DEPLOY_WATCH_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'autostart' => filter_var(env('DEPLOY_WATCH_AUTOSTART', true), FILTER_VALIDATE_BOOLEAN),

    'interval_seconds' => (int) env('DEPLOY_WATCH_INTERVAL', 120),

    'branch' => (string) env('DEPLOY_WATCH_BRANCH', 'main'),

    'repo_path' => (string) env('DEPLOY_WATCH_REPO_PATH', dirname(base_path())),

    'octane_reload' => filter_var(env('DEPLOY_WATCH_OCTANE_RELOAD', true), FILTER_VALIDATE_BOOLEAN),

    'environments' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'DEPLOY_WATCH_ENVS',
        'production,staging'
    ))))),
];
