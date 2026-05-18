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
 * ║  PrimaryScreeningRecordsController                                           ║
 * ║  ECSA-HC POE Sentinel · WHO/IHR 2005 Compliant                              ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  Purpose:   Full read API for primary screening records — list view,         ║
 * ║             detail, statistics dashboard, trend analysis, heatmap data,      ║
 * ║             and void action. Separate from PrimaryScreeningController         ║
 * ║             (the write/sync path). Designed for PrimaryRecords.vue.          ║
 * ║                                                                              ║
 * ║  Database:  poe_2026  (DB:: facade — NO Eloquent models anywhere)           ║
 * ║  Auth:      NONE — all routes are publicly accessible by design.             ║
 * ║             Auth middleware will be added as a separate layer later.         ║
 * ║             Do NOT add Authorization: Bearer checks anywhere in this file.   ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  GEOGRAPHIC SCOPE ENFORCEMENT (server-side, never trusted from client):      ║
 * ║                                                                              ║
 * ║    POE_PRIMARY, POE_SECONDARY, POE_DATA_OFFICER, POE_ADMIN                  ║
 * ║      → WHERE ps.poe_code = assignment.poe_code                              ║
 * ║                                                                              ║
 * ║    DISTRICT_SUPERVISOR                                                       ║
 * ║      → WHERE ps.district_code = assignment.district_code                    ║
 * ║                                                                              ║
 * ║    PHEOC_OFFICER                                                             ║
 * ║      → WHERE ps.pheoc_code = assignment.pheoc_code                          ║
 * ║                                                                              ║
 * ║    NATIONAL_ADMIN                                                            ║
 * ║      → WHERE ps.country_code = assignment.country_code                      ║
 * ║                                                                              ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  ROUTES (paste into routes/api.php — ORDER IS CRITICAL):                    ║
 * ║                                                                              ║
 * ║  use App\Http\Controllers\PrimaryScreeningRecordsController as PSRC;        ║
 * ║                                                                              ║
 * ║  // Declare BEFORE /primary-screenings/{id} to prevent Laravel routing      ║
 * ║  // "stats", "heatmap", "trend" as integer {id} params.                     ║
 * ║                                                                              ║
 * ║  Route::get  ('/primary-records/stats',   [PSRC::class, 'stats']);           ║
 * ║  Route::get  ('/primary-records/heatmap', [PSRC::class, 'heatmap']);         ║
 * ║  Route::get  ('/primary-records/trend',   [PSRC::class, 'trend']);           ║
 * ║  Route::get  ('/primary-records/export',  [PSRC::class, 'export']);          ║
 * ║  Route::get  ('/primary-records/{id}',    [PSRC::class, 'show']);            ║
 * ║  Route::get  ('/primary-records',         [PSRC::class, 'index']);           ║
 * ║  Route::patch('/primary-records/{id}/void',[PSRC::class,'void']);            ║
 * ║                                                                              ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  QUERY PARAMETERS — index():                                                 ║
 * ║    user_id            int (required) — AUTH_DATA.id                         ║
 * ║    page               int (default 1)                                        ║
 * ║    per_page           int (default 30, max 200)                              ║
 * ║    search             string — traveler_full_name, device_id, client_uuid   ║
 * ║    gender             MALE|FEMALE|OTHER|UNKNOWN                              ║
 * ║    symptoms_present   0|1                                                    ║
 * ║    record_status      COMPLETED|VOIDED                                       ║
 * ║    sync_status        UNSYNCED|SYNCED|FAILED                                 ║
 * ║    referral_created   0|1                                                    ║
 * ║    poe_code           string (district+ roles only, POE roles always scoped) ║
 * ║    date_from          YYYY-MM-DD (filters on captured_at)                   ║
 * ║    date_to            YYYY-MM-DD (filters on captured_at)                   ║
 * ║    temp_min           decimal (filter: temperature_value ≥ X, Celsius)      ║
 * ║    temp_max           decimal (filter: temperature_value ≤ X, Celsius)      ║
 * ║    sort_by            captured_at|temperature_value|gender (default captured_at)║
 * ║    sort_dir           asc|desc (default desc)                                ║
 * ║                                                                              ║
 * ║  QUERY PARAMETERS — stats():                                                 ║
 * ║    user_id            int (required)                                         ║
 * ║    date_from / date_to optional window (default today)                      ║
 * ║    poe_code           string (district+ roles: filter to a specific POE)    ║
 * ║                                                                              ║
 * ║  QUERY PARAMETERS — heatmap():                                               ║
 * ║    user_id            int (required)                                         ║
 * ║    date_from / date_to YYYY-MM-DD (default last 30 days)                   ║
 * ║    group_by           hour|day|weekday (default day)                        ║
 * ║                                                                              ║
 * ║  QUERY PARAMETERS — trend():                                                 ║
 * ║    user_id            int (required)                                         ║
 * ║    days               int (default 30, max 365) — rolling window            ║
 * ║    poe_code           string (district+ roles: filter to specific POE)      ║
 * ║                                                                              ║
 * ║  QUERY PARAMETERS — export():                                                ║
 * ║    user_id            int (required)                                         ║
 * ║    date_from / date_to required for export                                  ║
 * ║    [all index() filters apply]                                               ║
 * ║    Max 10,000 rows — returns flat array, no pagination                       ║
 * ║                                                                              ║
 * ║  QUERY PARAMETERS — void():                                                  ║
 * ║    user_id            int (required in request body)                        ║
 * ║    void_reason        string (required, min 10 chars)                       ║
 * ║                                                                              ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */
final class PrimaryScreeningRecordsController extends Controller
{
    // ═════════════════════════════════════════════════════════════════════
    // CONSTANTS
    // ═════════════════════════════════════════════════════════════════════

    private const MAX_PER_PAGE     = 200;
    private const DEFAULT_PER_PAGE = 30;
    private const EXPORT_LIMIT     = 10_000;

    private const VALID_GENDERS     = ['MALE', 'FEMALE', 'OTHER', 'UNKNOWN'];
    private const VALID_STATUSES    = ['COMPLETED', 'VOIDED'];
    private const VALID_SYNC_STATES = ['UNSYNCED', 'SYNCED', 'FAILED'];
    private const VALID_SORT_FIELDS = ['captured_at', 'temperature_value', 'gender', 'record_status', 'symptoms_present'];
    private const VALID_SORT_DIRS   = ['asc', 'desc'];
    private const VALID_GROUP_BY    = ['hour', 'day', 'weekday'];
    private const VALID_VOID_ROLES  = ['POE_ADMIN', 'NATIONAL_ADMIN'];

    /** POE-level roles — must have a poe_code on their assignment */
    private const POE_LEVEL_ROLES = ['POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_ADMIN', 'SCREENER'];

    /** Fever thresholds (Celsius) matching PrimaryScreeningController */
    private const TEMP_HIGH_C     = 37.5;
    private const TEMP_CRITICAL_C = 38.5;

