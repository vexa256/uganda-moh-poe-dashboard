<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * notifications:daily-digest
 *
 * Scheduled by routes/console.php to run every morning at 07:00 local time.
 * Builds the previous-24h surveillance digest per country scope and emails
 * every contact with receives_daily_report=1.
 *
 * Manual invocation:   php artisan notifications:daily-digest
 * Scheduled:           cron task runs it daily at 07:00
 */
final class NotificationsDailyDigest extends Command
{
    protected $signature   = 'notifications:daily-digest';
    protected $description = 'Send the daily POE surveillance digest to all subscribed contacts.';

    public function handle(): int
    {
        $this->info('Dispatching daily digest…');
        $result = NotificationDispatcher::sendDailyDigest('CRON:daily');
        $this->table(['metric', 'value'], [
            ['sent',    $result['sent']    ?? 0],
            ['skipped', $result['skipped'] ?? 0],
            ['failed',  $result['failed']  ?? 0],
        ]);
        return ($result['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
