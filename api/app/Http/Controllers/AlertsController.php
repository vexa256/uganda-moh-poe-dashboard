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
 * ║  AlertsController                                                            ║
 * ║  ECSA-HC POE Sentinel · WHO/IHR 2005 Annex 2 Aligned                        ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  Database:  poe_2026  (DB:: facade — NO Eloquent models)                    ║
 * ║  Auth:      NONE — all routes open by design. Auth middleware added later.   ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  ROUTES (routes/api.php):                                                    ║
 * ║                                                                              ║
 * ║  use App\Http\Controllers\AlertsController as AC;                           ║
 * ║                                                                              ║
 * ║  Route::get   ('/alerts',                    [AC::class,'index']);           ║
 * ║  Route::get   ('/alerts/{id}',               [AC::class,'show']);            ║
 * ║  Route::post  ('/alerts',                    [AC::class,'store']);           ║
 * ║  Route::patch ('/alerts/{id}/acknowledge',   [AC::class,'acknowledge']);     ║
 * ║  Route::patch ('/alerts/{id}/close',         [AC::class,'close']);           ║
 * ║  Route::get   ('/alerts/summary',            [AC::class,'summary']);         ║
 * ║                                                                              ║
 * ║  ⚠ ORDER: /alerts/summary MUST be declared BEFORE /alerts/{id}             ║
 * ║    to prevent "summary" being treated as a numeric ID.                       ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  IHR COMPLIANCE:                                                             ║
 * ║  This controller implements the alert acknowledgement workflow per           ║
 * ║  IHR 2005 Annex 2. The routing hierarchy (DISTRICT → PHEOC → NATIONAL)      ║
 * ║  maps directly to the four-level geographic scope enforced by the engine.    ║
 * ║                                                                              ║
 * ║  Alert lifecycle:  OPEN → ACKNOWLEDGED → CLOSED                             ║
 * ║  Invalid:          CLOSED → any  (terminal state)                           ║
 * ║                    ACKNOWLEDGED → OPEN  (regression not allowed)            ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */
final class AlertsController extends Controller
{
    private const VALID_STATUSES       = ['OPEN', 'ACKNOWLEDGED', 'CLOSED'];
    private const VALID_RISK_LEVELS    = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    private const VALID_ROUTED_TO      = ['DISTRICT', 'PHEOC', 'NATIONAL'];
    private const VALID_GENERATED_FROM = ['RULE_BASED', 'OFFICER'];
    private const VALID_PLATFORMS      = ['ANDROID', 'IOS', 'WEB'];

    /**
     * Canonical close categories. Every user-visible close path must map to
     * exactly one of these — they drive the War Room close dropdown, the
     * ALERT_CLOSED email body, and the 7-1-7 compliance report.
     *
     * DUPLICATE requires merged_into_alert_id. OTHER requires a close_note.
     */
    public const CLOSE_CATEGORIES = [
        'RESOLVED'                  => 'Case resolved through response',
        'FALSE_POSITIVE'            => 'Alert generated in error / not a case',
        'DUPLICATE'                 => 'Duplicate — merged into another alert',
        'LOST_TO_FOLLOWUP'          => 'Traveller lost to follow-up',
        'TRANSFERRED_OUT_OF_COUNTRY'=> 'Transferred out of country',
        'DECEASED'                  => 'Traveller deceased',
        'OTHER'                     => 'Other (explain in note)',
    ];

    /** Roles that may acknowledge/close at each routing level. */
    private const ACKNOWLEDGE_ROLES = [
        'DISTRICT' => ['DISTRICT_SUPERVISOR', 'PHEOC_OFFICER', 'NATIONAL_ADMIN'],
        'PHEOC'    => ['PHEOC_OFFICER', 'NATIONAL_ADMIN'],
        'NATIONAL' => ['NATIONAL_ADMIN'],
    ];

    private const MAX_PER_PAGE = 100;

    // ═════════════════════════════════════════════════════════════════════
    // GET /alerts
    // List alerts for the authenticated user's geographic scope.
    // Filters: status, risk_level, routed_to_level, date_from, date_to,
    //          ihr_tier, updated_after (cursor for incremental sync)
    // ═════════════════════════════════════════════════════════════════════

