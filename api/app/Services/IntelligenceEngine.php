<?php

declare (strict_types = 1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * IntelligenceEngine
 *
 * Hardcoded WHO IDSR / 7-1-7 anomaly detection. No machine learning, no
 * external services — every rule is a deterministic SQL query traceable to
 * a published source.
 *
 * Country-aware: every detector accepts an ISO country_code and returns
 * counts scoped to that country. The national roster receives only
 * its findings. When additional country rosters are later seeded,
 * each country's detector run lands in its own digest.
 *
 * Detectors implemented (all return non-negative integers + supporting
 * lists where useful):
 *
 *   1. silentPoes24h          — POEs with zero primary screenings in 24h
 *   2. unsubmittedPoes3d      — POEs without an aggregated submission in 3d
 *   3. dormantAccounts        — users with no last_login_at in 14d+
 *   4. stuckAlerts            — alerts OPEN > 24h without acknowledgement
 *   5. overdueFollowups       — alert_followups past due_at, not COMPLETED
 *   6. caseSpikes             — POEs with last-3d symptomatic-rate > 2× of
 *                                their prior 7-day baseline (z-score proxy)
 *
 * Plus rolling-window aggregates (3d screenings, 3d secondaries, 3d alerts)
 * for the National Intelligence digest.
 *
 * SOURCES:
 *   • WHO AFRO IDSR Technical Guidelines 3rd Ed. — Annex C (POE)
 *   • Uganda IDSR Technical Guidelines (MoH) — non-submission escalation
 *   • RTSL/WHO 7-1-7 — bottleneck classification
 *   • IHR 2005 Annex 2 — alert acknowledgement timing
 */
final class IntelligenceEngine
{
    /**
     * Run the full detector suite for one country and return a structured
     * report ready to be stamped into a NATIONAL_INTELLIGENCE template.
     */
    public static function runFullReport(string $countryCode): array
    {
        return [
            'country_code'           => $countryCode,
            'report_date'            => now()->format('Y-m-d'),
            'window_days'            => 3,

            // Detectors
            'poe_silent_24h'         => static::silentPoes24h($countryCode),
            'poe_no_submission_3d'   => static::unsubmittedPoes3d($countryCode),
            'dormant_accounts'       => static::dormantAccounts($countryCode),
            'stuck_alerts'           => static::stuckAlerts($countryCode),
            'overdue_followups'      => static::overdueFollowups($countryCode),
            'spike_count'            => static::caseSpikes($countryCode),

            // Rolling 3-day aggregates
            'total_screenings_3d'    => static::countSince('primary_screenings', 'captured_at', $countryCode, 3),
            'total_secondary_3d'     => static::countSince('secondary_screenings', 'opened_at', $countryCode, 3),
            'total_alerts_3d'        => static::countSince('alerts', 'created_at', $countryCode, 3),

            // Human-readable narrative built from the above
            'anomaly_summary'        => static::narrativeFor($countryCode),
        ];
    }

    /**
     * POEs with zero primary screenings in the last 24h, scoped to a
     * country. Built from primary_screenings.poe_code only — does not
     * cross-reference a master POE registry, so it captures POEs that
     * have been active before but went silent.
     */
    public static function silentPoes24h(string $countryCode): int
    {
        $since = now()->subDay()->format('Y-m-d H:i:s');
        // Distinct POEs that screened anything in the prior 7 days but
        // nothing in the last 24h.
        $activeRecent = DB::table('primary_screenings')
            ->where('country_code', $countryCode)
            ->where('captured_at', '>=', now()->subDays(7)->format('Y-m-d H:i:s'))
            ->whereNull('deleted_at')
            ->distinct()->pluck('poe_code');

        $silent = 0;
        foreach ($activeRecent as $poe) {
            $hadRecent = DB::table('primary_screenings')
                ->where('country_code', $countryCode)
                ->where('poe_code', $poe)
                ->where('captured_at', '>=', $since)
                ->whereNull('deleted_at')
                ->exists();
            if (! $hadRecent) $silent++;
        }
        return $silent;
    }

    /**
     * POEs that haven't sent an aggregated_submission in the last 3 days.
     * Definition of "should have sent" = had a submission in the prior 14d.
     */
    public static function unsubmittedPoes3d(string $countryCode): int
    {
        $window = now()->subDays(3)->format('Y-m-d H:i:s');
        $baseline = now()->subDays(14)->format('Y-m-d H:i:s');

        $regulars = DB::table('aggregated_submissions')
            ->where('country_code', $countryCode)
            ->where('created_at', '>=', $baseline)
            ->whereNull('deleted_at')
            ->distinct()->pluck('poe_code');

        $missing = 0;
        foreach ($regulars as $poe) {
            $hadRecent = DB::table('aggregated_submissions')
                ->where('country_code', $countryCode)
                ->where('poe_code', $poe)
                ->where('created_at', '>=', $window)
                ->whereNull('deleted_at')
                ->exists();
            if (! $hadRecent) $missing++;
        }
        return $missing;
    }

    /**
     * Users with no last_login_at in the last 14 days, scoped to country.
     */
    public static function dormantAccounts(string $countryCode): int
    {
        return (int) DB::table('users')
            ->where('country_code', $countryCode)
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('last_login_at')
                  ->orWhere('last_login_at', '<', now()->subDays(14));
            })
            ->count();
    }

    /**
     * Alerts open >24h without acknowledgement.
     */
    public static function stuckAlerts(string $countryCode): int
    {
        return (int) DB::table('alerts')
            ->where('country_code', $countryCode)
            ->where('status', 'OPEN')
            ->whereRaw('TIMESTAMPDIFF(HOUR, created_at, NOW()) > 24')
            ->whereNull('deleted_at')
            ->count();
    }

    /**
     * RTSL follow-ups past due, not COMPLETED, not NOT_APPLICABLE.
     */
    public static function overdueFollowups(string $countryCode): int
    {
        return (int) DB::table('alert_followups as f')
            ->leftJoin('alerts as a', 'a.id', '=', 'f.alert_id')
            ->where('a.country_code', $countryCode)
            ->whereNotNull('f.due_at')
            ->where('f.due_at', '<', now())
            ->whereNotIn('f.status', ['COMPLETED', 'NOT_APPLICABLE'])
            ->whereNull('f.deleted_at')
            ->count();
    }

    /**
     * POEs whose last-3d symptomatic count is more than 2× their prior
     * 7-day baseline. Z-score-style detection without the math: uses a
     * straight ratio because base rates are usually small at POE level.
     *
     * Returns the COUNT of POEs in spike state, not the spike magnitude.
     */
    public static function caseSpikes(string $countryCode): int
    {
        $now      = now();
        $w3Start  = $now->copy()->subDays(3)->format('Y-m-d H:i:s');
        $bStart   = $now->copy()->subDays(10)->format('Y-m-d H:i:s');
        $bEnd     = $now->copy()->subDays(3)->format('Y-m-d H:i:s');

        // Sum symptomatic per POE in the recent window
        $recent = DB::table('primary_screenings')
            ->where('country_code', $countryCode)
            ->where('captured_at', '>=', $w3Start)
            ->where('symptoms_present', 1)
            ->whereNull('deleted_at')
            ->selectRaw('poe_code, COUNT(*) AS n')
            ->groupBy('poe_code')->pluck('n', 'poe_code');

        $spikes = 0;
        foreach ($recent as $poe => $recentCount) {
            $baselineCount = (int) DB::table('primary_screenings')
                ->where('country_code', $countryCode)
                ->where('poe_code', $poe)
                ->where('captured_at', '>=', $bStart)
                ->where('captured_at', '<', $bEnd)
                ->where('symptoms_present', 1)
                ->whereNull('deleted_at')
                ->count();
            // Spike if recent (3d) ≥ 2× the baseline 7d average mapped to 3d
            // baselineCount is 7d → divide by 7 then multiply by 3 to compare windows
            $expected = max(1.0, $baselineCount * (3.0 / 7.0));
            if ($recentCount >= 2 * $expected) {
                $spikes++;
            }
        }
        return $spikes;
    }

    /**
     * Build a one-paragraph narrative summarising what the detectors found.
     * Plain English, no jargon-stuffing — designed to read well in an email.
     */
    public static function narrativeFor(string $countryCode): string
    {
        $silent     = static::silentPoes24h($countryCode);
        $missing    = static::unsubmittedPoes3d($countryCode);
        $dormant    = static::dormantAccounts($countryCode);
        $stuck      = static::stuckAlerts($countryCode);
        $overdue    = static::overdueFollowups($countryCode);
        $spikes     = static::caseSpikes($countryCode);

        $parts = [];
        if ($silent > 0)  $parts[] = "{$silent} POE(s) submitted no primary screenings in the last 24 hours";
        if ($missing > 0) $parts[] = "{$missing} POE(s) missed the 3-day aggregated reporting cadence";
        if ($spikes > 0)  $parts[] = "{$spikes} POE(s) show a symptomatic-rate spike vs. their prior 7-day baseline";
        if ($stuck > 0)   $parts[] = "{$stuck} alert(s) are still OPEN past 24 hours without acknowledgement";
        if ($overdue > 0) $parts[] = "{$overdue} RTSL follow-up(s) are overdue";
        if ($dormant > 0) $parts[] = "{$dormant} active account(s) have not logged in for 14+ days";

        if (empty($parts)) {
            return 'No anomalies detected in the 3-day window. Surveillance, reporting cadence and follow-up workflows are within target. Continue routine monitoring.';
        }
        return 'Detected: ' . implode('; ', $parts) . '. Review the in-app Intelligence Hub for per-POE drill-down and recommended remediation.';
    }

    /**
     * Helper — count rows in a table since N days ago, scoped to country.
     */
    private static function countSince(string $table, string $dateCol, string $countryCode, int $days): int
    {
        try {
            $q = DB::table($table)
                ->where('country_code', $countryCode)
                ->where($dateCol, '>=', now()->subDays($days)->format('Y-m-d H:i:s'));
            // Soft-delete column is named deleted_at in every table we touch
            $q->whereNull('deleted_at');
            return (int) $q->count();
        } catch (Throwable $e) {
            Log::warning("[IntelligenceEngine::countSince] {$table}.{$dateCol} -> " . $e->getMessage());
            return 0;
        }
    }
}
