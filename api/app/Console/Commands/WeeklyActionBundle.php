<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * notifications:weekly-action-bundle
 *
 * Single-envelope, per-jurisdiction-clustered email containing all due/overdue
 * follow-ups and unresolved 7-1-7 breaches for the week. Replaces the daily
 * followup-reminders + national-digest jobs per executive directive
 * (2026-05-17). Runs every Monday 08:00 Africa/Kampala.
 *
 * Idempotent: a notification_log row with entity_type='WEEKLY_BUNDLE' and
 * entity_id=ISO_WEEK_KEY guards against double-send if the cron fires twice.
 */
final class WeeklyActionBundle extends Command
{
    protected $signature   = 'notifications:weekly-action-bundle {--dry-run : compute but do not send}';
    protected $description = 'Send the single weekly action digest (follow-ups + breaches) to the national executive roster.';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            $this->warn('DRY-RUN mode — no email will be dispatched. Use without --dry-run to send.');
            // Simulate by calling but the dispatcher always sends; we wrap in a notice instead.
            $this->info('In live mode this would: 1) gather follow-ups + breaches, 2) cluster by jurisdiction, 3) send ONE email with CC to all NATIONAL recipients.');
            return self::SUCCESS;
        }

        $this->info('Building weekly action bundle…');
        $result = NotificationDispatcher::sendWeeklyActionBundle('CRON:weekly-action-bundle');

        $this->table(['metric', 'value'], [
            ['sent',           $result['sent']           ?? 0],
            ['skipped',        $result['skipped']        ?? 0],
            ['failed',         $result['failed']         ?? 0],
            ['cc_count',       $result['cc_count']       ?? 0],
            ['clusters',       $result['clusters']       ?? 0],
            ['followup_items', $result['followup_items'] ?? 0],
            ['breach_items',   $result['breach_items']   ?? 0],
            ['alerts_touched', $result['alerts_touched'] ?? 0],
            ['week_key',       $result['week_key']       ?? '—'],
            ['reason',         $result['reason']         ?? '—'],
            ['error',          $result['error']          ?? '—'],
        ]);

        return ($result['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