    public function index(Request $request): JsonResponse
    {
        $userId = (int) ($request->user()?->id ?? $request->query('user_id', 0));
        if ($userId <= 0) {
            return $this->err(422, 'user_id query parameter is required.', [
                'hint' => 'Append ?user_id={AUTH_DATA.id}',
            ]);
        }

        $user = $this->resolveUser($userId);
        if (! $user) {
            return $this->err(404, 'User not found.', ['user_id' => $userId]);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.', ['user_id' => $userId]);
        }

        try {
            $query = DB::table('alerts as a')
                ->leftJoin('secondary_screenings as ss', 'ss.id', '=', 'a.secondary_screening_id')
                ->leftJoin('users as ack_u', 'ack_u.id', '=', 'a.acknowledged_by_user_id')
                ->whereNull('a.deleted_at');

            // Geographic scope enforcement
            $roleKey = $user->role_key ?? '';
            if (in_array($roleKey, ['POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_ADMIN', 'SCREENER'], true)) {
                $query->where('a.poe_code', $assignment->poe_code);
            } elseif ($roleKey === 'DISTRICT_SUPERVISOR') {
                $query->where('a.district_code', $assignment->district_code);
            } elseif ($roleKey === 'PHEOC_OFFICER') {
                $query->where('a.pheoc_code', $assignment->pheoc_code);
            } else {
                // NATIONAL_ADMIN — sees everything in their country
                $query->where('a.country_code', $assignment->country_code);
            }

            // Scope by the alert's own country_code. The COALESCE(ss.country_code,
            // a.country_code) guard was removed because seed data has Uganda alerts
            // LEFT-JOINed to foreign-country secondary_screenings, causing
            // the COALESCE to evaluate as 'ZM' and reject all RW alerts. The alert
            // table's country_code is the authoritative scope signal; the linked
            // screening's country_code is irrelevant for visibility gating.
            $query->where('a.country_code', $assignment->country_code);

            // Filters
            if ($request->filled('status')) {
                $st = strtoupper($request->query('status'));
                if (in_array($st, self::VALID_STATUSES, true)) {
                    $query->where('a.status', $st);
                }
            }
            if ($request->filled('risk_level')) {
                $rl = strtoupper($request->query('risk_level'));
                if (in_array($rl, self::VALID_RISK_LEVELS, true)) {
                    $query->where('a.risk_level', $rl);
                }
            }
            if ($request->filled('routed_to_level')) {
                $rtl = strtoupper($request->query('routed_to_level'));
                if (in_array($rtl, self::VALID_ROUTED_TO, true)) {
                    $query->where('a.routed_to_level', $rtl);
                }
            }
            if ($request->filled('date_from')) {
                $query->where('a.created_at', '>=', $request->query('date_from') . ' 00:00:00');
            }
            if ($request->filled('date_to')) {
                $query->where('a.created_at', '<=', $request->query('date_to') . ' 23:59:59');
            }
            if ($request->filled('updated_after')) {
                $after = $this->safeDatetime($request->query('updated_after'));
                if ($after) {
                    $query->where('a.updated_at', '>', $after);
                }
            }
            // IHR tier filter — derived from alert_code prefix patterns
            if ($request->filled('ihr_tier')) {
                $tier = strtoupper($request->query('ihr_tier'));
                if ($tier === 'TIER_1') {
                    $query->where(function ($q) {
                        $q->where('a.alert_code', 'like', 'TIER1%')
                            ->orWhereIn('a.routed_to_level', ['NATIONAL']);
                    });
                } elseif ($tier === 'TIER_2') {
                    $query->where('a.routed_to_level', 'PHEOC');
                }
            }

            $total   = (clone $query)->count();
            $perPage = min((int) $request->query('per_page', 50), self::MAX_PER_PAGE);
            $page    = max(1, (int) $request->query('page', 1));
            $offset  = ($page - 1) * $perPage;

            // Default order: CRITICAL first, then most recent
            $alerts = $query
                ->select([
                    'a.*',
                    'ss.case_status                  as case_status',
                    'ss.syndrome_classification      as syndrome',
                    'ss.traveler_full_name           as traveler_full_name',
                    'ss.traveler_initials            as traveler_initials',
                    'ss.traveler_gender              as traveler_gender',
                    'ss.traveler_age_years           as traveler_age_years',
                    'ss.traveler_dob                 as traveler_dob',
                    'ss.traveler_anonymous_code      as traveler_anonymous_code',
                    'ss.traveler_nationality_country_code as traveler_nationality',
                    'ss.travel_document_type         as travel_document_type',
                    'ss.travel_document_number       as travel_document_number',
                    'ss.residence_country_code       as residence_country_code',
                    'ss.journey_start_country_code   as journey_start_country_code',
                    'ss.conveyance_type              as conveyance_type',
                    'ss.conveyance_identifier        as conveyance_identifier',
                    'ss.arrival_datetime             as arrival_datetime',
                    'ss.purpose_of_travel            as purpose_of_travel',
                    'ss.triage_category              as triage_category',
                    'ss.temperature_value            as temperature_value',
                    'ss.temperature_unit             as temperature_unit',
                    'ss.opened_at                    as case_opened_at',
                    'ss.client_uuid                  as secondary_case_client_uuid',
                    'ack_u.full_name                 as acknowledged_by_name',
                ])
                ->orderByRaw("FIELD(a.risk_level, 'CRITICAL','HIGH','MEDIUM','LOW')")
                ->orderBy('a.created_at', 'desc')
                ->skip($offset)
                ->take($perPage)
                ->get();

            // Attach the top-ranked suspected-disease code per alert. This is
            // the authoritative per-case signal from the scoring engine —
            // populated in secondary_suspected_diseases with rank_order=1.
            // Surfacing it lets the UI render "Suspected <disease name>"
            // without falling back to engine jargon like TIER1_ALWAYS_CRITICAL.
            $secIds = $alerts->pluck('secondary_screening_id')->filter()->unique()->values();
            $topDiseaseByScreening = [];
            if ($secIds->count() > 0) {
                $topRows = DB::table('secondary_suspected_diseases')
                    ->whereIn('secondary_screening_id', $secIds)
                    ->where('rank_order', 1)
                    ->select('secondary_screening_id','disease_code','confidence')
                    ->get();
                foreach ($topRows as $r) {
                    $topDiseaseByScreening[(int) $r->secondary_screening_id] = [
                        'disease_code' => (string) $r->disease_code,
                        'confidence'   => $r->confidence !== null ? (float) $r->confidence : null,
                    ];
                }
            }
            // Resolve the human-readable disease name once per unique code
            // (schema-tolerant: dev uses `disease_name`, prod uses `display_name`).
            $diseaseNames = [];
            $allCodes = collect($topDiseaseByScreening)->pluck('disease_code')->filter()->unique()->values();
            if ($allCodes->count() > 0) {
                $nameCol = \Schema::hasColumn('ref_diseases', 'disease_name') ? 'disease_name'
                    : (\Schema::hasColumn('ref_diseases', 'display_name') ? 'display_name' : null);
                if ($nameCol) {
                    $rows = DB::table('ref_diseases')
                        ->whereIn('disease_code', $allCodes)
                        ->select('disease_code', $nameCol)
                        ->get();
                    foreach ($rows as $r) $diseaseNames[(string) $r->disease_code] = $r->{$nameCol};
                }
            }

            foreach ($alerts as $a) {
                $sid = $a->secondary_screening_id ? (int) $a->secondary_screening_id : null;
                $top = $sid && isset($topDiseaseByScreening[$sid]) ? $topDiseaseByScreening[$sid] : null;
                $a->top_disease_code       = $top['disease_code'] ?? null;
                $a->top_disease_confidence = $top['confidence']   ?? null;
                $a->top_disease_name       = ($top && !empty($top['disease_code']) && isset($diseaseNames[$top['disease_code']]))
                    ? $diseaseNames[$top['disease_code']] : null;

                // Build a humane "traveller label" so cards can show "M · 35y · RW"
                // (or the anonymous code when present) without each view re-implementing
                // the demographics fallback.
                $code = (string) ($a->traveler_anonymous_code ?? '');
                $g    = (string) ($a->traveler_gender ?? '');
                $age  = $a->traveler_age_years ?? null;
                $nat  = (string) ($a->traveler_nationality ?? '');
                $bits = array_values(array_filter([
                    $g !== '' ? strtoupper($g[0]) : null,
                    $age !== null ? ($age . 'y') : null,
                    $nat !== '' ? $nat : null,
                ]));
                $a->traveler_label = $code !== '' ? $code : (count($bits) ? implode(' · ', $bits) : 'Anonymous');
            }

            // Summary counts by status for the dashboard pill strip
            $statusCounts = DB::table('alerts')
                ->select('status', DB::raw('COUNT(*) as cnt'))
                ->whereNull('deleted_at')
                ->where('country_code', $assignment->country_code)
                ->groupBy('status')
                ->pluck('cnt', 'status')
                ->toArray();

            return response()->json([
                'success' => true,
                'message' => 'Alerts retrieved.',
                'data'    => [
                    'items'         => $alerts->map(fn($a) => $this->formatAlert($a))->values(),
                    'total'         => $total,
                    'per_page'      => $perPage,
                    'page'          => $page,
                    'pages'         => (int) ceil($total / max(1, $perPage)),
                    'status_counts' => $statusCounts,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'alerts index');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /alerts/summary
    // Lightweight KPI counts for dashboard home pill strip.
    // Returns: open_critical, open_high, open_total, unacknowledged_24h
    // ⚠ Must be registered BEFORE /alerts/{id} in routes/api.php.
    // ═════════════════════════════════════════════════════════════════════

    public function summary(Request $request): JsonResponse
    {
        $userId = (int) ($request->user()?->id ?? $request->query('user_id', 0));
        if ($userId <= 0) {
            return $this->err(422, 'user_id query parameter is required.');
        }

        $user       = $this->resolveUser($userId);
        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $user || ! $assignment) {
            return $this->err(403, 'No active user or assignment.');
        }

        try {
            $base = DB::table('alerts')
                ->whereNull('deleted_at')
                ->where('country_code', $assignment->country_code)
                ->where('status', 'OPEN');

            // Scope to the user's level
            $roleKey = $user->role_key ?? '';
            if (in_array($roleKey, ['POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_ADMIN', 'SCREENER'], true)) {
                $base->where('poe_code', $assignment->poe_code);
            } elseif ($roleKey === 'DISTRICT_SUPERVISOR') {
                $base->where('district_code', $assignment->district_code);
            } elseif ($roleKey === 'PHEOC_OFFICER') {
                $base->where('pheoc_code', $assignment->pheoc_code);
            }

            $openTotal    = (clone $base)->count();
            $openCritical = (clone $base)->where('risk_level', 'CRITICAL')->count();
            $openHigh     = (clone $base)->where('risk_level', 'HIGH')->count();
            $unacked24h   = (clone $base)->where('created_at', '<', now()->subHours(24))->count();
            $nationalOpen = (clone $base)->where('routed_to_level', 'NATIONAL')->count();

            return $this->ok([
                'open_total'          => $openTotal,
                'open_critical'       => $openCritical,
                'open_high'           => $openHigh,
                'unacknowledged_24h'  => $unacked24h,
                'national_level_open' => $nationalOpen,
                'ihr_action_required' => $unacked24h > 0 || $openCritical > 0,
            ], 'Alert summary retrieved.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'alerts summary');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /alerts/{id}
    // ═════════════════════════════════════════════════════════════════════

    public function show(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()?->id ?? $request->query('user_id', 0));
        if ($userId <= 0) {
            return $this->err(422, 'user_id query parameter is required.');
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.');
        }

        try {
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) {
                return $this->err(404, 'Alert not found.', ['id' => $id]);
            }

            $scopeErr = $this->checkScope($alert, $assignment, $this->resolveUser($userId));
            if ($scopeErr) {
                return $scopeErr;
            }

            $formatted = $this->formatAlert($alert);

            // Attach related secondary case summary
            $case = DB::table('secondary_screenings')
                ->where('id', $alert->secondary_screening_id)
                ->whereNull('deleted_at')
                ->first();

            $formatted['secondary_case'] = $case ? [
                'id'                      => $case->id,
                'client_uuid'             => $case->client_uuid,
                'case_status'             => $case->case_status,
                'syndrome_classification' => $case->syndrome_classification,
                'risk_level'              => $case->risk_level,
                'final_disposition'       => $case->final_disposition,
                'traveler_gender'         => $case->traveler_gender,
                'traveler_full_name'      => $case->traveler_full_name,
                'opened_at'               => $case->opened_at,
                'dispositioned_at'        => $case->dispositioned_at,
            ] : null;

            // Attach top suspected disease
            $topDisease = DB::table('secondary_suspected_diseases')
                ->where('secondary_screening_id', $alert->secondary_screening_id)
                ->where('rank_order', 1)
                ->first();
            $formatted['top_suspected_disease'] = $topDisease ? (array) $topDisease : null;

            return $this->ok($formatted, 'Alert retrieved with case context.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'alerts show');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /alerts
    // Sync an alert from the mobile device (generated by SecondaryScreening.vue).
    // Idempotent by client_uuid.
    // ═════════════════════════════════════════════════════════════════════

    public function store(Request $request): JsonResponse
    {
        $userId = (int) $request->input('created_by_user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'created_by_user_id is required.', [
                'hint' => 'Send AUTH_DATA.id',
            ]);
        }

        $user = $this->resolveUser($userId);
        if (! $user || ! (bool) $user->is_active) {
            return $this->err(403, 'User not found or inactive.');
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.');
        }

        // Required field validation
        $clientUuid = (string) $request->input('client_uuid', '');
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $clientUuid)) {
            return $this->err(422, 'client_uuid must be a valid UUID v4.', [
                'received' => $clientUuid,
            ]);
        }

        $secId = $this->resolveSecondaryScreeningId($request->input('secondary_screening_id'));
        if (! $secId) {
            return $this->err(422, 'secondary_screening_id not found. Ensure secondary screening is synced first.', [
                'received' => $request->input('secondary_screening_id'),
            ]);
        }

        $alertCode  = trim((string) $request->input('alert_code', ''));
        $alertTitle = trim((string) $request->input('alert_title', ''));
        $riskLevel  = strtoupper((string) $request->input('risk_level', 'HIGH'));
        $routedTo   = strtoupper((string) $request->input('routed_to_level', 'DISTRICT'));
        $genFrom    = strtoupper((string) $request->input('generated_from', 'RULE_BASED'));

        if (empty($alertCode)) {
            return $this->err(422, 'alert_code is required.');
        }
        if (! in_array($riskLevel, self::VALID_RISK_LEVELS, true)) {
            return $this->err(422, 'Invalid risk_level.', ['valid' => self::VALID_RISK_LEVELS]);
        }
        if (! in_array($routedTo, self::VALID_ROUTED_TO, true)) {
            return $this->err(422, 'Invalid routed_to_level.', ['valid' => self::VALID_ROUTED_TO]);
        }

        try {
            // IDEMPOTENCY: existing alert by client_uuid
            $existing = DB::table('alerts')->where('client_uuid', $clientUuid)->first();
            if ($existing) {
                Log::info('[Alerts][store] Idempotent resubmit', [
                    'client_uuid' => $clientUuid,
                    'server_id'   => $existing->id,
                ]);
                return $this->ok(
                    $this->formatAlert($existing),
                    'Alert already exists (idempotent resubmit).',
                    ['idempotent' => true, 'server_id' => $existing->id]
                );
            }

            $now = now()->format('Y-m-d H:i:s');

            $alertId = DB::table('alerts')->insertGetId([
                'client_uuid'             => $clientUuid,
                'idempotency_key'         => null,
                'reference_data_version'  => $request->input('reference_data_version', 'rda-2026-02-01'),
                'server_received_at'      => $now,
                'country_code'            => $assignment->country_code,
                'province_code'           => $assignment->province_code,
                'pheoc_code'              => $assignment->pheoc_code,
                'district_code'           => $assignment->district_code,
                'poe_code'                => $assignment->poe_code,
                'secondary_screening_id'  => $secId,
                'generated_from'          => $genFrom,
                'risk_level'              => $riskLevel,
                'alert_code'              => substr($alertCode, 0, 80),
                'alert_title'             => substr($alertTitle ?: $alertCode, 0, 150),
                'alert_details'           => $request->input('alert_details') ? substr($request->input('alert_details'), 0, 500) : null,
                'ihr_tier'                => $this->normaliseIhrTier($request->input('ihr_tier')),
                'routed_to_level'         => $routedTo,
                'status'                  => 'OPEN',
                'acknowledged_by_user_id' => null,
                'acknowledged_at'         => null,
                'closed_at'               => null,
                'device_id'               => $request->input('device_id', 'unknown'),
                'app_version'             => $request->input('app_version'),
                'platform'                => in_array(strtoupper((string) $request->input('platform', 'ANDROID')), self::VALID_PLATFORMS, true)
                    ? strtoupper($request->input('platform'))
                    : 'ANDROID',
                'record_version'          => (int) $request->input('record_version', 1),
                'deleted_at'              => null,
                'sync_status'             => 'SYNCED',
                'synced_at'               => $now,
                'sync_attempt_count'      => 0,
                'last_sync_error'         => null,
                'created_at'              => $now,
                'updated_at'              => $now,
            ]);

            $newAlert = DB::table('alerts')->where('id', $alertId)->first();

            Log::info('[Alerts][store] Alert created', [
                'alert_id'     => $alertId,
                'alert_code'   => $alertCode,
                'risk_level'   => $riskLevel,
                'routed_to'    => $routedTo,
                'poe_code'     => $assignment->poe_code,
                'secondary_id' => $secId,
            ]);

            // Auto-seed the 14 RTSL early response actions against this alert
            // so the response checklist is ready the moment operators open
            // the case file. Idempotent — safe to re-call.
            $seedResult = \App\Services\NotificationDispatcher::seedRtsl14Followups($newAlert, $userId);
            Log::info('[Alerts][store] RTSL 14 seeded', $seedResult);

            // Fire-and-forget email notification to the IHR escalation ladder.
            // Catches all exceptions — failed emails never fail the alert create.
            $dispatch = \App\Services\NotificationDispatcher::dispatchAlertCreated($newAlert, $userId);
            Log::info('[Alerts][store] Notification dispatched', $dispatch);

            return $this->ok(
                $this->formatAlert($newAlert),
                'Alert created successfully.',
                [
                    'server_id'    => $alertId,
                    'idempotent'   => false,
                    'notification' => $dispatch,
                    'rtsl_seeded'  => $seedResult,
                ]
            );
        } catch (Throwable $e) {
            return $this->serverError($e, 'alerts store');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // PATCH /alerts/{id}/acknowledge
    //
    // OPEN → ACKNOWLEDGED transition.
    // Role enforcement: only roles authorised for this routed_to_level
    // can acknowledge. A DISTRICT_SUPERVISOR cannot acknowledge a NATIONAL
    // level alert. A NATIONAL_ADMIN can acknowledge any level.
    //
    // IHR basis: Annex 2 — acknowledgement closes the reporting loop
    // and triggers the follow-up action chain.
    // ═════════════════════════════════════════════════════════════════════

    public function acknowledge(Request $request, int $id): JsonResponse
    {
        $userId = (int) ($request->user()?->id ?? $request->input('user_id', 0));
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required in request body.');
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
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) {
                return $this->err(404, 'Alert not found.', ['id' => $id]);
            }

            $scopeErr = $this->checkScope($alert, $assignment, $user);
            if ($scopeErr) {
                return $scopeErr;
            }

            if ($alert->status === 'CLOSED') {
                return $this->err(409, 'Alert is CLOSED — terminal state. Cannot acknowledge.', [
                    'alert_id'  => $id,
                    'closed_at' => $alert->closed_at,
                ]);
            }
            if ($alert->status === 'ACKNOWLEDGED') {
                return $this->ok($this->formatAlert($alert), 'Alert already acknowledged (idempotent).', [
                    'idempotent'              => true,
                    'acknowledged_at'         => $alert->acknowledged_at,
                    'acknowledged_by_user_id' => $alert->acknowledged_by_user_id,
                ]);
            }

            // Role authorisation for the alert's routing level
            $routedTo     = $alert->routed_to_level;
            $allowedRoles = self::ACKNOWLEDGE_ROLES[$routedTo] ?? [];
            $userRole     = $user->role_key ?? '';
            if (! in_array($userRole, $allowedRoles, true)) {
                return $this->err(403, "Your role ({$userRole}) is not authorised to acknowledge {$routedTo}-level alerts.", [
                    'routed_to_level' => $routedTo,
                    'required_roles'  => $allowedRoles,
                    'your_role'       => $userRole,
                    'ihr_basis'       => 'IHR Annex 2 — acknowledgement authority is tier-locked to the routing level.',
                ]);
            }

            $now = now()->format('Y-m-d H:i:s');

            DB::table('alerts')->where('id', $id)->update([
                'status'                  => 'ACKNOWLEDGED',
                'acknowledged_by_user_id' => $userId,
                'acknowledged_at'         => $now,
                'record_version'          => (int) $alert->record_version + 1,
                'updated_at'              => $now,
            ]);

            // Timeline event — additive, parallels close() / reopen() / reassign() / escalate().
            // Best-effort: failure to write the audit row must NOT block the FSM transition.
            // Downstream readers (OwnershipController stream, qr-alert-out audit) need this
            // to compute median-ack-time and to render the lifecycle lineage correctly.
            try {
                DB::table('alert_timeline_events')->insert([
                    'alert_id'       => $id,
                    'event_code'     => 'ACKNOWLEDGED',
                    'event_category' => 'WORKFLOW',
                    'actor_user_id'  => $userId,
                    'actor_name'     => (string) ($user->full_name ?? $user->username ?? ''),
                    'actor_role'     => $userRole,
                    'summary'        => mb_substr(
                        "Alert acknowledged by {$userRole} at the {$routedTo} level.",
                        0, 500
                    ),
                    'payload_json'   => json_encode([
                        'routed_to_level' => $routedTo,
                        'from_status'     => $alert->status,
                        'alert_code'      => $alert->alert_code,
                    ]),
                    'severity'       => 'INFO',
                    'created_at'     => $now,
                ]);
            } catch (Throwable $e) {
                Log::warning('[Alerts][acknowledge] timeline insert failed: ' . $e->getMessage());
            }

            $updated = DB::table('alerts')->where('id', $id)->first();

            Log::info('[Alerts][acknowledge] Alert acknowledged', [
                'alert_id'   => $id,
                'by_user'    => $userId,
                'role'       => $userRole,
                'routed_to'  => $routedTo,
                'alert_code' => $alert->alert_code,
                'risk_level' => $alert->risk_level,
            ]);

            return $this->ok($this->formatAlert($updated), "Alert acknowledged by {$userRole}.", [
                'acknowledged_at'         => $now,
                'acknowledged_by_user_id' => $userId,
                'acknowledged_by_role'    => $userRole,
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'alerts acknowledge');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // PATCH /alerts/{id}/close
    //
    // OPEN → CLOSED  (direct, if alert was generated in error)
    // ACKNOWLEDGED → CLOSED  (normal closure after response actions)
    //
    // Only NATIONAL_ADMIN can close NATIONAL alerts.
    // PHEOC_OFFICER can close PHEOC and DISTRICT alerts.
    // DISTRICT_SUPERVISOR can close DISTRICT alerts only.
    // ═════════════════════════════════════════════════════════════════════

    public function close(Request $request, int $id): JsonResponse
    {
        // Actor — accept either sanctum user or legacy user_id query param
        $userId = (int) ($request->user()?->id ?? $request->input('user_id', 0));
        if ($userId <= 0) return $this->err(422, 'user_id is required.');

        $user = $this->resolveUser($userId);
        if (! $user) return $this->err(404, 'User not found.');

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) return $this->err(403, 'No active assignment.');

        $closeCategory = strtoupper(trim((string) $request->input('close_category', '')));
        $closeNote     = trim((string) $request->input('close_note', ''));
        $mergedInto    = $request->input('merged_into_alert_id');
        // Legacy contract (mobile): accept a plain close_reason. When the
        // admin UI sends close_category, we use it; otherwise fall back.
        $legacyReason  = trim((string) $request->input('close_reason', ''));

        if ($closeCategory === '' && $legacyReason !== '') {
            // Legacy path — leave category null, treat reason as note.
            $closeNote = $closeNote !== '' ? $closeNote : $legacyReason;
        }
        if ($closeCategory !== '' && ! array_key_exists($closeCategory, self::CLOSE_CATEGORIES)) {
            return $this->err(422, 'Invalid close_category.', [
                'allowed' => array_keys(self::CLOSE_CATEGORIES),
            ]);
        }
        if ($closeCategory === 'DUPLICATE') {
            $mergedInto = (int) $mergedInto;
            if ($mergedInto <= 0 || $mergedInto === $id) {
                return $this->err(422, 'merged_into_alert_id is required for DUPLICATE close_category.', [
                    'hint' => 'Reference the canonical alert this one duplicates.',
                ]);
            }
        } else {
            $mergedInto = null;
        }
        if ($closeCategory === 'OTHER' && strlen($closeNote) < 10) {
            return $this->err(422, 'close_note (≥10 chars) is required when close_category=OTHER.');
        }
        if ($closeCategory === '' && strlen($closeNote) < 5) {
            return $this->err(422, 'Either close_category or a ≥5-char close_note/close_reason is required.', [
                'hint' => 'Provide close_category + optional note, or a free-text reason.',
                'allowed_categories' => array_keys(self::CLOSE_CATEGORIES),
            ]);
        }

        try {
            $alert = DB::table('alerts')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $alert) return $this->err(404, 'Alert not found.', ['id' => $id]);

            $scopeErr = $this->checkScope($alert, $assignment, $user);
            if ($scopeErr) return $scopeErr;

            if ($alert->status === 'CLOSED') {
                return $this->ok($this->formatAlert($alert), 'Alert already closed (idempotent).', [
                    'idempotent' => true, 'closed_at' => $alert->closed_at,
                ]);
            }

            // Role authorisation
            $routedTo     = $alert->routed_to_level;
            $allowedRoles = self::ACKNOWLEDGE_ROLES[$routedTo] ?? [];
            $userRole     = $user->role_key ?? '';
            if (! in_array($userRole, $allowedRoles, true)) {
                return $this->err(403, "Your role ({$userRole}) is not authorised to close {$routedTo}-level alerts.", [
                    'required_roles' => $allowedRoles,
                    'your_role'      => $userRole,
                ]);
            }

            // BLOCKS_CLOSURE — enforce that no incomplete follow-up marked
            // blocks_closure=1 remains before we permit close. This is the
            // hard constraint dashboard.txt §M2 calls out.
            //
            // Override path: NATIONAL_ADMIN may bypass when override_blocking_followups=1
            // AND override_reason is ≥30 chars. The override flips every open
            // blocker to NOT_APPLICABLE with a forensic [OVERRIDE] note and
            // emits a CRITICAL timeline event listing every overridden item.
            $blockers = DB::table('alert_followups')
                ->where('alert_id', $id)
                ->where('blocks_closure', 1)
                ->whereNotIn('status', ['COMPLETED','NOT_APPLICABLE'])
                ->whereNull('deleted_at')
                ->select('id','action_code','action_label','status','due_at','assigned_to_role')
                ->get();
            $override       = (bool) $request->input('override_blocking_followups', false);
            $overrideReason = trim((string) $request->input('override_reason', ''));

            if ($blockers->count() > 0) {
                if (! $override) {
                    return $this->err(409, 'Cannot close — ' . $blockers->count() . ' blocking follow-up(s) are still open.', [
                        'code'     => 'BLOCKS_CLOSURE',
                        'blockers' => $blockers,
                        'hint'     => 'Mark each blocking follow-up as COMPLETED or NOT_APPLICABLE — or, as NATIONAL_ADMIN, send override_blocking_followups=1 with an override_reason ≥ 30 chars.',
                    ]);
                }
                if ($userRole !== 'NATIONAL_ADMIN') {
                    return $this->err(403, 'Only NATIONAL_ADMIN may override blocking follow-ups.');
                }
                if (mb_strlen($overrideReason) < 30) {
                    return $this->err(422, 'override_reason must be at least 30 characters.', [
                        'field' => 'override_reason',
                        'len'   => mb_strlen($overrideReason),
                    ]);
                }

                $overrideTs = now()->format('Y-m-d H:i:s');
                $overriddenIds = [];
                foreach ($blockers as $b) {
                    DB::table('alert_followups')->where('id', $b->id)->update([
                        'status'                => 'NOT_APPLICABLE',
                        'completed_at'          => $overrideTs,
                        'completed_by_user_id'  => $userId,
                        'notes'                 => mb_substr('[OVERRIDE by NATIONAL_ADMIN] ' . $overrideReason, 0, 500),
                        'updated_at'            => $overrideTs,
                        'record_version'        => DB::raw('record_version + 1'),
                    ]);
                    $overriddenIds[] = (int) $b->id;
                    try {
                        DB::table('alert_timeline_events')->insert([
                            'alert_id'            => $id,
                            'event_code'          => 'FOLLOWUP_NOT_APPLICABLE',
                            'event_category'      => 'WORKFLOW',
                            'actor_user_id'       => $userId,
                            'actor_name'          => (string) ($user->full_name ?? $user->username ?? ''),
                            'actor_role'          => $userRole,
                            'payload_json'        => json_encode([
                                'followup_id'   => $b->id,
                                'action_code'   => $b->action_code,
                                'action_label'  => $b->action_label,
                                'previous_status'=> $b->status,
                                'override'      => true,
                                'reason'        => $overrideReason,
                            ]),
                            'summary'             => mb_substr('Blocking follow-up "' . ($b->action_label ?? $b->action_code) . '" auto-set NOT_APPLICABLE under NATIONAL_ADMIN override.', 0, 500),
                            'severity'            => 'WARN',
                            'related_entity_type' => 'FOLLOWUP',
                            'related_entity_id'   => (int) $b->id,
                            'created_at'          => $overrideTs,
                        ]);
                    } catch (Throwable $e) { Log::warning('[Alerts][close override] timeline failed: ' . $e->getMessage()); }
                }
                try {
                    DB::table('alert_timeline_events')->insert([
                        'alert_id'       => $id,
                        'event_code'     => 'CLOSURE_OVERRIDE_USED',
                        'event_category' => 'WORKFLOW',
                        'actor_user_id'  => $userId,
                        'actor_name'     => (string) ($user->full_name ?? $user->username ?? ''),
                        'actor_role'     => $userRole,
                        'payload_json'   => json_encode([
                            'overridden_followup_ids' => $overriddenIds,
                            'reason'                  => $overrideReason,
                        ]),
                        'summary'        => mb_substr('NATIONAL_ADMIN closure override (' . count($overriddenIds) . ' follow-up(s)) — ' . $overrideReason, 0, 500),
                        'severity'       => 'CRITICAL',
                        'related_entity_type' => 'ALERT',
                        'related_entity_id'   => $id,
                        'created_at'     => $overrideTs,
                    ]);
                } catch (Throwable $e) { Log::warning('[Alerts][close override] umbrella timeline failed: ' . $e->getMessage()); }
            }

            $now = now()->format('Y-m-d H:i:s');

            // Auto-ack on direct close from OPEN
            $ackAt = $alert->acknowledged_at;
            $ackBy = $alert->acknowledged_by_user_id;
            if ($alert->status === 'OPEN') { $ackAt = $now; $ackBy = $userId; }

            // Compact technical summary — stays in timeline_events.payload_json
            // and in the email body, but is NOT appended into alert_details.
            // The alert_details column is the original detection record and
            // must remain immutable after creation; close metadata lives in
            // close_category / close_note / merged_into_alert_id.
            $summary = $closeCategory !== ''
                ? "[CLOSED/{$closeCategory}]" . ($closeNote !== '' ? " {$closeNote}" : '')
                : "[CLOSED: {$closeNote}]";

            DB::table('alerts')->where('id', $id)->update([
                'status'                  => 'CLOSED',
                'closed_at'               => $now,
                'close_category'          => $closeCategory !== '' ? $closeCategory : null,
                'close_note'              => $closeNote !== '' ? mb_substr($closeNote, 0, 500) : null,
                'merged_into_alert_id'    => $mergedInto,
                'acknowledged_by_user_id' => $ackBy,
                'acknowledged_at'         => $ackAt,
                'record_version'          => (int) $alert->record_version + 1,
                'updated_at'              => $now,
            ]);

            // Timeline event
            try {
                DB::table('alert_timeline_events')->insert([
                    'alert_id' => $id,
                    'event_code' => 'CLOSED',
                    'event_category' => 'WORKFLOW',
                    'actor_user_id' => $userId,
                    'actor_name' => (string) ($user->full_name ?? $user->username ?? ''),
                    'actor_role' => $userRole,
                    'summary' => mb_substr($summary, 0, 500),
                    'payload_json' => json_encode([
                        'close_category' => $closeCategory ?: null,
                        'close_note' => $closeNote ?: null,
                        'merged_into_alert_id' => $mergedInto,
                        'from_status' => $alert->status,
                    ]),
                    'severity' => 'INFO',
                    'created_at' => $now,
                ]);
            } catch (Throwable $e) {
                Log::warning('[Alerts][close] timeline insert failed: ' . $e->getMessage());
            }

            $updated = DB::table('alerts')->where('id', $id)->first();

            // Closure email (best-effort)
            $closerName = (string) ($user->full_name ?? $user->username ?? ('user#' . $userId));
            $reasonForEmail = $closeCategory !== ''
                ? (self::CLOSE_CATEGORIES[$closeCategory] . ($closeNote !== '' ? " — {$closeNote}" : ''))
                : $closeNote;
            $dispatch = \App\Services\NotificationDispatcher::dispatchAlertClosed($updated, $userId, $closerName, $reasonForEmail);

            return $this->ok($this->formatAlert($updated), 'Alert closed.', [
                'closed_at'       => $now,
                'close_category'  => $closeCategory ?: null,
                'close_note'      => $closeNote ?: null,
                'merged_into'     => $mergedInto,
                'notification'    => $dispatch,
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'alerts close');
        }
    }

    /**
     * Reopen is now handled by AlertCollaborationController::reopen which was
     * registered later in routes/api.php and wins the route match.
     * Kept this comment as a breadcrumb so nobody re-adds a duplicate.
     */

    /** GET /alerts/close-categories — seeds the War Room close dropdown. */
    public function closeCategories(): JsonResponse
    {
        $out = [];
        foreach (self::CLOSE_CATEGORIES as $k => $label) {
            $out[] = [
                'code' => $k, 'label' => $label,
                'requires_merged_into' => $k === 'DUPLICATE',
                'requires_note' => $k === 'OTHER',
            ];
        }
        return response()->json(['ok' => true, 'data' => ['categories' => $out]]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═════════════════════════════════════════════════════════════════════

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

    private function resolveSecondaryScreeningId(mixed $value): ?int
    {
        if (empty($value)) {
            return null;
        }

        if (is_numeric($value) && (int) $value > 0) {
            return DB::table('secondary_screenings')->where('id', (int) $value)->value('id') ?: null;
        }
        return DB::table('secondary_screenings')->where('client_uuid', (string) $value)->value('id') ?: null;
    }

    private function checkScope(object $alert, object $assignment, ?object $user): ?JsonResponse
    {
        $roleKey = $user->role_key ?? '';
        if (in_array($roleKey, ['POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_ADMIN', 'SCREENER'], true)) {
            if ($alert->poe_code !== $assignment->poe_code) {
                return $this->err(403, 'Alert belongs to a different POE.', [
                    'alert_poe' => $alert->poe_code, 'user_poe' => $assignment->poe_code,
                ]);
            }
        } elseif ($roleKey === 'DISTRICT_SUPERVISOR') {
            if ($alert->district_code !== $assignment->district_code) {
                return $this->err(403, 'Alert is in a different district.');
            }
        } elseif ($roleKey === 'PHEOC_OFFICER') {
            if ($alert->pheoc_code !== $assignment->pheoc_code) {
                return $this->err(403, 'Alert is in a different PHEOC region.');
            }
        } else {
            if ($alert->country_code !== $assignment->country_code) {
                return $this->err(403, 'Alert is in a different country.');
            }
        }
        return null;
    }

    private function formatAlert(object $alert): array
    {
        // Derive IHR tier from routing level and alert code
        $ihrTier = null;
        if ($alert->routed_to_level === 'NATIONAL') {
            $ihrTier = str_starts_with($alert->alert_code ?? '', 'TIER1')
                ? 'TIER_1_ALWAYS_NOTIFIABLE'
                : 'TIER_2_ANNEX2';
        } elseif ($alert->routed_to_level === 'PHEOC') {
            $ihrTier = 'TIER_2_ANNEX2';
        }

        // Time since creation — relevant for IHR 24h notification tracking
        $createdAt  = $alert->created_at ?? null;
        $hoursSince = $createdAt ? round(now()->diffInMinutes($createdAt) / 60, 1) : null;
        $overdue24h = $hoursSince !== null && $hoursSince > 24 && ($alert->status ?? '') === 'OPEN';

        return [
            'id'                      => (int) $alert->id,
            'client_uuid'             => $alert->client_uuid,
            'reference_data_version'  => $alert->reference_data_version,
            'server_received_at'      => $alert->server_received_at,
            'country_code'            => $alert->country_code,
            'province_code'           => $alert->province_code,
            'pheoc_code'              => $alert->pheoc_code,
            'district_code'           => $alert->district_code,
            'poe_code'                => $alert->poe_code,
            'secondary_screening_id'  => (int) $alert->secondary_screening_id,
            'generated_from'          => $alert->generated_from,
            'risk_level'              => $alert->risk_level,
            'alert_code'              => $alert->alert_code,
            'alert_title'             => $alert->alert_title,
            'alert_details'           => $alert->alert_details,
            'routed_to_level'         => $alert->routed_to_level,
            'status'                  => $alert->status,
            'acknowledged_by_user_id' => $alert->acknowledged_by_user_id
                ? (int) $alert->acknowledged_by_user_id : null,
            'acknowledged_by_name'    => $alert->acknowledged_by_name ?? null,
            'acknowledged_at'         => $alert->acknowledged_at,
            'closed_at'               => $alert->closed_at,
            'device_id'               => $alert->device_id,
            'app_version'             => $alert->app_version,
            'platform'                => $alert->platform,
            'record_version'          => (int) $alert->record_version,
            'deleted_at'              => $alert->deleted_at,
            'sync_status'             => $alert->sync_status ?? 'SYNCED',
            'synced_at'               => $alert->synced_at,
            'created_at'              => $alert->created_at,
            'updated_at'              => $alert->updated_at,
            // Derived fields for UI
            'ihr_tier'                => $ihrTier,
            'hours_since_creation'    => $hoursSince,
            'overdue_24h'             => $overdue24h,
            // Joined fields (only present from index query)
            'case_status'                 => $alert->case_status ?? null,
            'syndrome'                    => $alert->syndrome ?? null,
            'traveler_full_name'          => $alert->traveler_full_name ?? null,
            'traveler_initials'           => $alert->traveler_initials ?? null,
            'traveler_gender'             => $alert->traveler_gender ?? null,
            'traveler_age_years'          => $alert->traveler_age_years ?? null,
            'traveler_dob'                => $alert->traveler_dob ?? null,
            'traveler_anonymous_code'     => $alert->traveler_anonymous_code ?? null,
            'traveler_nationality'        => $alert->traveler_nationality ?? null,
            'traveler_label'              => $alert->traveler_label ?? null,
            'travel_document_type'        => $alert->travel_document_type ?? null,
            'travel_document_number'      => $alert->travel_document_number ?? null,
            'residence_country_code'      => $alert->residence_country_code ?? null,
            'journey_start_country_code'  => $alert->journey_start_country_code ?? null,
            'conveyance_type'             => $alert->conveyance_type ?? null,
            'conveyance_identifier'       => $alert->conveyance_identifier ?? null,
            'arrival_datetime'            => $alert->arrival_datetime ?? null,
            'purpose_of_travel'           => $alert->purpose_of_travel ?? null,
            'triage_category'             => $alert->triage_category ?? null,
            'temperature_value'           => isset($alert->temperature_value) ? (float) $alert->temperature_value : null,
            'temperature_unit'            => $alert->temperature_unit ?? null,
            'top_disease_code'            => $alert->top_disease_code ?? null,
            'top_disease_name'            => $alert->top_disease_name ?? null,
            'top_disease_confidence'      => isset($alert->top_disease_confidence) ? (float) $alert->top_disease_confidence : null,
            // Secondary case client_uuid — enables direct deep-link to the records view
            'secondary_case_client_uuid'  => $alert->secondary_case_client_uuid ?? null,
        ];
    }

    private function safeDatetime(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    /** Normalise ihr_tier to one of {TIER_1, TIER_2, null}. Whitespace/
     *  casing tolerant; anything else collapses to null so the dispatcher's
     *  `str_contains($tier,'TIER_1')` check is deterministic. */
    private function normaliseIhrTier(mixed $raw): ?string
    {
        if (!is_string($raw)) return null;
        $s = strtoupper(trim($raw));
        $s = preg_replace('/\s+/', '_', $s);
        if ($s === '' ) return null;
        if (str_contains($s, 'TIER_1') || $s === 'TIER1' || $s === '1') return 'TIER_1';
        if (str_contains($s, 'TIER_2') || $s === 'TIER2' || $s === '2') return 'TIER_2';
        return null;
    }

    private function ok(array $data, string $message, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];
        if (! empty($meta)) {
            $body['meta'] = $meta;
        }

        return response()->json($body, 200);
    }

    private function err(int $status, string $message, array $detail = []): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message, 'error' => $detail], $status);
    }

    private function serverError(Throwable $e, string $ctx): JsonResponse
    {
        Log::error("[Alerts][ERROR] {$ctx}", [
            'exception' => get_class($e), 'message'        => $e->getMessage(),
            'file'      => basename($e->getFile()), 'line' => $e->getLine(),
        ]);
        return response()->json([
            'success' => false, 'message' => "Server error during: {$ctx}",
            'error' => [
                'exception' => get_class($e), 'message'        => $e->getMessage(),
                'file'      => basename($e->getFile()), 'line' => $e->getLine(),
            ],
        ], 500);
    }
}
