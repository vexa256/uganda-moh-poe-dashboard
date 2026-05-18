<?php

declare(strict_types=1);

namespace App\Listeners\Octane;

use Laravel\Octane\Events\RequestTerminated;
use Throwable;

/**
 * Per-request memory probe — wired into Octane's RequestTerminated event.
 *
 * Writes one line per HTTP request to storage/logs/memory.log:
 *   [iso8601] METHOD route -> status  peak=NN.NMB  worker_rss=NN.NMB  pid=12345
 *
 * - "peak" is the per-request peak (we reset PHP's high-water mark after
 *   each request, so the next request reports its own peak, not cumulative).
 * - "worker_rss" is the worker process's current memory at the moment the
 *   request ends — watch this column rise over consecutive lines from the
 *   same pid to spot leaks.
 *
 * After 1-2 hours of normal traffic, the offender shows up as:
 *   - a row with peak > 80MB on a specific route, OR
 *   - worker_rss climbing steadily across a pid (leak fingerprint).
 *
 * Never throws — telemetry must not break a response.
 */
final class LogRequestMemory
{
    public function handle(RequestTerminated $event): void
    {
        try {
            $peak = memory_get_peak_usage(true);
            $rss  = memory_get_usage(true);

            $req = $event->request;
            $route = $req->route();
            $name = $route?->uri() ?: $req->path();
            $method = $req->method();
            $status = $event->response?->getStatusCode() ?? 0;

            $line = sprintf(
                "[%s] %-6s %-60s -> %3d  peak=%6.1fMB  worker_rss=%6.1fMB  pid=%d\n",
                date('c'),
                $method,
                substr((string) $name, 0, 60),
                $status,
                $peak / 1048576,
                $rss / 1048576,
                getmypid()
            );

            @file_put_contents(
                storage_path('logs/memory.log'),
                $line,
                FILE_APPEND | LOCK_EX
            );

            // Reset PHP's peak high-water mark so the next request reports its
            // own peak, not the worker's all-time peak.
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
        } catch (Throwable) {
            // Swallow — telemetry must never bubble up to the response layer.
        }
    }
}
