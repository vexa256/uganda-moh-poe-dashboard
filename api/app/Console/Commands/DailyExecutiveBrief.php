<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * notifications:daily-executive-brief
 *
 * 24-hour analytics email to the 10-person national executive roster.
 * KPI cards (screened, symptomatic, alerts, open caseload), 7-day trend
 * SVG bar chart, and top-5 POEs by volume. Sent every midnight
 * Africa/Kampala. Replaces the per-template notifications:daily-digest.
 *
 * Idempotent: a notification_log row with entity_type='DAILY_BRIEF' and
 * entity_id=YYYYMMDD guards against double-send.
 */
final class DailyExecutiveBrief extends Command
{
    protected $signature   = 'notifications:daily-executive-brief {--dry-run : compute but do not send}';
    protected $description = 'Send the daily executive analytics brief to the national executive roster.';

    public function handle(): int
    {
        if ($this->option('dry-run')) {
            $this->warn('DRY-RUN mode — no email will be dispatched.');
            $this->info('In live mode this would: 1) compute 24h KPIs + 7d trend + top-5 POEs, 2) send ONE email to executive roster.');
            return self::SUCCESS;
        }

        $this->info('Building daily executive brief…');
        $result = NotificationDispatcher::sendDailyExecutiveBrief('CRON:daily-executive-brief');

        $this->table(['metric', 'value'], [
            ['sent',       $result['sent']       ?? 0],
            ['skipped',    $result['skipped']    ?? 0],
            ['failed',     $result['failed']     ?? 0],
            ['cc_count',   $result['cc_count']   ?? 0],
            ['day_key',    $result['day_key']    ?? '—'],
            ['screened',   $result['screened']   ?? 0],
            ['alerts_new', $result['alerts_new'] ?? 0],
            ['reason',     $result['reason']     ?? '—'],
            ['error',      $result['error']      ?? '—'],
        ]);

        return ($result['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
