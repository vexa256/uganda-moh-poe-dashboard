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
 * ║  HomeDashboardController                                                     ║
 * ║  ECSA-HC POE Sentinel · WHO/IHR 2005 · Operational Home Dashboard          ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  Purpose:   Entry-point dashboard after login. Aggregates ALL entity types  ║
 * ║             into a single operational picture:                               ║
 * ║               • Today's primary screening counters                           ║
 * ║               • Open referral queue (by priority)                            ║
 * ║               • Active secondary cases                                       ║
 * ║               • Open / critical alerts                                       ║
 * ║               • Sync health across all 5 entity stores                      ║
 * ║               • Recent activity timeline                                     ║
 * ║               • WHO/IHR weekly summary snapshot                              ║
 * ║                                                                              ║
 * ║  Database:  poe_2026  (DB:: facade — NO Eloquent models anywhere)           ║
 * ║  Auth:      NONE — all routes are publicly accessible by design.             ║
 * ║             Do NOT add Authorization: Bearer checks in this file.            ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  GEOGRAPHIC SCOPE — server-enforced, never trusts client:                   ║
 * ║    POE roles (PRIMARY/SECONDARY/DATA_OFFICER/ADMIN)                         ║
 * ║      → all tables: WHERE poe_code = assignment.poe_code                    ║
 * ║    DISTRICT_SUPERVISOR → WHERE district_code = assignment.district_code    ║
 * ║    PHEOC_OFFICER        → WHERE pheoc_code   = assignment.pheoc_code       ║
 * ║    NATIONAL_ADMIN       → WHERE country_code = assignment.country_code     ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  ROUTES (paste into routes/api.php):                                        ║
 * ║                                                                              ║
 * ║  use App\Http\Controllers\HomeDashboardController as HDC;                  ║
 * ║                                                                              ║
 * ║  Route::get('/home/summary',  [HDC::class, 'summary']);                     ║
 * ║  Route::get('/home/live',     [HDC::class, 'live']);                        ║
 * ║  Route::get('/home/activity', [HDC::class, 'activity']);                    ║
 * ║                                                                              ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  ENDPOINT REFERENCE:                                                         ║
 * ║                                                                              ║
 * ║  GET /home/summary                                                           ║
 * ║    Full operational snapshot for the home dashboard.                         ║
 * ║    11 sub-queries covering all entity types.                                 ║
 * ║    Designed as the SINGLE API call that renders the entire home page.        ║
 * ║    Params: user_id (required)                                                ║
 * ║                                                                              ║
 * ║    Returns:                                                                  ║
 * ║      screening_today    → total, symptomatic, referrals, fever, last_at     ║
 * ║      referral_queue     → open (by priority), in_progress, oldest_open_mins ║
 * ║      secondary_cases    → active, open, in_progress, critical, high         ║
 * ║      alerts             → open, critical, high, acknowledged, unrouted      ║
 * ║      sync_health        → unsynced + failed per entity type + grand total   ║
 * ║      week_snapshot      → this-week totals vs last-week delta               ║
 * ║      critical_alerts[]  → up to 5 most urgent open CRITICAL/HIGH alerts     ║
 * ║      open_referrals[]   → up to 5 oldest open CRITICAL referrals            ║
 * ║      scope              → geographic scope that was applied                  ║
 * ║                                                                              ║
 * ║  GET /home/live                                                              ║
 * ║    Ultra-lightweight ticker. Single aggregation query per entity type.       ║
 * ║    Intended to be called every 30 seconds by the UI live ticker.             ║
 * ║    No joins. Minimal DB load.                                                ║
 * ║    Params: user_id (required)                                                ║
 * ║                                                                              ║
 * ║    Returns:                                                                  ║
 * ║      screened_today     → INT — primary screenings captured today            ║
 * ║      symptomatic_today  → INT — symptomatic captures today                  ║
 * ║      open_referrals     → INT — OPEN notifications at user's scope          ║
 * ║      critical_referrals → INT — OPEN CRITICAL priority notifications        ║
 * ║      open_alerts        → INT — OPEN alerts at user's scope                 ║
 * ║      critical_alerts    → INT — OPEN CRITICAL risk alerts                   ║
 * ║      active_cases       → INT — OPEN + IN_PROGRESS secondary cases          ║
 * ║      total_unsynced     → INT — sum of UNSYNCED+FAILED across all stores    ║
 * ║      has_critical_alert → BOOL — true if any CRITICAL alert is OPEN         ║
 * ║      server_time        → ISO timestamp                                      ║
 * ║                                                                              ║
 * ║  GET /home/activity                                                          ║
 * ║    Recent activity feed. Returns last 20 events across all entity types,    ║
 * ║    ordered newest-first. Used for the home page activity timeline widget.   ║
 * ║    Params: user_id (required), limit (default 20, max 50)                   ║
 * ║                                                                              ║
 * ║    Returns:                                                                  ║
 * ║      events[] — each event has:                                              ║
 * ║        type         → 'PRIMARY'|'REFERRAL'|'SECONDARY'|'ALERT'|'AGGREGATED' ║
 * ║        event_time   → datetime of the activity                               ║
 * ║        title        → human-readable short title                             ║
 * ║        subtitle     → supporting detail line                                 ║
 * ║        risk_level   → null | 'LOW'|'MEDIUM'|'HIGH'|'CRITICAL'               ║
 * ║        poe_code     → POE where the event occurred                           ║
 * ║        entity_id    → server id of the record                                ║
 * ║        entity_uuid  → client_uuid of the record                              ║
 * ║                                                                              ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */
