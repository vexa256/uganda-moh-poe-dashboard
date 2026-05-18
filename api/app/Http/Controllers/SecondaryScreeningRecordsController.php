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
 * ║  SecondaryScreeningRecordsController                                         ║
 * ║  ECSA-HC POE Sentinel · WHO/IHR 2005 Compliant                              ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  Purpose:   Read-only records view for secondary screening cases.            ║
 * ║             Separate from SecondaryScreeningController (write path).         ║
 * ║             Designed for the SecondaryScreeningRecords.vue list/detail UI.  ║
 * ║                                                                              ║
 * ║  Database:  poe_2026  (DB:: facade — NO Eloquent models anywhere)           ║
 * ║  Auth:      NONE — all routes are publicly accessible by design.             ║
 * ║             Auth middleware will be added as a separate layer later.         ║
 * ║             Do NOT add Authorization: Bearer checks anywhere in this file.   ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  GEOGRAPHIC SCOPE ENFORCEMENT (enforced server-side, never trusted from      ║
 * ║  client):                                                                    ║
 * ║                                                                              ║
 * ║    POE_PRIMARY, POE_SECONDARY, POE_DATA_OFFICER, POE_ADMIN                  ║
 * ║      → WHERE ss.poe_code = assignment.poe_code                              ║
 * ║                                                                              ║
 * ║    DISTRICT_SUPERVISOR                                                       ║
 * ║      → WHERE ss.district_code = assignment.district_code                    ║
 * ║                                                                              ║
 * ║    PHEOC_OFFICER                                                             ║
 * ║      → WHERE ss.pheoc_code = assignment.pheoc_code                          ║
 * ║                                                                              ║
 * ║    NATIONAL_ADMIN                                                            ║
 * ║      → WHERE ss.country_code = assignment.country_code                      ║
 * ║                                                                              ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  ROUTES (paste into routes/api.php — MUST be declared BEFORE any           ║
 * ║  wildcard {id} routes in the secondary-screenings prefix):                  ║
 * ║                                                                              ║
 * ║  use App\Http\Controllers\SecondaryScreeningRecordsController as SSRC;     ║
 * ║                                                                              ║
 * ║  // ── RECORDS VIEW ROUTES (declare BEFORE /secondary-screenings/{id}) ──── ║
 * ║  Route::get('/screening-records',          [SSRC::class, 'index']);          ║
 * ║  Route::get('/screening-records/stats',    [SSRC::class, 'stats']);          ║
 * ║  Route::get('/screening-records/{id}',     [SSRC::class, 'show']);           ║
 * ║                                                                              ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  QUERY PARAMETERS:                                                           ║
 * ║                                                                              ║
 * ║  GET /screening-records                                                      ║
 * ║    user_id        (required) — AUTH_DATA.id                                 ║
 * ║    page           (int, default 1)                                           ║
 * ║    per_page       (int, default 25, max 100)                                 ║
 * ║    search         (string) — searches traveler_full_name, client_uuid       ║
 * ║    case_status    (OPEN|IN_PROGRESS|DISPOSITIONED|CLOSED)                   ║
 * ║    risk_level     (LOW|MEDIUM|HIGH|CRITICAL)                                 ║
 * ║    syndrome       (ILI|SARI|AWD|...)                                         ║
 * ║    final_disposition (RELEASED|DELAYED|QUARANTINED|...)                      ║
 * ║    date_from      (YYYY-MM-DD) — filters on opened_at                       ║
 * ║    date_to        (YYYY-MM-DD) — filters on opened_at                       ║
 * ║    poe_code       (string) — only honoured for district+ roles               ║
 * ║    sort_by        (opened_at|risk_level|case_status, default opened_at)     ║
 * ║    sort_dir       (asc|desc, default desc)                                   ║
 * ║                                                                              ║
 * ║  GET /screening-records/stats                                                ║
 * ║    user_id        (required)                                                 ║
 * ║    date_from / date_to (optional window, default last 30 days)              ║
 * ║                                                                              ║
 * ║  GET /screening-records/{id}                                                 ║
 * ║    user_id        (required)                                                 ║
 * ║    — Returns full case detail with all 6 child tables + notification +       ║
 * ║      primary screening + alert                                               ║
 * ║                                                                              ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */
final class SecondaryScreeningRecordsController extends Controller
{
    // ═════════════════════════════════════════════════════════════════════
    // CONSTANTS
    // ═════════════════════════════════════════════════════════════════════

    private const MAX_PER_PAGE     = 100;
    private const DEFAULT_PER_PAGE = 25;

    private const VALID_CASE_STATUSES = ['OPEN', 'IN_PROGRESS', 'DISPOSITIONED', 'CLOSED'];
    private const VALID_RISK_LEVELS   = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    private const VALID_SYNDROMES     = [
        'ILI', 'SARI', 'AWD', 'BLOODY_DIARRHEA', 'VHF',
        'RASH_FEVER', 'JAUNDICE', 'NEUROLOGICAL', 'MENINGITIS', 'OTHER', 'NONE',
    ];
    private const VALID_DISPOSITIONS = [
        'RELEASED', 'DELAYED', 'QUARANTINED', 'ISOLATED',
        'REFERRED', 'TRANSFERRED', 'DENIED_BOARDING', 'OTHER',
    ];
    private const VALID_SORT_FIELDS = ['opened_at', 'risk_level', 'case_status', 'dispositioned_at', 'closed_at'];
    private const VALID_SORT_DIRS   = ['asc', 'desc'];

