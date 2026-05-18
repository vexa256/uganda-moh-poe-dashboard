<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * notifications:followup-reminders
 *
 * Scans alert_followups for DUE-SOON (<4h remaining) and OVERDUE items and
 * emails the POE's followup-subscribed contacts. Intended to run hourly so
 * reminders are timely without spamming.
 */
final class NotificationsFollowupReminders extends Command
{
    protected $signature   = 'notifications:followup-reminders';
    protected $description = 'Email due / overdue 7-1-7 follow-up reminders.';

    public function handle(): int
    {
        $this->info('Scanning follow-ups…');
        $result = NotificationDispatcher::sendFollowupReminders('CRON:followups');
        $this->table(['metric', 'value'], [
            ['overdue_count',   $result['overdue_count']   ?? 0],
            ['due_soon_count',  $result['due_soon_count']  ?? 0],
            ['sent',            $result['sent']            ?? 0],
            ['skipped',         $result['skipped']         ?? 0],
            ['failed',          $result['failed']          ?? 0],
        ]);
        return ($result['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
