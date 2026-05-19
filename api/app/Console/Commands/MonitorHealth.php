<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\SentinelMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;
use Symfony\Component\Process\Process;
use Throwable;

final class MonitorHealth extends Command
{
    protected $signature = 'monitor:watch {--once : Run a single cycle and exit}';

    protected $description = 'Daemon: every N seconds git-pulls, probes public URLs, emails on outage transitions.';

    private string $lockPath;

    private string $statePath;

    /** @var resource|null */
    private $lockHandle = null;

    private bool $stop = false;

    public function handle(): int
    {
        $this->lockPath  = storage_path('app/monitor.lock');
        $this->statePath = storage_path('app/monitor_state.json');
        @mkdir(dirname($this->lockPath), 0775, true);

        if (! $this->acquireLock()) {
            $this->warn('monitor:watch already running — exiting.');
            return self::SUCCESS;
        }

        $this->installSignalHandlers();

        $once = (bool) $this->option('once');
        $interval = max(30, (int) config('monitor.interval_seconds', 300));

        $this->info(sprintf(
            '[%s] monitor:watch started pid=%d interval=%ds once=%s',
            now()->toIso8601String(), getmypid(), $interval, $once ? 'yes' : 'no'
        ));

        do {
            try {
                $this->cycle();
            } catch (Throwable $e) {
                $this->error('Cycle error: ' . $e->getMessage());
                report($e);
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
        return self::SUCCESS;
    }

    private function cycle(): void
    {
        if (config('monitor.git_pull', true)) {
            $this->gitPull();
        }

        $results = $this->probeTargets();
        $allOk = ! collect($results)->contains(fn (array $r) => ! $r['ok']);
        $state = $this->loadState();

        $now = now();
        $nowTs = $now->getTimestamp();

        if (! $allOk) {
            $reminderSeconds = max(60, (int) config('monitor.reminder_seconds', 3600));
            $shouldEmail = false;
            $kind = 'down';

            if (! ($state['is_down'] ?? false)) {
                $shouldEmail = true;
                $kind = 'down';
                $state['is_down']      = true;
                $state['down_since']   = $now->toIso8601String();
                $state['last_email']   = $nowTs;
            } elseif (($nowTs - (int) ($state['last_email'] ?? 0)) >= $reminderSeconds) {
                $shouldEmail = true;
                $kind = 'reminder';
                $state['last_email']   = $nowTs;
            }

            if ($shouldEmail) {
                $this->sendAlert($results, $state['down_since'] ?? $now->toIso8601String(), $kind);
            }
        } else {
            if ($state['is_down'] ?? false) {
                $this->sendAlert($results, $state['down_since'] ?? '—', 'recovered');
            }
            $state['is_down']    = false;
            $state['down_since'] = null;
            $state['last_email'] = $state['last_email'] ?? 0;
        }

        $state['last_check'] = $now->toIso8601String();
        $this->saveState($state);

        $this->info(sprintf('[%s] cycle done — %s', $now->toIso8601String(), $allOk ? 'OK' : 'DOWN'));
    }

    /** @return array<int,array{name:string,url:string,ok:bool,detail:string}> */
    private function probeTargets(): array
    {
        $timeout = max(3, (int) config('monitor.request_timeout', 15));
        $rows = [];
        foreach ((array) config('monitor.targets', []) as $t) {
            $name = (string) ($t['name'] ?? 'target');
            $url  = (string) ($t['url']  ?? '');
            $expect = (int) ($t['expect'] ?? 200);
            if ($url === '') continue;

            try {
                $resp = Http::timeout($timeout)->connectTimeout(min($timeout, 8))
                    ->withOptions(['verify' => true, 'allow_redirects' => true])
                    ->get($url);
                $ok = $resp->status() === $expect || ($expect === 200 && $resp->successful());
                $rows[] = [
                    'name'   => $name,
                    'url'    => $url,
                    'ok'     => $ok,
                    'detail' => 'HTTP ' . $resp->status(),
                ];
            } catch (Throwable $e) {
                $rows[] = [
                    'name'   => $name,
                    'url'    => $url,
                    'ok'     => false,
                    'detail' => substr($e->getMessage(), 0, 200),
                ];
            }
        }
        return $rows;
    }

    private function gitPull(): void
    {
        try {
            $cwd = base_path();
            if (is_dir(dirname($cwd) . '/.git')) {
                $cwd = dirname($cwd);
            } elseif (! is_dir($cwd . '/.git')) {
                return; // not a git checkout
            }
            // Inline safe.directory so git doesn't refuse to operate on a
            // repo owned by a different user (CVE-2022-24765 mitigation).
            // Common case: repo cloned by `ubuntu`, monitor runs as
            // `www-data`. HOME=/tmp dodges the case where www-data has
            // no writable home for git's ~/.gitconfig.
            $env = [
                'GIT_CONFIG_COUNT'   => '2',
                'GIT_CONFIG_KEY_0'   => 'safe.directory',
                'GIT_CONFIG_VALUE_0' => $cwd,
                'GIT_CONFIG_KEY_1'   => 'safe.directory',
                'GIT_CONFIG_VALUE_1' => '*',
                'HOME'               => sys_get_temp_dir(),
            ];
            $proc = new Process(['git', 'pull', '--ff-only', '--quiet'], $cwd, $env, null, 60);
            $proc->run();
            if (! $proc->isSuccessful()) {
                $this->warn('git pull non-zero: ' . trim($proc->getErrorOutput() ?: $proc->getOutput()));
            }
        } catch (Throwable $e) {
            $this->warn('git pull failed: ' . $e->getMessage());
        }
    }

    /** @param array<int,array<string,mixed>> $results */
    private function sendAlert(array $results, string $downSince, string $kind): void
    {
        $recipients = array_values(array_filter((array) config('monitor.recipients', [])));
        if ($recipients === []) {
            $this->warn('No MONITOR_RECIPIENTS configured — alert skipped.');
            return;
        }

        $sender = (array) config('monitor.sender');
        $host = (string) (parse_url((string) ($results[0]['url'] ?? config('app.url')), PHP_URL_HOST) ?: config('app.url'));

        $subjectPrefix = match ($kind) {
            'recovered' => '[RECOVERED]',
            'reminder'  => '[STILL DOWN]',
            default     => '[DOWN]',
        };
        $subject = sprintf('%s Uganda POE Sentinel — %s', $subjectPrefix, $host);

        $viewData = [
            'results'   => $results,
            'host'      => $host,
            'downSince' => $downSince,
            'kind'      => strtoupper($kind),
        ];
        $html = View::make('emails.server-down', $viewData)->render();
        $text = $this->buildTextBody($results, $host, $downSince, $kind);

        $primary = array_shift($recipients);
        $cc = $recipients;

        try {
            $mail = new SentinelMail(
                toAddress:      $primary,
                subjectLine:    $subject,
                htmlBody:       $html,
                textBody:       $text,
                ccAddresses:    $cc,
                replyToAddress: $sender['email'] ?? null,
                replyToName:    $sender['name']  ?? null,
                entityRefId:    'monitor-' . $kind . '-' . bin2hex(random_bytes(6)),
            );
            Mail::send($mail);
            $this->info("Alert queued [{$kind}] → {$primary}" . ($cc ? ' (+'.count($cc).' cc)' : ''));
        } catch (Throwable $e) {
            $this->error('Alert send failed: ' . $e->getMessage());
            report($e);
        }
    }

    /** @param array<int,array<string,mixed>> $results */
    private function buildTextBody(array $results, string $host, string $downSince, string $kind): string
    {
        $lines = [];
        $lines[] = 'Uganda POE Sentinel — server watch';
        $lines[] = 'Notification: ' . strtoupper($kind);
        $lines[] = 'Host: ' . $host;
        $lines[] = 'First detected down: ' . $downSince;
        $lines[] = '';
        foreach ($results as $r) {
            $lines[] = sprintf('%s  %s  %s  (%s)',
                $r['ok'] ? 'UP  ' : 'DOWN',
                str_pad((string) $r['name'], 18),
                $r['url'],
                $r['detail']
            );
        }
        $lines[] = '';
        $lines[] = 'Regards,';
        $lines[] = 'AYEBARE Timothy — ECSAHC';
        return implode("\n", $lines);
    }

    /** @return array<string,mixed> */
    private function loadState(): array
    {
        if (! is_file($this->statePath)) return [];
        try {
            $raw = (string) file_get_contents($this->statePath);
            $j = json_decode($raw, true);
            return is_array($j) ? $j : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $state */
    private function saveState(array $state): void
    {
        @file_put_contents($this->statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function acquireLock(): bool
    {
        $fh = @fopen($this->lockPath, 'c+');
        if (! $fh) return false;
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
        if (! function_exists('pcntl_signal')) return;
        pcntl_async_signals(true);
        $h = function (): void { $this->stop = true; };
        pcntl_signal(SIGTERM, $h);
        pcntl_signal(SIGINT,  $h);
        pcntl_signal(SIGHUP,  $h);
    }
}
