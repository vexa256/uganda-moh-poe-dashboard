<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust loopback (nginx reverse-proxy in front of Octane).
        // Makes Laravel honour X-Forwarded-Proto / -Host / -For so that
        // url(), route(), form actions, and session cookies all see HTTPS
        // instead of the upstream plain-HTTP connection to 127.0.0.1:8123.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->alias([
            'role'        => \App\Http\Middleware\RoleGate::class,
            'require.poe' => \App\Http\Middleware\RequirePoeAssignment::class,
            'scope'       => \App\Http\Middleware\ResolveScope::class,
            'idempotent'  => \App\Http\Middleware\IdempotencyKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
