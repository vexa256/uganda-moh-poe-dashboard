<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * notifications:weekly-digest
 *
 * Scheduled every Monday 07:30 Kampala time via routes/console.php.
 * Sends the comprehensive 7-day scorecard to all contacts with
 * receives_weekly_report=1, including:
 *
 *   - 7d vs prior-7d screening volume comparison
 *   - Symptomatic and fever rates
 *   - Direction, nationality, conveyance breakdowns
 *   - Alert table by risk level + IHR 7-1-7 compliance rate
 *   - Secondary screening disposition breakdown
 *   - Top 8 suspected diseases
 *   - POE-by-POE activity table
 *   - Silent POEs (no submission this week)
 *   - Officer productivity (active vs dormant)
 *   - Blocking overdue follow-ups
 *   - Aggregated report submission compliance
 *
 * Manual invocation:   php artisan notifications:weekly-digest
 */
final class NotificationsWeeklyDigest extends Command
{
    protected $signature   = 'notifications:weekly-digest';
    protected $description = 'Send the comprehensive 7-day POE scorecard to all subscribed contacts.';

    public function handle(): int
    {
        $this->info('Dispatching weekly scorecard digest…');
        $result = NotificationDispatcher::sendWeeklyDigest('CRON:weekly');
        $this->table(['metric', 'value'], [
            ['sent',    $result['sent']    ?? 0],
            ['skipped', $result['skipped'] ?? 0],
            ['failed',  $result['failed']  ?? 0],
        ]);
        if (($result['failed'] ?? 0) > 0) {
            $this->error('Some emails failed — check notification_log for details.');
            return self::FAILURE;
        }
        $this->info('Weekly digest dispatched successfully.');
        return self::SUCCESS;
    }
}