    // ═════════════════════════════════════════════════════════════════════
    // GET /primary-records
    // Paginated list of primary screening records, scoped by role.
    // Enriched with screener name, notification status, and secondary case status.
    // Zero N+1 queries — enrichment uses indexed whereIn lookups.
    // ═════════════════════════════════════════════════════════════════════

    public function index(Request $request): JsonResponse
    {
        [$user, $assignment, $errResponse] = $this->resolveContext($request);
        if ($errResponse) {
            return $errResponse;
        }

        try {
            // ── Base query ────────────────────────────────────────────────
            $query = DB::table('primary_screenings as ps')
                ->leftJoin('users as screener', 'screener.id', '=', 'ps.captured_by_user_id')
                ->whereNull('ps.deleted_at');

            $this->applyScope($query, $user, $assignment);

            // ── Optional POE sub-filter (district+ roles only) ────────────
            if ($request->filled('poe_code') && ! in_array($user->role_key, self::POE_LEVEL_ROLES, true)) {
                $query->where('ps.poe_code', $request->query('poe_code'));
            }

            // ── Search ────────────────────────────────────────────────────
            $search = trim((string) $request->query('search', ''));
            if ($search !== '') {
                $like = '%' . $search . '%';
                $query->where(function ($q) use ($like, $search) {
                    $q->where('ps.traveler_full_name', 'like', $like)
                        ->orWhere('ps.device_id', 'like', $like)
                        ->orWhere('ps.client_uuid', 'like', $search . '%')
                        ->orWhere('screener.full_name', 'like', $like);
                });
            }

            // ── Enum + boolean filters ────────────────────────────────────
            $this->applyEnumFilter($query, $request, 'ps.gender', 'gender', self::VALID_GENDERS);
            $this->applyEnumFilter($query, $request, 'ps.record_status', 'record_status', self::VALID_STATUSES);
            $this->applyEnumFilter($query, $request, 'ps.sync_status', 'sync_status', self::VALID_SYNC_STATES);

            if ($request->filled('symptoms_present') && in_array($request->query('symptoms_present'), ['0', '1'], true)) {
                $query->where('ps.symptoms_present', (int) $request->query('symptoms_present'));
            }
            if ($request->filled('referral_created') && in_array($request->query('referral_created'), ['0', '1'], true)) {
                $query->where('ps.referral_created', (int) $request->query('referral_created'));
            }

            // ── Date range (captured_at) ──────────────────────────────────
            if ($request->filled('date_from')) {
                $query->where('ps.captured_at', '>=', $request->query('date_from') . ' 00:00:00');
            }
            if ($request->filled('date_to')) {
                $query->where('ps.captured_at', '<=', $request->query('date_to') . ' 23:59:59');
            }

            // ── Temperature range filter (always in Celsius — server normalises F) ──
            if ($request->filled('temp_min')) {
                $query->where('ps.temperature_value', '>=', (float) $request->query('temp_min'));
            }
            if ($request->filled('temp_max')) {
                $query->where('ps.temperature_value', '<=', (float) $request->query('temp_max'));
            }

            // ── Count before pagination ───────────────────────────────────
            $total   = (clone $query)->count();
            $perPage = min((int) $request->query('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
            $page    = max(1, (int) $request->query('page', 1));
            $offset  = ($page - 1) * $perPage;

            // ── Sort ──────────────────────────────────────────────────────
            $sortBy  = $request->query('sort_by', 'captured_at');
            $sortDir = strtolower((string) $request->query('sort_dir', 'desc'));
            if (! in_array($sortBy, self::VALID_SORT_FIELDS, true)) {
                $sortBy = 'captured_at';
            }

            if (! in_array($sortDir, self::VALID_SORT_DIRS, true)) {
                $sortDir = 'desc';
            }

            $query->orderBy('ps.' . $sortBy, $sortDir);
            if ($sortBy !== 'captured_at') {
                $query->orderBy('ps.captured_at', 'desc');
            }

            // ── Fetch page ────────────────────────────────────────────────
            $rows = $query
                ->select([
                    'ps.id',
                    'ps.client_uuid',
                    'ps.reference_data_version',
                    'ps.server_received_at',
                    'ps.country_code',
                    'ps.province_code',
                    'ps.pheoc_code',
                    'ps.district_code',
                    'ps.poe_code',
                    'ps.captured_by_user_id',
                    'ps.gender',
                    'ps.traveler_full_name',
                    'ps.temperature_value',
                    'ps.temperature_unit',
                    'ps.symptoms_present',
                    'ps.captured_at',
                    'ps.captured_timezone',
                    'ps.device_id',
                    'ps.app_version',
                    'ps.platform',
                    'ps.referral_created',
                    'ps.record_version',
                    'ps.record_status',
                    'ps.void_reason',
                    'ps.sync_status',
                    'ps.synced_at',
                    'ps.sync_attempt_count',
                    'ps.last_sync_error',
                    'ps.created_at',
                    'ps.updated_at',
                    DB::raw('screener.full_name  as screener_name'),
                    DB::raw('screener.role_key   as screener_role'),
                    DB::raw('screener.username   as screener_username'),
                ])
                ->skip($offset)
                ->take($perPage)
                ->get();

            // ── Enrich: notification status per record (one whereIn query) ─
            $psIds    = $rows->pluck('id')->filter()->values()->all();
            $notifMap = [];
            if (! empty($psIds)) {
                $notifs = DB::table('notifications')
                    ->whereIn('primary_screening_id', $psIds)
                    ->whereNull('deleted_at')
                    ->select('primary_screening_id', 'id', 'status', 'priority', 'client_uuid')
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->groupBy('primary_screening_id');
                foreach ($notifs as $psId => $ns) {
                    $notifMap[$psId] = [
                        'notification_id'       => $ns->first()->id,
                        'notification_uuid'     => $ns->first()->client_uuid,
                        'notification_status'   => $ns->first()->status,
                        'notification_priority' => $ns->first()->priority,
                    ];
                }
            }

            // ── Enrich: secondary case status per record (one whereIn query) ─
            $secMap = [];
            if (! empty($psIds)) {
                $secCases = DB::table('secondary_screenings')
                    ->whereIn('primary_screening_id', $psIds)
                    ->whereNull('deleted_at')
                    ->select('primary_screening_id', 'id', 'case_status', 'risk_level', 'syndrome_classification', 'final_disposition')
                    ->orderBy('created_at', 'desc')
                    ->get()
                    ->groupBy('primary_screening_id');
                foreach ($secCases as $psId => $cases) {
                    $secMap[$psId] = [
                        'secondary_case_id'     => $cases->first()->id,
                        'secondary_case_status' => $cases->first()->case_status,
                        'secondary_risk_level'  => $cases->first()->risk_level,
                        'secondary_syndrome'    => $cases->first()->syndrome_classification,
                        'secondary_disposition' => $cases->first()->final_disposition,
                    ];
                }
            }

            // ── Format items ──────────────────────────────────────────────
            $items = $rows->map(function ($row) use ($notifMap, $secMap) {
                $id    = $row->id;
                $tempC = $this->toC($row->temperature_value, $row->temperature_unit);
                return [
                    'id'                    => $id,
                    'client_uuid'           => $row->client_uuid,
                    'record_status'         => $row->record_status,
                    'sync_status'           => $row->sync_status,
                    'gender'                => $row->gender,
                    'traveler_full_name'    => $row->traveler_full_name,
                    'temperature_value'     => $row->temperature_value !== null ? (float) $row->temperature_value : null,
                    'temperature_unit'      => $row->temperature_unit,
                    'temperature_c'         => $tempC,
                    'temperature_flag'      => $this->tempFlag($tempC),
                    'symptoms_present'      => (bool) $row->symptoms_present,
                    'referral_created'      => (bool) $row->referral_created,
                    'captured_at'           => $row->captured_at,
                    'captured_timezone'     => $row->captured_timezone,
                    'poe_code'              => $row->poe_code,
                    'district_code'         => $row->district_code,
                    'province_code'         => $row->province_code,
                    'country_code'          => $row->country_code,
                    'void_reason'           => $row->void_reason,
                    'sync_attempt_count'    => (int) $row->sync_attempt_count,
                    'last_sync_error'       => $row->last_sync_error,
                    'synced_at'             => $row->synced_at,
                    'device_id'             => $row->device_id,
                    'platform'              => $row->platform,
                    'app_version'           => $row->app_version,
                    'record_version'        => (int) $row->record_version,
                    'server_received_at'    => $row->server_received_at,
                    'created_at'            => $row->created_at,
                    'updated_at'            => $row->updated_at,
                    'screener_name'         => $row->screener_name,
                    'screener_role'         => $row->screener_role,
                    'screener_username'     => $row->screener_username,
                    // Enriched notification
                    'notification_id'       => $notifMap[$id]['notification_id'] ?? null,
                    'notification_uuid'     => $notifMap[$id]['notification_uuid'] ?? null,
                    'notification_status'   => $notifMap[$id]['notification_status'] ?? null,
                    'notification_priority' => $notifMap[$id]['notification_priority'] ?? null,
                    // Enriched secondary case
                    'secondary_case_id'     => $secMap[$id]['secondary_case_id'] ?? null,
                    'secondary_case_status' => $secMap[$id]['secondary_case_status'] ?? null,
                    'secondary_risk_level'  => $secMap[$id]['secondary_risk_level'] ?? null,
                    'secondary_syndrome'    => $secMap[$id]['secondary_syndrome'] ?? null,
                    'secondary_disposition' => $secMap[$id]['secondary_disposition'] ?? null,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Primary screening records retrieved.',
                'data'    => [
                    'items'    => $items,
                    'total'    => $total,
                    'per_page' => $perPage,
                    'page'     => $page,
                    'pages'    => (int) ceil($total / max(1, $perPage)),
                    'scope'    => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'primary-records index');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /primary-records/{id}
    // Full record detail with linked notification and secondary case.
    // ═════════════════════════════════════════════════════════════════════

    public function show(Request $request, int $id): JsonResponse
    {
        [$user, $assignment, $errResponse] = $this->resolveContext($request);
        if ($errResponse) {
            return $errResponse;
        }

        try {
            $record = DB::table('primary_screenings as ps')
                ->leftJoin('users as screener', 'screener.id', '=', 'ps.captured_by_user_id')
                ->where('ps.id', $id)
                ->whereNull('ps.deleted_at')
                ->select('ps.*',
                    DB::raw('screener.full_name  as screener_name'),
                    DB::raw('screener.role_key   as screener_role'),
                    DB::raw('screener.username   as screener_username'),
                    DB::raw('screener.phone      as screener_phone')
                )
                ->first();

            if (! $record) {
                return $this->err(404, 'Primary screening record not found.', ['id' => $id]);
            }

            $scopeErr = $this->checkScope($record, $user, $assignment);
            if ($scopeErr) {
                return $scopeErr;
            }

            // ── Linked notification ───────────────────────────────────────
            $notification = DB::table('notifications as n')
                ->leftJoin('users as assigned', 'assigned.id', '=', 'n.assigned_user_id')
                ->where('n.primary_screening_id', $id)
                ->whereNull('n.deleted_at')
                ->select('n.*', DB::raw('assigned.full_name as assigned_user_name'))
                ->orderBy('n.created_at', 'desc')
                ->first();

            // ── Linked secondary case ─────────────────────────────────────
            $secondaryCase = null;
            if ($notification) {
                $secondaryCase = DB::table('secondary_screenings as ss')
                    ->leftJoin('users as opener', 'opener.id', '=', 'ss.opened_by_user_id')
                    ->where('ss.notification_id', $notification->id)
                    ->whereNull('ss.deleted_at')
                    ->select(
                        'ss.id', 'ss.client_uuid', 'ss.case_status', 'ss.risk_level',
                        'ss.syndrome_classification', 'ss.final_disposition',
                        'ss.triage_category', 'ss.emergency_signs_present',
                        'ss.temperature_value', 'ss.temperature_unit',
                        'ss.traveler_full_name', 'ss.traveler_gender',
                        'ss.opened_at', 'ss.dispositioned_at', 'ss.closed_at',
                        'ss.followup_required', 'ss.followup_assigned_level',
                        'ss.officer_notes', 'ss.sync_status',
                        DB::raw('opener.full_name as opener_name')
                    )
                    ->first();
            }

            // ── Linked alert (if any) ─────────────────────────────────────
            $alert = null;
            if ($secondaryCase) {
                $alert = DB::table('alerts')
                    ->where('secondary_screening_id', $secondaryCase->id)
                    ->whereNull('deleted_at')
                    ->select('id', 'alert_code', 'alert_title', 'risk_level', 'status',
                        'routed_to_level', 'generated_from', 'acknowledged_at', 'created_at')
                    ->orderBy('created_at', 'desc')
                    ->first();
            }

            // ── Void authorisation check ──────────────────────────────────
            $canVoid = $this->canVoid($record, $user);

            $tempC = $this->toC($record->temperature_value, $record->temperature_unit);

            return response()->json([
                'success' => true,
                'data'    => [
                    // All primary screening columns
                    'id'                     => $record->id,
                    'client_uuid'            => $record->client_uuid,
                    'idempotency_key'        => $record->idempotency_key,
                    'reference_data_version' => $record->reference_data_version,
                    'server_received_at'     => $record->server_received_at,
                    'country_code'           => $record->country_code,
                    'province_code'          => $record->province_code,
                    'pheoc_code'             => $record->pheoc_code,
                    'district_code'          => $record->district_code,
                    'poe_code'               => $record->poe_code,
                    'captured_by_user_id'    => $record->captured_by_user_id,
                    'gender'                 => $record->gender,
                    'traveler_full_name'     => $record->traveler_full_name,
                    'temperature_value'      => $record->temperature_value !== null ? (float) $record->temperature_value : null,
                    'temperature_unit'       => $record->temperature_unit,
                    'temperature_c'          => $tempC,
                    'temperature_flag'       => $this->tempFlag($tempC),
                    'symptoms_present'       => (bool) $record->symptoms_present,
                    'captured_at'            => $record->captured_at,
                    'captured_timezone'      => $record->captured_timezone,
                    'device_id'              => $record->device_id,
                    'app_version'            => $record->app_version,
                    'platform'               => $record->platform,
                    'referral_created'       => (bool) $record->referral_created,
                    'record_version'         => (int) $record->record_version,
                    'record_status'          => $record->record_status,
                    'void_reason'            => $record->void_reason,
                    'sync_status'            => $record->sync_status,
                    'synced_at'              => $record->synced_at,
                    'sync_attempt_count'     => (int) $record->sync_attempt_count,
                    'last_sync_error'        => $record->last_sync_error,
                    'created_at'             => $record->created_at,
                    'updated_at'             => $record->updated_at,
                    // Screener identity
                    'screener_name'          => $record->screener_name,
                    'screener_role'          => $record->screener_role,
                    'screener_username'      => $record->screener_username,
                    'screener_phone'         => $record->screener_phone,
                    // Related records
                    'notification'           => $notification ? (array) $notification : null,
                    'secondary_case'         => $secondaryCase ? (array) $secondaryCase : null,
                    'alert'                  => $alert ? (array) $alert : null,
                    // Authorisation
                    'can_void'               => $canVoid,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'primary-records show ' . $id);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /primary-records/stats
    // Aggregate statistics for the records dashboard header.
    // Covers: status breakdown, gender breakdown, temperature distribution,
    // symptomatic rate, referral funnel, sync health, and device breakdown.
    // ═════════════════════════════════════════════════════════════════════

    public function stats(Request $request): JsonResponse
    {
        [$user, $assignment, $errResponse] = $this->resolveContext($request);
        if ($errResponse) {
            return $errResponse;
        }

        try {
            // Default window: today. Override with date_from / date_to.
            $dateFrom = $request->filled('date_from')
                ? $request->query('date_from') . ' 00:00:00'
                : now()->startOfDay()->format('Y-m-d H:i:s');
            $dateTo = $request->filled('date_to')
                ? $request->query('date_to') . ' 23:59:59'
                : now()->endOfDay()->format('Y-m-d H:i:s');

            $base = function () use ($user, $assignment) {
                $q = DB::table('primary_screenings as ps')->whereNull('ps.deleted_at');
                $this->applyScope($q, $user, $assignment);
                return $q;
            };

            // Optional POE sub-filter for district+ roles
            $poeFilter = ($request->filled('poe_code') && ! in_array($user->role_key, self::POE_LEVEL_ROLES, true))
                ? $request->query('poe_code') : null;

            $windowed = function () use ($base, $dateFrom, $dateTo, $poeFilter) {
                $q = $base()->whereBetween('ps.captured_at', [$dateFrom, $dateTo]);
                if ($poeFilter) {
                    $q->where('ps.poe_code', $poeFilter);
                }

                return $q;
            };

            // ── Total counts (windowed) ───────────────────────────────────
            $totalWindowed = $windowed()->count();
            $totalAllTime  = ($poeFilter ? $base()->where('ps.poe_code', $poeFilter) : $base())->count();

            // ── Record status breakdown (all-time) ────────────────────────
            $statusCounts = ($poeFilter ? $base()->where('ps.poe_code', $poeFilter) : $base())
                ->groupBy('ps.record_status')
                ->select('ps.record_status', DB::raw('COUNT(*) as cnt'))
                ->get()->pluck('cnt', 'record_status')->all();

            // ── Gender breakdown (windowed) ───────────────────────────────
            $genderCounts = $windowed()
                ->where('ps.record_status', 'COMPLETED')
                ->groupBy('ps.gender')
                ->select('ps.gender', DB::raw('COUNT(*) as cnt'))
                ->get()->pluck('cnt', 'gender')->all();

            // ── Symptomatic rate (windowed, COMPLETED only) ───────────────
            $symptomCounts = $windowed()
                ->where('ps.record_status', 'COMPLETED')
                ->groupBy('ps.symptoms_present')
                ->select('ps.symptoms_present', DB::raw('COUNT(*) as cnt'))
                ->get()->pluck('cnt', 'symptoms_present')->all();

            $totalCompleted   = ($symptomCounts[0] ?? 0) + ($symptomCounts[1] ?? 0);
            $totalSymptomatic = (int) ($symptomCounts[1] ?? 0);
            $symptomaticRate  = $totalCompleted > 0
                ? round(($totalSymptomatic / $totalCompleted) * 100, 1)
                : 0.0;

            // ── Referral funnel (windowed) ────────────────────────────────
            $referralCreated = $windowed()
                ->where('ps.symptoms_present', 1)
                ->where('ps.referral_created', 1)
                ->where('ps.record_status', 'COMPLETED')
                ->count();

            // How many of those referrals were picked up (notification IN_PROGRESS or CLOSED)
            $referralsPickedUp = DB::table('notifications as n')
                ->join('primary_screenings as ps2', 'ps2.id', '=', 'n.primary_screening_id')
                ->whereNull('n.deleted_at')
                ->whereNull('ps2.deleted_at')
                ->where('ps2.record_status', 'COMPLETED')
                ->whereBetween('ps2.captured_at', [$dateFrom, $dateTo])
                ->whereIn('n.status', ['IN_PROGRESS', 'CLOSED']);

            if ($poeFilter) {
                $referralsPickedUp->where('ps2.poe_code', $poeFilter);
            }
            $this->applyScope($referralsPickedUp, $user, $assignment, 'ps2');
            $referralsPickedUp = $referralsPickedUp->count();

            // ── Temperature distribution (windowed, temp recorded only) ───
            $tempStats = $windowed()
                ->whereNotNull('ps.temperature_value')
                ->where('ps.record_status', 'COMPLETED')
                ->selectRaw('
                    COUNT(*)                                     as count_with_temp,
                    AVG(ps.temperature_value)                    as avg_temp,
                    MIN(ps.temperature_value)                    as min_temp,
                    MAX(ps.temperature_value)                    as max_temp,
                    SUM(CASE WHEN ps.temperature_value >= ? AND ps.temperature_unit = "C" THEN 1
                             WHEN ps.temperature_value >= ? AND ps.temperature_unit = "F" THEN 1 ELSE 0 END) as fever_count,
                    SUM(CASE WHEN ps.temperature_value >= ? AND ps.temperature_unit = "C" THEN 1
                             WHEN ps.temperature_value >= ? AND ps.temperature_unit = "F" THEN 1 ELSE 0 END) as critical_fever_count
                ', [
                    self::TEMP_HIGH_C,
                    $this->toF(self::TEMP_HIGH_C),
                    self::TEMP_CRITICAL_C,
                    $this->toF(self::TEMP_CRITICAL_C),
                ])
                ->first();

            // ── Sync health ───────────────────────────────────────────────
            $syncCounts = ($poeFilter ? $base()->where('ps.poe_code', $poeFilter) : $base())
                ->groupBy('ps.sync_status')
                ->select('ps.sync_status', DB::raw('COUNT(*) as cnt'))
                ->get()->pluck('cnt', 'sync_status')->all();

            // ── Device + platform breakdown (windowed) ────────────────────
            $platformCounts = $windowed()
                ->groupBy('ps.platform')
                ->select('ps.platform', DB::raw('COUNT(*) as cnt'))
                ->get()->map(fn($r) => ['platform' => $r->platform, 'count' => (int) $r->cnt])
                ->values();

            $deviceCounts = $windowed()
                ->groupBy('ps.device_id')
                ->select('ps.device_id', DB::raw('COUNT(*) as cnt'))
                ->orderByDesc('cnt')
                ->limit(10)
                ->get()->map(fn($r) => ['device_id' => $r->device_id, 'count' => (int) $r->cnt])
                ->values();

            // ── Today vs yesterday (for delta indicators) ─────────────────
            $today     = now()->format('Y-m-d');
            $yesterday = now()->subDay()->format('Y-m-d');
            $poeClause = $poeFilter;

            $todayBase = $base()
                ->when($poeClause, fn($q) => $q->where('ps.poe_code', $poeClause))
                ->whereDate('ps.captured_at', $today)
                ->where('ps.record_status', 'COMPLETED');

            $yesterdayBase = $base()
                ->when($poeClause, fn($q) => $q->where('ps.poe_code', $poeClause))
                ->whereDate('ps.captured_at', $yesterday)
                ->where('ps.record_status', 'COMPLETED');

            $todayTotal       = (clone $todayBase)->count();
            $todaySymptomatic = (clone $todayBase)->where('ps.symptoms_present', 1)->count();
            $yesterdayTotal   = (clone $yesterdayBase)->count();

            // ── Open referrals still awaiting secondary officer ───────────
            $openReferrals = DB::table('notifications as n')
                ->join('primary_screenings as ps3', 'ps3.id', '=', 'n.primary_screening_id')
                ->whereNull('n.deleted_at')
                ->where('n.status', 'OPEN')
                ->where('n.notification_type', 'SECONDARY_REFERRAL');
            $this->applyScope($openReferrals, $user, $assignment, 'ps3');
            if ($poeFilter) {
                $openReferrals->where('ps3.poe_code', $poeFilter);
            }

            $openReferrals = $openReferrals->count();

            return response()->json([
                'success' => true,
                'data'    => [
                    'window'      => ['date_from' => $dateFrom, 'date_to' => $dateTo],
                    'all_time'    => [
                        'total'     => $totalAllTime,
                        'completed' => (int) ($statusCounts['COMPLETED'] ?? 0),
                        'voided'    => (int) ($statusCounts['VOIDED'] ?? 0),
                        'unsynced'  => (int) ($syncCounts['UNSYNCED'] ?? 0),
                        'failed'    => (int) ($syncCounts['FAILED'] ?? 0),
                        'synced'    => (int) ($syncCounts['SYNCED'] ?? 0),
                    ],
                    'windowed'    => [
                        'total'                => $totalWindowed,
                        'total_symptomatic'    => $totalSymptomatic,
                        'total_asymptomatic'   => (int) ($symptomCounts[0] ?? 0),
                        'symptomatic_rate'     => $symptomaticRate,
                        'referrals_created'    => $referralCreated,
                        'referrals_picked_up'  => $referralsPickedUp,
                        'referral_pickup_rate' => $referralCreated > 0
                            ? round(($referralsPickedUp / $referralCreated) * 100, 1) : 0.0,
                    ],
                    'today'       => [
                        'total'          => $todayTotal,
                        'symptomatic'    => $todaySymptomatic,
                        'vs_yesterday'   => $todayTotal - $yesterdayTotal,
                        'open_referrals' => $openReferrals,
                    ],
                    'by_gender'   => [
                        'MALE'    => (int) ($genderCounts['MALE'] ?? 0),
                        'FEMALE'  => (int) ($genderCounts['FEMALE'] ?? 0),
                        'OTHER'   => (int) ($genderCounts['OTHER'] ?? 0),
                        'UNKNOWN' => (int) ($genderCounts['UNKNOWN'] ?? 0),
                    ],
                    'temperature' => [
                        'count_with_temp'      => (int) ($tempStats->count_with_temp ?? 0),
                        'avg_temp'             => $tempStats->avg_temp !== null ? round((float) $tempStats->avg_temp, 2) : null,
                        'min_temp'             => $tempStats->min_temp !== null ? (float) $tempStats->min_temp : null,
                        'max_temp'             => $tempStats->max_temp !== null ? (float) $tempStats->max_temp : null,
                        'fever_count'          => (int) ($tempStats->fever_count ?? 0),
                        'critical_fever_count' => (int) ($tempStats->critical_fever_count ?? 0),
                    ],
                    'sync_health' => [
                        'synced'   => (int) ($syncCounts['SYNCED'] ?? 0),
                        'unsynced' => (int) ($syncCounts['UNSYNCED'] ?? 0),
                        'failed'   => (int) ($syncCounts['FAILED'] ?? 0),
                    ],
                    'by_platform' => $platformCounts,
                    'by_device'   => $deviceCounts,
                    'scope'       => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'primary-records stats');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /primary-records/heatmap
    // Returns screening volume by time unit for heatmap/chart visualisation.
    // group_by=hour   → 24 buckets (hour of day, across the date window)
    // group_by=day    → one row per calendar day in the date window
    // group_by=weekday → 7 buckets (Mon–Sun) with averages
    // ═════════════════════════════════════════════════════════════════════

    public function heatmap(Request $request): JsonResponse
    {
        [$user, $assignment, $errResponse] = $this->resolveContext($request);
        if ($errResponse) {
            return $errResponse;
        }

        $groupBy = strtolower((string) $request->query('group_by', 'day'));
        if (! in_array($groupBy, self::VALID_GROUP_BY, true)) {
            return $this->err(422, "group_by must be one of: " . implode(', ', self::VALID_GROUP_BY));
        }

        $dateFrom = $request->filled('date_from')
            ? $request->query('date_from') . ' 00:00:00'
            : now()->subDays(30)->startOfDay()->format('Y-m-d H:i:s');
        $dateTo = $request->filled('date_to')
            ? $request->query('date_to') . ' 23:59:59'
            : now()->endOfDay()->format('Y-m-d H:i:s');

        try {
            $q = DB::table('primary_screenings as ps')
                ->whereNull('ps.deleted_at')
                ->where('ps.record_status', 'COMPLETED')
                ->whereBetween('ps.captured_at', [$dateFrom, $dateTo]);
            $this->applyScope($q, $user, $assignment);

            if ($groupBy === 'hour') {
                $rows = $q->selectRaw('
                    HOUR(ps.captured_at)               as bucket,
                    COUNT(*)                            as total,
                    SUM(ps.symptoms_present)            as symptomatic,
                    SUM(1 - ps.symptoms_present)        as asymptomatic
                ')->groupByRaw('HOUR(ps.captured_at)')->orderByRaw('bucket')->get();

                // Fill missing hours with zeros
                $byHour = $rows->keyBy('bucket');
                $data   = collect(range(0, 23))->map(fn($h) => [
                    'bucket'       => $h,
                    'label'        => sprintf('%02d:00', $h),
                    'total'        => (int) ($byHour[$h]->total ?? 0),
                    'symptomatic'  => (int) ($byHour[$h]->symptomatic ?? 0),
                    'asymptomatic' => (int) ($byHour[$h]->asymptomatic ?? 0),
                ]);

            } elseif ($groupBy === 'weekday') {
                // DAYOFWEEK: 1=Sunday, 2=Monday … 7=Saturday
                $rows = $q->selectRaw('
                    DAYOFWEEK(ps.captured_at)           as dow,
                    COUNT(*)                            as total,
                    SUM(ps.symptoms_present)            as symptomatic,
                    COUNT(DISTINCT DATE(ps.captured_at)) as day_count
                ')->groupByRaw('DAYOFWEEK(ps.captured_at)')->orderByRaw('dow')->get();

                $dowNames = [1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat'];
                $byDow    = $rows->keyBy('dow');
                $data     = collect(range(1, 7))->map(fn($d) => [
                    'bucket'      => $d,
                    'label'       => $dowNames[$d],
                    'total'       => (int) ($byDow[$d]->total ?? 0),
                    'symptomatic' => (int) ($byDow[$d]->symptomatic ?? 0),
                    'avg_per_day' => $byDow[$d]->day_count > 0
                        ? round($byDow[$d]->total / $byDow[$d]->day_count, 1) : 0.0,
                ]);

            } else {
                // day — one row per calendar date
                $rows = $q->selectRaw('
                    DATE(ps.captured_at)                as bucket,
                    COUNT(*)                            as total,
                    SUM(ps.symptoms_present)            as symptomatic,
                    SUM(1 - ps.symptoms_present)        as asymptomatic,
                    SUM(ps.referral_created)            as referrals
                ')->groupByRaw('DATE(ps.captured_at)')->orderByRaw('bucket')->get();

                $data = $rows->map(fn($r) => [
                    'bucket'       => (string) $r->bucket,
                    'label'        => (string) $r->bucket,
                    'total'        => (int) $r->total,
                    'symptomatic'  => (int) $r->symptomatic,
                    'asymptomatic' => (int) $r->asymptomatic,
                    'referrals'    => (int) $r->referrals,
                ])->values();
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'group_by'  => $groupBy,
                    'date_from' => $dateFrom,
                    'date_to'   => $dateTo,
                    'buckets'   => $data,
                    'scope'     => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'primary-records heatmap');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /primary-records/trend
    // Daily rolling totals for the last N days. Used for sparklines and
    // trend charts on the dashboard. Returns one row per day, always
    // filling days with zero screenings so the chart has no gaps.
    // ═════════════════════════════════════════════════════════════════════

    public function trend(Request $request): JsonResponse
    {
        [$user, $assignment, $errResponse] = $this->resolveContext($request);
        if ($errResponse) {
            return $errResponse;
        }

        $days = min((int) $request->query('days', 30), 365);
        if ($days < 1) {
            $days = 30;
        }

        $poeFilter = ($request->filled('poe_code') && ! in_array($user->role_key, self::POE_LEVEL_ROLES, true))
            ? $request->query('poe_code') : null;

        try {
            $from = now()->subDays($days - 1)->startOfDay()->format('Y-m-d H:i:s');
            $to   = now()->endOfDay()->format('Y-m-d H:i:s');

            $q = DB::table('primary_screenings as ps')
                ->whereNull('ps.deleted_at')
                ->where('ps.record_status', 'COMPLETED')
                ->whereBetween('ps.captured_at', [$from, $to])
                ->when($poeFilter, fn($q) => $q->where('ps.poe_code', $poeFilter));
            $this->applyScope($q, $user, $assignment);

            $rows = $q->selectRaw('
                DATE(ps.captured_at)     as date,
                COUNT(*)                 as total,
                SUM(ps.symptoms_present) as symptomatic,
                SUM(ps.referral_created) as referrals
            ')->groupByRaw('DATE(ps.captured_at)')->orderByRaw('date')->get()->keyBy('date');

            // Fill all N days including zero-count days
            $series = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date     = now()->subDays($i)->format('Y-m-d');
                $row      = $rows[$date] ?? null;
                $series[] = [
                    'date'         => $date,
                    'total'        => (int) ($row?->total ?? 0),
                    'symptomatic'  => (int) ($row?->symptomatic ?? 0),
                    'asymptomatic' => (int) (($row?->total ?? 0) - ($row?->symptomatic ?? 0)),
                    'referrals'    => (int) ($row?->referrals ?? 0),
                ];
            }

            // 7-day rolling average for smooth trend line
            $withAvg = [];
            foreach ($series as $i => $day) {
                $window    = array_slice($series, max(0, $i - 6), 7);
                $avg7      = round(array_sum(array_column($window, 'total')) / count($window), 1);
                $withAvg[] = array_merge($day, ['avg7' => $avg7]);
            }

            return response()->json([
                'success' => true,
                'data'    => [
                    'days'         => $days,
                    'date_from'    => $from,
                    'date_to'      => $to,
                    'series'       => $withAvg,
                    'total_window' => array_sum(array_column($series, 'total')),
                    'scope'        => $this->scopeSummary($user, $assignment),
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'primary-records trend');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /primary-records/export
    // Flat CSV-ready array of up to 10,000 records. No pagination.
    // Requires date_from + date_to. Intended for data officers to pull
    // records into Excel / reporting tools.
    // ═════════════════════════════════════════════════════════════════════

    public function export(Request $request): JsonResponse
    {
        [$user, $assignment, $errResponse] = $this->resolveContext($request);
        if ($errResponse) {
            return $errResponse;
        }

        if (! $request->filled('date_from') || ! $request->filled('date_to')) {
            return $this->err(422, 'date_from and date_to are both required for export.', [
                'hint' => 'Supply date_from=YYYY-MM-DD&date_to=YYYY-MM-DD. Max range: 90 days.',
            ]);
        }

        $dateFrom = $request->query('date_from');
        $dateTo   = $request->query('date_to');

        // Limit export window to 90 days to prevent runaway queries
        $diffDays = (int) now()->parse($dateFrom)->diffInDays(now()->parse($dateTo));
        if ($diffDays > 90) {
            return $this->err(422, 'Export window cannot exceed 90 days.', [
                'requested_days' => $diffDays,
                'max_days'       => 90,
            ]);
        }

        try {
            $q = DB::table('primary_screenings as ps')
                ->leftJoin('users as s', 's.id', '=', 'ps.captured_by_user_id')
                ->whereNull('ps.deleted_at')
                ->where('ps.captured_at', '>=', $dateFrom . ' 00:00:00')
                ->where('ps.captured_at', '<=', $dateTo . ' 23:59:59');

            $this->applyScope($q, $user, $assignment);
            $this->applyEnumFilter($q, $request, 'ps.gender', 'gender', self::VALID_GENDERS);
            $this->applyEnumFilter($q, $request, 'ps.record_status', 'record_status', self::VALID_STATUSES);

            if ($request->filled('symptoms_present') && in_array($request->query('symptoms_present'), ['0', '1'], true)) {
                $q->where('ps.symptoms_present', (int) $request->query('symptoms_present'));
            }

            $count = (clone $q)->count();

            $rows = $q->orderBy('ps.captured_at')
                ->select([
                    'ps.id', 'ps.client_uuid', 'ps.poe_code', 'ps.district_code',
                    'ps.province_code', 'ps.country_code', 'ps.gender',
                    'ps.traveler_full_name', 'ps.temperature_value', 'ps.temperature_unit',
                    'ps.symptoms_present', 'ps.referral_created', 'ps.record_status',
                    'ps.void_reason', 'ps.captured_at', 'ps.captured_timezone',
                    'ps.device_id', 'ps.platform', 'ps.app_version',
                    'ps.sync_status', 'ps.synced_at', 'ps.created_at',
                    DB::raw('s.full_name as screener_name'),
                    DB::raw('s.username  as screener_username'),
                ])
                ->limit(self::EXPORT_LIMIT)
                ->get()
                ->map(function ($r) {
                    $tempC = $this->toC($r->temperature_value, $r->temperature_unit);
                    return [
                        'id'                 => $r->id,
                        'client_uuid'        => $r->client_uuid,
                        'poe_code'           => $r->poe_code,
                        'district_code'      => $r->district_code,
                        'province_code'      => $r->province_code,
                        'country_code'       => $r->country_code,
                        'gender'             => $r->gender,
                        'traveler_full_name' => $r->traveler_full_name,
                        'temperature_value'  => $r->temperature_value !== null ? (float) $r->temperature_value : null,
                        'temperature_unit'   => $r->temperature_unit,
                        'temperature_c'      => $tempC,
                        'temperature_flag'   => $this->tempFlag($tempC),
                        'symptoms_present'   => (bool) $r->symptoms_present,
                        'referral_created'   => (bool) $r->referral_created,
                        'record_status'      => $r->record_status,
                        'void_reason'        => $r->void_reason,
                        'captured_at'        => $r->captured_at,
                        'captured_timezone'  => $r->captured_timezone,
                        'device_id'          => $r->device_id,
                        'platform'           => $r->platform,
                        'app_version'        => $r->app_version,
                        'sync_status'        => $r->sync_status,
                        'synced_at'          => $r->synced_at,
                        'screener_name'      => $r->screener_name,
                        'screener_username'  => $r->screener_username,
                    ];
                })->values();

            return response()->json([
                'success'         => true,
                'message'         => 'Export data retrieved.',
                'total_in_window' => $count,
                'exported'        => $rows->count(),
                'truncated'       => $count > self::EXPORT_LIMIT,
                'date_from'       => $dateFrom,
                'date_to'         => $dateTo,
                'data'            => $rows,
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'primary-records export');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // PATCH /primary-records/{id}/void
    // Void a primary screening record. Sets record_status = VOIDED.
    // Automatically closes the linked OPEN notification.
    // Does NOT touch a secondary case already IN_PROGRESS or DISPOSITIONED.
    //
    // Authorisation rules (IHR business spec §6.5):
    //   • Creating officer — within 24 hours of capture.
    //   • POE_ADMIN at the same POE — any time.
    //   • NATIONAL_ADMIN — any time.
    // ═════════════════════════════════════════════════════════════════════

    public function void(Request $request, int $id): JsonResponse
    {
        [$user, $assignment, $errResponse] = $this->resolveContext($request);
        if ($errResponse) {
            return $errResponse;
        }

        $voidReason = trim((string) $request->input('void_reason', ''));
        if (strlen($voidReason) < 10) {
            return $this->err(422, 'void_reason is required and must be at least 10 characters.', [
                'received_length' => strlen($voidReason),
                'minimum_length'  => 10,
            ]);
        }

        try {
            $record = DB::table('primary_screenings')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();

            if (! $record) {
                return $this->err(404, 'Primary screening record not found.', ['id' => $id]);
            }

            // Geographic scope check
            $scopeErr = $this->checkScope($record, $user, $assignment);
            if ($scopeErr) {
                return $scopeErr;
            }

            // Already voided
            if ($record->record_status === 'VOIDED') {
                return $this->err(409, 'Record is already voided.', [
                    'id'          => $id,
                    'void_reason' => $record->void_reason,
                ]);
            }

            // Authorisation check
            if (! $this->canVoid($record, $user)) {
                return $this->err(403, 'Not authorised to void this record.', [
                    'reason'              => 'Only the creating officer (within 24 hours), POE_ADMIN at this POE, or NATIONAL_ADMIN may void a primary screening record.',
                    'record_captured_by'  => $record->captured_by_user_id,
                    'your_user_id'        => $user->id,
                    'your_role'           => $user->role_key,
                    'hours_since_capture' => round((time() - strtotime($record->captured_at)) / 3600, 1),
                ]);
            }

            $now = now()->format('Y-m-d H:i:s');

            DB::transaction(function () use ($id, $record, $voidReason, $now, $user) {
                // Void the primary screening record
                DB::table('primary_screenings')
                    ->where('id', $id)
                    ->update([
                        'record_status'  => 'VOIDED',
                        'void_reason'    => $voidReason,
                        'record_version' => DB::raw('record_version + 1'),
                        'updated_at'     => $now,
                    ]);

                // Close linked OPEN notification
                // Business rule: OPEN → CLOSED automatically on void.
                // IN_PROGRESS/DISPOSITIONED notifications are NOT auto-closed
                // (secondary officer must close their case manually — §6.5).
                $closedReason = 'Primary screening voided: ' . mb_substr($voidReason, 0, 180) .
                ' (Voided by user ' . $user->id . ' at ' . $now . ')';

                DB::table('notifications')
                    ->where('primary_screening_id', $id)
                    ->where('status', 'OPEN')
                    ->whereNull('deleted_at')
                    ->update([
                        'status'         => 'CLOSED',
                        'reason_text'    => mb_substr($closedReason, 0, 255),
                        'closed_at'      => $now,
                        'record_version' => DB::raw('record_version + 1'),
                        'updated_at'     => $now,
                    ]);
            });

            // Reload for response
            $voided = DB::table('primary_screenings')->where('id', $id)->first();

            return response()->json([
                'success' => true,
                'message' => 'Primary screening record voided successfully. Linked OPEN referral closed.',
                'data'    => [
                    'id'            => $voided->id,
                    'record_status' => $voided->record_status,
                    'void_reason'   => $voided->void_reason,
                    'updated_at'    => $voided->updated_at,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'primary-records void ' . $id);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Resolve user + assignment from request query user_id.
     * Returns [user, assignment, errorResponse] — errorResponse is non-null on failure.
     *
     * @return array{0: object|null, 1: object|null, 2: JsonResponse|null}
     */
    private function resolveContext(Request $request): array
    {
        $userId = (int) $request->query('user_id', $request->input('user_id', 0));
        if ($userId <= 0) {
            return [null, null, $this->err(422, 'user_id is required.', [
                'hint' => 'Append ?user_id={AUTH_DATA.id} to the URL, or include in the request body.',
            ])];
        }
        $user = $this->resolveUser($userId);
        if (! $user) {
            return [null, null, $this->err(404, 'User not found.', ['user_id' => $userId])];
        }
        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return [null, null, $this->err(403, 'No active geographic assignment.', ['user_id' => $userId])];
        }
        return [$user, $assignment, null];
    }

    /**
     * Apply geographic scope to a query builder based on user role + assignment.
     * Server-side only — never trusts client-sent scope parameters.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param string $alias  Table alias on the primary_screenings table (default 'ps')
     */
    private function applyScope(object $query, object $user, object $assignment, string $alias = 'ps'): void
    {
        $role = $user->role_key ?? '';
        if (in_array($role, self::POE_LEVEL_ROLES, true)) {
            $query->where("{$alias}.poe_code", $assignment->poe_code);
        } elseif ($role === 'DISTRICT_SUPERVISOR') {
            $query->where("{$alias}.district_code", $assignment->district_code);
        } elseif ($role === 'PHEOC_OFFICER' || $role === 'PHEOC_ADMIN') {
            // PHEOC is the coordination arm of a province. Per PheocScope, province_code
            // and pheoc_code are aliases — prefer whichever the assignment has populated.
            $scopeCode = $assignment->province_code ?? $assignment->pheoc_code ?? null;
            $query->where("{$alias}.country_code", $assignment->country_code);
            if ($scopeCode !== null) {
                $query->where(function ($q) use ($alias, $scopeCode) {
                    $q->where("{$alias}.province_code", $scopeCode)
                      ->orWhere("{$alias}.pheoc_code", $scopeCode);
                });
            }
        } else {
            // NATIONAL_ADMIN and any other elevated roles
            $query->where("{$alias}.country_code", $assignment->country_code);
        }
    }

    /**
     * Check geographic scope for a specific record.
     * Returns 403 JsonResponse if denied, null if permitted.
     */
    private function checkScope(object $record, object $user, object $assignment): ?JsonResponse
    {
        $role = $user->role_key ?? '';
        if (in_array($role, self::POE_LEVEL_ROLES, true) && $record->poe_code !== $assignment->poe_code) {
            return $this->err(403, 'Access denied — record belongs to a different POE.', [
                'record_poe' => $record->poe_code, 'your_poe' => $assignment->poe_code,
            ]);
        }
        if ($role === 'DISTRICT_SUPERVISOR' && $record->district_code !== $assignment->district_code) {
            return $this->err(403, 'Access denied — record is in a different district.', [
                'record_district' => $record->district_code, 'your_district' => $assignment->district_code,
            ]);
        }
        if ($role === 'PHEOC_OFFICER' || $role === 'PHEOC_ADMIN') {
            $scopeCode = $assignment->province_code ?? $assignment->pheoc_code ?? null;
            $recordCode = $record->province_code ?? $record->pheoc_code ?? null;
            if ($scopeCode !== null && $scopeCode !== $recordCode) {
                return $this->err(403, 'Access denied — record is in a different PHEOC region.', [
                    'record_province' => $record->province_code, 'record_pheoc' => $record->pheoc_code,
                    'your_province'   => $assignment->province_code, 'your_pheoc' => $assignment->pheoc_code,
                ]);
            }
        }
        if ($record->country_code !== $assignment->country_code) {
            return $this->err(403, 'Access denied — record is in a different country.', [
                'record_country' => $record->country_code, 'your_country' => $assignment->country_code,
            ]);
        }
        return null;
    }

    /**
     * Can the given user void this primary screening record?
     *
     * NATIONAL_ADMIN   — unconditional.
     * PHEOC_OFFICER    — any record in their country scope (PHEOC jurisdiction).
     * DISTRICT_SUPERVISOR — any record in their district.
     * POE_ADMIN        — any record at their POE.
     * Creating officer — within 24h of capture (IHR §6.5).
     */
    private function canVoid(object $record, object $user): bool
    {
        $role = $user->role_key ?? '';

        if ($role === 'NATIONAL_ADMIN') {
            return true;
        }
        if ($role === 'PHEOC_OFFICER') {
            return ($record->country_code ?? null) === ($user->country_code ?? null);
        }
        if ($role === 'DISTRICT_SUPERVISOR') {
            return ($record->district_code ?? null) === ($user->district_code ?? null);
        }
        if ($role === 'POE_ADMIN') {
            return ($record->poe_code ?? null) === ($user->poe_code ?? null);
        }

        // Creating officer within 24 hours
        if ((int) $record->captured_by_user_id === (int) $user->id) {
            $hoursSince = (time() - strtotime($record->captured_at)) / 3600;
            return $hoursSince <= 24;
        }
        return false;
    }

    /** Apply a validated enum filter to the query. */
    private function applyEnumFilter(object $query, Request $request, string $column, string $param, array $valid): void
    {
        if (! $request->filled($param)) {
            return;
        }

        $val = strtoupper((string) $request->query($param));
        if (in_array($val, $valid, true)) {
            $query->where($column, $val);
        }
    }

    /** Summarise the geographic scope applied to this request (for client debugging). */
    private function scopeSummary(object $user, object $assignment): array
    {
        return [
            'role'          => $user->role_key,
            'poe_code'      => $assignment->poe_code ?? null,
            'district_code' => $assignment->district_code ?? null,
            'province_code' => $assignment->province_code ?? null,
            'pheoc_code'    => $assignment->pheoc_code ?? null,
            'country_code'  => $assignment->country_code,
        ];
    }

    /** Convert temperature to Celsius. Returns null if no value. */
    private function toC(?string $val, ?string $unit): ?float
    {
        if ($val === null) {
            return null;
        }

        $v = (float) $val;
        return $unit === 'F' ? round(($v - 32) * 5 / 9, 2) : round($v, 2);
    }

    /** Convert Celsius to Fahrenheit. */
    private function toF(float $celsius): float
    {
        return round($celsius * 9 / 5 + 32, 2);
    }

    /**
     * Temperature flag string for UI colour-coding.
     * Returned in both index() and show() so the Vue view never re-implements this logic.
     */
    private function tempFlag(?float $tempC): ?string
    {
        if ($tempC === null) {
            return null;
        }

        if ($tempC >= self::TEMP_CRITICAL_C) {
            return 'CRITICAL';
        }

        if ($tempC >= self::TEMP_HIGH_C) {
            return 'HIGH';
        }

        if ($tempC < 36.0) {
            return 'LOW';
        }

        return 'NORMAL';
    }

    private function resolveUser(int $id): ?object
    {
        return DB::table('users')->where('id', $id)->first() ?: null;
    }

    private function resolvePrimaryAssignment(int $userId): ?object
    {
        return DB::table('user_assignments')
            ->where('user_id', $userId)
            ->where('is_primary', 1)
            ->where('is_active', 1)
            ->where(function ($q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            })
            ->first() ?: null;
    }

    private function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error' => $detail], $status);
    }

    private function serverError(Throwable $e, string $context): JsonResponse
    {
        Log::error("[PrimaryRecords][ERROR] {$context}", [
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