final class HomeDashboardController extends Controller
{
    // ═════════════════════════════════════════════════════════════════════
    // CONSTANTS
    // ═════════════════════════════════════════════════════════════════════

    private const POE_LEVEL_ROLES  = ['POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_ADMIN', 'SCREENER'];
    private const FEVER_C          = 37.5;
    private const CRITICAL_FEVER_C = 38.5;
    private const MAX_ACTIVITY     = 50;

    // ═════════════════════════════════════════════════════════════════════
    // GET /home/summary
    // Full operational snapshot — loads the entire home dashboard in one call.
    // 11 parallel sub-queries, each scoped to the user's geographic assignment.
    // ═════════════════════════════════════════════════════════════════════

    public function summary(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        try {
            $today     = now()->format('Y-m-d');
            $yesterday = now()->subDay()->format('Y-m-d');
            $weekStart = now()->startOfWeek()->format('Y-m-d');
            $lastWkS   = now()->subWeek()->startOfWeek()->format('Y-m-d');
            $lastWkE   = now()->subWeek()->endOfWeek()->format('Y-m-d');

            // ── Q1: Today's primary screenings ────────────────────────────
            $screeningToday = $this->psBase($user, $assignment)
                ->whereDate('ps.captured_at', $today)
                ->where('ps.record_status', 'COMPLETED')
                ->selectRaw('
                    COUNT(*) AS total,
                    SUM(ps.symptoms_present) AS symptomatic,
                    SUM(ps.referral_created) AS referrals,
                    SUM(CASE WHEN ps.temperature_value IS NOT NULL THEN 1 ELSE 0 END) AS with_temp,
                    SUM(CASE
                        WHEN (ps.temperature_unit="C" AND ps.temperature_value >= ?)
                          OR (ps.temperature_unit="F" AND ps.temperature_value >= ?) THEN 1 ELSE 0 END) AS fever,
                    MAX(ps.captured_at) AS last_capture_at,
                    MIN(ps.captured_at) AS first_capture_at
                ', [self::FEVER_C, $this->toF(self::FEVER_C)])
                ->first();

            // ── Q2: Yesterday's total (for delta indicator) ───────────────
            $yesterdayTotal = $this->psBase($user, $assignment)
                ->whereDate('ps.captured_at', $yesterday)
                ->where('ps.record_status', 'COMPLETED')
                ->count();

            // ── Q3: This week vs last week ────────────────────────────────
            $thisWeekTotal = $this->psBase($user, $assignment)
                ->where('ps.captured_at', '>=', $weekStart . ' 00:00:00')
                ->where('ps.record_status', 'COMPLETED')
                ->count();

            $lastWeekTotal = $this->psBase($user, $assignment)
                ->whereBetween('ps.captured_at', [$lastWkS . ' 00:00:00', $lastWkE . ' 23:59:59'])
                ->where('ps.record_status', 'COMPLETED')
                ->count();

            // ── Q4: Referral queue (notifications) ────────────────────────
            $notifBase = DB::table('notifications as n')
                ->join('primary_screenings as ps', 'ps.id', '=', 'n.primary_screening_id')
                ->whereNull('n.deleted_at')
                ->whereNull('ps.deleted_at')
                ->where('n.notification_type', 'SECONDARY_REFERRAL');
            $this->applyScopeAlias($notifBase, $user, $assignment, 'ps');

            $queueStats = (clone $notifBase)
                ->selectRaw('
                    SUM(CASE WHEN n.status="OPEN" THEN 1 ELSE 0 END)                           AS open_total,
                    SUM(CASE WHEN n.status="OPEN" AND n.priority="CRITICAL" THEN 1 ELSE 0 END) AS open_critical,
                    SUM(CASE WHEN n.status="OPEN" AND n.priority="HIGH"     THEN 1 ELSE 0 END) AS open_high,
                    SUM(CASE WHEN n.status="OPEN" AND n.priority="NORMAL"   THEN 1 ELSE 0 END) AS open_normal,
                    SUM(CASE WHEN n.status="IN_PROGRESS"                    THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN n.status="CLOSED"                         THEN 1 ELSE 0 END) AS closed_total,
                    MIN(CASE WHEN n.status="OPEN" THEN n.created_at ELSE NULL END)              AS oldest_open_at
                ')->first();

            $oldestOpenMins = null;
            if ($queueStats->oldest_open_at) {
                $oldestOpenMins = (int) round(
                    (time() - strtotime($queueStats->oldest_open_at)) / 60
                );
            }

            // ── Q5: Top 5 most urgent open referrals for banner ───────────
            $criticalReferrals = (clone $notifBase)
                ->where('n.status', 'OPEN')
                ->whereIn('n.priority', ['CRITICAL', 'HIGH'])
                ->select(
                    'n.id', 'n.client_uuid', 'n.priority', 'n.reason_text',
                    'n.created_at', 'ps.gender', 'ps.temperature_value',
                    'ps.temperature_unit', 'ps.traveler_full_name', 'ps.poe_code'
                )
                ->orderByRaw("FIELD(n.priority,'CRITICAL','HIGH') ASC")
                ->orderBy('n.created_at', 'asc')
                ->limit(5)
                ->get()
                ->map(fn($r) => [
                    'id'                 => $r->id,
                    'client_uuid'        => $r->client_uuid,
                    'priority'           => $r->priority,
                    'reason_text'        => $r->reason_text,
                    'created_at'         => $r->created_at,
                    'gender'             => $r->gender,
                    'temperature_value'  => $r->temperature_value,
                    'temperature_unit'   => $r->temperature_unit,
                    'traveler_full_name' => $r->traveler_full_name,
                    'poe_code'           => $r->poe_code,
                    'age_minutes'        => (int) round((time() - strtotime($r->created_at)) / 60),
                ])
                ->values();

            // ── Q6: Secondary case active summary ─────────────────────────
            $secBase = DB::table('secondary_screenings as ss')
                ->whereNull('ss.deleted_at');
            $this->applyScopeAlias($secBase, $user, $assignment, 'ss');

            $caseStats = (clone $secBase)
                ->selectRaw('
                    SUM(CASE WHEN ss.case_status IN ("OPEN","IN_PROGRESS")   THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN ss.case_status="OPEN"                       THEN 1 ELSE 0 END) AS open,
                    SUM(CASE WHEN ss.case_status="IN_PROGRESS"                THEN 1 ELSE 0 END) AS in_progress,
                    SUM(CASE WHEN ss.case_status="DISPOSITIONED"              THEN 1 ELSE 0 END) AS dispositioned,
                    SUM(CASE WHEN ss.case_status="CLOSED"                     THEN 1 ELSE 0 END) AS closed,
                    SUM(CASE WHEN ss.case_status IN ("OPEN","IN_PROGRESS") AND ss.risk_level="CRITICAL" THEN 1 ELSE 0 END) AS active_critical,
                    SUM(CASE WHEN ss.case_status IN ("OPEN","IN_PROGRESS") AND ss.risk_level="HIGH"     THEN 1 ELSE 0 END) AS active_high,
                    SUM(CASE WHEN ss.emergency_signs_present=1 AND ss.case_status IN ("OPEN","IN_PROGRESS") THEN 1 ELSE 0 END) AS emergency_active,
                    COUNT(*) AS total_all_time
                ')->first();

            // ── Q7: Alerts summary ────────────────────────────────────────
            $alertBase = DB::table('alerts as al')
                ->join('secondary_screenings as ss', 'ss.id', '=', 'al.secondary_screening_id')
                ->whereNull('al.deleted_at')
                ->whereNull('ss.deleted_at');
            $this->applyScopeAlias($alertBase, $user, $assignment, 'ss');

            $alertStats = (clone $alertBase)
                ->selectRaw('
                    SUM(CASE WHEN al.status="OPEN" THEN 1 ELSE 0 END)                            AS open,
                    SUM(CASE WHEN al.status="OPEN" AND al.risk_level="CRITICAL" THEN 1 ELSE 0 END) AS open_critical,
                    SUM(CASE WHEN al.status="OPEN" AND al.risk_level="HIGH"     THEN 1 ELSE 0 END) AS open_high,
                    SUM(CASE WHEN al.status="OPEN" AND al.routed_to_level="NATIONAL" THEN 1 ELSE 0 END) AS open_national,
                    SUM(CASE WHEN al.status="OPEN" AND al.routed_to_level="PHEOC"    THEN 1 ELSE 0 END) AS open_pheoc,
                    SUM(CASE WHEN al.status="OPEN" AND al.routed_to_level="DISTRICT" THEN 1 ELSE 0 END) AS open_district,
                    SUM(CASE WHEN al.status="ACKNOWLEDGED" THEN 1 ELSE 0 END)                     AS acknowledged,
                    SUM(CASE WHEN al.status="CLOSED"       THEN 1 ELSE 0 END)                     AS closed,
                    AVG(CASE WHEN al.acknowledged_at IS NOT NULL
                             THEN TIMESTAMPDIFF(MINUTE, al.created_at, al.acknowledged_at)
                             ELSE NULL END)                                                         AS avg_ack_minutes
                ')->first();

            // ── Q8: Top 5 most urgent open alerts for banner ──────────────
            $criticalAlerts = (clone $alertBase)
                ->where('al.status', 'OPEN')
                ->select(
                    'al.id', 'al.client_uuid', 'al.alert_code', 'al.alert_title',
                    'al.risk_level', 'al.routed_to_level', 'al.generated_from',
                    'al.alert_details', 'al.created_at',
                    DB::raw('ss.poe_code as poe_code'),
                    DB::raw('ss.syndrome_classification as syndrome')
                )
                ->orderByRaw("FIELD(al.risk_level,'CRITICAL','HIGH','MEDIUM','LOW') ASC")
                ->orderByRaw("FIELD(al.routed_to_level,'NATIONAL','PHEOC','DISTRICT') ASC")
                ->orderBy('al.created_at', 'asc')
                ->limit(5)
                ->get()
                ->map(fn($r) => [
                    'id'              => $r->id,
                    'client_uuid'     => $r->client_uuid,
                    'alert_code'      => $r->alert_code,
                    'alert_title'     => $r->alert_title,
                    'risk_level'      => $r->risk_level,
                    'routed_to_level' => $r->routed_to_level,
                    'generated_from'  => $r->generated_from,
                    'alert_details'   => $r->alert_details,
                    'created_at'      => $r->created_at,
                    'poe_code'        => $r->poe_code,
                    'syndrome'        => $r->syndrome,
                    'age_minutes'     => (int) round((time() - strtotime($r->created_at)) / 60),
                ])
                ->values();

            // ── Q9: Sync health — UNSYNCED + FAILED per entity type ───────
            // Each store is counted independently. The counts cover ALL records
            // in the user's geographic scope, not just today's.
            $syncHealth = $this->buildSyncHealth($user, $assignment);

            // ── Q10: Aggregated submissions — last submission date ─────────
            $aggBase = DB::table('aggregated_submissions as ag')
                ->whereNull('ag.deleted_at');
            $this->applyScopeAlias($aggBase, $user, $assignment, 'ag');

            $aggStats = (clone $aggBase)
                ->selectRaw('
                    COUNT(*) AS total_submissions,
                    SUM(CASE WHEN ag.sync_status="UNSYNCED" THEN 1 ELSE 0 END) AS unsynced,
                    MAX(ag.period_end) AS last_period_end,
                    MAX(ag.created_at) AS last_submitted_at
                ')->first();

            // ── Q11: POE-level operational flags ──────────────────────────
            // Is any device at this POE silent for >24h with UNSYNCED records?
            $silentDevices = 0;
            if (in_array($user->role_key, self::POE_LEVEL_ROLES, true)) {
                $silentDevices = DB::table('primary_screenings')
                    ->where('poe_code', $assignment->poe_code)
                    ->where('sync_status', 'UNSYNCED')
                    ->whereNull('deleted_at')
                    ->where('captured_at', '<', now()->subHours(24)->format('Y-m-d H:i:s'))
                    ->distinct('device_id')
                    ->count('device_id');
            }

            // ── Compute derived metrics ───────────────────────────────────
            $todayTotal = (int) ($screeningToday->total ?? 0);
            $todaySymp  = (int) ($screeningToday->symptomatic ?? 0);
            $sympRate   = $todayTotal > 0 ? round($todaySymp / $todayTotal * 100, 1) : 0.0;

            $openAlerts    = (int) ($alertStats->open ?? 0);
            $critAlerts    = (int) ($alertStats->open_critical ?? 0);
            $openReferrals = (int) ($queueStats->open_total ?? 0);
            $critReferrals = (int) ($queueStats->open_critical ?? 0);

            // Operational severity level for the home page status banner
            $operationalStatus = 'NORMAL';
            if ($critAlerts > 0 || (int) ($caseStats->emergency_active ?? 0) > 0) {
                $operationalStatus = 'CRITICAL';
            } elseif ($openAlerts > 0 || $critReferrals > 0) {
                $operationalStatus = 'ELEVATED';
            } elseif ($openReferrals > 0 || (int) ($caseStats->active ?? 0) > 0) {
                $operationalStatus = 'ACTIVE';
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'generated_at'       => now()->toISOString(),
                    'scope'              => $this->scopeSummary($user, $assignment),
                    'operational_status' => $operationalStatus,

                    'screening_today'    => [
                        'total'            => $todayTotal,
                        'symptomatic'      => $todaySymp,
                        'asymptomatic'     => $todayTotal - $todaySymp,
                        'symptomatic_rate' => $sympRate,
                        'referrals'        => (int) ($screeningToday->referrals ?? 0),
                        'with_temp'        => (int) ($screeningToday->with_temp ?? 0),
                        'fever'            => (int) ($screeningToday->fever ?? 0),
                        'first_capture_at' => $screeningToday->first_capture_at,
                        'last_capture_at'  => $screeningToday->last_capture_at,
                        'vs_yesterday'     => $todayTotal - (int) $yesterdayTotal,
                        'yesterday_total'  => (int) $yesterdayTotal,
                    ],

                    'week_snapshot'      => [
                        'week_start'   => $weekStart,
                        'this_week'    => $thisWeekTotal,
                        'last_week'    => $lastWeekTotal,
                        'vs_last_week' => $thisWeekTotal - $lastWeekTotal,
                    ],

                    'referral_queue'     => [
                        'open'                => $openReferrals,
                        'open_critical'       => $critReferrals,
                        'open_high'           => (int) ($queueStats->open_high ?? 0),
                        'open_normal'         => (int) ($queueStats->open_normal ?? 0),
                        'in_progress'         => (int) ($queueStats->in_progress ?? 0),
                        'closed_total'        => (int) ($queueStats->closed_total ?? 0),
                        'oldest_open_minutes' => $oldestOpenMins,
                        'queue_critical'      => $critReferrals > 0,
                        'top_critical'        => $criticalReferrals,
                    ],

                    'secondary_cases'    => [
                        'active'           => (int) ($caseStats->active ?? 0),
                        'open'             => (int) ($caseStats->open ?? 0),
                        'in_progress'      => (int) ($caseStats->in_progress ?? 0),
                        'dispositioned'    => (int) ($caseStats->dispositioned ?? 0),
                        'closed'           => (int) ($caseStats->closed ?? 0),
                        'active_critical'  => (int) ($caseStats->active_critical ?? 0),
                        'active_high'      => (int) ($caseStats->active_high ?? 0),
                        'emergency_active' => (int) ($caseStats->emergency_active ?? 0),
                        'total_all_time'   => (int) ($caseStats->total_all_time ?? 0),
                    ],

                    'alerts'             => [
                        'open'            => $openAlerts,
                        'open_critical'   => $critAlerts,
                        'open_high'       => (int) ($alertStats->open_high ?? 0),
                        'open_national'   => (int) ($alertStats->open_national ?? 0),
                        'open_pheoc'      => (int) ($alertStats->open_pheoc ?? 0),
                        'open_district'   => (int) ($alertStats->open_district ?? 0),
                        'acknowledged'    => (int) ($alertStats->acknowledged ?? 0),
                        'closed'          => (int) ($alertStats->closed ?? 0),
                        'avg_ack_minutes' => $alertStats->avg_ack_minutes !== null
                            ? (int) round((float) $alertStats->avg_ack_minutes) : null,
                        'has_critical'    => $critAlerts > 0,
                        'top_alerts'      => $criticalAlerts,
                    ],

                    'sync_health'        => $syncHealth,

                    'aggregated'         => [
                        'total_submissions' => (int) ($aggStats->total_submissions ?? 0),
                        'unsynced'          => (int) ($aggStats->unsynced ?? 0),
                        'last_period_end'   => $aggStats->last_period_end,
                        'last_submitted_at' => $aggStats->last_submitted_at,
                    ],

                    'device_health'      => [
                        'silent_devices_with_data' => $silentDevices,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'home/summary');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /home/live
    // Ultra-lightweight 30-second ticker. Minimal DB load.
    // One aggregation query per entity type — no joins.
    // ═════════════════════════════════════════════════════════════════════

    public function live(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        try {
            $today = now()->format('Y-m-d');

            // ── Primary screenings today ──────────────────────────────────
            $psRow = $this->psBase($user, $assignment)
                ->whereDate('ps.captured_at', $today)
                ->where('ps.record_status', 'COMPLETED')
                ->selectRaw('COUNT(*) AS total, SUM(ps.symptoms_present) AS symptomatic, MAX(ps.captured_at) AS last_capture_at')
                ->first();

            // ── Open referrals ────────────────────────────────────────────
            $notifQ = DB::table('notifications as n')
                ->join('primary_screenings as ps', 'ps.id', '=', 'n.primary_screening_id')
                ->whereNull('n.deleted_at')
                ->whereNull('ps.deleted_at')
                ->where('n.notification_type', 'SECONDARY_REFERRAL')
                ->where('n.status', 'OPEN');
            $this->applyScopeAlias($notifQ, $user, $assignment, 'ps');

            $openReferrals = (clone $notifQ)->count();
            $critReferrals = (clone $notifQ)->where('n.priority', 'CRITICAL')->count();

            // ── Active secondary cases ────────────────────────────────────
            $secQ = DB::table('secondary_screenings as ss')
                ->whereNull('ss.deleted_at')
                ->whereIn('ss.case_status', ['OPEN', 'IN_PROGRESS']);
            $this->applyScopeAlias($secQ, $user, $assignment, 'ss');
            $activeCases = $secQ->count();

            // ── Open alerts ───────────────────────────────────────────────
            $alertQ = DB::table('alerts as al')
                ->join('secondary_screenings as ss', 'ss.id', '=', 'al.secondary_screening_id')
                ->whereNull('al.deleted_at')
                ->whereNull('ss.deleted_at')
                ->where('al.status', 'OPEN');
            $this->applyScopeAlias($alertQ, $user, $assignment, 'ss');

            $openAlerts = (clone $alertQ)->count();
            $critAlerts = (clone $alertQ)->where('al.risk_level', 'CRITICAL')->count();

            // ── Unsynced total (all entity types in scope) ─────────────
            $syncHealth    = $this->buildSyncHealth($user, $assignment);
            $totalUnsynced = $syncHealth['grand_total_unsynced'];

            return response()->json([
                'success' => true,
                'data'    => [
                    'screened_today'     => (int) ($psRow->total ?? 0),
                    'symptomatic_today'  => (int) ($psRow->symptomatic ?? 0),
                    'last_capture_at'    => $psRow->last_capture_at,
                    'open_referrals'     => $openReferrals,
                    'critical_referrals' => $critReferrals,
                    'active_cases'       => $activeCases,
                    'open_alerts'        => $openAlerts,
                    'critical_alerts'    => $critAlerts,
                    'total_unsynced'     => $totalUnsynced,
                    'has_critical_alert' => $critAlerts > 0,
                    'queue_critical'     => $critReferrals > 0,
                    'server_time'        => now()->toISOString(),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'home/live');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /home/activity
    // Recent activity timeline — last N events across all entity types.
    // Uses UNION ALL to merge events from 4 entity tables into one feed.
    // ═════════════════════════════════════════════════════════════════════

    public function activity(Request $request): JsonResponse
    {
        [$user, $assignment, $err] = $this->resolveContext($request);
        if ($err) {
            return $err;
        }

        $limit = min((int) $request->query('limit', 20), self::MAX_ACTIVITY);

        try {
            [$scopeCol, $scopeVal] = $this->scopeColVal($user, $assignment);

            // ── Recent primary screenings ─────────────────────────────────
            $primarySql = DB::table('primary_screenings as ps')
                ->whereNull('ps.deleted_at')
                ->where("ps.{$scopeCol}", $scopeVal)
                ->selectRaw("
                    'PRIMARY'                                      AS type,
                    ps.captured_at                                 AS event_time,
                    CONCAT(
                        CASE ps.gender WHEN 'MALE' THEN '♂' WHEN 'FEMALE' THEN '♀' ELSE '⚥' END,
                        ' ',
                        CASE ps.symptoms_present WHEN 1 THEN 'Symptomatic traveler screened'
                                                        ELSE 'Asymptomatic traveler screened' END
                    )                                              AS title,
                    CONCAT(ps.poe_code,
                        CASE WHEN ps.temperature_value IS NOT NULL
                             THEN CONCAT(' · ', ps.temperature_value, '°', ps.temperature_unit)
                             ELSE '' END
                    )                                              AS subtitle,
                    NULL                                           AS risk_level,
                    ps.poe_code,
                    ps.id                                          AS entity_id,
                    ps.client_uuid                                 AS entity_uuid
                ")
                ->orderBy('ps.captured_at', 'desc')
                ->limit($limit);

            // ── Recent referral notifications ─────────────────────────────
            $notifSql = DB::table('notifications as n')
                ->join('primary_screenings as ps', 'ps.id', '=', 'n.primary_screening_id')
                ->whereNull('n.deleted_at')
                ->where("ps.{$scopeCol}", $scopeVal)
                ->selectRaw("
                    'REFERRAL'                                     AS type,
                    n.created_at                                   AS event_time,
                    CONCAT(n.priority, ' referral ', n.status)     AS title,
                    CONCAT(ps.poe_code, ' · ', n.reason_code)      AS subtitle,
                    NULL                                           AS risk_level,
                    ps.poe_code,
                    n.id                                           AS entity_id,
                    n.client_uuid                                  AS entity_uuid
                ")
                ->orderBy('n.created_at', 'desc')
                ->limit($limit);

            // ── Recent secondary case events ──────────────────────────────
            $secSql = DB::table('secondary_screenings as ss')
                ->whereNull('ss.deleted_at')
                ->where("ss.{$scopeCol}", $scopeVal)
                ->selectRaw("
                    'SECONDARY'                                    AS type,
                    COALESCE(ss.closed_at, ss.dispositioned_at, ss.opened_at) AS event_time,
                    CONCAT('Case ', ss.case_status,
                        CASE WHEN ss.risk_level IS NOT NULL THEN CONCAT(' · ', ss.risk_level, ' risk') ELSE '' END
                    )                                              AS title,
                    CONCAT(ss.poe_code,
                        CASE WHEN ss.syndrome_classification IS NOT NULL
                             THEN CONCAT(' · ', REPLACE(ss.syndrome_classification,'_',' '))
                             ELSE '' END
                    )                                              AS subtitle,
                    ss.risk_level,
                    ss.poe_code,
                    ss.id                                          AS entity_id,
                    ss.client_uuid                                 AS entity_uuid
                ")
                ->orderByRaw('COALESCE(ss.closed_at, ss.dispositioned_at, ss.opened_at) DESC')
                ->limit($limit);

            // ── Recent alerts ─────────────────────────────────────────────
            $alertSql = DB::table('alerts as al')
                ->join('secondary_screenings as ss', 'ss.id', '=', 'al.secondary_screening_id')
                ->whereNull('al.deleted_at')
                ->where("ss.{$scopeCol}", $scopeVal)
                ->selectRaw("
                    'ALERT'                                        AS type,
                    COALESCE(al.acknowledged_at, al.created_at)   AS event_time,
                    CONCAT('Alert: ', al.alert_title)             AS title,
                    CONCAT(al.risk_level, ' · ', al.routed_to_level, ' · ', al.status) AS subtitle,
                    al.risk_level,
                    ss.poe_code,
                    al.id                                          AS entity_id,
                    al.client_uuid                                 AS entity_uuid
                ")
                ->orderByRaw('COALESCE(al.acknowledged_at, al.created_at) DESC')
                ->limit($limit);

            // ── Merge and sort the four streams ───────────────────────────
            // Run each query, combine, sort by event_time desc, take limit.
            $events = collect()
                ->merge($primarySql->get())
                ->merge($notifSql->get())
                ->merge($secSql->get())
                ->merge($alertSql->get())
                ->sortByDesc('event_time')
                ->take($limit)
                ->values()
                ->map(fn($e) => [
                    'type'        => $e->type,
                    'event_time'  => $e->event_time,
                    'title'       => $e->title,
                    'subtitle'    => $e->subtitle,
                    'risk_level'  => $e->risk_level,
                    'poe_code'    => $e->poe_code,
                    'entity_id'   => $e->entity_id,
                    'entity_uuid' => $e->entity_uuid,
                    'age_minutes' => $e->event_time
                        ? (int) round((time() - strtotime($e->event_time)) / 60)
                        : null,
                ]);

            return response()->json([
                'success' => true,
                'data'    => [
                    'events'      => $events,
                    'event_count' => $events->count(),
                    'scope'       => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'home/activity');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // PRIVATE — SYNC HEALTH BUILDER
    // Counts UNSYNCED + FAILED records for each entity type within scope.
    // Uses 5 independent count queries — one per entity table.
    // Returns a structured object with per-type breakdown and grand total.
    // ═════════════════════════════════════════════════════════════════════

    private function buildSyncHealth(object $user, object $assignment): array
    {
        [$scopeCol, $scopeVal] = $this->scopeColVal($user, $assignment);

        // Primary screenings
        $ps = DB::table('primary_screenings')
            ->where($scopeCol, $scopeVal)
            ->whereNull('deleted_at')
            ->selectRaw("
                SUM(CASE WHEN sync_status='UNSYNCED' THEN 1 ELSE 0 END) AS unsynced,
                SUM(CASE WHEN sync_status='FAILED'   THEN 1 ELSE 0 END) AS failed,
                COUNT(*) AS total
            ")->first();

        // Notifications
        $notif = DB::table('notifications')
            ->where($scopeCol, $scopeVal)
            ->whereNull('deleted_at')
            ->selectRaw("
                SUM(CASE WHEN sync_status='UNSYNCED' THEN 1 ELSE 0 END) AS unsynced,
                SUM(CASE WHEN sync_status='FAILED'   THEN 1 ELSE 0 END) AS failed,
                COUNT(*) AS total
            ")->first();

        // Secondary screenings — scoped on poe_code, district_code, or pheoc_code
        $secScopeCol = ($scopeCol === 'poe_code') ? 'poe_code'
            : (($scopeCol === 'district_code') ? 'district_code'
                : (($scopeCol === 'pheoc_code') ? 'pheoc_code' : 'country_code'));

        $sec = DB::table('secondary_screenings')
            ->where($secScopeCol, $scopeVal)
            ->whereNull('deleted_at')
            ->selectRaw("
                SUM(CASE WHEN sync_status='UNSYNCED' THEN 1 ELSE 0 END) AS unsynced,
                SUM(CASE WHEN sync_status='FAILED'   THEN 1 ELSE 0 END) AS failed,
                COUNT(*) AS total
            ")->first();

        // Alerts — join secondary for scope
        $alertQ = DB::table('alerts as al')
            ->join('secondary_screenings as ss', 'ss.id', '=', 'al.secondary_screening_id')
            ->whereNull('al.deleted_at')
            ->where("ss.{$secScopeCol}", $scopeVal);
        $alertSyncRow = (clone $alertQ)
            ->selectRaw("
                SUM(CASE WHEN al.sync_status='UNSYNCED' THEN 1 ELSE 0 END) AS unsynced,
                SUM(CASE WHEN al.sync_status='FAILED'   THEN 1 ELSE 0 END) AS failed,
                COUNT(*) AS total
            ")->first();

        // Aggregated submissions
        $agg = DB::table('aggregated_submissions')
            ->where($scopeCol, $scopeVal)
            ->whereNull('deleted_at')
            ->selectRaw("
                SUM(CASE WHEN sync_status='UNSYNCED' THEN 1 ELSE 0 END) AS unsynced,
                SUM(CASE WHEN sync_status='FAILED'   THEN 1 ELSE 0 END) AS failed,
                COUNT(*) AS total
            ")->first();

        $psU        = (int) ($ps->unsynced ?? 0) + (int) ($ps->failed ?? 0);
        $notifU     = (int) ($notif->unsynced ?? 0) + (int) ($notif->failed ?? 0);
        $secU       = (int) ($sec->unsynced ?? 0) + (int) ($sec->failed ?? 0);
        $alertU     = (int) ($alertSyncRow->unsynced ?? 0) + (int) ($alertSyncRow->failed ?? 0);
        $aggU       = (int) ($agg->unsynced ?? 0) + (int) ($agg->failed ?? 0);
        $grandTotal = $psU + $notifU + $secU + $alertU + $aggU;

        return [
            'primary_screenings'     => [
                'unsynced' => (int) ($ps->unsynced ?? 0),
                'failed'   => (int) ($ps->failed ?? 0),
                'total'    => (int) ($ps->total ?? 0),
                'pending'  => $psU,
            ],
            'notifications'          => [
                'unsynced' => (int) ($notif->unsynced ?? 0),
                'failed'   => (int) ($notif->failed ?? 0),
                'total'    => (int) ($notif->total ?? 0),
                'pending'  => $notifU,
            ],
            'secondary_screenings'   => [
                'unsynced' => (int) ($sec->unsynced ?? 0),
                'failed'   => (int) ($sec->failed ?? 0),
                'total'    => (int) ($sec->total ?? 0),
                'pending'  => $secU,
            ],
            'alerts'                 => [
                'unsynced' => (int) ($alertSyncRow->unsynced ?? 0),
                'failed'   => (int) ($alertSyncRow->failed ?? 0),
                'total'    => (int) ($alertSyncRow->total ?? 0),
                'pending'  => $alertU,
            ],
            'aggregated_submissions' => [
                'unsynced' => (int) ($agg->unsynced ?? 0),
                'failed'   => (int) ($agg->failed ?? 0),
                'total'    => (int) ($agg->total ?? 0),
                'pending'  => $aggU,
            ],
            'grand_total_unsynced'   => $grandTotal,
            'sync_healthy'           => $grandTotal === 0,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Resolve user + assignment from ?user_id query param.
     * Returns [user, assignment, errorResponse|null].
     */
    private function resolveContext(Request $request): array
    {
        $userId = (int) $request->query('user_id', 0);
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

    /**
     * Base query for primary_screenings with geographic scope applied.
     * Always uses 'ps' alias.
     */
    private function psBase(object $user, object $assignment): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('primary_screenings as ps')->whereNull('ps.deleted_at');
        $this->applyScopeAlias($q, $user, $assignment, 'ps');
        return $q;
    }

    /**
     * Apply geographic scope to any query using a table alias.
     * Selects the correct column based on role.
     */
    private function applyScopeAlias(object $q, object $user, object $assignment, string $alias): void
    {
        $role = $user->role_key ?? '';
        if (in_array($role, self::POE_LEVEL_ROLES, true)) {
            $q->where("{$alias}.poe_code", $assignment->poe_code);
        } elseif ($role === 'DISTRICT_SUPERVISOR') {
            $q->where("{$alias}.district_code", $assignment->district_code);
        } elseif ($role === 'PHEOC_OFFICER' || $role === 'PHEOC_ADMIN') {
            $scopeCode = $assignment->province_code ?? $assignment->pheoc_code ?? null;
            $q->where("{$alias}.country_code", $assignment->country_code);
            if ($scopeCode !== null) {
                $q->where(function ($qq) use ($alias, $scopeCode) {
                    $qq->where("{$alias}.province_code", $scopeCode)
                       ->orWhere("{$alias}.pheoc_code", $scopeCode);
                });
            }
        } else {
            $q->where("{$alias}.country_code", $assignment->country_code);
        }
    }

    /**
     * Returns [column_name, value] for the user's scope.
     * Used when building raw UNION queries that need the scope inline.
     */
    private function scopeColVal(object $user, object $assignment): array
    {
        $role = $user->role_key ?? '';
        if (in_array($role, self::POE_LEVEL_ROLES, true)) {
            return ['poe_code', $assignment->poe_code];
        }

        if ($role === 'DISTRICT_SUPERVISOR') {
            return ['district_code', $assignment->district_code];
        }

        if ($role === 'PHEOC_OFFICER') {
            return ['pheoc_code', $assignment->pheoc_code];
        }

        return ['country_code', $assignment->country_code];
    }

    /** Convert Celsius to Fahrenheit. */
    private function toF(float $c): float
    {return round($c * 9 / 5 + 32, 2);}

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
        Log::error("[HomeDashboard][ERROR] {$context}", [
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
