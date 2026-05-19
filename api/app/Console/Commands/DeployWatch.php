<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Throwable;

final class DeployWatch extends Command
{
    protected $signature = 'deploy:watch
        {--once : Run a single sync cycle and exit}
        {--no-initial-migrate : Skip the boot-time migration check}
    ';

    protected $description = 'Daemon: polls origin, fast-forwards when ahead, runs migrate, reloads Octane. No cron required.';

    private string $lockPath;

    private string $logPath;

    /** @var resource|null */
    private $lockHandle = null;

    private bool $stop = false;

    public function handle(): int
    {
        $this->lockPath = storage_path('app/deploy-watch.lock');
        $this->logPath = storage_path('logs/deploy-watch.log');
        @mkdir(dirname($this->lockPath), 0775, true);
        @mkdir(dirname($this->logPath), 0775, true);

        if (! $this->acquireLock()) {
            $this->log('another instance already running — exiting');

            return self::SUCCESS;
        }

        $this->installSignalHandlers();

        $interval = max(30, (int) config('deploy.interval_seconds', 120));
        $once = (bool) $this->option('once');
        $this->log(sprintf('started pid=%d interval=%ds once=%s repo=%s branch=%s',
            getmypid(), $interval, $once ? 'yes' : 'no',
            (string) config('deploy.repo_path'), (string) config('deploy.branch')
        ));

        // Boot-time idempotent migrate. Handles the "code already pulled,
        // but migrate never ran" state — which is exactly the gov-server
        // situation today. No-op when ledger is clean.
        if (! $this->option('no-initial-migrate')) {
            $this->runMigrate('boot');
        }

        do {
            try {
                $this->syncCycle();
            } catch (Throwable $e) {
                $this->log('cycle error: '.$e->getMessage());
            }

            if ($once || $this->stop) {
                break;
            }

            // Sleep in 1-second slices so SIGTERM is honoured promptly.
            for ($i = 0; $i < $interval && ! $this->stop; $i++) {
                sleep(1);
                if (function_exists('pcntl_signal_dispatch')) {
                    pcntl_signal_dispatch();
                }
            }
        } while (! $this->stop);

        $this->releaseLock();
        $this->log('exited cleanly');

        return self::SUCCESS;
    }

    private function syncCycle(): void
    {
        $repo = $this->resolveRepoPath();
        if ($repo === null) {
            // No .git anywhere — nothing to sync. Stay alive (operator
            // may rsync the repo into place later).
            return;
        }

        $branch = (string) config('deploy.branch', 'main');

        // 1. Fetch (non-destructive).
        $fetch = $this->git($repo, ['fetch', '--quiet', 'origin', $branch], 60);
        if (! $fetch['ok']) {
            $this->log('git fetch failed: '.$fetch['err']);

            return;
        }

        // 2. Compare local HEAD vs origin.
        $local = $this->git($repo, ['rev-parse', 'HEAD'], 10);
        $remote = $this->git($repo, ['rev-parse', "origin/{$branch}"], 10);
        if (! $local['ok'] || ! $remote['ok']) {
            $this->log('rev-parse failed: '.($local['err'] ?: $remote['err']));

            return;
        }
        if (trim($local['out']) === trim($remote['out'])) {
            // Already up to date — quiet success.
            return;
        }

        $this->log(sprintf('remote ahead: local=%s remote=%s — pulling',
            substr(trim($local['out']), 0, 8),
            substr(trim($remote['out']), 0, 8)
        ));

        // 3. Pull (--ff-only refuses to rewrite if the local has diverged).
        $pull = $this->git($repo, ['pull', '--ff-only', '--quiet', 'origin', $branch], 120);
        if (! $pull['ok']) {
            $this->log('git pull failed: '.$pull['err'].' — leaving server untouched');

            return;
        }
        $this->log('git pull OK');

        // 4. Migrate (idempotent — the SOT reconciliation migration is
        //    a single-shot no-op when the ledger is already current).
        $this->runMigrate('post-pull');

        // 5. Octane reload — the worker pool must be cycled or it keeps
        //    running the old class definitions in-process.
        if ((bool) config('deploy.octane_reload', true)) {
            $this->runOctaneReload();
        }

        // 6. Exit so the next HTTP-triggered ensureDeployWatchRunning()
        //    respawns us with the freshly-pulled DeployWatch class. This
        //    matters: a long-lived daemon never sees its own code updates
        //    until restart.
        $this->stop = true;
    }

