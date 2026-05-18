<?php

namespace App\Providers;

use App\View\Composers\ScopeComposer;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Inject $scope + $currentUser into every admin view automatically.
        View::composer('admin.*', ScopeComposer::class);

        // Self-bootstrap the monitor:watch daemon on first HTTP hit.
        // Skipped in CLI to avoid spawning from inside the daemon itself.
        if (! $this->app->runningInConsole() && config('monitor.autostart', true)) {
            $this->ensureMonitorRunning();
        }
    }

    private function ensureMonitorRunning(): void
    {
        // Rate-limit attempts so we don't fork on every single request.
        $stamp = storage_path('app/monitor.boot');
        $now = time();
        if (is_file($stamp) && ($now - (int) @filemtime($stamp)) < 60) {
            return;
        }
        @touch($stamp);

        $lockPath = storage_path('app/monitor.lock');
        if (is_file($lockPath)) {
            $pid = (int) @file_get_contents($lockPath);
            if ($pid > 0 && file_exists("/proc/{$pid}")) {
                return; // already running
            }
        }

        // Detach the watcher so it survives the request lifetime.
        $php = PHP_BINARY ?: 'php';
        $artisan = base_path('artisan');
        $log = storage_path('logs/monitor.log');
        $cmd = sprintf(
            'nohup %s %s monitor:watch >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($artisan),
            escapeshellarg($log)
        );
        @exec($cmd);
    }
}
