<?php

namespace App\Providers;

use App\View\Composers\ScopeComposer;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Replace Laravel's default Migrator with one that re-checks the
        // ledger before every individual migration. This lets the SOT
        // reconciliation migration backfill the migrations table mid-run
        // so historical migrations whose tables already exist are skipped
        // instead of crashing on CREATE TABLE.
        //
        // `extend()` (not `singleton()`) so the wrapper applies AFTER the
        // deferrable MigrationServiceProvider resolves 'migrator'.
        $this->app->extend('migrator', function ($migrator, $app) {
            return new \App\Migrations\CollisionSafeMigrator(
                $migrator->getRepository(),
                $app['db'],
                $app['files'],
                $app['events']
            );
        });
    }

    public function boot(): void
    {
        // ── HTTPS scheme guard ──────────────────────────────────────────────
        // Force every url() / route() / asset() helper to render https:// in
        // any non-local environment. This is belt-and-suspenders defence
        // against reverse-proxy mis-configurations where nginx does not
        // forward X-Forwarded-Proto, in which case Laravel would otherwise
        // emit http:// URLs that the browser blocks as mixed content.
        //
        // Seen in prod 2026-05-19 on poes.health.go.ug: the dashboard view
        // emitted url('/admin/reports/...') as http:// and Chrome blocked
        // every XHR with "Mixed Content … This request has been blocked".
        if (! $this->app->environment('local')) {
            URL::forceScheme('https');
        }

        // Inject $scope + $currentUser into every admin view automatically.
        View::composer('admin.*', ScopeComposer::class);

        // Self-bootstrap the monitor:watch daemon on first HTTP hit.
        // Skipped in CLI to avoid spawning from inside the daemon itself.
        if (! $this->app->runningInConsole() && config('monitor.autostart', true)) {
            $this->ensureMonitorRunning();
        }

        // Self-bootstrap the deploy:watch daemon on first HTTP hit. Same
        // shape as monitor:watch — rate-limited, lockfile-guarded. Gated
        // to environments that actually want auto-deploy (production /
        // staging by default); local dev opts out via DEPLOY_WATCH_ENVS.
        if (! $this->app->runningInConsole() && config('deploy.autostart', true)) {
            $envs = (array) config('deploy.environments', ['production', 'staging']);
            if (in_array($this->app->environment(), $envs, true)) {
                $this->ensureDeployWatchRunning();
            }
        }
    }

    private function ensureDeployWatchRunning(): void
    {
        // Rate-limit attempts so we don't fork on every single request.
        $stamp = storage_path('app/deploy-watch.boot');
        $now = time();
        if (is_file($stamp) && ($now - (int) @filemtime($stamp)) < 60) {
            return;
        }
        @touch($stamp);

        $lockPath = storage_path('app/deploy-watch.lock');
        if (is_file($lockPath)) {
            $pid = (int) @file_get_contents($lockPath);
            if ($pid > 0 && file_exists("/proc/{$pid}")) {
                return; // already running
            }
        }

        $php = PHP_BINARY ?: 'php';
        $artisan = base_path('artisan');
        $log = storage_path('logs/deploy-watch.log');
        $cmd = sprintf(
            'nohup %s %s deploy:watch >> %s 2>&1 &',
            escapeshellarg($php),
            escapeshellarg($artisan),
            escapeshellarg($log)
        );
        @exec($cmd);
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
