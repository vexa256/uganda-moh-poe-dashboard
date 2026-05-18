<?php

declare (strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  PrimaryScreeningDashboardController                                         ║
 * ║  ECSA-HC POE Sentinel · WHO/IHR 2005 · Analytics & Reporting Engine         ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  Purpose:   Full operational intelligence dashboard for primary screening.   ║
 * ║             Covers real-time operational metrics, historical trend analysis,  ║
 * ║             epidemiological indicators, referral funnel analytics, geographic  ║
 * ║             comparison, device/sync health, and WHO/IHR weekly report data.  ║
 * ║                                                                              ║
 * ║  Database:  poe_2026  (DB:: facade — NO Eloquent anywhere)                  ║
 * ║  Auth:      NONE — routes are publicly accessible by design.                 ║
 * ║             Do NOT add Authorization: Bearer checks in this file.            ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  GEOGRAPHIC SCOPE — server-enforced, never trusts client params:             ║
 * ║    POE_PRIMARY, POE_SECONDARY, POE_DATA_OFFICER, POE_ADMIN                  ║
 * ║      → WHERE poe_code = assignment.poe_code                                 ║
 * ║    DISTRICT_SUPERVISOR → WHERE district_code = assignment.district_code     ║
 * ║    PHEOC_OFFICER        → WHERE pheoc_code = assignment.pheoc_code          ║
 * ║    NATIONAL_ADMIN       → WHERE country_code = assignment.country_code      ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  ROUTES (paste into routes/api.php — BEFORE any /{id} wildcard routes):     ║
 * ║                                                                              ║
 * ║  use App\Http\Controllers\PrimaryScreeningDashboardController as PSDC;     ║
 * ║                                                                              ║
 * ║  Route::get('/dashboard/summary',         [PSDC::class, 'summary']);         ║
 * ║  Route::get('/dashboard/trend',           [PSDC::class, 'trend']);           ║
 * ║  Route::get('/dashboard/heatmap',         [PSDC::class, 'heatmap']);         ║
 * ║  Route::get('/dashboard/funnel',          [PSDC::class, 'funnel']);          ║
 * ║  Route::get('/dashboard/epi',             [PSDC::class, 'epi']);             ║
 * ║  Route::get('/dashboard/poe-comparison',  [PSDC::class, 'poeComparison']);   ║
 * ║  Route::get('/dashboard/screener-report', [PSDC::class, 'screenerReport']);  ║
 * ║  Route::get('/dashboard/device-health',   [PSDC::class, 'deviceHealth']);    ║
 * ║  Route::get('/dashboard/alerts-summary',  [PSDC::class, 'alertsSummary']);   ║
 * ║  Route::get('/dashboard/weekly-report',   [PSDC::class, 'weeklyReport']);    ║
 * ║  Route::get('/dashboard/live',            [PSDC::class, 'live']);            ║
 * ║                                                                              ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  COMMON QUERY PARAMETERS (all endpoints):                                    ║
 * ║    user_id      int (required) — AUTH_DATA.id                               ║
 * ║    poe_code     string (district+ only — sub-filter within their scope)     ║
 * ║                                                                              ║
 * ║  DATE WINDOW PARAMETERS (where applicable):                                  ║
 * ║    date_from    YYYY-MM-DD (captured_at ≥ this date at 00:00:00)           ║
 * ║    date_to      YYYY-MM-DD (captured_at ≤ this date at 23:59:59)           ║
 * ║    days         int (alternative: rolling window, e.g. days=30; max 365)   ║
 * ║                                                                              ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  ENDPOINT REFERENCE:                                                         ║
 * ║                                                                              ║
 * ║  GET /dashboard/summary                                                      ║
 * ║    Full operational snapshot. Combines today's live counters, yesterday      ║
 * ║    comparison (delta), this-week totals, all-time aggregates, referral       ║
 * ║    queue health, alert status, and sync health. Designed as the single       ║
 * ║    API call that loads the main dashboard "above the fold".                  ║
 * ║                                                                              ║
 * ║  GET /dashboard/trend                                                        ║
 * ║    Daily time-series for the specified window (default: last 30 days).       ║
 * ║    One row per calendar day. Zero-fills missing days (no chart gaps).        ║
 * ║    Includes 7-day rolling average. Used for line/area charts.                ║
 * ║    Params: days (1–365), date_from/date_to                                  ║
 * ║                                                                              ║
 * ║  GET /dashboard/heatmap                                                      ║
 * ║    Volume distribution by time unit.                                         ║
 * ║    group_by=hour    → 24 hourly buckets (peak detection)                    ║
 * ║    group_by=weekday → 7 weekday buckets with avg per occurrence              ║
 * ║    group_by=day     → calendar grid (for GitHub-style heatmap)               ║
 * ║    group_by=month   → 12 monthly buckets (year overview)                    ║
 * ║    Params: group_by, date_from/date_to                                       ║
 * ║                                                                              ║
 * ║  GET /dashboard/funnel                                                       ║
 * ║    Referral & case management funnel from primary → notification →           ║
 * ║    secondary case → disposition. Shows conversion rates at each stage.       ║
 * ║    Identifies bottlenecks: unactioned referrals, stalled cases, etc.         ║
 * ║                                                                              ║
 * ║  GET /dashboard/epi                                                          ║
 * ║    Epidemiological indicators: symptom rate trend, temperature distribution, ║
 * ║    fever prevalence by gender, traveler origin country breakdown,            ║
 * ║    syndrome classification from secondary cases, risk level distribution.    ║
 * ║                                                                              ║
 * ║  GET /dashboard/poe-comparison                                               ║
 * ║    Side-by-side metrics for all POEs within the user's geographic scope.     ║
 * ║    POE-level users: 404 (no comparison for single POE).                      ║
 * ║    District+: each POE's total, symptomatic rate, referral rate, sync health.║
 * ║                                                                              ║
 * ║  GET /dashboard/screener-report                                               ║
 * ║    Per-officer performance metrics: records captured, symptomatic count,     ║
 * ║    referrals created, last active timestamp, avg captures per day, device.   ║
 * ║    Params: date_from/date_to, poe_code (district+ sub-filter)               ║
 * ║                                                                              ║
 * ║  GET /dashboard/device-health                                                 ║
 * ║    Sync health per device: unsynced count, oldest unsynced record,           ║
 * ║    last seen timestamp, failed sync records, app version, platform.          ║
 * ║    Identifies devices that have not synced in >24h (data loss risk).         ║
 * ║                                                                              ║
 * ║  GET /dashboard/alerts-summary                                               ║
 * ║    Active alert dashboard: open/acknowledged alerts by risk level,           ║
 * ║    routing level distribution, time-to-acknowledge metrics, recent alerts.   ║
 * ║                                                                              ║
 * ║  GET /dashboard/weekly-report                                                 ║
 * ║    WHO/IHR compliant weekly summary report data. Returns one complete         ║
 * ║    report-week object: total by gender, symptomatic rate, referral count,    ║
 * ║    case outcomes, alerts raised, average daily throughput, peak day.         ║
 * ║    Params: week_offset=0 (current week), week_offset=1 (last week), etc.    ║
 * ║                                                                              ║
 * ║  GET /dashboard/live                                                          ║
 * ║    Lightweight real-time ticker endpoint. Returns today's running totals     ║
 * ║    with minimal DB load. Intended to be polled every 30 seconds by the UI.  ║
 * ║    No heavy joins — single aggregation query only.                           ║
 * ║                                                                              ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */
final class PrimaryScreeningDashboardController extends Controller
{
    // ═════════════════════════════════════════════════════════════════════
    // CONSTANTS
    // ═════════════════════════════════════════════════════════════════════

    private const POE_LEVEL_ROLES = ['POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_ADMIN', 'SCREENER'];
    private const MAX_DAYS        = 365;
    private const FEVER_C         = 37.5;
    private const HIGH_FEVER_C    = 38.5;
    private const VALID_HEATMAP   = ['hour', 'weekday', 'day', 'month'];

    // ═════════════════════════════════════════════════════════════════════
    // GET /dashboard/summary
    // Full operational snapshot — the "above the fold" dashboard load.
    // All sub-queries are independent and cannot block each other.
    // Total: 8 DB queries for the complete dashboard.
    // ═════════════════════════════════════════════════════════════════════

    public function summary(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        $poeFilter = $this->poeSubFilter($request, $user);

        try {
            $today      = now()->format('Y-m-d');
            $yesterday  = now()->subDay()->format('Y-m-d');
            $weekStart  = now()->startOfWeek()->format('Y-m-d');
            $monthStart = now()->startOfMonth()->format('Y-m-d');

            // ── Query 1: All-time totals ──────────────────────────────────
            $allTime = $this->base($user, $assignment, $poeFilter)
                ->selectRaw('
                    COUNT(*)                                    AS total_all_time,
                    SUM(CASE WHEN record_status="COMPLETED" THEN 1 ELSE 0 END) AS total_completed,
                    SUM(CASE WHEN record_status="VOIDED"    THEN 1 ELSE 0 END) AS total_voided,
                    SUM(CASE WHEN record_status="COMPLETED" AND symptoms_present=1 THEN 1 ELSE 0 END) AS total_symptomatic,
                    SUM(CASE WHEN record_status="COMPLETED" AND symptoms_present=0 THEN 1 ELSE 0 END) AS total_asymptomatic,
                    SUM(CASE WHEN record_status="COMPLETED" AND referral_created=1  THEN 1 ELSE 0 END) AS total_referrals,
                    SUM(CASE WHEN sync_status="UNSYNCED"   THEN 1 ELSE 0 END) AS total_unsynced,
                    SUM(CASE WHEN sync_status="FAILED"     THEN 1 ELSE 0 END) AS total_sync_failed,
                    SUM(CASE WHEN record_status="COMPLETED" AND gender="MALE"    THEN 1 ELSE 0 END) AS total_male,
                    SUM(CASE WHEN record_status="COMPLETED" AND gender="FEMALE"  THEN 1 ELSE 0 END) AS total_female,
                    SUM(CASE WHEN record_status="COMPLETED" AND gender="OTHER"   THEN 1 ELSE 0 END) AS total_other,
                    SUM(CASE WHEN record_status="COMPLETED" AND gender="UNKNOWN" THEN 1 ELSE 0 END) AS total_unknown,
                    MAX(captured_at) AS last_capture_at
                ')->first();

            // ── Query 2: Today ────────────────────────────────────────────
            $todayRow = $this->base($user, $assignment, $poeFilter)
                ->whereDate('captured_at', $today)
                ->where('record_status', 'COMPLETED')
                ->selectRaw('
                    COUNT(*) AS total,
                    SUM(symptoms_present) AS symptomatic,
                    SUM(CASE WHEN gender="MALE"   THEN 1 ELSE 0 END) AS male,
                    SUM(CASE WHEN gender="FEMALE" THEN 1 ELSE 0 END) AS female,
                    SUM(referral_created) AS referrals,
                    SUM(CASE WHEN temperature_value IS NOT NULL THEN 1 ELSE 0 END) AS with_temp,
                    AVG(CASE WHEN temperature_value IS NOT NULL AND temperature_unit="C" THEN temperature_value
                             WHEN temperature_value IS NOT NULL AND temperature_unit="F" THEN (temperature_value-32)*5/9
                             ELSE NULL END) AS avg_temp_c,
                    MIN(captured_at) AS first_capture_at,
                    MAX(captured_at) AS last_capture_at
                ')->first();

            // ── Query 3: Yesterday (for delta) ────────────────────────────
            $yesterdayTotal = $this->base($user, $assignment, $poeFilter)
                ->whereDate('captured_at', $yesterday)
                ->where('record_status', 'COMPLETED')
                ->count();

            // ── Query 4: This week ────────────────────────────────────────
            $weekRow = $this->base($user, $assignment, $poeFilter)
                ->where('captured_at', '>=', $weekStart . ' 00:00:00')
                ->where('record_status', 'COMPLETED')
                ->selectRaw('
                    COUNT(*) AS total,
                    SUM(symptoms_present) AS symptomatic,
                    SUM(referral_created) AS referrals
                ')->first();

            // ── Query 5: This month ───────────────────────────────────────
            $monthRow = $this->base($user, $assignment, $poeFilter)
                ->where('captured_at', '>=', $monthStart . ' 00:00:00')
                ->where('record_status', 'COMPLETED')
                ->selectRaw('COUNT(*) AS total, SUM(symptoms_present) AS symptomatic')
                ->first();

            // ── Query 6: Referral queue health ────────────────────────────
            $queueBase = DB::table('notifications as n')
                ->join('primary_screenings as ps', 'ps.id', '=', 'n.primary_screening_id')
                ->whereNull('n.deleted_at')
                ->whereNull('ps.deleted_at')
                ->where('n.notification_type', 'SECONDARY_REFERRAL');
            $this->applyScope($queueBase, $user, $assignment, 'ps');
            if ($poeFilter) {
                $queueBase->where('ps.poe_code', $poeFilter);
            }

            $queueStats = (clone $queueBase)
                ->selectRaw('
                    SUM(CASE WHEN n.status="OPEN"        THEN 1 ELSE 0 END) AS open_referrals,
                    SUM(CASE WHEN n.status="IN_PROGRESS" THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN n.status="CLOSED"      THEN 1 ELSE 0 END) AS closed,
                    SUM(CASE WHEN n.priority="CRITICAL"  AND n.status="OPEN" THEN 1 ELSE 0 END) AS critical_open,
                    SUM(CASE WHEN n.priority="HIGH"      AND n.status="OPEN" THEN 1 ELSE 0 END) AS high_open,
                    MIN(CASE WHEN n.status="OPEN" THEN n.created_at ELSE NULL END) AS oldest_open_referral
                ')->first();

            // ── Query 7: Alerts ───────────────────────────────────────────
            $alertBase = DB::table('alerts as a')
                ->join('secondary_screenings as ss', 'ss.id', '=', 'a.secondary_screening_id')
                ->whereNull('a.deleted_at')
                ->whereNull('ss.deleted_at');
            $this->applyScope($alertBase, $user, $assignment, 'ss');
            if ($poeFilter) {
                $alertBase->where('ss.poe_code', $poeFilter);
            }

            $alertStats = (clone $alertBase)
                ->selectRaw('
                    SUM(CASE WHEN a.status="OPEN"         THEN 1 ELSE 0 END) AS open_alerts,
                    SUM(CASE WHEN a.status="ACKNOWLEDGED" THEN 1 ELSE 0 END) AS acknowledged_alerts,
                    SUM(CASE WHEN a.status="OPEN" AND a.risk_level="CRITICAL" THEN 1 ELSE 0 END) AS critical_alerts,
                    SUM(CASE WHEN a.status="OPEN" AND a.risk_level="HIGH"     THEN 1 ELSE 0 END) AS high_alerts
                ')->first();

            // ── Query 8: Fever metrics today ──────────────────────────────
            $feverToday = $this->base($user, $assignment, $poeFilter)
                ->whereDate('captured_at', $today)
                ->where('record_status', 'COMPLETED')
                ->whereNotNull('temperature_value')
                ->selectRaw('
                    COUNT(*) AS count_with_temp,
                    SUM(CASE WHEN (temperature_unit="C" AND temperature_value >= ?)
                              OR  (temperature_unit="F" AND temperature_value >= ?) THEN 1 ELSE 0 END) AS fever_count,
                    SUM(CASE WHEN (temperature_unit="C" AND temperature_value >= ?)
                              OR  (temperature_unit="F" AND temperature_value >= ?) THEN 1 ELSE 0 END) AS high_fever_count
                ', [
                    self::FEVER_C, $this->toF(self::FEVER_C),
                    self::HIGH_FEVER_C, $this->toF(self::HIGH_FEVER_C),
                ])->first();

            // ── Compute derived metrics ───────────────────────────────────
            $todayTotal        = (int) ($todayRow->total ?? 0);
            $todaySymptomatic  = (int) ($todayRow->symptomatic ?? 0);
            $todayAsymptomatic = $todayTotal - $todaySymptomatic;
            $symptomaticRate   = $todayTotal > 0
                ? round($todaySymptomatic / $todayTotal * 100, 1) : 0.0;

            $completedAllTime       = (int) ($allTime->total_completed ?? 0);
            $symptomaticAllTime     = (int) ($allTime->total_symptomatic ?? 0);
            $symptomaticRateAllTime = $completedAllTime > 0
                ? round($symptomaticAllTime / $completedAllTime * 100, 1) : 0.0;

            $oldestOpenReferralMinutes = null;
            if ($queueStats->oldest_open_referral) {
                $oldestOpenReferralMinutes = (int) round(
                    (time() - strtotime($queueStats->oldest_open_referral)) / 60
                );
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'generated_at'   => now()->toISOString(),
                    'scope'          => $this->scopeSummary($user, $assignment),

                    'today'          => [
                        'date'             => $today,
                        'total'            => $todayTotal,
                        'symptomatic'      => $todaySymptomatic,
                        'asymptomatic'     => $todayAsymptomatic,
                        'symptomatic_rate' => $symptomaticRate,
                        'referrals'        => (int) ($todayRow->referrals ?? 0),
                        'male'             => (int) ($todayRow->male ?? 0),
                        'female'           => (int) ($todayRow->female ?? 0),
                        'with_temp'        => (int) ($todayRow->with_temp ?? 0),
                        'avg_temp_c'       => $todayRow->avg_temp_c !== null ? round((float) $todayRow->avg_temp_c, 2) : null,
                        'fever_count'      => (int) ($feverToday->fever_count ?? 0),
                        'high_fever_count' => (int) ($feverToday->high_fever_count ?? 0),
                        'first_capture_at' => $todayRow->first_capture_at,
                        'last_capture_at'  => $todayRow->last_capture_at,
                        'vs_yesterday'     => $todayTotal - (int) $yesterdayTotal,
                        'yesterday_total'  => (int) $yesterdayTotal,
                    ],

                    'this_week'      => [
                        'week_start'  => $weekStart,
                        'total'       => (int) ($weekRow->total ?? 0),
                        'symptomatic' => (int) ($weekRow->symptomatic ?? 0),
                        'referrals'   => (int) ($weekRow->referrals ?? 0),
                    ],

                    'this_month'     => [
                        'month_start' => $monthStart,
                        'total'       => (int) ($monthRow->total ?? 0),
                        'symptomatic' => (int) ($monthRow->symptomatic ?? 0),
                    ],

                    'all_time'       => [
                        'total'            => (int) ($allTime->total_all_time ?? 0),
                        'completed'        => (int) ($allTime->total_completed ?? 0),
                        'voided'           => (int) ($allTime->total_voided ?? 0),
                        'symptomatic'      => $symptomaticAllTime,
                        'asymptomatic'     => (int) ($allTime->total_asymptomatic ?? 0),
                        'symptomatic_rate' => $symptomaticRateAllTime,
                        'referrals'        => (int) ($allTime->total_referrals ?? 0),
                        'male'             => (int) ($allTime->total_male ?? 0),
                        'female'           => (int) ($allTime->total_female ?? 0),
                        'other'            => (int) ($allTime->total_other ?? 0),
                        'unknown'          => (int) ($allTime->total_unknown ?? 0),
                        'last_capture_at'  => $allTime->last_capture_at,
                        'unsynced'         => (int) ($allTime->total_unsynced ?? 0),
                        'sync_failed'      => (int) ($allTime->total_sync_failed ?? 0),
                    ],

                    'referral_queue' => [
                        'open'                => (int) ($queueStats->open_referrals ?? 0),
                        'in_progress'         => (int) ($queueStats->in_progress ?? 0),
                        'closed_total'        => (int) ($queueStats->closed ?? 0),
                        'critical_open'       => (int) ($queueStats->critical_open ?? 0),
                        'high_open'           => (int) ($queueStats->high_open ?? 0),
                        'oldest_open_minutes' => $oldestOpenReferralMinutes,
                        'queue_critical'      => ($queueStats->critical_open ?? 0) > 0,
                    ],

                    'alerts'         => [
                        'open'          => (int) ($alertStats->open_alerts ?? 0),
                        'acknowledged'  => (int) ($alertStats->acknowledged_alerts ?? 0),
                        'critical_open' => (int) ($alertStats->critical_alerts ?? 0),
                        'high_open'     => (int) ($alertStats->high_alerts ?? 0),
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'dashboard/summary');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /dashboard/trend
    // Daily time-series with 7-day rolling average. Zero-fills gaps.
    // ═════════════════════════════════════════════════════════════════════

    public function trend(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        $poeFilter   = $this->poeSubFilter($request, $user);
        [$from, $to] = $this->dateWindow($request, 30);

        try {
            $rows = $this->base($user, $assignment, $poeFilter)
                ->where('record_status', 'COMPLETED')
                ->whereBetween('captured_at', [$from, $to])
                ->selectRaw('
                    DATE(captured_at)           AS date,
                    COUNT(*)                    AS total,
                    SUM(symptoms_present)       AS symptomatic,
                    SUM(1-symptoms_present)     AS asymptomatic,
                    SUM(referral_created)       AS referrals,
                    SUM(CASE WHEN gender="MALE"   THEN 1 ELSE 0 END) AS male,
                    SUM(CASE WHEN gender="FEMALE" THEN 1 ELSE 0 END) AS female,
                    SUM(CASE WHEN temperature_value IS NOT NULL THEN 1 ELSE 0 END) AS with_temp,
                    AVG(CASE WHEN temperature_unit="C" THEN temperature_value
                             WHEN temperature_unit="F" THEN (temperature_value-32)*5/9
                             ELSE NULL END) AS avg_temp_c
                ')
                ->groupByRaw('DATE(captured_at)')
                ->orderByRaw('date')
                ->get()
                ->keyBy('date');

            // Build day-by-day series with zero-fill
            $start   = new \DateTime($from);
            $end     = new \DateTime($to);
            $series  = [];
            $current = clone $start;

            while ($current <= $end) {
                $date     = $current->format('Y-m-d');
                $row      = $rows[$date] ?? null;
                $series[] = [
                    'date'         => $date,
                    'total'        => (int) ($row?->total ?? 0),
                    'symptomatic'  => (int) ($row?->symptomatic ?? 0),
                    'asymptomatic' => (int) ($row?->asymptomatic ?? 0),
                    'referrals'    => (int) ($row?->referrals ?? 0),
                    'male'         => (int) ($row?->male ?? 0),
                    'female'       => (int) ($row?->female ?? 0),
                    'with_temp'    => (int) ($row?->with_temp ?? 0),
                    'avg_temp_c'   => $row?->avg_temp_c !== null ? round((float) $row->avg_temp_c, 2) : null,
                ];
                $current->modify('+1 day');
            }

            // Compute 7-day rolling average (centred on each day)
            $count = count($series);
            foreach ($series as $i => &$day) {
                $windowStart = max(0, $i - 6);
                $window      = array_slice($series, $windowStart, $i - $windowStart + 1);
                $day['avg7'] = count($window) > 0
                    ? round(array_sum(array_column($window, 'total')) / count($window), 1)
                    : 0.0;
                $day['symptomaticPct'] = $day['total'] > 0
                    ? round($day['symptomatic'] / $day['total'] * 100, 1) : 0.0;
            }
            unset($day);

            return response()->json([
                'success' => true,
                'data'    => [
                    'date_from'    => $from,
                    'date_to'      => $to,
                    'days'         => $count,
                    'total_window' => array_sum(array_column($series, 'total')),
                    'peak_day'     => $this->peakDay($series),
                    'series'       => $series,
                    'scope'        => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'dashboard/trend');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /dashboard/heatmap
    // Volume distribution across time buckets.
    // group_by: hour | weekday | day | month
    // ═════════════════════════════════════════════════════════════════════

    public function heatmap(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        $poeFilter = $this->poeSubFilter($request, $user);
        $groupBy   = strtolower((string) $request->query('group_by', 'hour'));
        if (! in_array($groupBy, self::VALID_HEATMAP, true)) {
            return $this->err(422, 'group_by must be one of: ' . implode(', ', self::VALID_HEATMAP));
        }

        [$from, $to] = $this->dateWindow($request, 30);

        try {
            $base = $this->base($user, $assignment, $poeFilter)
                ->where('record_status', 'COMPLETED')
                ->whereBetween('captured_at', [$from, $to]);

            if ($groupBy === 'hour') {
                $rows = (clone $base)
                    ->selectRaw('HOUR(captured_at) AS bucket, COUNT(*) AS total, SUM(symptoms_present) AS symptomatic')
                    ->groupByRaw('HOUR(captured_at)')
                    ->orderByRaw('bucket')
                    ->get()
                    ->keyBy('bucket');

                $data = collect(range(0, 23))->map(fn($h) => [
                    'bucket'      => $h,
                    'label'       => sprintf('%02d:00', $h),
                    'total'       => (int) ($rows[$h]->total ?? 0),
                    'symptomatic' => (int) ($rows[$h]->symptomatic ?? 0),
                ]);

            } elseif ($groupBy === 'weekday') {
                // DAYOFWEEK: 1=Sun … 7=Sat
                $rows = (clone $base)
                    ->selectRaw('
                        DAYOFWEEK(captured_at) AS dow,
                        COUNT(*) AS total,
                        SUM(symptoms_present) AS symptomatic,
                        COUNT(DISTINCT DATE(captured_at)) AS day_count
                    ')
                    ->groupByRaw('DAYOFWEEK(captured_at)')
                    ->orderByRaw('dow')
                    ->get()
                    ->keyBy('dow');

                $names = [1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat'];
                $data  = collect(range(1, 7))->map(fn($d) => [
                    'bucket'      => $d,
                    'label'       => $names[$d],
                    'total'       => (int) ($rows[$d]->total ?? 0),
                    'symptomatic' => (int) ($rows[$d]->symptomatic ?? 0),
                    'avg_per_day' => ($rows[$d]->day_count ?? 0) > 0
                        ? round($rows[$d]->total / $rows[$d]->day_count, 1) : 0.0,
                ]);

            } elseif ($groupBy === 'month') {
                $rows = (clone $base)
                    ->selectRaw('MONTH(captured_at) AS bucket, COUNT(*) AS total, SUM(symptoms_present) AS symptomatic')
                    ->groupByRaw('MONTH(captured_at)')
                    ->orderByRaw('bucket')
                    ->get()
                    ->keyBy('bucket');

                $months = [1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4  => 'Apr', 5  => 'May', 6  => 'Jun',
                    7            => 'Jul', 8 => 'Aug', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dec'];
                $data = collect(range(1, 12))->map(fn($m) => [
                    'bucket'      => $m,
                    'label'       => $months[$m],
                    'total'       => (int) ($rows[$m]->total ?? 0),
                    'symptomatic' => (int) ($rows[$m]->symptomatic ?? 0),
                ]);

            } else {
                // day — calendar grid (one row per date)
                $rows = (clone $base)
                    ->selectRaw('DATE(captured_at) AS bucket, COUNT(*) AS total, SUM(symptoms_present) AS symptomatic, SUM(referral_created) AS referrals')
                    ->groupByRaw('DATE(captured_at)')
                    ->orderByRaw('bucket')
                    ->get();

                $data = $rows->map(fn($r) => [
                    'bucket'      => (string) $r->bucket,
                    'label'       => (string) $r->bucket,
                    'total'       => (int) $r->total,
                    'symptomatic' => (int) $r->symptomatic,
                    'referrals'   => (int) $r->referrals,
                ])->values();
            }

            // Intensity scale for heatmap rendering (0–1, relative to max)
            $maxTotal = $data->max('total') ?: 1;
            $data     = $data->map(fn($d) => array_merge($d, [
                'intensity' => round($d['total'] / $maxTotal, 3),
            ]));

            return response()->json([
                'success' => true,
                'data'    => [
                    'group_by'  => $groupBy,
                    'date_from' => $from,
                    'date_to'   => $to,
                    'max_total' => (int) $maxTotal,
                    'buckets'   => $data->values(),
                    'scope'     => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'dashboard/heatmap');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /dashboard/funnel
    // Referral & secondary screening case funnel with conversion rates.
    // Shows where referrals drop off and cases stall.
    // ═════════════════════════════════════════════════════════════════════

    public function funnel(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        $poeFilter   = $this->poeSubFilter($request, $user);
        [$from, $to] = $this->dateWindow($request, 30);

        try {
            // Stage 1: Total primary screenings (COMPLETED)
            $totalCompleted = $this->base($user, $assignment, $poeFilter)
                ->where('record_status', 'COMPLETED')
                ->whereBetween('captured_at', [$from, $to])
                ->count();

            // Stage 2: Symptomatic
            $totalSymptomatic = $this->base($user, $assignment, $poeFilter)
                ->where('record_status', 'COMPLETED')
                ->where('symptoms_present', 1)
                ->whereBetween('captured_at', [$from, $to])
                ->count();

            // Stage 3: Referrals created
            $totalReferrals = $this->base($user, $assignment, $poeFilter)
                ->where('record_status', 'COMPLETED')
                ->where('symptoms_present', 1)
                ->where('referral_created', 1)
                ->whereBetween('captured_at', [$from, $to])
                ->count();

            // Stage 4: Referrals picked up (notification IN_PROGRESS or CLOSED)
            $notifBase = DB::table('notifications as n')
                ->join('primary_screenings as ps', 'ps.id', '=', 'n.primary_screening_id')
                ->whereNull('n.deleted_at')
                ->whereNull('ps.deleted_at')
                ->where('ps.record_status', 'COMPLETED')
                ->whereBetween('ps.captured_at', [$from, $to]);
            $this->applyScope($notifBase, $user, $assignment, 'ps');
            if ($poeFilter) {
                $notifBase->where('ps.poe_code', $poeFilter);
            }

            $notifStats = (clone $notifBase)
                ->selectRaw('
                    SUM(CASE WHEN n.status="OPEN"        THEN 1 ELSE 0 END) AS notif_open,
                    SUM(CASE WHEN n.status="IN_PROGRESS" THEN 1 ELSE 0 END) AS notif_in_progress,
                    SUM(CASE WHEN n.status="CLOSED"      THEN 1 ELSE 0 END) AS notif_closed,
                    SUM(CASE WHEN n.priority="CRITICAL"  THEN 1 ELSE 0 END) AS priority_critical,
                    SUM(CASE WHEN n.priority="HIGH"      THEN 1 ELSE 0 END) AS priority_high,
                    SUM(CASE WHEN n.priority="NORMAL"    THEN 1 ELSE 0 END) AS priority_normal,
                    AVG(CASE WHEN n.status != "OPEN" AND n.opened_at IS NOT NULL
                             THEN TIMESTAMPDIFF(MINUTE, n.created_at, n.opened_at) ELSE NULL END) AS avg_pickup_minutes
                ')->first();

            $referralsPickedUp = ((int) ($notifStats->notif_in_progress ?? 0)) + ((int) ($notifStats->notif_closed ?? 0));

            // Stage 5: Secondary cases opened
            $secBase = DB::table('secondary_screenings as ss')
                ->join('primary_screenings as ps', 'ps.id', '=', 'ss.primary_screening_id')
                ->whereNull('ss.deleted_at')
                ->whereNull('ps.deleted_at')
                ->whereBetween('ps.captured_at', [$from, $to]);
            $this->applyScope($secBase, $user, $assignment, 'ps');
            if ($poeFilter) {
                $secBase->where('ps.poe_code', $poeFilter);
            }

            $secStats = (clone $secBase)
                ->selectRaw('
                    COUNT(*) AS total_cases,
                    SUM(CASE WHEN ss.case_status="OPEN"          THEN 1 ELSE 0 END) AS open,
                    SUM(CASE WHEN ss.case_status="IN_PROGRESS"   THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN ss.case_status="DISPOSITIONED" THEN 1 ELSE 0 END) AS dispositioned,
                    SUM(CASE WHEN ss.case_status="CLOSED"        THEN 1 ELSE 0 END) AS closed,
                    SUM(CASE WHEN ss.risk_level="CRITICAL"       THEN 1 ELSE 0 END) AS risk_critical,
                    SUM(CASE WHEN ss.risk_level="HIGH"           THEN 1 ELSE 0 END) AS risk_high,
                    SUM(CASE WHEN ss.final_disposition="RELEASED"    THEN 1 ELSE 0 END) AS released,
                    SUM(CASE WHEN ss.final_disposition="QUARANTINED" THEN 1 ELSE 0 END) AS quarantined,
                    SUM(CASE WHEN ss.final_disposition="ISOLATED"    THEN 1 ELSE 0 END) AS isolated,
                    SUM(CASE WHEN ss.final_disposition="REFERRED"    THEN 1 ELSE 0 END) AS referred,
                    AVG(CASE WHEN ss.closed_at IS NOT NULL
                             THEN TIMESTAMPDIFF(MINUTE, ss.opened_at, ss.closed_at) ELSE NULL END) AS avg_case_duration_minutes
                ')->first();

            // Conversion rates at each funnel stage
            $totalCases = (int) ($secStats->total_cases ?? 0);

            $funnel = [
                ['stage' => 'Screened (Completed)', 'count' => $totalCompleted, 'rate' => 100.0, 'description' => 'Completed primary screenings in window'],
                ['stage' => 'Symptomatic', 'count' => $totalSymptomatic, 'rate' => $this->pct($totalSymptomatic, $totalCompleted), 'description' => 'Officers assessed symptoms present'],
                ['stage' => 'Referral Created', 'count' => $totalReferrals, 'rate' => $this->pct($totalReferrals, $totalSymptomatic), 'description' => 'Secondary referral notification issued'],
                ['stage' => 'Referral Picked Up', 'count' => $referralsPickedUp, 'rate' => $this->pct($referralsPickedUp, $totalReferrals), 'description' => 'Secondary officer opened the case'],
                ['stage' => 'Case Closed', 'count' => (int) ($secStats->closed ?? 0), 'rate' => $this->pct((int) ($secStats->closed ?? 0), $totalCases), 'description' => 'Secondary case closed with disposition'],
            ];

            return response()->json([
                'success' => true,
                'data'    => [
                    'date_from'       => $from,
                    'date_to'         => $to,
                    'funnel'          => $funnel,
                    'notifications'   => [
                        'open'               => (int) ($notifStats->notif_open ?? 0),
                        'in_progress'        => (int) ($notifStats->notif_in_progress ?? 0),
                        'closed'             => (int) ($notifStats->notif_closed ?? 0),
                        'priority_critical'  => (int) ($notifStats->priority_critical ?? 0),
                        'priority_high'      => (int) ($notifStats->priority_high ?? 0),
                        'priority_normal'    => (int) ($notifStats->priority_normal ?? 0),
                        'avg_pickup_minutes' => $notifStats->avg_pickup_minutes !== null
                            ? round((float) $notifStats->avg_pickup_minutes) : null,
                    ],
                    'secondary_cases' => [
                        'total'                     => $totalCases,
                        'open'                      => (int) ($secStats->open ?? 0),
                        'in_progress'               => (int) ($secStats->in_progress ?? 0),
                        'dispositioned'             => (int) ($secStats->dispositioned ?? 0),
                        'closed'                    => (int) ($secStats->closed ?? 0),
                        'risk_critical'             => (int) ($secStats->risk_critical ?? 0),
                        'risk_high'                 => (int) ($secStats->risk_high ?? 0),
                        'released'                  => (int) ($secStats->released ?? 0),
                        'quarantined'               => (int) ($secStats->quarantined ?? 0),
                        'isolated'                  => (int) ($secStats->isolated ?? 0),
                        'referred'                  => (int) ($secStats->referred ?? 0),
                        'avg_case_duration_minutes' => $secStats->avg_case_duration_minutes !== null
                            ? (int) round((float) $secStats->avg_case_duration_minutes) : null,
                    ],
                    'scope'           => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'dashboard/funnel');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /dashboard/epi
    // Epidemiological indicators: temperature, fever, gender, syndrome.
    // ═════════════════════════════════════════════════════════════════════

    public function epi(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        $poeFilter   = $this->poeSubFilter($request, $user);
        [$from, $to] = $this->dateWindow($request, 30);

        try {
            $base = $this->base($user, $assignment, $poeFilter)
                ->where('record_status', 'COMPLETED')
                ->whereBetween('captured_at', [$from, $to]);

            // Temperature distribution
            $tempStats = (clone $base)
                ->whereNotNull('temperature_value')
                ->selectRaw('
                    COUNT(*) AS count_with_temp,
                    AVG(CASE WHEN temperature_unit="C" THEN temperature_value
                             WHEN temperature_unit="F" THEN (temperature_value-32)*5/9 END) AS avg_c,
                    MIN(CASE WHEN temperature_unit="C" THEN temperature_value
                             WHEN temperature_unit="F" THEN (temperature_value-32)*5/9 END) AS min_c,
                    MAX(CASE WHEN temperature_unit="C" THEN temperature_value
                             WHEN temperature_unit="F" THEN (temperature_value-32)*5/9 END) AS max_c,
                    SUM(CASE WHEN (temperature_unit="C" AND temperature_value < 36.0)
                              OR  (temperature_unit="F" AND temperature_value < 96.8) THEN 1 ELSE 0 END) AS hypothermia,
                    SUM(CASE WHEN (temperature_unit="C" AND temperature_value BETWEEN 36.0 AND 37.4)
                              OR  (temperature_unit="F" AND temperature_value BETWEEN 96.8 AND 99.3) THEN 1 ELSE 0 END) AS normal_range,
                    SUM(CASE WHEN (temperature_unit="C" AND temperature_value BETWEEN 37.5 AND 38.4)
                              OR  (temperature_unit="F" AND temperature_value BETWEEN 99.5 AND 101.1) THEN 1 ELSE 0 END) AS low_grade_fever,
                    SUM(CASE WHEN (temperature_unit="C" AND temperature_value >= 38.5)
                              OR  (temperature_unit="F" AND temperature_value >= 101.3) THEN 1 ELSE 0 END) AS high_fever
                ')->first();

            // Gender × symptomatic cross-tab
            $genderEpi = (clone $base)
                ->selectRaw('
                    gender,
                    COUNT(*) AS total,
                    SUM(symptoms_present) AS symptomatic,
                    SUM(CASE WHEN (temperature_unit="C" AND temperature_value >= ?)
                              OR  (temperature_unit="F" AND temperature_value >= ?) THEN 1 ELSE 0 END) AS fever_count
                ', [self::FEVER_C, $this->toF(self::FEVER_C)])
                ->groupBy('gender')
                ->orderBy('gender')
                ->get()
                ->map(fn($r) => [
                    'gender'      => $r->gender,
                    'total'       => (int) $r->total,
                    'symptomatic' => (int) $r->symptomatic,
                    'fever_count' => (int) $r->fever_count,
                    'symp_rate'   => (int) $r->total > 0 ? round($r->symptomatic / $r->total * 100, 1) : 0.0,
                ])->values();

            // Symptomatic rate by day of week (identify high-risk travel days)
            $sympByDow = (clone $base)
                ->selectRaw('
                    DAYOFWEEK(captured_at) AS dow,
                    COUNT(*) AS total,
                    SUM(symptoms_present) AS symptomatic
                ')
                ->groupByRaw('DAYOFWEEK(captured_at)')
                ->orderByRaw('dow')
                ->get()
                ->keyBy('dow');

            $dowNames     = [1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat'];
            $sympByDowOut = collect(range(1, 7))->map(fn($d) => [
                'dow'         => $d,
                'label'       => $dowNames[$d],
                'total'       => (int) ($sympByDow[$d]->total ?? 0),
                'symptomatic' => (int) ($sympByDow[$d]->symptomatic ?? 0),
                'symp_rate'   => ($sympByDow[$d]->total ?? 0) > 0
                    ? round($sympByDow[$d]->symptomatic / $sympByDow[$d]->total * 100, 1) : 0.0,
            ]);

            // Syndrome classification from linked secondary cases (top syndromes)
            $syndromeBase = DB::table('secondary_screenings as ss')
                ->join('primary_screenings as ps', 'ps.id', '=', 'ss.primary_screening_id')
                ->whereNull('ss.deleted_at')
                ->whereNull('ps.deleted_at')
                ->whereNotNull('ss.syndrome_classification')
                ->whereBetween('ps.captured_at', [$from, $to]);
            $this->applyScope($syndromeBase, $user, $assignment, 'ps');
            if ($poeFilter) {
                $syndromeBase->where('ps.poe_code', $poeFilter);
            }

            $syndromes = (clone $syndromeBase)
                ->selectRaw('ss.syndrome_classification AS syndrome, COUNT(*) AS cnt, SUM(CASE WHEN ss.risk_level IN ("HIGH","CRITICAL") THEN 1 ELSE 0 END) AS high_risk')
                ->groupBy('ss.syndrome_classification')
                ->orderByDesc('cnt')
                ->limit(10)
                ->get()
                ->map(fn($r) => [
                    'syndrome'  => $r->syndrome,
                    'count'     => (int) $r->cnt,
                    'high_risk' => (int) $r->high_risk,
                ])->values();

            // Platform breakdown (mobile vs web, iOS vs Android)
            $platforms = (clone $base)
                ->selectRaw('platform, COUNT(*) AS cnt')
                ->groupBy('platform')
                ->orderByDesc('cnt')
                ->get()
                ->map(fn($r) => ['platform' => $r->platform, 'count' => (int) $r->cnt])
                ->values();

            return response()->json([
                'success' => true,
                'data'    => [
                    'date_from'       => $from,
                    'date_to'         => $to,
                    'temperature'     => [
                        'count_with_temp' => (int) ($tempStats->count_with_temp ?? 0),
                        'avg_c'           => $tempStats->avg_c !== null ? round((float) $tempStats->avg_c, 2) : null,
                        'min_c'           => $tempStats->min_c !== null ? round((float) $tempStats->min_c, 2) : null,
                        'max_c'           => $tempStats->max_c !== null ? round((float) $tempStats->max_c, 2) : null,
                        'bands'           => [
                            'hypothermia'     => (int) ($tempStats->hypothermia ?? 0),
                            'normal'          => (int) ($tempStats->normal_range ?? 0),
                            'low_grade_fever' => (int) ($tempStats->low_grade_fever ?? 0),
                            'high_fever'      => (int) ($tempStats->high_fever ?? 0),
                        ],
                    ],
                    'by_gender'       => $genderEpi,
                    'symp_by_weekday' => $sympByDowOut,
                    'syndromes'       => $syndromes,
                    'by_platform'     => $platforms,
                    'scope'           => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'dashboard/epi');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /dashboard/poe-comparison
    // Side-by-side metrics for all POEs in the user's scope.
    // POE-level users receive 403 — comparison requires district+ scope.
    // ═════════════════════════════════════════════════════════════════════

    public function poeComparison(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        if (in_array($user->role_key, self::POE_LEVEL_ROLES, true)) {
            return $this->err(403, 'POE comparison requires district, PHEOC, or national scope.', [
                'your_role' => $user->role_key,
                'hint'      => 'This endpoint is for supervisory roles that can see multiple POEs.',
            ]);
        }

        [$from, $to] = $this->dateWindow($request, 30);

        try {
            $q = DB::table('primary_screenings as ps')
                ->whereNull('ps.deleted_at')
                ->where('ps.record_status', 'COMPLETED')
                ->whereBetween('ps.captured_at', [$from, $to]);
            $this->applyScope($q, $user, $assignment);

            $rows = $q->selectRaw('
                ps.poe_code,
                COUNT(*) AS total,
                SUM(ps.symptoms_present) AS symptomatic,
                SUM(ps.referral_created) AS referrals,
                SUM(CASE WHEN ps.sync_status="UNSYNCED" THEN 1 ELSE 0 END) AS unsynced,
                SUM(CASE WHEN ps.sync_status="FAILED"   THEN 1 ELSE 0 END) AS sync_failed,
                SUM(CASE WHEN ps.temperature_value IS NOT NULL THEN 1 ELSE 0 END) AS with_temp,
                AVG(CASE WHEN ps.temperature_unit="C" THEN ps.temperature_value
                         WHEN ps.temperature_unit="F" THEN (ps.temperature_value-32)*5/9 END) AS avg_temp_c,
                COUNT(DISTINCT ps.device_id) AS device_count,
                COUNT(DISTINCT ps.captured_by_user_id) AS screener_count,
                MAX(ps.captured_at) AS last_capture_at
            ')->groupBy('ps.poe_code')
                ->orderByDesc('total')
                ->get();

            $poes = $rows->map(function ($r) {
                $symp_rate = (int) $r->total > 0 ? round((float) $r->symptomatic / (int) $r->total * 100, 1) : 0.0;
                $ref_rate  = (int) $r->symptomatic > 0 ? round((float) $r->referrals / (int) $r->symptomatic * 100, 1) : 0.0;
                return [
                    'poe_code'         => $r->poe_code,
                    'total'            => (int) $r->total,
                    'symptomatic'      => (int) $r->symptomatic,
                    'referrals'        => (int) $r->referrals,
                    'symptomatic_rate' => $symp_rate,
                    'referral_rate'    => $ref_rate,
                    'unsynced'         => (int) $r->unsynced,
                    'sync_failed'      => (int) $r->sync_failed,
                    'sync_health_pct'  => (int) $r->total > 0
                        ? round((1 - (($r->unsynced + $r->sync_failed) / $r->total)) * 100, 1) : 100.0,
                    'with_temp'        => (int) $r->with_temp,
                    'avg_temp_c'       => $r->avg_temp_c !== null ? round((float) $r->avg_temp_c, 2) : null,
                    'device_count'     => (int) $r->device_count,
                    'screener_count'   => (int) $r->screener_count,
                    'last_capture_at'  => $r->last_capture_at,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data'    => [
                    'date_from' => $from,
                    'date_to'   => $to,
                    'poe_count' => $poes->count(),
                    'poes'      => $poes,
                    'totals'    => [
                        'total'       => $poes->sum('total'),
                        'symptomatic' => $poes->sum('symptomatic'),
                        'referrals'   => $poes->sum('referrals'),
                        'unsynced'    => $poes->sum('unsynced'),
                    ],
                    'scope'     => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'dashboard/poe-comparison');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /dashboard/screener-report
    // Per-officer metrics over the specified date window.
    // ═════════════════════════════════════════════════════════════════════

    public function screenerReport(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        $poeFilter   = $this->poeSubFilter($request, $user);
        [$from, $to] = $this->dateWindow($request, 30);

        try {
            $days = max(1, (new \DateTime($from))->diff(new \DateTime($to))->days + 1);

            // Build explicitly with full table prefixes to avoid ambiguity after the
            // LEFT JOIN. Do NOT use $this->base() here — it adds whereNull('deleted_at')
            // without a table prefix which becomes ambiguous once users is joined.
            $q = DB::table('primary_screenings as ps')
                ->leftJoin('users as u', 'u.id', '=', 'ps.captured_by_user_id')
                ->whereNull('ps.deleted_at')
                ->where('ps.record_status', 'COMPLETED')
                ->whereBetween('ps.captured_at', [$from, $to]);

            // Apply geographic scope with explicit alias
            $this->applyScope($q, $user, $assignment, 'ps');
            if ($poeFilter) {
                $q->where('ps.poe_code', $poeFilter);
            }

            $rows = $q->selectRaw('
                    ps.captured_by_user_id                                    AS user_id,
                    u.full_name,
                    u.username,
                    u.role_key,
                    COUNT(*)                                                   AS total,
                    SUM(ps.symptoms_present)                                   AS symptomatic,
                    SUM(ps.referral_created)                                   AS referrals,
                    SUM(CASE WHEN ps.temperature_value IS NOT NULL THEN 1 ELSE 0 END) AS with_temp,
                    COUNT(DISTINCT ps.device_id)                               AS devices_used,
                    MAX(ps.captured_at)                                        AS last_active_at,
                    MIN(ps.captured_at)                                        AS first_active_at,
                    COUNT(DISTINCT DATE(ps.captured_at))                       AS active_days
                ')
                ->groupBy('ps.captured_by_user_id', 'u.full_name', 'u.username', 'u.role_key')
                ->orderByDesc('total')
                ->get();

            $screeners = $rows->map(fn($r) => [
                'user_id'            => $r->user_id,
                'full_name'          => $r->full_name ?? 'Unknown',
                'username'           => $r->username ?? '—',
                'role_key'           => $r->role_key ?? '—',
                'total'              => (int) $r->total,
                'symptomatic'        => (int) $r->symptomatic,
                'referrals'          => (int) $r->referrals,
                'with_temp'          => (int) $r->with_temp,
                'devices_used'       => (int) $r->devices_used,
                'active_days'        => (int) $r->active_days,
                'avg_per_active_day' => (int) $r->active_days > 0
                    ? round($r->total / $r->active_days, 1) : 0.0,
                'symptomatic_rate'   => (int) $r->total > 0 ? round($r->symptomatic / $r->total * 100, 1) : 0.0,
                'last_active_at'     => $r->last_active_at,
                'first_active_at'    => $r->first_active_at,
            ])->values();

            return response()->json([
                'success' => true,
                'data'    => [
                    'date_from'      => $from,
                    'date_to'        => $to,
                    'window_days'    => $days,
                    'screener_count' => $screeners->count(),
                    'screeners'      => $screeners,
                    'scope'          => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'dashboard/screener-report');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /dashboard/device-health
    // Sync health per device. Flags devices silent for >24h.
    // ═════════════════════════════════════════════════════════════════════

    public function deviceHealth(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        $poeFilter = $this->poeSubFilter($request, $user);

        try {
            $rows = $this->base($user, $assignment, $poeFilter)
                ->selectRaw('
                    device_id,
                    platform,
                    MAX(app_version) AS app_version,
                    COUNT(*) AS total_records,
                    SUM(CASE WHEN sync_status="SYNCED"   THEN 1 ELSE 0 END) AS synced,
                    SUM(CASE WHEN sync_status="UNSYNCED" THEN 1 ELSE 0 END) AS unsynced,
                    SUM(CASE WHEN sync_status="FAILED"   THEN 1 ELSE 0 END) AS failed,
                    MAX(captured_at) AS last_capture_at,
                    MAX(CASE WHEN sync_status="SYNCED" THEN synced_at END) AS last_synced_at,
                    MIN(CASE WHEN sync_status="UNSYNCED" THEN captured_at END) AS oldest_unsynced_at,
                    COUNT(DISTINCT captured_by_user_id) AS user_count
                ')
                ->groupBy('device_id', 'platform')
                ->orderByDesc('last_capture_at')
                ->get();

            $now     = time();
            $devices = $rows->map(function ($r) use ($now) {
                $unsyncedCount = (int) $r->unsynced;
                $lastCaptured  = $r->last_capture_at ? strtotime($r->last_capture_at) : 0;
                $hoursSilent   = $lastCaptured > 0 ? round(($now - $lastCaptured) / 3600, 1) : null;
                $syncHealthPct = (int) $r->total_records > 0
                    ? round($r->synced / $r->total_records * 100, 1) : 100.0;
                return [
                    'device_id'           => $r->device_id,
                    'platform'            => $r->platform,
                    'app_version'         => $r->app_version,
                    'total_records'       => (int) $r->total_records,
                    'synced'              => (int) $r->synced,
                    'unsynced'            => $unsyncedCount,
                    'failed'              => (int) $r->failed,
                    'sync_health_pct'     => $syncHealthPct,
                    'last_capture_at'     => $r->last_capture_at,
                    'last_synced_at'      => $r->last_synced_at,
                    'oldest_unsynced_at'  => $r->oldest_unsynced_at,
                    'hours_since_capture' => $hoursSilent,
                    'status'              => $this->deviceStatus($unsyncedCount, $hoursSilent),
                    'user_count'          => (int) $r->user_count,
                    'data_loss_risk'      => $unsyncedCount > 0 && $hoursSilent !== null && $hoursSilent > 24,
                ];
            })->values();

            $atRisk = $devices->where('data_loss_risk', true)->count();

            return response()->json([
                'success' => true,
                'data'    => [
                    'device_count'    => $devices->count(),
                    'devices_at_risk' => $atRisk,
                    'total_unsynced'  => $devices->sum('unsynced'),
                    'total_failed'    => $devices->sum('failed'),
                    'devices'         => $devices,
                    'scope'           => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'dashboard/device-health');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /dashboard/alerts-summary
    // Alert dashboard: open/acknowledged breakdown, routing, acknowledgement time.
    // ═════════════════════════════════════════════════════════════════════

    public function alertsSummary(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        $poeFilter   = $this->poeSubFilter($request, $user);
        [$from, $to] = $this->dateWindow($request, 30);

        try {
            $alertBase = DB::table('alerts as a')
                ->join('secondary_screenings as ss', 'ss.id', '=', 'a.secondary_screening_id')
                ->whereNull('a.deleted_at')
                ->whereNull('ss.deleted_at')
                ->whereBetween('a.created_at', [$from, $to]);
            $this->applyScope($alertBase, $user, $assignment, 'ss');
            if ($poeFilter) {
                $alertBase->where('ss.poe_code', $poeFilter);
            }

            // Aggregate stats
            $stats = (clone $alertBase)
                ->selectRaw('
                    COUNT(*) AS total_alerts,
                    SUM(CASE WHEN a.status="OPEN"         THEN 1 ELSE 0 END) AS open,
                    SUM(CASE WHEN a.status="ACKNOWLEDGED" THEN 1 ELSE 0 END) AS acknowledged,
                    SUM(CASE WHEN a.status="CLOSED"       THEN 1 ELSE 0 END) AS closed,
                    SUM(CASE WHEN a.risk_level="CRITICAL" THEN 1 ELSE 0 END) AS critical,
                    SUM(CASE WHEN a.risk_level="HIGH"     THEN 1 ELSE 0 END) AS high,
                    SUM(CASE WHEN a.generated_from="RULE_BASED" THEN 1 ELSE 0 END) AS rule_based,
                    SUM(CASE WHEN a.generated_from="OFFICER"    THEN 1 ELSE 0 END) AS officer_generated,
                    SUM(CASE WHEN a.routed_to_level="DISTRICT"  THEN 1 ELSE 0 END) AS routed_district,
                    SUM(CASE WHEN a.routed_to_level="PHEOC"     THEN 1 ELSE 0 END) AS routed_pheoc,
                    SUM(CASE WHEN a.routed_to_level="NATIONAL"  THEN 1 ELSE 0 END) AS routed_national,
                    AVG(CASE WHEN a.acknowledged_at IS NOT NULL
                             THEN TIMESTAMPDIFF(MINUTE, a.created_at, a.acknowledged_at) ELSE NULL END) AS avg_ack_minutes
                ')->first();

            // Recent open alerts (last 10)
            $recentOpen = (clone $alertBase)
                ->where('a.status', 'OPEN')
                ->select('a.id', 'a.alert_code', 'a.alert_title', 'a.risk_level', 'a.routed_to_level',
                    'a.created_at', 'ss.poe_code', 'ss.syndrome_classification', 'ss.risk_level AS case_risk')
                ->orderByRaw("FIELD(a.risk_level,'CRITICAL','HIGH','LOW') ASC")
                ->orderBy('a.created_at', 'asc')
                ->limit(10)
                ->get();

            // Alert code breakdown (top 5 alert types)
            $topCodes = (clone $alertBase)
                ->selectRaw('a.alert_code, COUNT(*) AS cnt')
                ->groupBy('a.alert_code')
                ->orderByDesc('cnt')
                ->limit(5)
                ->get()
                ->map(fn($r) => ['alert_code' => $r->alert_code, 'count' => (int) $r->cnt])
                ->values();

            return response()->json([
                'success' => true,
                'data'    => [
                    'date_from'       => $from,
                    'date_to'         => $to,
                    'totals'          => [
                        'total'           => (int) ($stats->total_alerts ?? 0),
                        'open'            => (int) ($stats->open ?? 0),
                        'acknowledged'    => (int) ($stats->acknowledged ?? 0),
                        'closed'          => (int) ($stats->closed ?? 0),
                        'critical'        => (int) ($stats->critical ?? 0),
                        'high'            => (int) ($stats->high ?? 0),
                        'rule_based'      => (int) ($stats->rule_based ?? 0),
                        'officer_raised'  => (int) ($stats->officer_generated ?? 0),
                        'routed_district' => (int) ($stats->routed_district ?? 0),
                        'routed_pheoc'    => (int) ($stats->routed_pheoc ?? 0),
                        'routed_national' => (int) ($stats->routed_national ?? 0),
                        'avg_ack_minutes' => $stats->avg_ack_minutes !== null
                            ? (int) round((float) $stats->avg_ack_minutes) : null,
                    ],
                    'recent_open'     => $recentOpen,
                    'top_alert_codes' => $topCodes,
                    'scope'           => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'dashboard/alerts-summary');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /dashboard/weekly-report
    // WHO/IHR compliant weekly summary. week_offset=0 = current week.
    // ═════════════════════════════════════════════════════════════════════

    public function weeklyReport(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        $poeFilter  = $this->poeSubFilter($request, $user);
        $weekOffset = max(0, min(52, (int) $request->query('week_offset', 0)));

        try {
            $weekStart = now()->startOfWeek()->subWeeks($weekOffset);
            $weekEnd   = (clone $weekStart)->endOfWeek();
            $from      = $weekStart->format('Y-m-d') . ' 00:00:00';
            $to        = $weekEnd->format('Y-m-d') . ' 23:59:59';
            $prevFrom  = $weekStart->copy()->subWeek()->format('Y-m-d') . ' 00:00:00';
            $prevTo    = $weekEnd->copy()->subWeek()->format('Y-m-d') . ' 23:59:59';

            // Current week
            $week = $this->base($user, $assignment, $poeFilter)
                ->where('record_status', 'COMPLETED')
                ->whereBetween('captured_at', [$from, $to])
                ->selectRaw('
                    COUNT(*) AS total,
                    SUM(symptoms_present) AS symptomatic,
                    SUM(referral_created) AS referrals,
                    SUM(CASE WHEN gender="MALE"    THEN 1 ELSE 0 END) AS male,
                    SUM(CASE WHEN gender="FEMALE"  THEN 1 ELSE 0 END) AS female,
                    SUM(CASE WHEN gender="OTHER"   THEN 1 ELSE 0 END) AS other,
                    SUM(CASE WHEN gender="UNKNOWN" THEN 1 ELSE 0 END) AS unknown,
                    SUM(CASE WHEN (temperature_unit="C" AND temperature_value >= ?)
                              OR  (temperature_unit="F" AND temperature_value >= ?) THEN 1 ELSE 0 END) AS fever_count,
                    SUM(CASE WHEN (temperature_unit="C" AND temperature_value >= ?)
                              OR  (temperature_unit="F" AND temperature_value >= ?) THEN 1 ELSE 0 END) AS high_fever_count,
                    COUNT(DISTINCT captured_by_user_id) AS screener_count,
                    COUNT(DISTINCT device_id) AS device_count
                ', [
                    self::FEVER_C, $this->toF(self::FEVER_C),
                    self::HIGH_FEVER_C, $this->toF(self::HIGH_FEVER_C),
                ])->first();

            // Previous week total for comparison
            $prevWeekTotal = $this->base($user, $assignment, $poeFilter)
                ->where('record_status', 'COMPLETED')
                ->whereBetween('captured_at', [$prevFrom, $prevTo])
                ->count();

            // Peak day this week
            $peakDay = $this->base($user, $assignment, $poeFilter)
                ->where('record_status', 'COMPLETED')
                ->whereBetween('captured_at', [$from, $to])
                ->selectRaw('DATE(captured_at) AS date, COUNT(*) AS cnt')
                ->groupByRaw('DATE(captured_at)')
                ->orderByDesc('cnt')
                ->first();

            // Secondary case outcomes this week
            $secOutcomes = DB::table('secondary_screenings as ss')
                ->join('primary_screenings as ps', 'ps.id', '=', 'ss.primary_screening_id')
                ->whereNull('ss.deleted_at')
                ->whereNull('ps.deleted_at')
                ->where('ps.record_status', 'COMPLETED')
                ->whereBetween('ps.captured_at', [$from, $to]);
            $this->applyScope($secOutcomes, $user, $assignment, 'ps');
            if ($poeFilter) {
                $secOutcomes->where('ps.poe_code', $poeFilter);
            }

            $secWeek = (clone $secOutcomes)
                ->selectRaw('
                    COUNT(*) AS cases,
                    SUM(CASE WHEN ss.case_status="CLOSED" THEN 1 ELSE 0 END) AS closed,
                    SUM(CASE WHEN ss.risk_level IN ("HIGH","CRITICAL") THEN 1 ELSE 0 END) AS high_risk,
                    SUM(CASE WHEN ss.final_disposition="RELEASED"    THEN 1 ELSE 0 END) AS released,
                    SUM(CASE WHEN ss.final_disposition="QUARANTINED" THEN 1 ELSE 0 END) AS quarantined,
                    SUM(CASE WHEN ss.final_disposition="ISOLATED"    THEN 1 ELSE 0 END) AS isolated
                ')->first();

            // Alerts this week
            $alertsWeek = DB::table('alerts as a')
                ->join('secondary_screenings as ss2', 'ss2.id', '=', 'a.secondary_screening_id')
                ->whereNull('a.deleted_at')
                ->whereNull('ss2.deleted_at')
                ->whereBetween('a.created_at', [$from, $to]);
            $this->applyScope($alertsWeek, $user, $assignment, 'ss2');
            if ($poeFilter) {
                $alertsWeek->where('ss2.poe_code', $poeFilter);
            }

            $alertCount = (clone $alertsWeek)->count();

            $total = (int) ($week->total ?? 0);
            $symp  = (int) ($week->symptomatic ?? 0);

            return response()->json([
                'success' => true,
                'data'    => [
                    'week_label'  => $weekStart->format('d M Y') . ' – ' . $weekEnd->format('d M Y'),
                    'week_start'  => $weekStart->format('Y-m-d'),
                    'week_end'    => $weekEnd->format('Y-m-d'),
                    'week_offset' => $weekOffset,
                    'report'      => [
                        'total_screened'      => $total,
                        'total_symptomatic'   => $symp,
                        'total_asymptomatic'  => $total - $symp,
                        'symptomatic_rate'    => $total > 0 ? round($symp / $total * 100, 1) : 0.0,
                        'total_referrals'     => (int) ($week->referrals ?? 0),
                        'male'                => (int) ($week->male ?? 0),
                        'female'              => (int) ($week->female ?? 0),
                        'other'               => (int) ($week->other ?? 0),
                        'unknown'             => (int) ($week->unknown ?? 0),
                        'fever_count'         => (int) ($week->fever_count ?? 0),
                        'high_fever_count'    => (int) ($week->high_fever_count ?? 0),
                        'screener_count'      => (int) ($week->screener_count ?? 0),
                        'device_count'        => (int) ($week->device_count ?? 0),
                        'avg_daily'           => $total > 0 ? round($total / 7, 1) : 0.0,
                        'peak_day'            => $peakDay ? ['date' => $peakDay->date, 'count' => (int) $peakDay->cnt] : null,
                        'vs_previous_week'    => $total - (int) $prevWeekTotal,
                        'previous_week_total' => (int) $prevWeekTotal,
                        'secondary_cases'     => (int) ($secWeek->cases ?? 0),
                        'cases_high_risk'     => (int) ($secWeek->high_risk ?? 0),
                        'cases_released'      => (int) ($secWeek->released ?? 0),
                        'cases_quarantined'   => (int) ($secWeek->quarantined ?? 0),
                        'cases_isolated'      => (int) ($secWeek->isolated ?? 0),
                        'alerts_raised'       => $alertCount,
                    ],
                    'scope'       => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'dashboard/weekly-report');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /dashboard/live
    // Lightweight today-only ticker. Single aggregation query.
    // Intended to be polled every 30 seconds by the UI sync ticker.
    // ═════════════════════════════════════════════════════════════════════

    public function live(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        $poeFilter = $this->poeSubFilter($request, $user);
        $today     = now()->format('Y-m-d');

        try {
            $row = $this->base($user, $assignment, $poeFilter)
                ->whereDate('captured_at', $today)
                ->where('record_status', 'COMPLETED')
                ->selectRaw('
                    COUNT(*) AS total,
                    SUM(symptoms_present) AS symptomatic,
                    SUM(referral_created) AS referrals,
                    SUM(CASE WHEN sync_status="UNSYNCED" THEN 1 ELSE 0 END) AS unsynced,
                    MAX(captured_at) AS last_capture_at
                ')->first();

            $openReferrals = DB::table('notifications as n')
                ->join('primary_screenings as ps', 'ps.id', '=', 'n.primary_screening_id')
                ->where('n.status', 'OPEN')
                ->whereNull('n.deleted_at')
                ->whereNull('ps.deleted_at');
            $this->applyScope($openReferrals, $user, $assignment, 'ps');
            if ($poeFilter) {
                $openReferrals->where('ps.poe_code', $poeFilter);
            }

            $openCount = $openReferrals->count();

            return response()->json([
                'success' => true,
                'data'    => [
                    'date'            => $today,
                    'total'           => (int) ($row->total ?? 0),
                    'symptomatic'     => (int) ($row->symptomatic ?? 0),
                    'referrals'       => (int) ($row->referrals ?? 0),
                    'open_referrals'  => $openCount,
                    'unsynced'        => (int) ($row->unsynced ?? 0),
                    'last_capture_at' => $row->last_capture_at,
                    'server_time'     => now()->toISOString(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'dashboard/live');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═════════════════════════════════════════════════════════════════════

    /** Resolve user + assignment. Returns [user, assignment, errorResponse|null]. */
    private function resolveContext(Request $request): array
    {
        $userId = (int) $request->query('user_id', $request->input('user_id', 0));
        if ($userId <= 0) {
            return [null, null, $this->err(422, 'user_id is required.', [
                'hint' => 'Append ?user_id={AUTH_DATA.id} to the URL.',
            ])];
        }
        $user = DB::table('users')->where('id', $userId)->first() ?: null;
        if (! $user) {
            return [null, null, $this->err(404, 'User not found.', ['user_id' => $userId])];
        }

        $assignment = DB::table('user_assignments')
            ->where('user_id', $userId)->where('is_primary', 1)->where('is_active', 1)
            ->where(fn($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
            ->first() ?: null;
        if (! $assignment) {
            return [null, null, $this->err(403, 'No active assignment.', ['user_id' => $userId])];
        }

        return [$user, $assignment, null];
    }

    /** Base query on primary_screenings with geographic scope applied. */
    private function base(object $user, object $assignment, ?string $poeFilter = null): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('primary_screenings')->whereNull('deleted_at');
        $this->applyScope($q, $user, $assignment);
        if ($poeFilter) {
            $q->where('poe_code', $poeFilter);
        }

        return $q;
    }

    /** Apply geographic scope. Alias defaults to bare table (no join prefix). */
    private function applyScope(object $q, object $user, object $assignment, string $alias = ''): void
    {
        $col  = fn($c) => $alias ? "{$alias}.{$c}" : $c;
        $role = $user->role_key ?? '';
        if (in_array($role, self::POE_LEVEL_ROLES, true)) {
            $q->where($col('poe_code'), $assignment->poe_code);
        } elseif ($role === 'DISTRICT_SUPERVISOR') {
            $q->where($col('district_code'), $assignment->district_code);
        } elseif ($role === 'PHEOC_OFFICER' || $role === 'PHEOC_ADMIN') {
            $scopeCode = $assignment->province_code ?? $assignment->pheoc_code ?? null;
            $q->where($col('country_code'), $assignment->country_code);
            if ($scopeCode !== null) {
                $q->where(function ($qq) use ($col, $scopeCode) {
                    $qq->where($col('province_code'), $scopeCode)
                       ->orWhere($col('pheoc_code'), $scopeCode);
                });
            }
        } else {
            $q->where($col('country_code'), $assignment->country_code);
        }
    }

    /** Optional POE sub-filter for district+ roles (ignored for POE-level roles). */
    private function poeSubFilter(Request $request, object $user): ?string
    {
        if (! $request->filled('poe_code')) {
            return null;
        }

        if (in_array($user->role_key, self::POE_LEVEL_ROLES, true)) {
            return null;
        }

        return (string) $request->query('poe_code');
    }

    /** Resolve date window from request. Returns [from_datetime_str, to_datetime_str]. */
    private function dateWindow(Request $request, int $defaultDays = 30): array
    {
        if ($request->filled('date_from') && $request->filled('date_to')) {
            return [
                $request->query('date_from') . ' 00:00:00',
                $request->query('date_to') . ' 23:59:59',
            ];
        }
        $days = min((int) $request->query('days', $defaultDays), self::MAX_DAYS);
        return [
            now()->subDays($days - 1)->startOfDay()->format('Y-m-d H:i:s'),
            now()->endOfDay()->format('Y-m-d H:i:s'),
        ];
    }

    /** Convert Celsius to Fahrenheit. */
    private function toF(float $c): float
    {
        return round($c * 9 / 5 + 32, 2);
    }

    /** Find the peak day (highest total) in a day series. */
    private function peakDay(array $series): ?array
    {
        if (empty($series)) {
            return null;
        }

        $peak = array_reduce($series, fn($carry, $d) => (! $carry || $d['total'] > $carry['total']) ? $d : $carry, null);
        return $peak ? ['date' => $peak['date'], 'total' => $peak['total']] : null;
    }

    /** Compute percentage safely (avoids divide-by-zero). */
    private function pct(int $numerator, int $denominator): float
    {
        return $denominator > 0 ? round($numerator / $denominator * 100, 1) : 0.0;
    }

    /** Device status label based on unsynced count and hours since last capture. */
    private function deviceStatus(int $unsynced, ?float $hours): string
    {
        if ($hours === null) {
            return 'UNKNOWN';
        }

        if ($unsynced > 0 && $hours > 48) {
            return 'CRITICAL';
        }

        if ($unsynced > 0 && $hours > 24) {
            return 'WARNING';
        }

        if ($hours > 72) {
            return 'SILENT';
        }

        if ($unsynced > 0) {
            return 'PENDING';
        }

        return 'HEALTHY';
    }

    private function scopeSummary(object $user, object $assignment): array
    {
        return [
            'role'          => $user->role_key,
            'poe_code'      => $assignment->poe_code ?? null,
            'district_code' => $assignment->district_code ?? null,
            'pheoc_code'    => $assignment->pheoc_code ?? null,
            'country_code'  => $assignment->country_code,
        ];
    }

    private function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error' => $detail], $status);
    }

    private function serverError(Throwable $e, string $context): JsonResponse
    {
        Log::error("[Dashboard][ERROR] {$context}", [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);
        return response()->json([
            'success' => false,
            'message' => "Server error in: {$context}",
            'error' => [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => basename($e->getFile()),
                'line'      => $e->getLine(),
            ],
        ], 500);
    }
}
