<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\NotificationDispatcher;
use Illuminate\Console\Command;

/**
 * notifications:national-digest
 *
 * Triennial intelligence digest. Runs every 3 days (08:00 local time)
 * via the Laravel scheduler. For each country with at least one
 * NATIONAL-tier subscriber, runs the IntelligenceEngine and emails the
 * top-3 priority NATIONAL contacts a structured anomaly + activity report.
 *
 * Operational roster (priority 4-19) is intentionally excluded — this is
 * a strategic briefing, not a tactical alert.
 *
 * Manual invocation:  php artisan notifications:national-digest
 */
final class NotificationsNationalDigest extends Command
{
    protected $signature   = 'notifications:national-digest';
    protected $description = 'Send the triennial National Surveillance Intelligence digest to NATIONAL_ADMIN-tier contacts.';

    public function handle(): int
    {
        $this->info('Generating national intelligence digests…');
        $r = NotificationDispatcher::sendNationalIntelligenceDigest('CRON:national-intel');
        $this->table(['metric', 'value'], [
            ['countries_processed', $r['countries_processed'] ?? 0],
            ['sent',                $r['sent']                ?? 0],
            ['skipped',             $r['skipped']             ?? 0],
            ['failed',              $r['failed']              ?? 0],
        ]);
        return ($r['failed'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