    private function runMigrate(string $reason): void
    {
        try {
            $proc = new Process(
                [PHP_BINARY ?: 'php', base_path('artisan'), 'migrate', '--force', '--no-interaction'],
                base_path(),
                null,
                null,
                600
            );
            $proc->run();
            $out = trim($proc->getOutput().$proc->getErrorOutput());
            $first = strtok($out, "\n") ?: '';
            $this->log(sprintf('migrate [%s] exit=%d: %s', $reason, $proc->getExitCode(), $first));
            if (! $proc->isSuccessful()) {
                // Log full output on failure so the operator can see what
                // broke without re-running anything manually.
                $this->log('migrate full output:'.PHP_EOL.$out);
            }
        } catch (Throwable $e) {
            $this->log('migrate failed: '.$e->getMessage());
        }
    }

    private function runOctaneReload(): void
    {
        try {
            $proc = new Process(
                [PHP_BINARY ?: 'php', base_path('artisan'), 'octane:reload'],
                base_path(),
                null,
                null,
                30
            );
            $proc->run();
            $this->log(sprintf('octane:reload exit=%d', $proc->getExitCode()));
        } catch (Throwable $e) {
            // Octane may not be running (e.g., apache/nginx-only); not fatal.
            $this->log('octane:reload skipped: '.$e->getMessage());
        }
    }

    private function resolveRepoPath(): ?string
    {
        $configured = (string) config('deploy.repo_path');
        if ($configured !== '' && is_dir($configured.'/.git')) {
            return $configured;
        }
        // Common Laravel layouts: api/ checked out as a subdir of the repo.
        foreach ([base_path(), dirname(base_path())] as $candidate) {
            if (is_dir($candidate.'/.git')) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<int,string>  $args
     * @return array{ok:bool,out:string,err:string}
     */
    private function git(string $cwd, array $args, int $timeoutSeconds): array
    {
        try {
            // Force git to trust this repo regardless of ownership. The
            // common gov-server layout has the repo cloned by `ubuntu`
            // but the daemon runs as `www-data` via Octane; git's
            // CVE-2022-24765 "dubious ownership" check would otherwise
            // refuse every fetch. We inject safe.directory inline as
            // ad-hoc config so we don't have to write to ~/.gitconfig
            // (www-data's HOME may be /nonexistent).
            $env = [
                'GIT_CONFIG_COUNT' => '2',
                'GIT_CONFIG_KEY_0' => 'safe.directory',
                'GIT_CONFIG_VALUE_0' => $cwd,
                'GIT_CONFIG_KEY_1' => 'safe.directory',
                'GIT_CONFIG_VALUE_1' => '*',
                'HOME' => sys_get_temp_dir(),
            ];
            $proc = new Process(array_merge(['git'], $args), $cwd, $env, null, $timeoutSeconds);
            $proc->run();

            return [
                'ok' => $proc->isSuccessful(),
                'out' => $proc->getOutput(),
                'err' => trim($proc->getErrorOutput() ?: $proc->getOutput()),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'out' => '', 'err' => $e->getMessage()];
        }
    }

    private function acquireLock(): bool
    {
        $fh = @fopen($this->lockPath, 'c+');
        if (! $fh) {
            return false;
        }
        if (! flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return false;
        }
        ftruncate($fh, 0);
        fwrite($fh, (string) getmypid());
        fflush($fh);
        $this->lockHandle = $fh;

        return true;
    }

    private function releaseLock(): void
    {
        if ($this->lockHandle) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
            @unlink($this->lockPath);
            $this->lockHandle = null;
        }
    }

    private function installSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function (): void {
            $this->stop = true;
        };
        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGHUP, $handler);
    }

    private function log(string $msg): void
    {
        $line = sprintf("[%s] %s\n", date('c'), $msg);
        @file_put_contents($this->logPath, $line, FILE_APPEND);
        if ($this->getOutput()) {
            $this->line(rtrim($line));
        }
    }
}