    // Roles that are scoped to a single POE
    private const POE_LEVEL_ROLES = ['POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_ADMIN', 'SCREENER'];

    // ═════════════════════════════════════════════════════════════════════
    // GET /screening-records
    // Paginated list of secondary screening cases, scoped by role.
    // Enriched with opener name, notification status, primary screening
    // summary, and top suspected disease for the list row.
    // ═════════════════════════════════════════════════════════════════════

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required.', [
                'hint' => 'Append ?user_id={AUTH_DATA.id} to the URL.',
            ]);
        }

        $user = $this->resolveUser($userId);
        if (! $user) {
            return $this->err(404, 'User not found.', ['user_id' => $userId]);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active geographic assignment.', ['user_id' => $userId]);
        }

        try {
            // ── Base query with enrichment joins ─────────────────────────
            $query = DB::table('secondary_screenings as ss')
                ->leftJoin('users as opener', 'opener.id', '=', 'ss.opened_by_user_id')
                ->leftJoin('notifications as n', 'n.id', '=', 'ss.notification_id')
                ->leftJoin('primary_screenings as ps', 'ps.id', '=', 'ss.primary_screening_id')
                ->whereNull('ss.deleted_at');

            // ── Geographic scope — server-side, never from client ─────────
            $this->applyScope($query, $user, $assignment);

            // ── Optional POE sub-filter (district+ roles only) ────────────
            $poeFilter = $request->query('poe_code');
            if ($poeFilter && ! in_array($user->role_key, self::POE_LEVEL_ROLES, true)) {
                $query->where('ss.poe_code', (string) $poeFilter);
            }

            // ── Search: traveler name, document number, client_uuid prefix ─
            $search = trim((string) $request->query('search', ''));
            if ($search !== '') {
                $like = '%' . $search . '%';
                $query->where(function ($q) use ($like, $search) {
                    $q->where('ss.traveler_full_name', 'like', $like)
                        ->orWhere('ss.travel_document_number', 'like', $like)
                        ->orWhere('ss.client_uuid', 'like', $search . '%')
                        ->orWhere('ss.officer_notes', 'like', $like)
                        ->orWhere('opener.full_name', 'like', $like);
                });
            }

            // ── Enum filters ──────────────────────────────────────────────
            $this->applyEnumFilter($query, $request, 'ss.case_status', 'case_status', self::VALID_CASE_STATUSES);
            $this->applyEnumFilter($query, $request, 'ss.risk_level', 'risk_level', self::VALID_RISK_LEVELS);
            $this->applyEnumFilter($query, $request, 'ss.syndrome_classification', 'syndrome', self::VALID_SYNDROMES);
            $this->applyEnumFilter($query, $request, 'ss.final_disposition', 'final_disposition', self::VALID_DISPOSITIONS);

            // ── Date range (on opened_at) ─────────────────────────────────
            if ($request->filled('date_from')) {
                $query->where('ss.opened_at', '>=', $request->query('date_from') . ' 00:00:00');
            }
            if ($request->filled('date_to')) {
                $query->where('ss.opened_at', '<=', $request->query('date_to') . ' 23:59:59');
            }

            // ── Incremental sync cursor ─────────────────────────────────────
            // Vue sends updated_after=<ISO> → returns ONLY records changed since last sync.
            // Keeps background syncs fast at any record count.
            if ($request->filled('updated_after')) {
                $query->where('ss.updated_at', '>', $request->query('updated_after'));
            }
            // Sync status filter for offline queue
            if ($request->filled('sync_status')) {
                $sv = strtoupper((string) $request->query('sync_status'));
                if (in_array($sv, ['UNSYNCED', 'SYNCED', 'FAILED'], true)) {
                    $query->where('ss.sync_status', $sv);
                }
            }

            // ── Count before pagination ───────────────────────────────────
            $total   = (clone $query)->count();
            $perPage = min((int) $request->query('per_page', self::DEFAULT_PER_PAGE), self::MAX_PER_PAGE);
            $page    = max(1, (int) $request->query('page', 1));
            $offset  = ($page - 1) * $perPage;

            // ── Sort ──────────────────────────────────────────────────────
            $sortBy  = $request->query('sort_by', 'opened_at');
            $sortDir = strtolower($request->query('sort_dir', 'desc'));
            if (! in_array($sortBy, self::VALID_SORT_FIELDS, true)) {
                $sortBy = 'opened_at';
            }

            if (! in_array($sortDir, self::VALID_SORT_DIRS, true)) {
                $sortDir = 'desc';
            }

            // Risk-level sort uses a custom order expression
            if ($sortBy === 'risk_level') {
                $query->orderByRaw("FIELD(ss.risk_level, 'CRITICAL','HIGH','MEDIUM','LOW') " . strtoupper($sortDir));
            } else {
                $query->orderBy('ss.' . $sortBy, $sortDir);
            }
            // Tie-break: always newest first within same sort key
            if ($sortBy !== 'opened_at') {
                $query->orderBy('ss.opened_at', 'desc');
            }

            // ── Fetch page ────────────────────────────────────────────────
            $rows = $query
                ->select([
                    // Case identity
                    'ss.id',
                    'ss.client_uuid',
                    'ss.case_status',
                    'ss.risk_level',
                    'ss.syndrome_classification',
                    'ss.final_disposition',
                    'ss.followup_required',
                    'ss.followup_assigned_level',
                    'ss.triage_category',
                    'ss.emergency_signs_present',
                    // Traveler summary (no full PII in list — detail endpoint has everything)
                    'ss.traveler_gender',
                    'ss.traveler_age_years',
                    'ss.traveler_full_name',
                    'ss.traveler_nationality_country_code',
                    'ss.travel_document_type',
                    // Vitals summary
                    'ss.temperature_value',
                    'ss.temperature_unit',
                    // Timestamps
                    'ss.opened_at',
                    'ss.dispositioned_at',
                    'ss.closed_at',
                    // Geography
                    'ss.poe_code',
                    'ss.district_code',
                    'ss.province_code',
                    'ss.pheoc_code',
                    'ss.country_code',
                    // Sync
                    'ss.sync_status',
                    'ss.device_id',
                    // Opener
                    DB::raw('opener.full_name as opener_name'),
                    DB::raw('opener.role_key  as opener_role'),
                    // Notification
                    DB::raw('n.status   as notification_status'),
                    DB::raw('n.priority as notification_priority'),
                    DB::raw('n.id       as notification_id'),
                    // Primary screening summary
                    DB::raw('ps.gender              as primary_gender'),
                    DB::raw('ps.temperature_value   as primary_temp_value'),
                    DB::raw('ps.temperature_unit    as primary_temp_unit'),
                    DB::raw('ps.captured_at         as primary_captured_at'),
                    DB::raw('ps.record_status        as primary_record_status'),
                    DB::raw('ps.traveler_full_name   as primary_traveler_name'),
                    // Extended audit & sync fields for write-through IDB cache
                    'ss.record_version', 'ss.reference_data_version',
                    'ss.server_received_at', 'ss.synced_at',
                    'ss.sync_attempt_count', 'ss.last_sync_error',
                    'ss.app_version', 'ss.platform',
                    'ss.opened_by_user_id', 'ss.opened_timezone',
                    'ss.primary_screening_id',
                    'ss.created_at', 'ss.updated_at',
                    DB::raw('opener.username      as opener_username'),
                    DB::raw('opener.phone         as opener_phone'),
                    DB::raw('n.reason_code        as notification_reason_code'),
                    DB::raw('n.reason_text        as notification_reason_text'),
                    DB::raw('n.opened_at          as notification_opened_at'),
                    DB::raw('n.closed_at          as notification_closed_at'),
                    DB::raw('n.assigned_role_key  as notification_assigned_role'),
                    DB::raw('ps.symptoms_present  as primary_symptoms_present'),
                    DB::raw('ps.referral_created  as primary_referral_created'),
                    DB::raw('ps.captured_timezone as primary_captured_timezone'),
                    DB::raw('ps.poe_code          as primary_poe_code'),
                    DB::raw('ps.sync_status       as primary_sync_status'),
                ])
                ->skip($offset)
                ->take($perPage)
                ->get();

            // ── Enrich: top suspected disease per case (single query) ─────
            $caseIds     = $rows->pluck('id')->filter()->values()->all();
            $topDiseases = [];
            if (! empty($caseIds)) {
                $diseaseRows = DB::table('secondary_suspected_diseases')
                    ->whereIn('secondary_screening_id', $caseIds)
                    ->where('rank_order', 1)
                    ->select('secondary_screening_id', 'disease_code', 'confidence')
                    ->get()
                    ->keyBy('secondary_screening_id');
                foreach ($diseaseRows as $cid => $d) {
                    $topDiseases[$cid] = [
                        'disease_code' => $d->disease_code,
                        'confidence'   => $d->confidence !== null ? (float) $d->confidence : null,
                    ];
                }
            }

            // ── Enrich: action count per case ─────────────────────────────
            $actionCounts = [];
            if (! empty($caseIds)) {
                $counts = DB::table('secondary_actions')
                    ->whereIn('secondary_screening_id', $caseIds)
                    ->where('is_done', 1)
                    ->groupBy('secondary_screening_id')
                    ->select('secondary_screening_id', DB::raw('COUNT(*) as cnt'))
                    ->get()
                    ->keyBy('secondary_screening_id');
                foreach ($counts as $cid => $c) {
                    $actionCounts[$cid] = (int) $c->cnt;
                }
            }

            // ── Enrich: alert per case (most recent) ──────────────────────
            $alerts = [];
            if (! empty($caseIds)) {
                $alertRows = DB::table('alerts')
                    ->whereIn('secondary_screening_id', $caseIds)
                    ->whereNull('deleted_at')
                    ->orderBy('created_at', 'desc')
                    ->select('secondary_screening_id', 'alert_code', 'risk_level', 'status', 'routed_to_level')
                    ->get()
                    ->groupBy('secondary_screening_id');
                foreach ($alertRows as $cid => $alertList) {
                    $alerts[$cid] = [
                        'alert_code'      => $alertList->first()->alert_code,
                        'risk_level'      => $alertList->first()->risk_level,
                        'status'          => $alertList->first()->status,
                        'routed_to_level' => $alertList->first()->routed_to_level,
                        'count'           => $alertList->count(),
                    ];
                }
            }

            // ── Format list items ─────────────────────────────────────────
            $items = $rows->map(function ($row) use ($topDiseases, $actionCounts, $alerts) {
                $cid = $row->id;
                return [
                    // Identity
                    'id'                                => $cid,
                    'client_uuid'                       => $row->client_uuid,
                    'case_status'                       => $row->case_status,
                    'risk_level'                        => $row->risk_level,
                    'syndrome_classification'           => $row->syndrome_classification,
                    'final_disposition'                 => $row->final_disposition,
                    'followup_required'                 => (bool) $row->followup_required,
                    'followup_assigned_level'           => $row->followup_assigned_level,
                    'triage_category'                   => $row->triage_category,
                    'emergency_signs_present'           => (bool) $row->emergency_signs_present,
                    // Traveler (summary — PII kept minimal in list)
                    'traveler_gender'                   => $row->traveler_gender,
                    'traveler_age_years'                => $row->traveler_age_years !== null ? (int) $row->traveler_age_years : null,
                    'traveler_full_name'                => $row->traveler_full_name,
                    'traveler_nationality_country_code' => $row->traveler_nationality_country_code,
                    'travel_document_type'              => $row->travel_document_type,
                    // Vitals summary
                    'temperature_value'                 => $row->temperature_value !== null ? (float) $row->temperature_value : null,
                    'temperature_unit'                  => $row->temperature_unit,
                    // Timestamps
                    'opened_at'                         => $row->opened_at,
                    'dispositioned_at'                  => $row->dispositioned_at,
                    'closed_at'                         => $row->closed_at,
                    // Geography
                    'poe_code'                          => $row->poe_code,
                    'district_code'                     => $row->district_code,
                    // Opener
                    'opener_name'                       => $row->opener_name,
                    'opener_role'                       => $row->opener_role,
                    // Notification
                    'notification_id'                   => $row->notification_id,
                    'notification_status'               => $row->notification_status,
                    'notification_priority'             => $row->notification_priority,
                    // Primary screening summary
                    'primary_gender'                    => $row->primary_gender,
                    'primary_temp_value'                => $row->primary_temp_value !== null ? (float) $row->primary_temp_value : null,
                    'primary_temp_unit'                 => $row->primary_temp_unit,
                    'primary_captured_at'               => $row->primary_captured_at,
                    'primary_record_status'             => $row->primary_record_status,
                    'primary_traveler_name'             => $row->primary_traveler_name,
                    // Enriched
                    'top_disease'                       => $topDiseases[$cid] ?? null,
                    'actions_done_count'                => $actionCounts[$cid] ?? 0,
                    'alert'                             => $alerts[$cid] ?? null,
                    // Sync + audit
                    'sync_status'                       => $row->sync_status,
                    'synced_at'                         => $row->synced_at ?? null,
                    'sync_attempt_count'                => (int) ($row->sync_attempt_count ?? 0),
                    'last_sync_error'                   => $row->last_sync_error ?? null,
                    'record_version'                    => (int) ($row->record_version ?? 1),
                    'reference_data_version'            => $row->reference_data_version ?? null,
                    'server_received_at'                => $row->server_received_at ?? null,
                    'app_version'                       => $row->app_version ?? null,
                    'platform'                          => $row->platform ?? null,
                    'created_at'                        => $row->created_at ?? null,
                    'updated_at'                        => $row->updated_at ?? null,
                    'opened_timezone'                   => $row->opened_timezone ?? null,
                    'opened_by_user_id'                 => $row->opened_by_user_id ?? null,
                    'primary_screening_id'              => $row->primary_screening_id ?? null,
                    'opener_username'                   => $row->opener_username ?? null,
                    'opener_phone'                      => $row->opener_phone ?? null,
                    'notification_reason_code'          => $row->notification_reason_code ?? null,
                    'notification_reason_text'          => $row->notification_reason_text ?? null,
                    'notification_opened_at'            => $row->notification_opened_at ?? null,
                    'notification_closed_at'            => $row->notification_closed_at ?? null,
                    'notification_assigned_role'        => $row->notification_assigned_role ?? null,
                    'primary_symptoms_present'          => isset($row->primary_symptoms_present) ? (bool) $row->primary_symptoms_present : null,
                    'primary_referral_created'          => isset($row->primary_referral_created) ? (bool) $row->primary_referral_created : null,
                    'primary_captured_timezone'         => $row->primary_captured_timezone ?? null,
                    'primary_poe_code'                  => $row->primary_poe_code ?? null,
                    'primary_sync_status'               => $row->primary_sync_status ?? null,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'message' => 'Secondary screening records retrieved.',
                'data'    => [
                    'items'    => $items,
                    'total'    => $total,
                    'per_page' => $perPage,
                    'page'     => $page,
                    'pages'    => (int) ceil($total / max(1, $perPage)),
                    'scope'    => [
                        'role'     => $user->role_key,
                        'poe_code' => $assignment->poe_code ?? null,
                        'district' => $assignment->district_code ?? null,
                        'pheoc'    => $assignment->pheoc_code ?? null,
                        'country'  => $assignment->country_code,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'screening-records index');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /screening-records/stats
    // Aggregate statistics for the records dashboard header.
    // Scoped by the same geographic rules as index().
    // ═════════════════════════════════════════════════════════════════════

    public function stats(Request $request): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required.');
        }

        $user = $this->resolveUser($userId);
        if (! $user) {
            return $this->err(404, 'User not found.');
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.');
        }

        try {
            // Date window — default last 30 days
            $dateFrom = $request->query('date_from')
                ? $request->query('date_from') . ' 00:00:00'
                : now()->subDays(30)->startOfDay()->format('Y-m-d H:i:s');
            $dateTo = $request->query('date_to')
                ? $request->query('date_to') . ' 23:59:59'
                : now()->endOfDay()->format('Y-m-d H:i:s');

            // ── Base scoped query factory ─────────────────────────────────
            $base = function () use ($user, $assignment) {
                $q = DB::table('secondary_screenings as ss')->whereNull('ss.deleted_at');
                $this->applyScope($q, $user, $assignment);
                return $q;
            };

            // ── Windowed base (for trend stats) ──────────────────────────
            $windowed = function () use ($base, $dateFrom, $dateTo) {
                return $base()->whereBetween('ss.opened_at', [$dateFrom, $dateTo]);
            };

            // ── Case status breakdown ─────────────────────────────────────
            $statusCounts = $base()
                ->groupBy('ss.case_status')
                ->select('ss.case_status', DB::raw('COUNT(*) as cnt'))
                ->get()
                ->pluck('cnt', 'case_status')
                ->all();

            // ── Risk level breakdown (all-time) ──────────────────────────
            $riskCounts = $base()
                ->whereNotNull('ss.risk_level')
                ->groupBy('ss.risk_level')
                ->select('ss.risk_level', DB::raw('COUNT(*) as cnt'))
                ->get()
                ->pluck('cnt', 'risk_level')
                ->all();

            // ── Syndrome breakdown (windowed) ─────────────────────────────
            $syndromes = $windowed()
                ->whereNotNull('ss.syndrome_classification')
                ->groupBy('ss.syndrome_classification')
                ->select('ss.syndrome_classification', DB::raw('COUNT(*) as cnt'))
                ->orderByDesc('cnt')
                ->get()
                ->map(fn($r) => ['syndrome' => $r->syndrome_classification, 'count' => (int) $r->cnt])
                ->values();

            // ── Disposition breakdown (windowed, closed cases only) ───────
            $dispositions = $windowed()
                ->whereNotNull('ss.final_disposition')
                ->where('ss.case_status', 'CLOSED')
                ->groupBy('ss.final_disposition')
                ->select('ss.final_disposition', DB::raw('COUNT(*) as cnt'))
                ->orderByDesc('cnt')
                ->get()
                ->map(fn($r) => ['disposition' => $r->final_disposition, 'count' => (int) $r->cnt])
                ->values();

            // ── Unsynced cases (potential data loss indicator) ────────────
            $unsyncedCount = $base()
                ->where('ss.sync_status', 'UNSYNCED')
                ->count();

            // ── High/critical cases requiring follow-up ───────────────────
            $pendingFollowup = $base()
                ->where('ss.followup_required', 1)
                ->where('ss.case_status', '!=', 'CLOSED')
                ->count();

            // ── Active alerts (open + acknowledged) ───────────────────────
            $alertsQuery = DB::table('alerts as a')
                ->join('secondary_screenings as ss', 'ss.id', '=', 'a.secondary_screening_id')
                ->whereNull('a.deleted_at')
                ->whereNull('ss.deleted_at')
                ->whereIn('a.status', ['OPEN', 'ACKNOWLEDGED']);
            $this->applyScope($alertsQuery, $user, $assignment, 'ss');
            $activeAlerts = $alertsQuery->count();

            // ── Today's activity ──────────────────────────────────────────
            $today       = now()->format('Y-m-d');
            $todayOpened = $base()
                ->whereDate('ss.opened_at', $today)
                ->count();
            $todayClosed = $base()
                ->whereDate('ss.closed_at', $today)
                ->where('ss.case_status', 'CLOSED')
                ->count();

            // ── Average case duration (windowed closed cases, in hours) ───
            $avgDurationResult = $windowed()
                ->where('ss.case_status', 'CLOSED')
                ->whereNotNull('ss.opened_at')
                ->whereNotNull('ss.closed_at')
                ->select(DB::raw('AVG(TIMESTAMPDIFF(MINUTE, ss.opened_at, ss.closed_at)) as avg_minutes'))
                ->value('avg_minutes');
            $avgDurationHours = $avgDurationResult !== null
                ? round((float) $avgDurationResult / 60, 1)
                : null;

            // ── Total counts ──────────────────────────────────────────────
            $totalAll    = array_sum($statusCounts);
            $totalClosed = (int) ($statusCounts['CLOSED'] ?? 0);
            $totalOpen   = (int) ($statusCounts['OPEN'] ?? 0) + (int) ($statusCounts['IN_PROGRESS'] ?? 0);

            return response()->json([
                'success' => true,
                'data'    => [
                    'window'                  => [
                        'date_from' => $dateFrom,
                        'date_to'   => $dateTo,
                    ],
                    'totals'                  => [
                        'all'              => $totalAll,
                        'open_active'      => $totalOpen,
                        'closed'           => $totalClosed,
                        'unsynced'         => $unsyncedCount,
                        'pending_followup' => $pendingFollowup,
                        'active_alerts'    => $activeAlerts,
                    ],
                    'today'                   => [
                        'opened' => $todayOpened,
                        'closed' => $todayClosed,
                    ],
                    'by_status'               => $statusCounts,
                    'by_risk_level'           => $riskCounts,
                    'by_syndrome'             => $syndromes,
                    'by_disposition'          => $dispositions,
                    'avg_case_duration_hours' => $avgDurationHours,
                    'scope'                   => [
                        'role'     => $user->role_key,
                        'poe_code' => $assignment->poe_code ?? null,
                    ],
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'screening-records stats');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /screening-records/{id}
    // Full case detail — all child tables, notification, primary screening,
    // alert. Used by the modal/detail panel in the Vue records view.
    // ═════════════════════════════════════════════════════════════════════

    public function show(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required.');
        }

        $user = $this->resolveUser($userId);
        if (! $user) {
            return $this->err(404, 'User not found.');
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.');
        }

        try {
            // ── Load case with opener name ────────────────────────────────
            $case = DB::table('secondary_screenings as ss')
                ->leftJoin('users as opener', 'opener.id', '=', 'ss.opened_by_user_id')
                ->where('ss.id', $id)
                ->whereNull('ss.deleted_at')
                ->select(
                    'ss.*',
                    DB::raw('opener.full_name     as opener_name'),
                    DB::raw('opener.username      as opener_username'),
                    DB::raw('opener.role_key      as opener_role'),
                    DB::raw('opener.phone         as opener_phone'),
                    DB::raw('opener.email         as opener_email'),
                    DB::raw('opener.is_active     as opener_is_active'),
                    DB::raw('opener.last_login_at as opener_last_login_at')
                )
                ->first();

            if (! $case) {
                return $this->err(404, 'Secondary screening case not found.', [
                    'id'   => $id,
                    'hint' => 'The case may not exist or may have been deleted.',
                ]);
            }

            // ── Geographic scope check ────────────────────────────────────
            $scopeErr = $this->checkScope($case, $user, $assignment);
            if ($scopeErr) {
                return $scopeErr;
            }

            // ── Load all child tables in parallel (single query each) ─────
            $symptoms = DB::table('secondary_symptoms')
                ->where('secondary_screening_id', $id)
                ->orderBy('symptom_code')
                ->get()
                ->map(fn($r) => [
                    'id'           => $r->id,
                    'symptom_code' => $r->symptom_code,
                    'is_present'   => (bool) $r->is_present,
                    'onset_date'   => $r->onset_date,
                    'details'      => $r->details,
                ])
                ->values();

            $exposures = DB::table('secondary_exposures')
                ->where('secondary_screening_id', $id)
                ->orderBy('exposure_code')
                ->get()
                ->map(fn($r) => [
                    'id'            => $r->id,
                    'exposure_code' => $r->exposure_code,
                    'response'      => $r->response,
                    'details'       => $r->details,
                ])
                ->values();

            $actions = DB::table('secondary_actions')
                ->where('secondary_screening_id', $id)
                ->orderBy('action_code')
                ->get()
                ->map(fn($r) => [
                    'id'          => $r->id,
                    'action_code' => $r->action_code,
                    'is_done'     => (bool) $r->is_done,
                    'details'     => $r->details,
                ])
                ->values();

            $samples = DB::table('secondary_samples')
                ->where('secondary_screening_id', $id)
                ->orderBy('id')
                ->get()
                ->map(fn($r) => [
                    'id'                => $r->id,
                    'sample_collected'  => (bool) $r->sample_collected,
                    'sample_type'       => $r->sample_type,
                    'sample_identifier' => $r->sample_identifier,
                    'lab_destination'   => $r->lab_destination,
                    'collected_at'      => $r->collected_at,
                ])
                ->values();

            $travelCountries = DB::table('secondary_travel_countries')
                ->where('secondary_screening_id', $id)
                ->orderBy('arrival_date', 'desc')
                ->get()
                ->map(fn($r) => [
                    'id'             => $r->id,
                    'country_code'   => $r->country_code,
                    'travel_role'    => $r->travel_role,
                    'arrival_date'   => $r->arrival_date,
                    'departure_date' => $r->departure_date,
                ])
                ->values();

            $diseases = DB::table('secondary_suspected_diseases')
                ->where('secondary_screening_id', $id)
                ->orderBy('rank_order')
                ->get()
                ->map(fn($r) => [
                    'id'           => $r->id,
                    'disease_code' => $r->disease_code,
                    'rank_order'   => (int) $r->rank_order,
                    'confidence'   => $r->confidence !== null ? (float) $r->confidence : null,
                    'reasoning'    => $r->reasoning,
                ])
                ->values();

            // ── Notification — ALL columns + assigned user + creator ─────
            $notification = null;
            if ($case->notification_id) {
                $notifRow = DB::table('notifications as notif')
                    ->leftJoin('users as asgn', 'asgn.id', '=', 'notif.assigned_user_id')
                    ->leftJoin('users as ncreator', 'ncreator.id', '=', 'notif.created_by_user_id')
                    ->where('notif.id', $case->notification_id)
                    ->select(
                        'notif.*',
                        DB::raw('asgn.full_name    as assigned_user_name'),
                        DB::raw('asgn.username     as assigned_user_username'),
                        DB::raw('asgn.role_key     as assigned_user_role'),
                        DB::raw('ncreator.full_name as creator_name'),
                        DB::raw('ncreator.username  as creator_username'),
                        DB::raw('ncreator.role_key  as creator_role')
                    )->first();
                $notification = $notifRow ? (array) $notifRow : null;
            }

            // ── Primary screening — ALL columns + full screener details ──
            $primaryScreening = null;
            if ($case->primary_screening_id) {
                $primaryScreening = DB::table('primary_screenings as ps')
                    ->leftJoin('users as screener', 'screener.id', '=', 'ps.captured_by_user_id')
                    ->where('ps.id', $case->primary_screening_id)
                    ->select(
                        'ps.*',
                        DB::raw('screener.full_name  as screener_name'),
                        DB::raw('screener.username   as screener_username'),
                        DB::raw('screener.role_key   as screener_role'),
                        DB::raw('screener.phone      as screener_phone'),
                        DB::raw('screener.email      as screener_email'),
                        DB::raw('screener.is_active  as screener_is_active')
                    )->first();
            }

            // ── Alert — ALL columns + acknowledger details ───────────────
            $alert    = null;
            $alertRow = DB::table('alerts as al')
                ->leftJoin('users as ack', 'ack.id', '=', 'al.acknowledged_by_user_id')
                ->where('al.secondary_screening_id', $id)
                ->whereNull('al.deleted_at')
                ->orderBy('al.created_at', 'desc')
                ->select(
                    'al.*',
                    DB::raw('ack.full_name as acknowledger_name'),
                    DB::raw('ack.username  as acknowledger_username'),
                    DB::raw('ack.role_key  as acknowledger_role')
                )->first();
            if ($alertRow) {$alert = (array) $alertRow;}

            // ── Build response ────────────────────────────────────────────
            return response()->json([
                'success' => true,
                'data'    => [
                    // Case core fields — all columns
                    'id'                                => $case->id,
                    'client_uuid'                       => $case->client_uuid,
                    'reference_data_version'            => $case->reference_data_version,
                    'server_received_at'                => $case->server_received_at,
                    'country_code'                      => $case->country_code,
                    'province_code'                     => $case->province_code,
                    'pheoc_code'                        => $case->pheoc_code,
                    'district_code'                     => $case->district_code,
                    'poe_code'                          => $case->poe_code,
                    'primary_screening_id'              => $case->primary_screening_id,
                    'notification_id'                   => $case->notification_id,
                    'opened_by_user_id'                 => $case->opened_by_user_id,
                    'opener_name'                       => $case->opener_name,
                    'opener_username'                   => $case->opener_username ?? null,
                    'opener_role'                       => $case->opener_role,
                    'opener_phone'                      => $case->opener_phone ?? null,
                    'opener_email'                      => $case->opener_email ?? null,
                    'opener_is_active'                  => isset($case->opener_is_active) ? (bool) $case->opener_is_active : null,
                    'opener_last_login_at'              => $case->opener_last_login_at ?? null,
                    'case_status'                       => $case->case_status,
                    // Traveler identity
                    'traveler_full_name'                => $case->traveler_full_name,
                    'traveler_initials'                 => $case->traveler_initials,
                    'traveler_anonymous_code'           => $case->traveler_anonymous_code,
                    'travel_document_type'              => $case->travel_document_type,
                    'travel_document_number'            => $case->travel_document_number,
                    'traveler_gender'                   => $case->traveler_gender,
                    'traveler_age_years'                => $case->traveler_age_years !== null ? (int) $case->traveler_age_years : null,
                    'traveler_dob'                      => $case->traveler_dob,
                    'traveler_nationality_country_code' => $case->traveler_nationality_country_code,
                    'traveler_occupation'               => $case->traveler_occupation,
                    'residence_country_code'            => $case->residence_country_code,
                    'residence_address_text'            => $case->residence_address_text,
                    'phone_number'                      => $case->phone_number,
                    'alternative_phone'                 => $case->alternative_phone,
                    'email'                             => $case->email,
                    'destination_address_text'          => $case->destination_address_text,
                    'destination_district_code'         => $case->destination_district_code,
                    'emergency_contact_name'            => $case->emergency_contact_name,
                    'emergency_contact_phone'           => $case->emergency_contact_phone,
                    // Travel itinerary
                    'journey_start_country_code'        => $case->journey_start_country_code,
                    'embarkation_port_city'             => $case->embarkation_port_city,
                    'conveyance_type'                   => $case->conveyance_type,
                    'conveyance_identifier'             => $case->conveyance_identifier,
                    'seat_number'                       => $case->seat_number,
                    'arrival_datetime'                  => $case->arrival_datetime,
                    'departure_datetime'                => $case->departure_datetime,
                    'purpose_of_travel'                 => $case->purpose_of_travel,
                    'planned_length_of_stay_days'       => $case->planned_length_of_stay_days !== null ? (int) $case->planned_length_of_stay_days : null,
                    // Clinical triage
                    'triage_category'                   => $case->triage_category,
                    'emergency_signs_present'           => (bool) $case->emergency_signs_present,
                    'general_appearance'                => $case->general_appearance,
                    'temperature_value'                 => $case->temperature_value !== null ? (float) $case->temperature_value : null,
                    'temperature_unit'                  => $case->temperature_unit,
                    'pulse_rate'                        => $case->pulse_rate !== null ? (int) $case->pulse_rate : null,
                    'respiratory_rate'                  => $case->respiratory_rate !== null ? (int) $case->respiratory_rate : null,
                    'bp_systolic'                       => $case->bp_systolic !== null ? (int) $case->bp_systolic : null,
                    'bp_diastolic'                      => $case->bp_diastolic !== null ? (int) $case->bp_diastolic : null,
                    'oxygen_saturation'                 => $case->oxygen_saturation !== null ? (float) $case->oxygen_saturation : null,
                    // Clinical decision
                    'syndrome_classification'           => $case->syndrome_classification,
                    'risk_level'                        => $case->risk_level,
                    'officer_notes'                     => $case->officer_notes,
                    'final_disposition'                 => $case->final_disposition,
                    'disposition_details'               => $case->disposition_details,
                    'followup_required'                 => (bool) $case->followup_required,
                    'followup_assigned_level'           => $case->followup_assigned_level,
                    // Timestamps
                    'opened_at'                         => $case->opened_at,
                    'opened_timezone'                   => $case->opened_timezone,
                    'dispositioned_at'                  => $case->dispositioned_at,
                    'closed_at'                         => $case->closed_at,
                    // Audit
                    'device_id'                         => $case->device_id,
                    'app_version'                       => $case->app_version,
                    'platform'                          => $case->platform,
                    'record_version'                    => (int) $case->record_version,
                    'sync_status'                       => $case->sync_status,
                    'synced_at'                         => $case->synced_at,
                    'created_at'                        => $case->created_at,
                    'updated_at'                        => $case->updated_at,
                    // Child tables — ALL columns
                    'symptoms'                          => $symptoms,
                    'exposures'                         => $exposures,
                    'actions'                           => $actions,
                    'samples'                           => $samples,
                    'travel_countries'                  => $travelCountries,
                    'suspected_diseases'                => $diseases,
                    // Related records — ALL columns, all joins
                    'notification'                      => $notification,
                    'primary_screening'                 => $primaryScreening ? array_merge(
                        (array) $primaryScreening,
                        [
                            'symptoms_present'   => (bool) ($primaryScreening->symptoms_present ?? false),
                            'referral_created'   => (bool) ($primaryScreening->referral_created ?? false),
                            'temperature_value'  => $primaryScreening->temperature_value !== null ? (float) $primaryScreening->temperature_value : null,
                            'screener_is_active' => isset($primaryScreening->screener_is_active) ? (bool) $primaryScreening->screener_is_active : null,
                        ]
                    ) : null,
                    'alert'                             => $alert,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'screening-records show ' . $id);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Apply geographic scope to a query builder based on the user's role
     * and primary assignment. Server-side only — never trusts client scope.
     *
     * @param \Illuminate\Database\Query\Builder $query
     * @param string $tableAlias  Table alias to qualify column (default 'ss')
     */
    private function applyScope(object $query, object $user, object $assignment, string $alias = 'ss'): void
    {
        $role = $user->role_key ?? '';
        if (in_array($role, self::POE_LEVEL_ROLES, true)) {
            $query->where("{$alias}.poe_code", $assignment->poe_code);
        } elseif ($role === 'DISTRICT_SUPERVISOR') {
            $query->where("{$alias}.district_code", $assignment->district_code);
        } elseif ($role === 'PHEOC_OFFICER' || $role === 'PHEOC_ADMIN') {
            $scopeCode = $assignment->province_code ?? $assignment->pheoc_code ?? null;
            $query->where("{$alias}.country_code", $assignment->country_code);
            if ($scopeCode !== null) {
                $query->where(function ($q) use ($alias, $scopeCode) {
                    $q->where("{$alias}.province_code", $scopeCode)
                      ->orWhere("{$alias}.pheoc_code", $scopeCode);
                });
            }
        } else {
            // NATIONAL_ADMIN and any other elevated roles — country scope
            $query->where("{$alias}.country_code", $assignment->country_code);
        }
    }

    /**
     * Check geographic scope for a specific case object.
     * Returns 403 JsonResponse if denied, null if permitted.
     */
    private function checkScope(object $case, object $user, object $assignment): ?JsonResponse
    {
        $role = $user->role_key ?? '';
        if (in_array($role, self::POE_LEVEL_ROLES, true)) {
            if ($case->poe_code !== $assignment->poe_code) {
                return $this->err(403, 'Access denied. Case belongs to a different POE.', [
                    'case_poe_code' => $case->poe_code,
                    'your_poe_code' => $assignment->poe_code,
                ]);
            }
        } elseif ($role === 'DISTRICT_SUPERVISOR') {
            if ($case->district_code !== $assignment->district_code) {
                return $this->err(403, 'Access denied. Case is in a different district.', [
                    'case_district' => $case->district_code,
                    'your_district' => $assignment->district_code,
                ]);
            }
        } elseif ($role === 'PHEOC_OFFICER' || $role === 'PHEOC_ADMIN') {
            $scopeCode = $assignment->province_code ?? $assignment->pheoc_code ?? null;
            $caseCode  = $case->province_code ?? $case->pheoc_code ?? null;
            if ($scopeCode !== null && $scopeCode !== $caseCode) {
                return $this->err(403, 'Access denied. Case is in a different PHEOC region.', [
                    'case_province' => $case->province_code, 'case_pheoc' => $case->pheoc_code,
                    'your_province' => $assignment->province_code, 'your_pheoc' => $assignment->pheoc_code,
                ]);
            }
        } else {
            if ($case->country_code !== $assignment->country_code) {
                return $this->err(403, 'Access denied. Case is in a different country.', [
                    'case_country' => $case->country_code,
                    'your_country' => $assignment->country_code,
                ]);
            }
        }
        return null;
    }

    /** Apply a validated enum filter to the query. */
    private function applyEnumFilter(
        object $query,
        Request $request,
        string $column,
        string $param,
        array $valid
    ): void {
        if (! $request->filled($param)) {
            return;
        }

        $val = strtoupper((string) $request->query($param));
        if (in_array($val, $valid, true)) {
            $query->where($column, $val);
        }
    }

    /** Resolve a user record by id. */
    private function resolveUser(int $id): ?object
    {
        return DB::table('users')->where('id', $id)->first() ?: null;
    }

    /** Get the primary active assignment for a user. */
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

    /** Build a structured 422/403/404 error response. */
    private function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => $detail,
        ], $status);
    }

    /** Build a 500 response with exception detail for developers. */
    private function serverError(Throwable $e, string $context): JsonResponse
    {
        Log::error("[ScreeningRecords][ERROR] {$context}", [
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
