<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * notifications:retry-failed
 *
 * Re-delivers FAILED notification_log rows with retry_count < 4. Intended to
 * run every 15 minutes so transient SMTP hiccups recover automatically.
 */
final class NotificationsRetryFailed extends Command
{
    protected $signature   = 'notifications:retry-failed';
    protected $description = 'Retry FAILED notification_log rows (up to 4 attempts each).';

    public function handle(): int
    {
        $this->info('Retrying failed notifications…');
        $result = NotificationDispatcher::retryFailed('CRON:retry');
        $this->table(['metric', 'value'], [
            ['candidates',   $result['candidates']   ?? 0],
            ['retried',      $result['retried']      ?? 0],
            ['still_failed', $result['still_failed'] ?? 0],
        ]);
        return self::SUCCESS;
    }
}
