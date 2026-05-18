<?php

declare (strict_types = 1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * ╔══════════════════════════════════════════════════════════════════════════════╗
 * ║  PrimaryScreeningController                                                  ║
 * ║  ECSA-HC POE Sentinel · WHO/IHR 2005 Compliant                              ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  Database: poe_2026  (DB:: facade — NO Eloquent models anywhere)            ║
 * ║  Auth: NONE — all routes are publicly accessible by design.                  ║
 * ║        Auth middleware will be added as a separate layer in routes/api.php  ║
 * ║        when sanctum tokens are wired. Do NOT add Bearer checks here.         ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  ROUTES (paste into routes/api.php):                                         ║
 * ║                                                                              ║
 * ║  use App\Http\Controllers\PrimaryScreeningController;                        ║
 * ║                                                                              ║
 * ║  Route::post  ('/primary-screenings',                [PSC::class,'store']);  ║
 * ║  Route::get   ('/primary-screenings',                [PSC::class,'index']);  ║
 * ║  Route::get   ('/primary-screenings/{id}',           [PSC::class,'show']);   ║
 * ║  Route::patch ('/primary-screenings/{id}/void',      [PSC::class,'void']);   ║
 * ║  Route::get   ('/primary-screenings/stats/today',    [PSC::class,'stats']);  ║
 * ║  Route::get   ('/referral-queue',                    [PSC::class,'queue']);  ║
 * ║  Route::patch ('/referral-queue/{notifId}/cancel',   [PSC::class,'cancelReferral']); ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  BUSINESS LOGIC IMPLEMENTED:                                                  ║
 * ║                                                                              ║
 * ║  store()          POST /primary-screenings                                   ║
 * ║    • Idempotent: duplicate client_uuid → return existing record (200, not 409)║
 * ║    • gender required: MALE|FEMALE|OTHER|UNKNOWN                               ║
 * ║    • symptoms_present required: 0 or 1 (tinyint)                             ║
 * ║    • temperature: value+unit must both be present or both null               ║
 * ║    • temperature ranges validated (C: 25–45, F: 77–113)                      ║
 * ║    • traveler_full_name: OPTIONAL at primary. Only required at secondary.    ║
 * ║    • captured_at must not be in the future beyond 5 minutes (clock drift)    ║
 * ║    • poe_code / district_code / country_code from AUTH_DATA — user's scope  ║
 * ║    • symptoms_present=1 → auto-creates notification atomically in DB::transaction ║
 * ║    • notification priority: CRITICAL(≥38.5°C+symptoms) HIGH(≥37.5°C+symptoms) NORMAL ║
 * ║    • reason_text auto-generated with officer name, POE, geo, temp, priority  ║
 * ║    • referral_created=1 stamped on primary record when notification created  ║
 * ║                                                                              ║
 * ║  index()          GET /primary-screenings                                    ║
 * ║    • Scoped to user's poe_code from user_assignments                         ║
 * ║    • Filters: date_from, date_to, symptoms_present, record_status, gender    ║
 * ║    • Excludes deleted (deleted_at IS NULL) and voided unless requested       ║
 * ║    • Pagination: per_page (max 200), page                                    ║
 * ║    • Joins notifications for referral status                                 ║
 * ║                                                                              ║
 * ║  show()           GET /primary-screenings/{id}                               ║
 * ║    • Returns full record + linked notification (if exists) + secondary case  ║
 * ║    • Scoped to user's poe_code                                               ║
 * ║                                                                              ║
 * ║  void()           PATCH /primary-screenings/{id}/void                        ║
 * ║    • Sets record_status=VOIDED, void_reason required (min 10 chars)          ║
 * ║    • Auto-closes linked OPEN/IN_PROGRESS notification (status→CLOSED)        ║
 * ║    • Does NOT touch a secondary case already IN_PROGRESS or DISPOSITIONED    ║
 * ║    • Only the creating officer (within 24h) or POE_ADMIN/NATIONAL_ADMIN       ║
 * ║    • Voided records are excluded from counts and stats                        ║
 * ║                                                                              ║
 * ║  stats()          GET /primary-screenings/stats/today                        ║
 * ║    • Today's counts scoped to user's POE                                     ║
 * ║    • Returns: total, symptomatic, asymptomatic, male, female, other, unknown ║
 * ║    • Also returns pending_sync count and open_referrals count                ║
 * ║                                                                              ║
 * ║  queue()          GET /referral-queue                                        ║
 * ║    • Returns OPEN notifications at user's POE for secondary screening        ║
 * ║    • Joins primary_screenings for gender, temperature                        ║
 * ║    • Orders by priority DESC, created_at ASC                                 ║
 * ║    • A canceled referral's notification is CLOSED — it will NOT appear here  ║
 * ║                                                                              ║
 * ║  cancelReferral() PATCH /referral-queue/{notifId}/cancel                     ║
 * ║    • BUSINESS RULE: Canceling a referral does NOT delete the primary record.  ║
 * ║      The primary screening record remains COMPLETED with referral_created=1   ║
 * ║      (immutable audit field — the referral was issued, then cancelled).       ║
 * ║    • Sets notification.status → CLOSED, reason_text stamped with canceller   ║
 * ║    • Primary record stays as-is (COMPLETED, symptoms_present=1, referral_created=1)║
 * ║    • This is correct: the traveler WAS symptomatic, the referral was issued, ║
 * ║      but the secondary officer declined to investigate (documented decision). ║
 * ║    • If a secondary case is already OPEN/IN_PROGRESS: refuse cancel (409).   ║
 * ║      The secondary officer must close the case from their side.              ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */
final class PrimaryScreeningController extends Controller
{
    // ════════════════════════════════════════════════════════════════════════════
    // CONSTANTS
    // ════════════════════════════════════════════════════════════════════════════

    private const VALID_GENDERS    = ['MALE', 'FEMALE', 'OTHER', 'UNKNOWN'];
    private const VALID_PLATFORMS  = ['ANDROID', 'IOS', 'WEB'];
    private const VALID_TEMP_UNITS = ['C', 'F'];
    private const TEMP_C_MIN       = 25.00;
    private const TEMP_C_MAX       = 45.00;
    private const TEMP_F_MIN       = 77.00;
    private const TEMP_F_MAX       = 113.00;

    /** Maximum seconds a captured_at may be ahead of server clock (clock drift). */
    private const FUTURE_DRIFT_SECONDS = 300;

    /** Notification priority thresholds (Celsius). */
    private const TEMP_HIGH_C     = 37.5;
    private const TEMP_CRITICAL_C = 38.5;

    /** Max records returned per page. */
    private const MAX_PER_PAGE = 200;

    // ════════════════════════════════════════════════════════════════════════════
    // POST /primary-screenings
    // Create one primary screening record. If symptoms_present=1, atomically
    // creates a SECONDARY_REFERRAL notification in the same DB transaction.
    // ════════════════════════════════════════════════════════════════════════════

    public function store(Request $request): JsonResponse
    {
        // ── STEP 1: AUTH CONTEXT ─────────────────────────────────────────────
        // We resolve the submitting user from user_id in the request body.
        // (No Bearer token middleware yet — per LAW 2 of the dev laws.)
        // The view sends captured_by_user_id; we trust it and cross-check
        // against their user_assignments for geographic scope.

        $userId = (int) $request->input('captured_by_user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'captured_by_user_id is required and must be a positive integer.', [
                'field'    => 'captured_by_user_id',
                'received' => $request->input('captured_by_user_id'),
                'hint'     => 'Send the authenticated user\'s integer id from AUTH_DATA.id stored in sessionStorage.',
            ]);
        }

        $user = $this->resolveUser($userId);
        if ($user === null) {
            return $this->err(404, 'User not found.', [
                'captured_by_user_id' => $userId,
                'hint'                => 'The user id does not exist in the users table.',
            ]);
        }
        if (! (bool) $user->is_active) {
            return $this->err(403, 'User account is inactive. This screening cannot be recorded.', [
                'user_id' => $userId,
                'hint'    => 'Reactivate the user via the admin panel or directly: UPDATE users SET is_active=1 WHERE id=' . $userId,
            ]);
        }

        // ── STEP 2: RESOLVE GEOGRAPHIC SCOPE ────────────────────────────────
        // poe_code, district_code, province_code, pheoc_code, country_code
        // MUST come from the user's active assignment — NOT from the request body.
        // A screener cannot submit records for a POE they are not assigned to.

        $assignment = $this->resolvePrimaryAssignment($userId);
        if ($assignment === null) {
            return $this->err(403, 'No active geographic assignment found for this user.', [
                'user_id' => $userId,
                'hint'    => 'The user has no row in user_assignments with is_active=1 AND (ends_at IS NULL OR ends_at > NOW()). '
                . 'Create an assignment before attempting to screen.',
            ]);
        }

        // POE-level roles require a poe_code on their assignment.
        $serverPoeCode = $assignment->poe_code ?? null;
        if (empty($serverPoeCode)) {
            return $this->err(403, 'User assignment has no poe_code. Cannot record a primary screening without a POE assignment.', [
                'user_id'          => $userId,
                'assignment_id'    => $assignment->id,
                'assignment_level' => 'No poe_code — this user may be a supervisor role with no direct POE.',
                'hint'             => 'Assign the user to a specific POE (set poe_code in user_assignments).',
            ]);
        }

        // ── STEP 3: VALIDATE REQUEST BODY ────────────────────────────────────

        $v = Validator::make($request->all(), [
            'client_uuid'            => ['required', 'string', 'size:36',
                'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i'],
            'reference_data_version' => ['required', 'string', 'max:40'],
            'gender'                 => ['required', 'string', 'in:MALE,FEMALE,OTHER,UNKNOWN'],
            'symptoms_present'       => ['required', 'integer', 'in:0,1'],
            'captured_at'            => ['required', 'date_format:Y-m-d H:i:s'],
            'device_id'              => ['required', 'string', 'max:80'],
            'platform'               => ['nullable', 'string', 'in:ANDROID,IOS,WEB'],
            'app_version'            => ['nullable', 'string', 'max:40'],
            'captured_timezone'      => ['nullable', 'string', 'max:64'],
            // Temperature — both present or both absent
            'temperature_value'      => ['nullable', 'numeric'],
            'temperature_unit'       => ['nullable', 'string', 'in:C,F'],
            // Traveler name — optional at primary screening.
            // Required only when referring to secondary (handled in notification block below).
            'traveler_full_name'     => ['nullable', 'string', 'max:150'],
            // primary_screenings.traveler_direction enum('ENTRY','EXIT','TRANSIT')
            'traveler_direction'     => ['nullable', 'string', 'in:ENTRY,EXIT,TRANSIT'],
            'record_version'         => ['nullable', 'integer', 'min:1'],
        ]);

        if ($v->fails()) {
            return $this->err(422, 'Validation failed. See error.validation_errors for field-level detail.', [
                'validation_errors' => $v->errors()->toArray(),
                'received_keys'     => array_keys($request->all()),
                'hint'              => 'All fields are documented in the API spec. '
                . 'captured_at must be "Y-m-d H:i:s" format (MySQL datetime). '
                . 'client_uuid must be a valid UUID v4.',
            ]);
        }

        $data = $v->validated();

        // ── STEP 4: BUSINESS-RULE VALIDATIONS ────────────────────────────────

        // 4a. Temperature: value + unit must BOTH be set or BOTH be null.
        $tempValue = isset($data['temperature_value']) && $data['temperature_value'] !== '' && $data['temperature_value'] !== null
            ? (float) $data['temperature_value']
            : null;
        $tempUnit = $data['temperature_unit'] ?? null;

        if ($tempValue !== null && $tempUnit === null) {
            return $this->err(422, 'temperature_unit is required when temperature_value is provided.', [
                'temperature_value' => $tempValue,
                'temperature_unit'  => null,
                'hint'              => 'Send both temperature_value and temperature_unit (C or F), or send neither.',
            ]);
        }
        if ($tempValue === null && $tempUnit !== null) {
            return $this->err(422, 'temperature_value is required when temperature_unit is provided.', [
                'temperature_value' => null,
                'temperature_unit'  => $tempUnit,
                'hint'              => 'Send both temperature_value and temperature_unit (C or F), or send neither.',
            ]);
        }

        // 4b. Temperature clinical range check.
        if ($tempValue !== null && $tempUnit !== null) {
            $minT = $tempUnit === 'C' ? self::TEMP_C_MIN : self::TEMP_F_MIN;
            $maxT = $tempUnit === 'C' ? self::TEMP_C_MAX : self::TEMP_F_MAX;
            if ($tempValue < $minT || $tempValue > $maxT) {
                return $this->err(422, 'Temperature value is outside the valid clinical range.', [
                    'temperature_value'      => $tempValue,
                    'temperature_unit'       => $tempUnit,
                    'valid_range_celsius'    => ['min' => self::TEMP_C_MIN, 'max' => self::TEMP_C_MAX],
                    'valid_range_fahrenheit' => ['min' => self::TEMP_F_MIN, 'max' => self::TEMP_F_MAX],
                    'hint'                   => 'If the thermometer reading is outside this range, the device may be malfunctioning. Record without temperature.',
                ]);
            }
        }

        // 4b-bis. Referral integrity: when symptoms are present a notification
        // is created for secondary screening — the secondary officer must have
        // a real traveller name to dispatch on. Anonymous referrals are
        // refused at the API boundary so legacy data + future submissions
        // stay coherent.
        if ((int) $data['symptoms_present'] === 1) {
            $nameSrv = isset($data['traveler_full_name']) ? trim((string) $data['traveler_full_name']) : '';
            if (mb_strlen($nameSrv) < 2) {
                return $this->err(422, 'Traveller full name is required for any symptomatic referral.', [
                    'field'             => 'traveler_full_name',
                    'symptoms_present'  => 1,
                    'hint'              => 'Capture the traveller\'s name (min 2 characters) before flagging symptoms.',
                ]);
            }
        }

        // 4c. captured_at must not be in the future beyond drift window.
        $capturedAt = $data['captured_at'];
        $capturedTs = strtotime($capturedAt);
        $serverNow  = time();
        if ($capturedTs === false) {
            return $this->err(422, 'captured_at could not be parsed as a datetime.', [
                'captured_at'     => $capturedAt,
                'expected_format' => 'Y-m-d H:i:s',
            ]);
        }
        if ($capturedTs > ($serverNow + self::FUTURE_DRIFT_SECONDS)) {
            return $this->err(422, 'captured_at is too far in the future. Check device clock.', [
                'captured_at'          => $capturedAt,
                'server_utc_now'       => date('Y-m-d H:i:s', $serverNow),
                'max_future_drift_sec' => self::FUTURE_DRIFT_SECONDS,
                'difference_seconds'   => $capturedTs - $serverNow,
                'hint'                 => 'Sync the device clock. Allow up to ' . self::FUTURE_DRIFT_SECONDS . 's of drift.',
            ]);
        }

        $clientUuid = (string) $data['client_uuid'];

        // ── STEP 5: IDEMPOTENCY CHECK ─────────────────────────────────────────
        // If this exact client_uuid already exists → return the existing server
        // record. This is a clean re-submission (e.g. app retried after timeout).
        // Return 200, not 409. Include a flag so the view knows it was a duplicate.

        try {
            $existing = DB::table('primary_screenings')
                ->where('client_uuid', $clientUuid)
                ->first();
        } catch (Throwable $e) {
            return $this->dbError($e, 'primary_screenings idempotency lookup');
        }

        if ($existing !== null) {
            // Fetch linked notification (if any) to return full picture.
            $existingNotif = DB::table('notifications')
                ->where('primary_screening_id', $existing->id)
                ->first();

            Log::info('[PrimaryScreeningController] idempotent re-submission', [
                'client_uuid' => $clientUuid,
                'server_id'   => $existing->id,
                'user_id'     => $userId,
            ]);

            return response()->json([
                'success'    => true,
                'message'    => 'Record already exists. Returning existing server record (idempotent re-submission).',
                'idempotent' => true,
                'data'       => $this->formatScreening($existing, $existingNotif),
            ], 200);
        }

        // ── STEP 6: DETERMINE NOTIFICATION PRIORITY ──────────────────────────
        // Priority is calculated here so the view never has to guess.
        // CRITICAL: temp >= 38.5°C AND symptoms
        // HIGH:     temp >= 37.5°C AND symptoms
        // NORMAL:   symptoms present without elevated temp (or no temp measured)
        // NO NOTIF: symptoms_present = 0

        $symptomsPresent = (int) $data['symptoms_present'];
        $priority        = 'NORMAL';

        if ($symptomsPresent === 1 && $tempValue !== null) {
            $tempC = $tempUnit === 'F'
                ? ($tempValue - 32) * 5 / 9
                : $tempValue;

            if ($tempC >= self::TEMP_CRITICAL_C) {
                $priority = 'CRITICAL';
            } elseif ($tempC >= self::TEMP_HIGH_C) {
                $priority = 'HIGH';
            }
        }

        // ── STEP 7: BUILD RECORD PAYLOAD ─────────────────────────────────────

        $now          = now()->toDateTimeString();
        $gender       = strtoupper((string) $data['gender']);
        $platform     = strtoupper((string) ($data['platform'] ?? 'ANDROID'));
        $travelerName = isset($data['traveler_full_name'])
            ? trim((string) $data['traveler_full_name'])
            : null;
        $travelerName  = $travelerName === '' ? null : $travelerName;
        $recordVersion = max(1, (int) ($data['record_version'] ?? 1));

        $screeningRow = [
            'client_uuid'            => $clientUuid,
            'idempotency_key'        => null,
            'reference_data_version' => (string) $data['reference_data_version'],
            'server_received_at'     => $now,
            // Geographic scope — ALWAYS from server-resolved assignment, never from request
            'country_code'           => (string) $assignment->country_code,
            'province_code'          => $assignment->province_code,
            'pheoc_code'             => $assignment->pheoc_code,
            'district_code'          => (string) $assignment->district_code,
            'poe_code'               => (string) $assignment->poe_code,
            // Clinical
            'captured_by_user_id'    => $userId,
            'gender'                 => $gender,
            'traveler_full_name'     => $travelerName,
            'traveler_direction'     => $data['traveler_direction'] ?? null,
            'temperature_value'      => $tempValue,
            'temperature_unit'       => $tempUnit,
            'symptoms_present'       => $symptomsPresent,
            'captured_at'            => $capturedAt,
            'captured_timezone'      => $data['captured_timezone'] ?? null,
            // Device
            'device_id'              => (string) $data['device_id'],
            'app_version'            => $data['app_version'] ?? null,
            'platform'               => in_array($platform, self::VALID_PLATFORMS) ? $platform : 'ANDROID',
                                           // Control
            'referral_created'       => 0, // stamped to 1 inside transaction if symptoms
            'record_version'         => $recordVersion,
            'record_status'          => 'COMPLETED',
            'void_reason'            => null,
            'deleted_at'             => null,
            'sync_status'            => 'SYNCED',
            'synced_at'              => $now,
            'sync_attempt_count'     => 0,
            'last_sync_error'        => null,
            'created_at'             => $now,
            'updated_at'             => $now,
        ];

        // ── STEP 8: ATOMIC DB WRITE ───────────────────────────────────────────
        // We use DB::transaction() to guarantee that:
        //   (a) primary_screenings row and (b) notifications row
        // are written together or not at all.
        //
        // If the notification insert fails after the primary insert, we roll back
        // everything. The client will retry and get the same behaviour until both
        // succeed. A half-written state (primary exists but no notification) is
        // never possible.

        $screeningId    = null;
        $notificationId = null;
        $notifUuid      = null;

        try {
            DB::transaction(function () use (
                $screeningRow, $symptomsPresent, $priority, $userId,
                $assignment, $gender, $tempValue, $tempUnit, $travelerName,
                $now, &$screeningId, &$notificationId, &$notifUuid
            ) {
                // ── INSERT PRIMARY SCREENING ──────────────────────────────────
                if ($symptomsPresent === 1) {
                    $screeningRow['referral_created'] = 1;
                }

                $screeningId = DB::table('primary_screenings')->insertGetId($screeningRow);

                if ($screeningId === null || $screeningId === 0) {
                    throw new \RuntimeException('primary_screenings insertGetId returned 0. Insert may have silently failed.');
                }

                // ── INSERT NOTIFICATION (if symptomatic) ──────────────────────
                if ($symptomsPresent === 1) {
                    // Build the reason_text that the secondary officer sees.
                    // Contains everything they need without opening the full record.
                    $officerName = $this->resolveOfficerName($userId);
                    $tempSummary = $tempValue !== null
                        ? number_format($tempValue, 1) . '°' . $tempUnit
                        : 'Not measured';

                    $reasonText = sprintf(
                        'Symptoms present. Gender: %s. Temp: %s. Priority: %s. Traveler: %s. '
                        . 'POE: %s. District: %s. PHEOC: %s. Officer: %s.',
                        $gender,
                        $tempSummary,
                        $priority,
                        $travelerName ?? '[Not captured]',
                        $assignment->poe_code,
                        $assignment->district_code ?? '—',
                        $assignment->pheoc_code ?? '—',
                        $officerName
                    );
                    // Defensive cap: column is TEXT (≈65 KB) since migration
                    // 2026_04_23_000005 — this 60k bound is belt-and-suspenders
                    // insurance against a future schema narrow or a pathological
                    // traveler name. A realistic reason_text is ≤ 400 chars.
                    if (mb_strlen($reasonText, 'UTF-8') > 60000) {
                        $reasonText = mb_substr($reasonText, 0, 60000, 'UTF-8');
                    }

                    $notifUuid = (string) \Illuminate\Support\Str::uuid();

                    $notifRow = [
                        'client_uuid'            => $notifUuid,
                        'idempotency_key'        => null,
                        'reference_data_version' => $screeningRow['reference_data_version'],
                        'server_received_at'     => $now,
                        'country_code'           => $screeningRow['country_code'],
                        'province_code'          => $screeningRow['province_code'],
                        'pheoc_code'             => $screeningRow['pheoc_code'],
                        'district_code'          => $screeningRow['district_code'],
                        'poe_code'               => $screeningRow['poe_code'],
                        'primary_screening_id'   => $screeningId,
                        'created_by_user_id'     => $userId,
                        'notification_type'      => 'SECONDARY_REFERRAL',
                        'status'                 => 'OPEN',
                        'priority'               => $priority,
                        'reason_code'            => 'PRIMARY_SYMPTOMS_DETECTED',
                        'reason_text'            => $reasonText,
                        'assigned_role_key'      => 'POE_SECONDARY',
                        'assigned_user_id'       => null,
                        'opened_at'              => null,
                        'closed_at'              => null,
                        'device_id'              => $screeningRow['device_id'],
                        'app_version'            => $screeningRow['app_version'],
                        'platform'               => $screeningRow['platform'],
                        'record_version'         => 1,
                        'deleted_at'             => null,
                        'sync_status'            => 'SYNCED',
                        'synced_at'              => $now,
                        'sync_attempt_count'     => 0,
                        'last_sync_error'        => null,
                        'created_at'             => $now,
                        'updated_at'             => $now,
                    ];

                    $notificationId = DB::table('notifications')->insertGetId($notifRow);

                    if ($notificationId === null || $notificationId === 0) {
                        throw new \RuntimeException(
                            'notifications insertGetId returned 0. Notification insert may have silently failed. '
                            . 'Rolled back. Retry the request.'
                        );
                    }
                }
            });
        } catch (Throwable $e) {
            return $this->dbError($e, 'primary_screenings + notifications atomic insert');
        }

        // ── STEP 9: FETCH INSERTED RECORDS AND RETURN ────────────────────────
        // Re-fetch from DB so the response contains every column exactly as stored.
        // Never trust the in-memory $screeningRow for the response — the DB may
        // have applied defaults or triggers that differ from what we sent.

        try {
            $inserted = DB::table('primary_screenings')->where('id', $screeningId)->first();
            $notif    = $notificationId
                ? DB::table('notifications')->where('id', $notificationId)->first()
                : null;
        } catch (Throwable $e) {
            // The insert succeeded — don't roll back. Return a partial success.
            Log::warning('[PrimaryScreeningController] inserted but re-fetch failed', [
                'screening_id'    => $screeningId,
                'notification_id' => $notificationId,
                'exception'       => get_class($e),
                'message'         => $e->getMessage(),
            ]);
            return response()->json([
                'success' => true,
                'message' => 'Record inserted but re-fetch failed. Use screening_id to retrieve. See warning.',
                'warning' => 'Re-fetch from primary_screenings WHERE id=' . $screeningId . ' failed: ' . $e->getMessage(),
                'data'    => [
                    'id'              => $screeningId,
                    'client_uuid'     => $clientUuid,
                    'notification_id' => $notificationId,
                ],
            ], 200);
        }

        Log::info('[PrimaryScreeningController] store ok', [
            'screening_id'     => $screeningId,
            'notification_id'  => $notificationId,
            'symptoms_present' => $symptomsPresent,
            'priority'         => $priority,
            'poe_code'         => $assignment->poe_code,
            'user_id'          => $userId,
        ]);

        // Fire-and-forget email notification when a referral is created.
        // Silent on no-referral / low-priority; emits HIGH/CRITICAL template
        // to the POE + DISTRICT + PHEOC + NATIONAL ladder.
        $notificationDispatch = null;
        if ($symptomsPresent === 1 && $notif) {
            $notificationDispatch = \App\Services\NotificationDispatcher::dispatchScreeningReferral(
                $inserted, $notif, $userId
            );
            Log::info('[PrimaryScreeningController] Referral notification dispatched', (array) $notificationDispatch);
        }

        $responseMessage = $symptomsPresent === 1
            ? 'Primary screening recorded. Symptomatic traveler — referral notification created (priority: ' . $priority . ').'
            : 'Primary screening recorded. No symptoms detected.';

        return response()->json([
            'success'    => true,
            'message'    => $responseMessage,
            'idempotent' => false,
            'data'       => $this->formatScreening($inserted, $notif),
            'notification' => $notificationDispatch,
        ], 201);
    }

    // ════════════════════════════════════════════════════════════════════════════
    // GET /primary-screenings
    // Paginated list scoped to the requesting user's POE.
    // ════════════════════════════════════════════════════════════════════════════

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->input('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required as a query parameter or request body field.', [
                'hint' => 'Pass ?user_id=N or send user_id in the request body. No Bearer token is in use yet.',
            ]);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if ($assignment === null) {
            return $this->err(403, 'No active assignment found for user_id=' . $userId . '.', []);
        }
        if (empty($assignment->poe_code)) {
            return $this->err(403, 'User assignment has no poe_code. Cannot scope primary screenings.', [
                'user_id' => $userId,
            ]);
        }

                                                               // Parse filters
        $dateFrom       = $request->input('date_from');        // YYYY-MM-DD
        $dateTo         = $request->input('date_to');          // YYYY-MM-DD
        $symptomsFilter = $request->input('symptoms_present'); // 0 | 1 | null
        $statusFilter   = $request->input('record_status');    // COMPLETED | VOIDED | null (default: COMPLETED only)
        $genderFilter   = $request->input('gender');
        $perPage        = min((int) $request->input('per_page', 50), self::MAX_PER_PAGE);
        $page           = max(1, (int) $request->input('page', 1));
        $offset         = ($page - 1) * $perPage;

        try {
            $q = DB::table('primary_screenings as ps')
                ->leftJoin('notifications as n', 'n.primary_screening_id', '=', 'ps.id')
                ->select([
                    'ps.*',
                    'n.id        as notification_id',
                    'n.status    as notification_status',
                    'n.priority  as notification_priority',
                    'n.closed_at as notification_closed_at',
                ])
                ->where('ps.poe_code', $assignment->poe_code)
                ->whereNull('ps.deleted_at');

            // Default: only COMPLETED unless caller explicitly requests VOIDED or ALL
            if ($statusFilter === 'VOIDED') {
                $q->where('ps.record_status', 'VOIDED');
            } elseif ($statusFilter === 'ALL') {
                // No filter — return both COMPLETED and VOIDED
            } else {
                $q->where('ps.record_status', 'COMPLETED');
            }

            if ($dateFrom) {
                $q->where('ps.captured_at', '>=', $dateFrom . ' 00:00:00');
            }
            if ($dateTo) {
                $q->where('ps.captured_at', '<=', $dateTo . ' 23:59:59');
            }
            if ($symptomsFilter !== null && $symptomsFilter !== '') {
                $q->where('ps.symptoms_present', (int) $symptomsFilter);
            }
            if ($genderFilter && in_array(strtoupper($genderFilter), self::VALID_GENDERS)) {
                $q->where('ps.gender', strtoupper($genderFilter));
            }

            $total = $q->count();
            $rows  = $q->orderBy('ps.captured_at', 'desc')
                ->limit($perPage)
                ->offset($offset)
                ->get();

        } catch (Throwable $e) {
            return $this->dbError($e, 'primary_screenings index query');
        }

        return response()->json([
            'success' => true,
            'message' => 'Primary screenings retrieved.',
            'data'    => [
                'items'    => $rows->map(fn($r) => $this->formatScreeningWithNotifColumns($r)),
                'total'    => $total,
                'per_page' => $perPage,
                'page'     => $page,
                'pages'    => (int) ceil($total / $perPage),
                'poe_code' => $assignment->poe_code,
                'filters'  => compact('dateFrom', 'dateTo', 'symptomsFilter', 'statusFilter', 'genderFilter'),
            ],
        ], 200);
    }

    // ════════════════════════════════════════════════════════════════════════════
    // GET /primary-screenings/{id}
    // Full record + linked notification + secondary case stub.
    // ════════════════════════════════════════════════════════════════════════════

    public function show(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->input('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required.', ['hint' => 'Pass ?user_id=N on the query string.']);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if ($assignment === null) {
            return $this->err(403, 'No active assignment found for user_id=' . $userId . '.', []);
        }

        try {
            $screening = DB::table('primary_screenings')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();
        } catch (Throwable $e) {
            return $this->dbError($e, 'primary_screenings show lookup id=' . $id);
        }

        if ($screening === null) {
            return $this->err(404, 'Primary screening record not found.', [
                'id'   => $id,
                'hint' => 'Check the id. Deleted records (deleted_at IS NOT NULL) are not returned.',
            ]);
        }

        // Geographic scope enforcement — user can only see records at their POE.
        if (! empty($assignment->poe_code) && $screening->poe_code !== $assignment->poe_code) {
            return $this->err(403, 'Access denied. This record belongs to a different POE.', [
                'record_poe_code' => $screening->poe_code,
                'user_poe_code'   => $assignment->poe_code,
                'hint'            => 'Users can only access primary screenings from their assigned POE.',
            ]);
        }

        // Fetch linked notification (null if no referral or non-symptomatic)
        $notif = null;
        if ((int) $screening->referral_created === 1) {
            try {
                $notif = DB::table('notifications')
                    ->where('primary_screening_id', $screening->id)
                    ->first();
            } catch (Throwable $e) {
                Log::warning('[PrimaryScreeningController] notification fetch failed for screening ' . $id, [
                    'exception' => get_class($e), 'message' => $e->getMessage(),
                ]);
            }
        }

        // Fetch secondary case stub if a secondary case was opened
        $secondaryCase = null;
        if ($notif !== null) {
            try {
                $secondaryCase = DB::table('secondary_screenings')
                    ->where('notification_id', $notif->id)
                    ->select(['id', 'case_status', 'opened_at', 'closed_at', 'final_disposition', 'risk_level', 'syndrome_classification'])
                    ->first();
            } catch (Throwable $e) {
                Log::warning('[PrimaryScreeningController] secondary case fetch failed', [
                    'notification_id' => $notif?->id, 'message' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Primary screening record retrieved.',
            'data'    => array_merge(
                $this->formatScreening($screening, $notif),
                ['secondary_case' => $secondaryCase ? $this->formatSecondaryStub($secondaryCase) : null]
            ),
        ], 200);
    }

    // ════════════════════════════════════════════════════════════════════════════
    // PATCH /primary-screenings/{id}/void
    // Void a primary screening record. Auto-closes linked OPEN notification.
    // ════════════════════════════════════════════════════════════════════════════

    public function void(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->input('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required.', []);
        }

        $user = $this->resolveUser($userId);
        if ($user === null) {
            return $this->err(404, 'User not found.', ['user_id' => $userId]);
        }

        $v = Validator::make($request->all(), [
            'void_reason' => ['required', 'string', 'min:10', 'max:255'],
        ]);
        if ($v->fails()) {
            return $this->err(422, 'void_reason is required (minimum 10 characters).', [
                'validation_errors' => $v->errors()->toArray(),
                'hint'              => 'Provide a meaningful reason for voiding this record.',
            ]);
        }
        $voidReason = trim((string) $v->validated()['void_reason']);

        try {
            $screening = DB::table('primary_screenings')
                ->where('id', $id)
                ->whereNull('deleted_at')
                ->first();
        } catch (Throwable $e) {
            return $this->dbError($e, 'primary_screenings void lookup');
        }

        if ($screening === null) {
            return $this->err(404, 'Primary screening record not found.', ['id' => $id]);
        }

        if ($screening->record_status === 'VOIDED') {
            return $this->err(409, 'Record is already voided. No action taken.', [
                'id'            => $id,
                'record_status' => 'VOIDED',
                'void_reason'   => $screening->void_reason,
                'hint'          => 'A voided record cannot be voided again.',
            ]);
        }

        // Permission check:
        // - The creating officer: within 24 hours of captured_at
        // - POE_ADMIN / NATIONAL_ADMIN: anytime
        $roleKey      = strtoupper((string) ($user->role_key ?? ''));
        $isAdmin      = in_array($roleKey, ['POE_ADMIN', 'NATIONAL_ADMIN'], true);
        $isCreator    = (int) $screening->captured_by_user_id === $userId;
        $withinWindow = strtotime($screening->captured_at) > (time() - 86400); // 24h

        if (! $isAdmin && ! ($isCreator && $withinWindow)) {
            return $this->err(403, 'You do not have permission to void this record.', [
                'record_id'           => $id,
                'your_user_id'        => $userId,
                'your_role_key'       => $roleKey,
                'captured_by_user_id' => $screening->captured_by_user_id,
                'captured_at'         => $screening->captured_at,
                'void_window_hours'   => 24,
                'hint'                => 'You can void your own records within 24 hours of capture. POE_ADMIN and NATIONAL_ADMIN can void anytime.',
            ]);
        }

        $now = now()->toDateTimeString();

        try {
            DB::transaction(function () use ($id, $voidReason, $now, $screening) {
                // Void the primary screening
                DB::table('primary_screenings')
                    ->where('id', $id)
                    ->update([
                        'record_status'  => 'VOIDED',
                        'void_reason'    => $voidReason,
                        'record_version' => DB::raw('record_version + 1'),
                        'updated_at'     => $now,
                    ]);

                // Auto-close linked notification IF it is OPEN (not yet picked up by secondary)
                // If status is IN_PROGRESS, the secondary officer must close the case themselves.
                if ((int) $screening->referral_created === 1) {
                    DB::table('notifications')
                        ->where('primary_screening_id', $id)
                        ->where('status', 'OPEN')
                        ->update([
                            'status'         => 'CLOSED',
                            'closed_at'      => $now,
                            'reason_text'    => 'Primary screening voided: ' . $voidReason,
                            'record_version' => DB::raw('record_version + 1'),
                            'updated_at'     => $now,
                        ]);
                    // IN_PROGRESS notifications are left for the secondary officer
                    // to handle — they are NOT auto-closed.
                }
            });
        } catch (Throwable $e) {
            return $this->dbError($e, 'primary_screenings void + notification close transaction');
        }

        // Fetch updated record for the response
        $updated = DB::table('primary_screenings')->where('id', $id)->first();
        $notif   = DB::table('notifications')->where('primary_screening_id', $id)->first();

        Log::info('[PrimaryScreeningController] record voided', [
            'screening_id' => $id,
            'voided_by'    => $userId,
            'void_reason'  => $voidReason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Primary screening record voided. Linked OPEN notification (if any) has been closed.',
            'data'    => $this->formatScreening($updated, $notif),
        ], 200);
    }

    // ════════════════════════════════════════════════════════════════════════════
    // GET /primary-screenings/stats/today
    // Today's screening summary for the user's POE.
    // ════════════════════════════════════════════════════════════════════════════

    public function stats(Request $request): JsonResponse
    {
        $userId = (int) $request->input('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required.', []);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if ($assignment === null) {
            return $this->err(403, 'No active assignment found for user_id=' . $userId . '.', []);
        }
        if (empty($assignment->poe_code)) {
            return $this->err(403, 'User assignment has no poe_code.', ['user_id' => $userId]);
        }

        $poeCode = $assignment->poe_code;
        $today   = now()->toDateString(); // YYYY-MM-DD in server timezone

        try {
            $totals = DB::table('primary_screenings')
                ->selectRaw("
                    COUNT(*)                                                AS total_screened,
                    SUM(CASE WHEN symptoms_present = 1 THEN 1 ELSE 0 END)  AS total_symptomatic,
                    SUM(CASE WHEN symptoms_present = 0 THEN 1 ELSE 0 END)  AS total_asymptomatic,
                    SUM(CASE WHEN gender = 'MALE'    THEN 1 ELSE 0 END)    AS total_male,
                    SUM(CASE WHEN gender = 'FEMALE'  THEN 1 ELSE 0 END)    AS total_female,
                    SUM(CASE WHEN gender = 'OTHER'   THEN 1 ELSE 0 END)    AS total_other,
                    SUM(CASE WHEN gender = 'UNKNOWN' THEN 1 ELSE 0 END)    AS total_unknown,
                    SUM(CASE WHEN referral_created = 1 THEN 1 ELSE 0 END)  AS total_referrals_created,
                    SUM(CASE WHEN temperature_value IS NOT NULL THEN 1 ELSE 0 END) AS total_with_temp,
                    MAX(captured_at)                                        AS last_capture_at
                ")
                ->where('poe_code', $poeCode)
                ->where('record_status', 'COMPLETED')
                ->whereNull('deleted_at')
                ->whereRaw("DATE(captured_at) = ?", [$today])
                ->first();

            $openReferrals = DB::table('notifications')
                ->where('poe_code', $poeCode)
                ->where('status', 'OPEN')
                ->where('notification_type', 'SECONDARY_REFERRAL')
                ->whereNull('deleted_at')
                ->count();

            $inProgressReferrals = DB::table('notifications')
                ->where('poe_code', $poeCode)
                ->where('status', 'IN_PROGRESS')
                ->where('notification_type', 'SECONDARY_REFERRAL')
                ->whereNull('deleted_at')
                ->count();

            $pendingSync = DB::table('primary_screenings')
                ->where('poe_code', $poeCode)
                ->where('sync_status', 'UNSYNCED')
                ->whereNull('deleted_at')
                ->count();

        } catch (Throwable $e) {
            return $this->dbError($e, 'primary_screenings stats query');
        }

        return response()->json([
            'success' => true,
            'message' => 'Today\'s screening statistics for POE: ' . $poeCode,
            'data'    => [
                'poe_code'                => $poeCode,
                'date'                    => $today,
                'total_screened'          => (int) ($totals->total_screened ?? 0),
                'total_symptomatic'       => (int) ($totals->total_symptomatic ?? 0),
                'total_asymptomatic'      => (int) ($totals->total_asymptomatic ?? 0),
                'total_male'              => (int) ($totals->total_male ?? 0),
                'total_female'            => (int) ($totals->total_female ?? 0),
                'total_other'             => (int) ($totals->total_other ?? 0),
                'total_unknown'           => (int) ($totals->total_unknown ?? 0),
                'total_referrals_created' => (int) ($totals->total_referrals_created ?? 0),
                'total_with_temp'         => (int) ($totals->total_with_temp ?? 0),
                'last_capture_at'         => $totals->last_capture_at ?? null,
                'open_referrals'          => (int) $openReferrals,
                'in_progress_referrals'   => (int) $inProgressReferrals,
                'pending_sync'            => (int) $pendingSync,
            ],
        ], 200);
    }

    // ════════════════════════════════════════════════════════════════════════════
    // GET /referral-queue
    // Open SECONDARY_REFERRAL notifications at the user's POE.
    // This is what the secondary screening officer sees.
    // ════════════════════════════════════════════════════════════════════════════

    public function queue(Request $request): JsonResponse
    {
        $userId = (int) $request->input('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required.', []);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if ($assignment === null) {
            return $this->err(403, 'No active assignment found for user_id=' . $userId . '.', []);
        }

        // Resolve the user's role to apply the correct jurisdiction scope.
        // NATIONAL_ADMIN sees all notifications for their country.
        // PHEOC_OFFICER sees their province.
        // DISTRICT_SUPERVISOR sees their district.
        // POE-level roles (POE_PRIMARY, POE_SECONDARY, POE_DATA_OFFICER, POE_ADMIN, SCREENER)
        // see only their POE — requires poe_code on assignment.
        $user     = DB::table('users')->where('id', $userId)->first(['role_key']);
        $roleKey  = strtoupper((string) ($user->role_key ?? ''));

        // Accept status filter: OPEN (default), IN_PROGRESS, ALL
        $statusFilter = strtoupper((string) $request->input('status', 'OPEN'));
        $perPage      = min((int) $request->input('per_page', 50), 200);
        $page         = max(1, (int) $request->input('page', 1));
        $offset       = ($page - 1) * $perPage;

        try {
            $q = DB::table('notifications as n')
                ->join('primary_screenings as ps', 'ps.id', '=', 'n.primary_screening_id')
                ->leftJoin('users as u', 'u.id', '=', 'n.created_by_user_id')
                ->select([
                    // Notification fields
                    'n.id                    as notification_id',
                    'n.client_uuid           as notification_uuid',
                    'n.status                as notification_status',
                    'n.priority              as notification_priority',
                    'n.reason_code',
                    'n.reason_text',
                    'n.assigned_role_key',
                    'n.assigned_user_id',
                    'n.opened_at             as notification_opened_at',
                    'n.closed_at             as notification_closed_at',
                    'n.created_at            as notification_created_at',
                    // 2026-05-06: record_version is required by the client's
                    // writeToIdb to apply mutable-field updates (status / closed_at).
                    // Without it the client receives null -> coerces to 1 -> update branch
                    // never fires, so cross-device CLOSED status changes never propagate.
                    'n.record_version        as record_version',
                    'n.updated_at            as notification_updated_at',
                    'n.poe_code              as notification_poe_code',
                    'n.country_code          as notification_country_code',
                    'n.province_code         as notification_province_code',
                    'n.pheoc_code            as notification_pheoc_code',
                    'n.district_code         as notification_district_code',
                    // Primary screening context
                    'ps.id                   as primary_screening_id',
                    'ps.client_uuid          as primary_uuid',
                    'ps.gender',
                    'ps.temperature_value',
                    'ps.temperature_unit',
                    'ps.symptoms_present',
                    'ps.captured_at',
                    'ps.captured_timezone',
                    'ps.traveler_full_name',
                    'ps.record_status        as primary_record_status',
                    // Officer who created the primary screening
                    'u.full_name             as screener_name',
                    'u.role_key              as screener_role',
                ])
                ->where('n.notification_type', 'SECONDARY_REFERRAL')
                ->whereNull('n.deleted_at')
                ->whereNull('ps.deleted_at')
                // Hard country isolation — ALWAYS filter to this tenant's country_code.
                // Prevents cross-country data bleed (e.g. Zambia records appearing in
                // Uganda) regardless of role. The API's COUNTRY_CODE env var is the
                // authoritative tenant identifier.
                ->where('n.country_code', env('COUNTRY_CODE', $assignment->country_code ?? 'RW'));

            // ── Jurisdiction scope — role-aware ────────────────────────────
            if ($roleKey === 'NATIONAL_ADMIN') {
                // National: scope to country only — sees every POE
                $q->where('n.country_code', $assignment->country_code ?? 'RW');
            } elseif ($roleKey === 'PHEOC_OFFICER') {
                // Province: scope to province/PHEOC code
                $pheocCode = $assignment->pheoc_code ?? $assignment->province_code ?? null;
                if ($pheocCode) {
                    $q->where('n.pheoc_code', $pheocCode);
                } else {
                    $q->where('n.country_code', $assignment->country_code ?? 'RW');
                }
            } elseif ($roleKey === 'DISTRICT_SUPERVISOR') {
                // District: scope to district code
                $districtCode = $assignment->district_code ?? null;
                if ($districtCode) {
                    $q->where('n.district_code', $districtCode);
                } else {
                    $q->where('n.country_code', $assignment->country_code ?? 'RW');
                }
            } else {
                // POE-level (POE_ADMIN, POE_PRIMARY, POE_SECONDARY, POE_DATA_OFFICER, SCREENER)
                // Must have a poe_code on their assignment.
                $poeCode = $assignment->poe_code ?? null;
                if (empty($poeCode)) {
                    return $this->err(403, 'User assignment has no poe_code. POE-level roles require a POE assignment.', ['user_id' => $userId, 'role' => $roleKey]);
                }

                // Naming-convention robustness: the same physical POE may be
                // stored as a short code ('RW-KIC-001') in one assignment and
                // as a full name ('Entebbe International Airport') in another.
                // Resolve all equivalent poe_code values from ref_poes so
                // every screener at the same gate sees the same queue.
                $equivalentCodes = [$poeCode];
                try {
                    $poeMeta = DB::table('ref_poes')
                        ->where(function ($qr) use ($poeCode) {
                            $qr->where('poe_code', $poeCode)
                               ->orWhere('poe_name', $poeCode);
                        })
                        ->where('is_active', 1)
                        ->whereNull('deleted_at')
                        ->first(['poe_code', 'poe_name']);
                    if ($poeMeta) {
                        if (! empty($poeMeta->poe_code)) $equivalentCodes[] = $poeMeta->poe_code;
                        if (! empty($poeMeta->poe_name))  $equivalentCodes[] = $poeMeta->poe_name;
                    }
                } catch (\Throwable $e) {
                    // ref_poes lookup failed — proceed with just the original code
                }
                $equivalentCodes = array_values(array_unique(array_filter($equivalentCodes)));

                if (count($equivalentCodes) === 1) {
                    $q->where('n.poe_code', $equivalentCodes[0]);
                } else {
                    $q->whereIn('n.poe_code', $equivalentCodes);
                }
            }

            if ($statusFilter === 'ALL') {
                // Return ALL statuses including CLOSED so the queue view can
                // show the full case history (officers must be able to see
                // closed/dispositioned cases they handled).
                $q->whereIn('n.status', ['OPEN', 'IN_PROGRESS', 'CLOSED']);
            } elseif ($statusFilter === 'CLOSED') {
                $q->where('n.status', 'CLOSED');
            } elseif ($statusFilter === 'IN_PROGRESS') {
                $q->where('n.status', 'IN_PROGRESS');
            } elseif ($statusFilter === 'OPEN_AND_IN_PROGRESS') {
                // Legacy default behaviour preserved for callers that still
                // want the old "active queue only" view.
                $q->whereIn('n.status', ['OPEN', 'IN_PROGRESS']);
            } else {
                // Default: OPEN only
                $q->where('n.status', 'OPEN');
            }

            // Incremental sync support: only return rows updated after this
            // ISO-8601 timestamp. The mobile app passes its last-sync cursor
            // here so 8-second polling becomes near-zero-cost.
            if ($request->filled('updated_after')) {
                $after = $request->query('updated_after');
                try {
                    $afterDt = new \DateTimeImmutable($after);
                    $q->where('n.updated_at', '>', $afterDt->format('Y-m-d H:i:s'));
                } catch (\Throwable) {
                    // Invalid timestamp — ignore, fall through to full query.
                }
            }

            $total = $q->count();
            $rows  = $q
                // Status ordering: active cases (OPEN, IN_PROGRESS) above CLOSED
                ->orderByRaw("FIELD(n.status, 'OPEN', 'IN_PROGRESS', 'CLOSED')")
                // Priority within active: CRITICAL first
                ->orderByRaw("FIELD(n.priority, 'CRITICAL', 'HIGH', 'NORMAL')")
                // Most recent first overall — newest activity on top
                ->orderBy('n.created_at', 'desc')
                ->limit($perPage)
                ->offset($offset)
                ->get();

        } catch (Throwable $e) {
            return $this->dbError($e, 'referral queue query');
        }

        // Build a human-readable scope label for the response message
        $scopeLabel = match (true) {
            $roleKey === 'NATIONAL_ADMIN'      => 'country: ' . ($assignment->country_code ?? 'RW'),
            $roleKey === 'PHEOC_OFFICER'       => 'province: ' . ($assignment->pheoc_code ?? $assignment->province_code ?? '?'),
            $roleKey === 'DISTRICT_SUPERVISOR' => 'district: ' . ($assignment->district_code ?? '?'),
            default                            => 'POE: ' . ($poeCode ?? '?'),
        };

        return response()->json([
            'success' => true,
            'message' => 'Referral queue retrieved for ' . $scopeLabel,
            'data'    => [
                'items'         => $rows->map(fn($r) => $this->formatQueueItem($r)),
                'total'         => $total,
                'per_page'      => $perPage,
                'page'          => $page,
                'pages'         => (int) ceil($total / $perPage),
                'poe_code'      => $poeCode ?? null,
                'scope'         => $scopeLabel,
                'role'          => $roleKey,
                'status_filter' => $statusFilter,
            ],
        ], 200);
    }

    // ════════════════════════════════════════════════════════════════════════════
    // PATCH /referral-queue/{notifId}/cancel
    //
    // CRITICAL BUSINESS RULE:
    // Canceling a referral closes the NOTIFICATION only.
    // The primary screening record remains COMPLETED and untouched.
    // referral_created stays = 1 (immutable audit — the referral WAS issued).
    // The traveler was symptomatic. That is a historical fact. We document
    // the decision to cancel the secondary investigation, not erase it.
    //
    // Think of it as: "We created a referral. Then we decided not to investigate.
    // Both facts are preserved in the audit trail."
    //
    // The view should reflect this: a canceled referral shows the primary record
    // as COMPLETED (symptomatic, referral issued, referral canceled) — not voided.
    // ════════════════════════════════════════════════════════════════════════════

    public function cancelReferral(Request $request, int $notifId): JsonResponse
    {
        $userId = (int) $request->input('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required.', []);
        }

        $v = Validator::make($request->all(), [
            'cancel_reason' => ['required', 'string', 'min:5', 'max:255'],
        ]);
        if ($v->fails()) {
            return $this->err(422, 'cancel_reason is required (minimum 5 characters).', [
                'validation_errors' => $v->errors()->toArray(),
                'hint'              => 'Document why this referral is being cancelled.',
            ]);
        }
        $cancelReason = trim((string) $v->validated()['cancel_reason']);

        // Resolve notification
        try {
            $notif = DB::table('notifications')
                ->where('id', $notifId)
                ->whereNull('deleted_at')
                ->first();
        } catch (Throwable $e) {
            return $this->dbError($e, 'notifications cancel lookup id=' . $notifId);
        }

        if ($notif === null) {
            return $this->err(404, 'Notification not found.', [
                'notification_id' => $notifId,
                'hint'            => 'The notification may not exist or may have been deleted.',
            ]);
        }

        // Scope check — notification must be at the user's POE
        $assignment = $this->resolvePrimaryAssignment($userId);
        if ($assignment === null || $notif->poe_code !== $assignment->poe_code) {
            return $this->err(403, 'Access denied. This notification belongs to a different POE.', [
                'notification_poe' => $notif->poe_code,
                'user_poe'         => $assignment->poe_code ?? null,
            ]);
        }

        // Only OPEN notifications can be canceled.
        if ($notif->status === 'CLOSED') {
            return $this->err(409, 'Notification is already closed. Cannot cancel a closed referral.', [
                'notification_id' => $notifId,
                'status'          => 'CLOSED',
                'closed_at'       => $notif->closed_at,
                'hint'            => 'This referral is already closed. No action needed.',
            ]);
        }

        // If IN_PROGRESS: a secondary case has already been opened. The secondary
        // officer must close the case — we cannot cancel from the primary side.
        if ($notif->status === 'IN_PROGRESS') {
            // Fetch the secondary case for context
            $secCase = DB::table('secondary_screenings')
                ->where('notification_id', $notifId)
                ->select(['id', 'case_status', 'opened_at'])
                ->first();

            return $this->err(409, 'Cannot cancel this referral. A secondary screening case has already been opened.', [
                'notification_id'       => $notifId,
                'notification_status'   => 'IN_PROGRESS',
                'secondary_case_id'     => $secCase?->id,
                'secondary_case_status' => $secCase?->case_status,
                'secondary_opened_at'   => $secCase?->opened_at,
                'hint'                  => 'The secondary officer must close this case from their secondary screening view. '
                . 'Use secondary_case_id to locate and close it.',
            ]);
        }

        $now           = now()->toDateTimeString();
        $user          = $this->resolveUser($userId);
        $cancellerName = $user ? ($user->full_name ?? $user->username ?? 'User #' . $userId) : 'User #' . $userId;

        try {
            DB::table('notifications')
                ->where('id', $notifId)
                ->update([
                    'status'         => 'CLOSED',
                    'closed_at'      => $now,
                    'reason_text'    => 'Referral cancelled by ' . $cancellerName . ': ' . $cancelReason,
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at'     => $now,
                ]);
        } catch (Throwable $e) {
            return $this->dbError($e, 'notifications cancel update id=' . $notifId);
        }

        // Fetch the primary screening for the response so the view has the
        // complete picture of what happened.
        $primaryScreening = null;
        try {
            $primaryScreening = DB::table('primary_screenings')
                ->where('id', $notif->primary_screening_id)
                ->first();
        } catch (Throwable $e) {
            Log::warning('[PrimaryScreeningController] cancelReferral: primary re-fetch failed', [
                'primary_id' => $notif->primary_screening_id,
                'message'    => $e->getMessage(),
            ]);
        }

        $updatedNotif = DB::table('notifications')->where('id', $notifId)->first();

        Log::info('[PrimaryScreeningController] referral cancelled', [
            'notification_id'      => $notifId,
            'primary_screening_id' => $notif->primary_screening_id,
            'cancelled_by'         => $userId,
            'cancel_reason'        => $cancelReason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Referral notification closed. The primary screening record remains COMPLETED and is preserved in full for audit.',
            'data'    => [
                'notification'      => $this->formatNotification($updatedNotif),
                'primary_screening' => $primaryScreening ? $this->formatScreening($primaryScreening, $updatedNotif) : null,
                'audit_note'        => 'referral_created remains 1 on the primary record. '
                . 'This is intentional: the referral was issued and then cancelled. '
                . 'Both facts are preserved. The primary record is NOT voided.',
            ],
        ], 200);
    }

    // ════════════════════════════════════════════════════════════════════════════
    // PRIVATE — RESOLVERS
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Resolve a user row by id. Returns null if not found.
     */
    private function resolveUser(int $userId): ?object
    {
        try {
            return DB::table('users')->where('id', $userId)->first() ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve the user's primary active assignment.
     * Checks: is_active=1 AND (ends_at IS NULL OR ends_at > NOW()).
     * Returns null if not found.
     */
    private function resolvePrimaryAssignment(int $userId): ?object
    {
        try {
            return DB::table('user_assignments')
                ->where('user_id', $userId)
                ->where('is_primary', 1)
                ->where('is_active', 1)
                ->where(function ($q) {
                    $q->whereNull('ends_at')
                        ->orWhere('ends_at', '>', now()->toDateTimeString());
                })
                ->first();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve the officer's display name for reason_text generation.
     */
    private function resolveOfficerName(int $userId): string
    {
        try {
            $u = DB::table('users')->where('id', $userId)->value('full_name');
            return $u ?? ('Officer #' . $userId);
        } catch (Throwable) {
            return 'Officer #' . $userId;
        }
    }

    // ════════════════════════════════════════════════════════════════════════════
    // PRIVATE — FORMATTERS
    // Every column is explicitly mapped. Nothing is left to magic.
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Format a primary_screenings row with its linked notification.
     * This is the canonical shape returned to the view.
     */
    private function formatScreening(?object $s, ?object $n): array
    {
        if ($s === null) {
            return [];
        }

        return [
            // ── Identifiers ──────────────────────────────────────────────────
            'id'                     => $s->id,
            'client_uuid'            => $s->client_uuid,
            'server_id'              => $s->id, // alias for poeDB.js LAW 3

            // ── Sync / Metadata ───────────────────────────────────────────────
            'reference_data_version' => $s->reference_data_version,
            'server_received_at'     => $s->server_received_at,
            'sync_status'            => 'SYNCED', // always SYNCED after server accept
            'synced_at'              => $s->synced_at,
            'sync_attempt_count'     => (int) $s->sync_attempt_count,
            'last_sync_error'        => $s->last_sync_error,

            // ── Geography ─────────────────────────────────────────────────────
            'country_code'           => $s->country_code,
            'province_code'          => $s->province_code,
            'pheoc_code'             => $s->pheoc_code,
            'district_code'          => $s->district_code,
            'poe_code'               => $s->poe_code,

            // ── Clinical ──────────────────────────────────────────────────────
            'captured_by_user_id'    => $s->captured_by_user_id,
            'gender'                 => $s->gender,
            'traveler_full_name'     => $s->traveler_full_name,
            'traveler_direction'     => $s->traveler_direction,
            'temperature_value'      => $s->temperature_value !== null ? (float) $s->temperature_value : null,
            'temperature_unit'       => $s->temperature_unit,
            'symptoms_present'       => (int) $s->symptoms_present,
            'captured_at'            => $s->captured_at,
            'captured_timezone'      => $s->captured_timezone,

            // ── Control ───────────────────────────────────────────────────────
            'referral_created'       => (int) $s->referral_created,
            'record_status'          => $s->record_status,
            'void_reason'            => $s->void_reason,
            'record_version'         => (int) $s->record_version,

            // ── Device ────────────────────────────────────────────────────────
            'device_id'              => $s->device_id,
            'app_version'            => $s->app_version,
            'platform'               => $s->platform,

            // ── Timestamps ────────────────────────────────────────────────────
            'created_at'             => $s->created_at,
            'updated_at'             => $s->updated_at,

            // ── Notification (null if no referral or non-symptomatic) ─────────
            'notification'           => $n ? $this->formatNotification($n) : null,
        ];
    }

    /**
     * Format a row from the index() JOIN query that has both ps.* and notification columns.
     */
    private function formatScreeningWithNotifColumns(object $r): array
    {
        // Build a pseudo notification object from the join columns
        $notif = null;
        if (! empty($r->notification_id)) {
            $notif = (object) [
                'id'        => $r->notification_id,
                'status'    => $r->notification_status,
                'priority'  => $r->notification_priority,
                'closed_at' => $r->notification_closed_at,
            ];
        }

        return array_merge(
            $this->formatScreening($r, null),
            [
                'notification' => $notif ? [
                    'id'        => $notif->id,
                    'status'    => $notif->status,
                    'priority'  => $notif->priority,
                    'closed_at' => $notif->closed_at,
                ] : null,
            ]
        );
    }

    /**
     * Format a notifications row.
     */
    private function formatNotification(?object $n): ?array
    {
        if ($n === null) {
            return null;
        }
        return [
            'id'                   => $n->id,
            'client_uuid'          => $n->client_uuid,
            'server_id'            => $n->id,
            'notification_type'    => $n->notification_type,
            'status'               => $n->status,
            'priority'             => $n->priority,
            'reason_code'          => $n->reason_code,
            'reason_text'          => $n->reason_text,
            'assigned_role_key'    => $n->assigned_role_key,
            'assigned_user_id'     => $n->assigned_user_id,
            'opened_at'            => $n->opened_at,
            'closed_at'            => $n->closed_at,
            'poe_code'             => $n->poe_code,
            'primary_screening_id' => $n->primary_screening_id,
            'sync_status'          => 'SYNCED',
            'created_at'           => $n->created_at,
            'updated_at'           => $n->updated_at,
        ];
    }

    /**
     * Format a queue item (notifications JOIN primary_screenings JOIN users).
     */
    private function formatQueueItem(object $r): array
    {
        return [
            // Notification
            'notification_id'         => $r->notification_id,
            'notification_uuid'       => $r->notification_uuid,
            'notification_status'     => $r->notification_status,
            'priority'                => $r->notification_priority,
            'reason_code'             => $r->reason_code,
            'reason_text'             => $r->reason_text,
            'assigned_role_key'       => $r->assigned_role_key,
            'assigned_user_id'        => $r->assigned_user_id,
            'notification_opened_at'  => $r->notification_opened_at,
            'notification_created_at' => $r->notification_created_at,
            // Closed_at + updated_at + record_version are passed through so
            // the client's offline writeToIdb can detect cross-device status
            // changes. The version-guarded merge in NotificationsCenter only
            // updates the local notification when the server's record_version
            // is strictly higher than the cached one.
            'notification_closed_at'  => $r->notification_closed_at  ?? null,
            'notification_updated_at' => $r->notification_updated_at ?? null,
            'record_version'          => isset($r->record_version) ? (int) $r->record_version : 1,
            // Primary screening context — what the secondary officer needs
            'primary_screening_id'    => $r->primary_screening_id,
            'primary_uuid'            => $r->primary_uuid,
            'gender'                  => $r->gender,
            'traveler_direction'      => $r->traveler_direction ?? null,
            'temperature_value'       => $r->temperature_value !== null ? (float) $r->temperature_value : null,
            'temperature_unit'        => $r->temperature_unit,
            'traveler_full_name'      => $r->traveler_full_name, // may be null — not required at primary
            'symptoms_present'        => (int) $r->symptoms_present,
            'captured_at'             => $r->captured_at,
            'captured_timezone'       => $r->captured_timezone,
            'primary_record_status'   => $r->primary_record_status,
            // Screener who created the referral
            'screener_name'           => $r->screener_name,
            'screener_role'           => $r->screener_role,
            // UI helpers
            'poe_code'                => $r->notification_poe_code,
            // Scope columns — needed by the offline IDB cache on the client.
            // Without these the cached queue item is filtered out of any
            // role-scoped local view (DISTRICT/PHEOC see nothing).
            'country_code'            => $r->notification_country_code  ?? null,
            'province_code'           => $r->notification_province_code ?? null,
            'pheoc_code'              => $r->notification_pheoc_code    ?? null,
            'district_code'           => $r->notification_district_code ?? null,
            'is_voided_primary'       => $r->primary_record_status === 'VOIDED',
        ];
    }

    /**
     * Format a secondary_screenings stub (minimal fields for the show() response).
     */
    private function formatSecondaryStub(object $s): array
    {
        return [
            'id'                      => $s->id,
            'case_status'             => $s->case_status,
            'opened_at'               => $s->opened_at,
            'closed_at'               => $s->closed_at,
            'final_disposition'       => $s->final_disposition,
            'risk_level'              => $s->risk_level,
            'syndrome_classification' => $s->syndrome_classification,
        ];
    }

    // ════════════════════════════════════════════════════════════════════════════
    // PRIVATE — RESPONSE HELPERS
    // ════════════════════════════════════════════════════════════════════════════

    /**
     * Structured error response.
     * Never sugar-coated. Tells the dev exactly what failed and why.
     *
     * Shape:
     * {
     *   "success": false,
     *   "message": "...",
     *   "error": { ...raw detail... }
     * }
     */
    private function err(int $status, string $message, array $error): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => $error,
        ], $status);
    }

    /**
     * Catch-all for DB / unexpected exceptions.
     * Returns raw exception class, message, file, line.
     * Stack trace is redacted in production, full in local/staging.
     */
    private function dbError(Throwable $e, string $context): JsonResponse
    {
        Log::error('[PrimaryScreeningController] exception during: ' . $context, [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);

        return response()->json([
            'success' => false,
            'message' => 'A server error occurred during: ' . $context,
            'error'   => [
                'context'   => $context,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
                'trace'     => app()->environment('production')
                    ? '[redacted in production]'
                    : array_slice(explode("\n", $e->getTraceAsString()), 0, 20),
            ],
        ], 500);
    }
}

/*
 * ═══════════════════════════════════════════════════════════════════════════════
 * ROUTES — paste into routes/api.php
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 * use App\Http\Controllers\PrimaryScreeningController;
 *
 * Route::post  ('/primary-screenings',              [PrimaryScreeningController::class, 'store']);
 * Route::get   ('/primary-screenings/stats/today',  [PrimaryScreeningController::class, 'stats']);
 * Route::get   ('/primary-screenings',              [PrimaryScreeningController::class, 'index']);
 * Route::get   ('/primary-screenings/{id}',         [PrimaryScreeningController::class, 'show']);
 * Route::patch ('/primary-screenings/{id}/void',    [PrimaryScreeningController::class, 'void']);
 * Route::get   ('/referral-queue',                  [PrimaryScreeningController::class, 'queue']);
 * Route::patch ('/referral-queue/{notifId}/cancel', [PrimaryScreeningController::class, 'cancelReferral']);
 *
 * NOTE: /stats/today must be declared BEFORE /{id} or Laravel will try to
 *       resolve "stats" as an integer id and fail with a model binding error.
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 *
 * ═══════════════════════════════════════════════════════════════════════════════
 * COMPLETE RESPONSE CATALOGUE — EVERY POSSIBLE RESPONSE FROM EVERY ENDPOINT
 * ═══════════════════════════════════════════════════════════════════════════════
 *
 *
 * ── POST /primary-screenings ─────────────────────────────────────────────────
 *
 * 201 Created — new non-symptomatic record:
 * {
 *   "success": true,
 *   "message": "Primary screening recorded. No symptoms detected.",
 *   "idempotent": false,
 *   "data": {
 *     "id": 42,
 *     "client_uuid": "xxxxxxxx-xxxx-4xxx-...",
 *     "server_id": 42,
 *     "reference_data_version": "rda-2026-02-01",
 *     "server_received_at": "2026-03-26 10:00:00",
 *     "sync_status": "SYNCED",
 *     "country_code": "UG",
 *     "province_code": "Kabale Provincial PHEOC",
 *     "pheoc_code": "Kabale Provincial PHEOC",
 *     "district_code": "Kisoro District",
 *     "poe_code": "Bunagana",
 *     "captured_by_user_id": 3,
 *     "gender": "MALE",
 *     "traveler_full_name": null,
 *     "temperature_value": null,
 *     "temperature_unit": null,
 *     "symptoms_present": 0,
 *     "captured_at": "2026-03-26 09:58:00",
 *     "captured_timezone": "Africa/Kampala",
 *     "referral_created": 0,
 *     "record_status": "COMPLETED",
 *     "void_reason": null,
 *     "record_version": 1,
 *     "device_id": "ECSA-ABCD-EFGH",
 *     "app_version": "0.0.1",
 *     "platform": "ANDROID",
 *     "created_at": "2026-03-26 10:00:00",
 *     "updated_at": "2026-03-26 10:00:00",
 *     "notification": null
 *   }
 * }
 *
 * 201 Created — symptomatic + referral (priority: HIGH):
 * {
 *   "success": true,
 *   "message": "Primary screening recorded. Symptomatic traveler — referral notification created (priority: HIGH).",
 *   "idempotent": false,
 *   "data": {
 *     "id": 43,
 *     ...all primary columns...,
 *     "symptoms_present": 1,
 *     "referral_created": 1,
 *     "notification": {
 *       "id": 12,
 *       "client_uuid": "yyyyyyyy-...",
 *       "server_id": 12,
 *       "notification_type": "SECONDARY_REFERRAL",
 *       "status": "OPEN",
 *       "priority": "HIGH",
 *       "reason_code": "PRIMARY_SYMPTOMS_DETECTED",
 *       "reason_text": "Symptoms present. Gender: FEMALE. Temp: 38.1°C. Priority: HIGH. ...",
 *       "assigned_role_key": "POE_SECONDARY",
 *       "assigned_user_id": null,
 *       "opened_at": null,
 *       "closed_at": null,
 *       "poe_code": "Bunagana",
 *       "primary_screening_id": 43,
 *       "sync_status": "SYNCED",
 *       "created_at": "...",
 *       "updated_at": "..."
 *     }
 *   }
 * }
 *
 * 200 OK — idempotent re-submission (same client_uuid submitted again):
 * {
 *   "success": true,
 *   "message": "Record already exists. Returning existing server record (idempotent re-submission).",
 *   "idempotent": true,
 *   "data": { ...same shape as 201... }
 * }
 *
 * 422 — missing captured_by_user_id:
 * { "success": false, "message": "captured_by_user_id is required...", "error": {...} }
 *
 * 422 — validation failure (bad gender value):
 * {
 *   "success": false,
 *   "message": "Validation failed. See error.validation_errors for field-level detail.",
 *   "error": {
 *     "validation_errors": { "gender": ["The selected gender is invalid."] },
 *     "received_keys": ["client_uuid","gender","symptoms_present",...],
 *     "hint": "..."
 *   }
 * }
 *
 * 422 — temperature unit missing when value present:
 * {
 *   "success": false,
 *   "message": "temperature_unit is required when temperature_value is provided.",
 *   "error": {
 *     "temperature_value": 37.2,
 *     "temperature_unit": null,
 *     "hint": "Send both temperature_value and temperature_unit (C or F), or send neither."
 *   }
 * }
 *
 * 422 — temperature out of clinical range:
 * {
 *   "success": false,
 *   "message": "Temperature value is outside the valid clinical range.",
 *   "error": {
 *     "temperature_value": 99.0,
 *     "temperature_unit": "C",
 *     "valid_range_celsius": { "min": 25, "max": 45 },
 *     "valid_range_fahrenheit": { "min": 77, "max": 113 }
 *   }
 * }
 *
 * 422 — captured_at in the future:
 * {
 *   "success": false,
 *   "message": "captured_at is too far in the future. Check device clock.",
 *   "error": {
 *     "captured_at": "2026-03-27 10:00:00",
 *     "server_utc_now": "2026-03-26 10:00:00",
 *     "max_future_drift_sec": 300,
 *     "difference_seconds": 86400
 *   }
 * }
 *
 * 403 — no active assignment:
 * {
 *   "success": false,
 *   "message": "No active geographic assignment found for this user.",
 *   "error": { "user_id": 3, "hint": "Create an assignment before attempting to screen." }
 * }
 *
 * 403 — no poe_code on assignment:
 * {
 *   "success": false,
 *   "message": "User assignment has no poe_code. Cannot record a primary screening without a POE assignment.",
 *   "error": { "user_id": 3, "assignment_id": 7, "assignment_level": "..." }
 * }
 *
 * 500 — database exception:
 * {
 *   "success": false,
 *   "message": "A server error occurred during: primary_screenings + notifications atomic insert",
 *   "error": {
 *     "context": "primary_screenings + notifications atomic insert",
 *     "exception": "Illuminate\\Database\\QueryException",
 *     "message": "SQLSTATE[23000]: Duplicate entry '...' for key 'uq_primary_client_uuid'",
 *     "file": "...",
 *     "line": 123,
 *     "trace": ["#0 ...", "#1 ..."]
 *   }
 * }
 *
 *
 * ── GET /primary-screenings ──────────────────────────────────────────────────
 *
 * 200 OK:
 * {
 *   "success": true,
 *   "message": "Primary screenings retrieved.",
 *   "data": {
 *     "items": [ ...array of screening objects with embedded notification... ],
 *     "total": 124,
 *     "per_page": 50,
 *     "page": 1,
 *     "pages": 3,
 *     "poe_code": "Bunagana",
 *     "filters": { "dateFrom": null, "dateTo": null, "symptomsFilter": null, ... }
 *   }
 * }
 *
 *
 * ── GET /primary-screenings/{id} ─────────────────────────────────────────────
 *
 * 200 OK:
 * {
 *   "success": true,
 *   "message": "Primary screening record retrieved.",
 *   "data": {
 *     ...full screening object...,
 *     "notification": { ...or null... },
 *     "secondary_case": {
 *       "id": 7,
 *       "case_status": "IN_PROGRESS",
 *       "opened_at": "...",
 *       "closed_at": null,
 *       "final_disposition": null,
 *       "risk_level": null,
 *       "syndrome_classification": null
 *     }
 *   }
 * }
 *
 * 404 — record not found:
 * { "success": false, "message": "Primary screening record not found.", "error": { "id": 999 } }
 *
 * 403 — different POE:
 * {
 *   "success": false,
 *   "message": "Access denied. This record belongs to a different POE.",
 *   "error": { "record_poe_code": "Katuna", "user_poe_code": "Bunagana" }
 * }
 *
 *
 * ── PATCH /primary-screenings/{id}/void ──────────────────────────────────────
 *
 * 200 OK:
 * {
 *   "success": true,
 *   "message": "Primary screening record voided. Linked OPEN notification (if any) has been closed.",
 *   "data": { ...voided screening with notification... }
 * }
 *
 * 409 — already voided:
 * { "success": false, "message": "Record is already voided. No action taken.", "error": { "id": 42, "void_reason": "..." } }
 *
 * 403 — void permission denied:
 * {
 *   "success": false,
 *   "message": "You do not have permission to void this record.",
 *   "error": {
 *     "your_user_id": 5,
 *     "your_role_key": "SCREENER",
 *     "captured_by_user_id": 3,
 *     "void_window_hours": 24,
 *     "hint": "You can void your own records within 24 hours of capture..."
 *   }
 * }
 *
 *
 * ── GET /primary-screenings/stats/today ──────────────────────────────────────
 *
 * 200 OK:
 * {
 *   "success": true,
 *   "message": "Today's screening statistics for POE: Bunagana",
 *   "data": {
 *     "poe_code": "Bunagana",
 *     "date": "2026-03-26",
 *     "total_screened": 47,
 *     "total_symptomatic": 3,
 *     "total_asymptomatic": 44,
 *     "total_male": 28,
 *     "total_female": 16,
 *     "total_other": 1,
 *     "total_unknown": 2,
 *     "total_referrals_created": 3,
 *     "total_with_temp": 12,
 *     "last_capture_at": "2026-03-26 09:58:00",
 *     "open_referrals": 2,
 *     "in_progress_referrals": 1,
 *     "pending_sync": 0
 *   }
 * }
 *
 *
 * ── GET /referral-queue ───────────────────────────────────────────────────────
 *
 * 200 OK:
 * {
 *   "success": true,
 *   "message": "Referral queue retrieved for POE: Bunagana",
 *   "data": {
 *     "items": [
 *       {
 *         "notification_id": 12,
 *         "notification_uuid": "...",
 *         "notification_status": "OPEN",
 *         "priority": "CRITICAL",
 *         "reason_code": "PRIMARY_SYMPTOMS_DETECTED",
 *         "reason_text": "Symptoms present. Gender: FEMALE. Temp: 39.2°C. Priority: CRITICAL. ...",
 *         "assigned_role_key": "POE_SECONDARY",
 *         "assigned_user_id": null,
 *         "notification_created_at": "2026-03-26 09:55:00",
 *         "primary_screening_id": 43,
 *         "primary_uuid": "...",
 *         "gender": "FEMALE",
 *         "temperature_value": 39.2,
 *         "temperature_unit": "C",
 *         "traveler_full_name": "JANE DOE",
 *         "symptoms_present": 1,
 *         "captured_at": "2026-03-26 09:54:00",
 *         "primary_record_status": "COMPLETED",
 *         "screener_name": "AYEBARE TIMOTHY KAMUKAMA",
 *         "screener_role": "SCREENER",
 *         "poe_code": "Bunagana",
 *         "is_voided_primary": false
 *       }
 *     ],
 *     "total": 2,
 *     "per_page": 50,
 *     "page": 1,
 *     "pages": 1,
 *     "poe_code": "Bunagana",
 *     "status_filter": "OPEN"
 *   }
 * }
 *
 *
 * ── PATCH /referral-queue/{notifId}/cancel ────────────────────────────────────
 *
 * 200 OK:
 * {
 *   "success": true,
 *   "message": "Referral notification closed. The primary screening record remains COMPLETED and is preserved in full for audit.",
 *   "data": {
 *     "notification": { ...closed notification... },
 *     "primary_screening": { ...unchanged COMPLETED primary record, referral_created still = 1... },
 *     "audit_note": "referral_created remains 1 on the primary record. This is intentional..."
 *   }
 * }
 *
 * 409 — already closed:
 * { "success": false, "message": "Notification is already closed. Cannot cancel a closed referral.", "error": {...} }
 *
 * 409 — secondary case already opened:
 * {
 *   "success": false,
 *   "message": "Cannot cancel this referral. A secondary screening case has already been opened.",
 *   "error": {
 *     "notification_id": 12,
 *     "notification_status": "IN_PROGRESS",
 *     "secondary_case_id": 7,
 *     "secondary_case_status": "IN_PROGRESS",
 *     "hint": "The secondary officer must close this case from their secondary screening view."
 *   }
 * }
 */
