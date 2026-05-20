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
 * ║  SecondaryScreeningController                                                ║
 * ║  ECSA-HC POE Sentinel · WHO/IHR 2005 Compliant                              ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  Database:  poe_2026  (DB:: facade — NO Eloquent models anywhere)           ║
 * ║  Auth:      NONE — all routes are publicly accessible by design.             ║
 * ║             Auth middleware will be added as a separate layer later.         ║
 * ║             Do NOT add Authorization: Bearer checks anywhere in this file.   ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  ROUTES (paste into routes/api.php — ORDER IS CRITICAL):                    ║
 * ║                                                                              ║
 * ║  use App\Http\Controllers\SecondaryScreeningController as SSC;              ║
 * ║                                                                              ║
 * ║  Route::post  ('/secondary-screenings',               [SSC::class,'store']);        ║
 * ║  Route::get   ('/secondary-screenings',               [SSC::class,'index']);        ║
 * ║  Route::get   ('/secondary-screenings/{id}',          [SSC::class,'show']);         ║
 * ║  Route::patch ('/secondary-screenings/{id}',          [SSC::class,'update']);       ║
 * ║  Route::patch ('/secondary-screenings/{id}/status',   [SSC::class,'updateStatus']); ║
 * ║  Route::post  ('/secondary-screenings/{id}/symptoms', [SSC::class,'syncSymptoms']); ║
 * ║  Route::post  ('/secondary-screenings/{id}/exposures',[SSC::class,'syncExposures']);║
 * ║  Route::post  ('/secondary-screenings/{id}/actions',  [SSC::class,'syncActions']);  ║
 * ║  Route::post  ('/secondary-screenings/{id}/samples',  [SSC::class,'syncSamples']);  ║
 * ║  Route::post  ('/secondary-screenings/{id}/travel',   [SSC::class,'syncTravel']);   ║
 * ║  Route::post  ('/secondary-screenings/{id}/diseases', [SSC::class,'syncDiseases']); ║
 * ║  Route::post  ('/secondary-screenings/{id}/sync',     [SSC::class,'fullSync']);     ║
 * ╠══════════════════════════════════════════════════════════════════════════════╣
 * ║  REFACTOR CHANGES vs PREVIOUS VERSION:                                       ║
 * ║                                                                              ║
 * ║  FIX 1 — TYPO: 'CSFL' → 'CSF' in VALID_SAMPLE_TYPES. Constant now          ║
 * ║          actively enforced in syncSamples() and fullSync() samples block.   ║
 * ║                                                                              ║
 * ║  FIX 2 — screening_outcome: Vue UI sends NON_CASE | SUSPECTED_CASE |        ║
 * ║          PERSON_UNDER_SURVEILLANCE on every saveDisposition() and           ║
 * ║          syncNow() call. Column does not yet exist in secondary_screenings   ║
 * ║          per POE_2026.sql. Value is accepted, validated, and encoded as a   ║
 * ║          structured prefix in disposition_details (OUTCOME:xxx | ...) so    ║
 * ║          zero data is lost. formatCase() decodes it back into               ║
 * ║          screening_outcome in the response so the Vue UI restores it.       ║
 * ║          TODO: Run migration — see PENDING SCHEMA MIGRATIONS block below.   ║
 * ║                                                                              ║
 * ║  FIX 3 — fullSync() state machine: Previously fullSync() wrote any          ║
 * ║          case_status value directly, bypassing all clinical validation.     ║
 * ║          Now validateStatusTransitionForSync() is called BEFORE the         ║
 * ║          DB transaction and enforces:                                       ║
 * ║            • Transition map (same as updateStatus)                          ║
 * ║            • DISPOSITIONED: syndrome + risk + disposition in payload-or-    ║
 * ║              stored, plus ISOLATED/REFERRED_HOSPITAL for HIGH/CRITICAL      ║
 * ║            • CLOSED from IN_PROGRESS: officer_notes required                ║
 * ║          Notification is also closed atomically when case reaches CLOSED.   ║
 * ║                                                                              ║
 * ║  FIX 4 — explicit_absent: Vue UI sends explicit_absent (0|1) on every       ║
 * ║          symptom record. Column does not yet exist in secondary_symptoms.   ║
 * ║          Centralised insertSymptom() helper accepts the field and has a     ║
 * ║          commented-out line ready to activate after migration.              ║
 * ║          TODO: Run migration — see PENDING SCHEMA MIGRATIONS block below.   ║
 * ║                                                                              ║
 * ║  FIX 5 — store() observability: Added Log::info when notification is        ║
 * ║          IN_PROGRESS on arrival — idempotency check handles correctly.      ║
 * ║                                                                              ║
 * ║  FIX 6 — show() double query: notifications table was queried twice.         ║
 * ║          Fixed to assign to variable once.                                  ║
 * ╚══════════════════════════════════════════════════════════════════════════════╝
 */
final class SecondaryScreeningController extends Controller
{
    // ═════════════════════════════════════════════════════════════════════
    // CONSTANTS — exact match with DB schema ENUMs
    // ═════════════════════════════════════════════════════════════════════

    private const VALID_CASE_STATUSES     = ['OPEN', 'IN_PROGRESS', 'DISPOSITIONED', 'CLOSED'];
    private const VALID_GENDERS           = ['MALE', 'FEMALE', 'OTHER', 'UNKNOWN'];
    private const VALID_TRIAGE_CATEGORIES = ['NON_URGENT', 'URGENT', 'EMERGENCY'];
    private const VALID_APPEARANCES       = ['WELL', 'UNWELL', 'SEVERELY_ILL'];
    private const VALID_RISK_LEVELS       = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];
    private const VALID_DISPOSITIONS      = [
        // Legacy codes — accepted for backward compatibility with old IDB records
        'RELEASED', 'DELAYED', 'QUARANTINED', 'ISOLATED',
        'REFERRED', 'TRANSFERRED', 'DENIED_BOARDING', 'OTHER',
        // WHO/IDSR canonical disposition codes (Change 20 — additive enum extension)
        'RELEASED_NO_CONDITION',
        'RELEASED_UNDER_FOLLOWUP',
        'REFERRED_HEALTH_FACILITY',
        'ISOLATED_ADMITTED',
        'DECEASED_AT_POE',
        // 2026-05-06 — IHR Article 31 repatriation: traveller refused entry
        // and returning to their country of origin. Additive, non-breaking.
        'RETURN_TO_ORIGIN',
    ];
    private const VALID_FOLLOWUP_LEVELS    = ['POE', 'DISTRICT', 'PHEOC', 'NATIONAL'];
    private const VALID_CONVEYANCE_TYPES   = ['AIR', 'LAND', 'SEA', 'OTHER'];
    private const VALID_TRAVEL_ROLES       = ['VISITED', 'TRANSIT'];
    private const VALID_EXPOSURE_RESPONSES = ['YES', 'NO', 'UNKNOWN'];
    private const VALID_TEMP_UNITS         = ['C', 'F'];
    private const VALID_PLATFORMS          = ['ANDROID', 'IOS', 'WEB'];

    /** FIX 7: Traveler direction at the POE - ENTRY, EXIT, or TRANSIT through the corridor. */
    private const VALID_TRAVELER_DIRECTIONS = ['ENTRY', 'EXIT', 'TRANSIT'];

    /** FIX 1: 'CSFL' corrected to 'CSF'. Now enforced in sample write paths. */
    private const VALID_SAMPLE_TYPES = [
        'BLOOD', 'SERUM', 'URINE', 'STOOL', 'NASAL_SWAB',
        'THROAT_SWAB', 'SPUTUM', 'CSF', 'SKIN_LESION', 'OTHER',
    ];

    /** Syndrome codes matching the business spec exactly. */
    private const VALID_SYNDROMES = [
        // Legacy codes — accepted for backward compatibility
        'ILI', 'SARI', 'AWD', 'BLOODY_DIARRHEA', 'VHF',
        'RASH_FEVER', 'JAUNDICE', 'NEUROLOGICAL', 'MENINGITIS', 'OTHER', 'NONE',
        // WHO/IDSR canonical syndromic codes (Change 21 — additive enum extension)
        'ACUTE_FEBRILE_ILLNESS',
        'ACUTE_HAEMORRHAGIC_FEVER',
        'ACUTE_RESPIRATORY_SYNDROME',
        'SEVERE_ACUTE_RESPIRATORY_INFECTION',
        'ACUTE_WATERY_DIARRHOEA',
        'ACUTE_BLOODY_DIARRHOEA',
        'ACUTE_JAUNDICE_SYNDROME',
        'ACUTE_NEUROLOGICAL_SYNDROME',
        'ACUTE_FLACCID_PARALYSIS',
        'RASH_ILLNESS_FEVER',
        'RESPIRATORY_ILLNESS_NON_SEVERE',
        'ACUTE_VECTOR_BORNE',
        'FOODBORNE_ILLNESS',
        'ZOONOTIC_EXPOSURE_ILLNESS',
        'MENINGITIS_ENCEPHALITIS',
        'UNEXPLAINED_DEATH',
        'NO_SYNDROME',
    ];

    /**
     * FIX 2: Three WHO/IHR screening outcome values sent by the Vue UI
     * from the SCREENING_OUTCOMES constant in SecondaryScreening.vue.
     * Column pending migration — see PENDING SCHEMA MIGRATIONS below.
     */
    private const VALID_SCREENING_OUTCOMES = [
        'NON_CASE',
        'SUSPECTED_CASE',
        'PERSON_UNDER_SURVEILLANCE',
    ];

    /** Actions requiring ISOLATED or REFERRED_HOSPITAL when risk is HIGH/CRITICAL. */
    private const HIGH_RISK_REQUIRED_ACTIONS = ['ISOLATED', 'REFERRED_HOSPITAL'];

    /**
     * FIX 11: IHR tier disease IDs for notification logging.
     * Source: WHO IHR 2005 Annex 2.
     */
    private const IHR_TIER1_ALWAYS_NOTIFIABLE = [
        'smallpox', 'sars', 'influenza_new_subtype_zoonotic', 'polio',
    ];
    private const IHR_TIER2_ANNEX2 = [
        'cholera', 'yellow_fever', 'ebola_virus_disease', 'marburg_virus_disease',
        'lassa_fever', 'cchf', 'rift_valley_fever', 'mpox', 'meningococcal_meningitis',
        'measles', 'mers', 'pneumonic_plague', 'bubonic_plague',
    ];

    /** FIX 3: Centralised state machine map used by updateStatus() and fullSync(). */
    private const ALLOWED_TRANSITIONS = [
        'OPEN'          => ['IN_PROGRESS'],
        'IN_PROGRESS'   => ['DISPOSITIONED', 'CLOSED'],
        'DISPOSITIONED' => ['CLOSED'],
    ];

    private const MAX_PER_PAGE     = 100;
    private const FUTURE_DRIFT_SEC = 300;

    // ═════════════════════════════════════════════════════════════════════
    // GET /secondary-screenings/by-notification/{uuid}
    //
    // FIX 8: Resolves a secondary case by notification UUID or integer ID.
    // Used by the Vue view on init — the Vue navigates from NotificationsCenter
    // using the notification client_uuid and needs to find the linked case.
    // Returns 404 if no case exists yet (Vue should call store() to create it).
    // MUST be registered BEFORE /secondary-screenings/{id} in routes.
    // ═════════════════════════════════════════════════════════════════════

    public function showByNotification(Request $request, string $uuid): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id query parameter is required.', [
                'hint' => 'Append ?user_id={AUTH_DATA.id}',
            ]);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.', ['user_id' => $userId]);
        }

        try {
            $notifId = $this->resolveNotificationId($uuid);
            if (! $notifId) {
                return $this->err(404, 'Notification not found.', [
                    'notification_uuid' => $uuid,
                    'hint'              => 'The notification may not have synced yet. Sync primary screenings first.',
                ]);
            }

            $case = DB::table('secondary_screenings')
                ->where('notification_id', $notifId)
                ->whereNull('deleted_at')
                ->first();

            if (! $case) {
                return $this->err(404, 'No secondary screening case found for this notification.', [
                    'notification_id'   => $notifId,
                    'notification_uuid' => $uuid,
                    'hint'              => 'Call POST /secondary-screenings to open a new case for this referral.',
                ]);
            }

            $scopeErr = $this->checkScope($case, $assignment, DB::table('users')->where('id', $userId)->first());
            if ($scopeErr) {
                return $scopeErr;
            }

            return $this->buildFullCaseResponse($case, $notifId);
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_screenings showByNotification');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /notifications/by-uuid/{uuid}
    // Return the notification record + its linked primary screening so the
    // mobile app can hydrate IDB on a fresh device / cross-account open
    // when the notification was never synced locally. Read-only.
    // ═════════════════════════════════════════════════════════════════════
    public function showNotificationByUuid(Request $request, string $uuid): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id query parameter is required.', [
                'hint' => 'Append ?user_id={AUTH_DATA.id}',
            ]);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.', ['user_id' => $userId]);
        }

        try {
            $notif = DB::table('notifications')->where('client_uuid', $uuid)->first();
            if (! $notif) {
                return $this->err(404, 'Notification not found.', [
                    'notification_uuid' => $uuid,
                ]);
            }

            // Country scope — refuse to hydrate IDB with cross-country data even
            // when a user deep-links a UUID. Without this guard a Uganda officer
            // who knows a foreign-country notification UUID could pull cross-tenant data.
            $userCountry  = $assignment->country_code ?? null;
            $notifCountry = $notif->country_code ?? null;
            if ($userCountry && $notifCountry && $notifCountry !== $userCountry) {
                return $this->err(403, 'Notification is in a different country.', [
                    'notification_country' => $notifCountry,
                    'user_country'         => $userCountry,
                ]);
            }

            $primary = null;
            if (! empty($notif->primary_screening_id)) {
                $primary = DB::table('primary_screenings')
                    ->where('id', $notif->primary_screening_id)
                    ->first();
            }

            return response()->json([
                'ok'   => true,
                'data' => [
                    'notification'      => $notif,
                    'primary_screening' => $primary,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'notifications showByUuid');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /secondary-screenings
    // Open a new secondary screening case linked to a SECONDARY_REFERRAL
    // notification. Idempotent by client_uuid and by notification_id.
    // Atomically transitions the notification OPEN → IN_PROGRESS.
    // ═════════════════════════════════════════════════════════════════════

    public function store(Request $request): JsonResponse
    {
        $userId = (int) $request->input('opened_by_user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'opened_by_user_id is required and must be a positive integer.', [
                'field'    => 'opened_by_user_id',
                'received' => $request->input('opened_by_user_id'),
                'hint'     => 'Send AUTH_DATA.id from sessionStorage.',
            ]);
        }

        $user = $this->resolveUser($userId);
        if (! $user) {
            return $this->err(404, 'User not found.', ['opened_by_user_id' => $userId]);
        }
        if (! (bool) $user->is_active) {
            return $this->err(403, 'User account is inactive.', ['user_id' => $userId]);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active geographic assignment found for this user.', [
                'user_id' => $userId,
                'hint'    => 'Create a user_assignment row with is_active=1 for this user.',
            ]);
        }

        $v = Validator::make($request->all(), [
            'client_uuid'            => ['required', 'string', 'size:36',
                'regex:/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i'],
            'reference_data_version' => ['required', 'string', 'max:40'],
            'notification_id'        => ['required'],
            'primary_screening_id'   => ['required'],
            'device_id'              => ['required', 'string', 'max:80'],
            'platform'               => ['nullable', 'string', 'in:ANDROID,IOS,WEB'],
            'app_version'            => ['nullable', 'string', 'max:40'],
            'opened_at'              => ['nullable', 'date_format:Y-m-d H:i:s'],
            'opened_timezone'        => ['nullable', 'string', 'max:64'],
            'traveler_gender'        => ['nullable', 'string', 'in:MALE,FEMALE,OTHER,UNKNOWN'],
            'record_version'         => ['nullable', 'integer', 'min:1'],
        ]);

        if ($v->fails()) {
            return $this->err(422, 'Validation failed.', [
                'validation_errors' => $v->errors()->toArray(),
                'hint'              => 'client_uuid (UUID v4), notification_id, primary_screening_id, device_id are required.',
            ]);
        }

        $data       = $v->validated();
        $clientUuid = (string) $data['client_uuid'];

        try {
            return DB::transaction(function () use ($data, $clientUuid, $userId, $assignment, $user, $request) {

                // IDEMPOTENCY 1: existing secondary by client_uuid
                $existing = DB::table('secondary_screenings')
                    ->where('client_uuid', $clientUuid)
                    ->first();

                if ($existing) {
                    Log::info('[SecondaryScreening][store] Idempotent resubmit by client_uuid', [
                        'client_uuid' => $clientUuid,
                        'server_id'   => $existing->id,
                        'case_status' => $existing->case_status,
                    ]);
                    return $this->ok(
                        $this->formatCase($existing),
                        'Secondary case already exists. Returning existing record (idempotent resubmit).',
                        ['idempotent' => true, 'server_id' => $existing->id]
                    );
                }

                // RESOLVE NOTIFICATION FK
                $notifId = $this->resolveNotificationId($data['notification_id']);
                if (! $notifId) {
                    return $this->err(422, 'Notification not found. Ensure the notification has been synced before opening a secondary case.', [
                        'notification_id_received' => $data['notification_id'],
                        'hint'                     => 'Sync primary + notification first.',
                    ]);
                }

                $notification = DB::table('notifications')->where('id', $notifId)->first();
                if (! $notification) {
                    return $this->err(404, 'Notification record not found.', ['notification_id' => $notifId]);
                }

                // NOTIFICATION STATUS GUARD
                if ($notification->status === 'CLOSED') {
                    return $this->err(409, 'Cannot open a secondary case for a CLOSED notification.', [
                        'notification_id'     => $notifId,
                        'notification_status' => $notification->status,
                        'closed_at'           => $notification->closed_at,
                        'hint'                => 'A new referral from primary screening is required to open a case.',
                    ]);
                }

                // FIX 5: Log when notification is already IN_PROGRESS
                if ($notification->status === 'IN_PROGRESS') {
                    Log::info('[SecondaryScreening][store] Notification already IN_PROGRESS — idempotency check follows', [
                        'notification_id' => $notifId,
                    ]);
                }

                // IDEMPOTENCY 2: existing case for this notification.
                // lockForUpdate() so two simultaneous POSTs for the same
                // notification can't both pass this check and both insert.
                // The DB-level UNIQUE INDEX on notification_id_active is the
                // final backstop — see catch block below.
                $existingByNotif = DB::table('secondary_screenings')
                    ->where('notification_id', $notifId)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->first();

                if ($existingByNotif) {
                    Log::info('[SecondaryScreening][store] Case already exists for notification', [
                        'notification_id'  => $notifId,
                        'existing_case_id' => $existingByNotif->id,
                    ]);
                    return $this->ok(
                        $this->formatCase($existingByNotif),
                        'A secondary case already exists for this referral notification. Returning existing case.',
                        ['idempotent' => true, 'server_id' => $existingByNotif->id, 'notification_id' => $notifId]
                    );
                }

                // RESOLVE PRIMARY SCREENING FK
                $primaryId = $this->resolvePrimaryScreeningId($data['primary_screening_id']);
                if (! $primaryId) {
                    return $this->err(422, 'Primary screening not found. Ensure it was synced before opening a secondary case.', [
                        'primary_screening_id_received' => $data['primary_screening_id'],
                        'hint'                          => 'Sync the primary screening record first.',
                    ]);
                }

                $primaryScreening = DB::table('primary_screenings')->where('id', $primaryId)->first();
                if (! $primaryScreening) {
                    return $this->err(404, 'Primary screening record not found.', ['primary_screening_id' => $primaryId]);
                }

                $now            = now()->format('Y-m-d H:i:s');
                $openedAt       = $data['opened_at'] ?? $now;
                $openedTz       = $data['opened_timezone'] ?? null;
                $travelerGender = $data['traveler_gender'] ?? $primaryScreening->gender ?? 'UNKNOWN';

                try {
                    $caseId = DB::table('secondary_screenings')->insertGetId([
                    'client_uuid'                       => $clientUuid,
                    'idempotency_key'                   => null,
                    'reference_data_version'            => $data['reference_data_version'],
                    'server_received_at'                => $now,
                    'country_code'                      => $assignment->country_code,
                    'province_code'                     => $assignment->province_code,
                    'pheoc_code'                        => $assignment->pheoc_code,
                    'district_code'                     => $assignment->district_code,
                    'poe_code'                          => $assignment->poe_code,
                    'primary_screening_id'              => $primaryId,
                    'notification_id'                   => $notifId,
                    'opened_by_user_id'                 => $userId,
                    'case_status'                       => 'OPEN',
                    'traveler_gender'                   => $travelerGender,
                    'traveler_full_name'                => null,
                    'traveler_initials'                 => null,
                    'traveler_anonymous_code'           => null,
                    'travel_document_type'              => null,
                    'travel_document_number'            => null,
                    'traveler_age_years'                => null,
                    'traveler_dob'                      => null,
                    'traveler_nationality_country_code' => null,
                    'traveler_occupation'               => null,
                    'residence_country_code'            => null,
                    'residence_address_text'            => null,
                    'phone_number'                      => null,
                    'alternative_phone'                 => null,
                    'email'                             => null,
                    'destination_address_text'          => null,
                    'destination_district_code'         => null,
                    'emergency_contact_name'            => null,
                    'emergency_contact_phone'           => null,
                    'journey_start_country_code'        => null,
                    'embarkation_port_city'             => null,
                    'conveyance_type'                   => null,
                    'conveyance_identifier'             => null,
                    'seat_number'                       => null,
                    'arrival_datetime'                  => null,
                    'departure_datetime'                => null,
                    'purpose_of_travel'                 => null,
                    'planned_length_of_stay_days'       => null,
                    'triage_category'                   => null,
                    'emergency_signs_present'           => 0,
                    'general_appearance'                => null,
                    'temperature_value'                 => null,
                    'temperature_unit'                  => null,
                    'pulse_rate'                        => null,
                    'respiratory_rate'                  => null,
                    'bp_systolic'                       => null,
                    'bp_diastolic'                      => null,
                    'oxygen_saturation'                 => null,
                    'syndrome_classification'           => null,
                    'risk_level'                        => null,
                    'officer_notes'                     => null,
                    'final_disposition'                 => null,
                    'disposition_details'               => null,
                    'followup_required'                 => 0,
                    'followup_assigned_level'           => null,
                    'opened_at'                         => $openedAt,
                    'opened_timezone'                   => $openedTz,
                    'dispositioned_at'                  => null,
                    'closed_at'                         => null,
                    'device_id'                         => $data['device_id'],
                    'app_version'                       => $data['app_version'] ?? null,
                    'platform'                          => $data['platform'] ?? 'ANDROID',
                    'record_version'                    => (int) ($data['record_version'] ?? 1),
                    'deleted_at'                        => null,
                    'sync_status'                       => 'SYNCED',
                    'synced_at'                         => $now,
                    'sync_attempt_count'                => 0,
                    'last_sync_error'                   => null,
                    'created_at'                        => $now,
                    'updated_at'                        => $now,
                    ]);
                } catch (\Illuminate\Database\QueryException $qe) {
                    // DB-level unique-key collision on (notification_id_active)
                    // OR (client_uuid). Either way, a row already exists for
                    // this notification — re-select and return it idempotently
                    // so the mobile gets a usable server_id rather than a 500.
                    // SQLSTATE 23000 = integrity constraint violation.
                    if ($qe->getCode() !== '23000') {
                        throw $qe;
                    }
                    $row = DB::table('secondary_screenings')
                        ->where('notification_id', $notifId)
                        ->whereNull('deleted_at')
                        ->first();
                    if (! $row) {
                        // Collision on client_uuid but the matching live row was
                        // already soft-deleted between our check and our insert.
                        $row = DB::table('secondary_screenings')
                            ->where('client_uuid', $clientUuid)
                            ->first();
                    }
                    if (! $row) {
                        throw $qe; // genuinely unexpected — let the outer catch surface it.
                    }
                    Log::info('[SecondaryScreening][store] Idempotent recovery from unique-key collision', [
                        'notification_id' => $notifId,
                        'server_id'       => $row->id,
                        'sqlstate'        => $qe->getCode(),
                    ]);
                    return $this->ok(
                        $this->formatCase($row),
                        'A secondary case already exists for this referral notification. Returning existing case.',
                        ['idempotent' => true, 'server_id' => $row->id, 'notification_id' => $notifId, 'recovered_from' => 'unique_key_collision']
                    );
                }

                // TRANSITION NOTIFICATION OPEN → IN_PROGRESS
                DB::table('notifications')
                    ->where('id', $notifId)
                    ->where('status', 'OPEN')
                    ->update([
                        'status'           => 'IN_PROGRESS',
                        'opened_at'        => $openedAt,
                        'assigned_user_id' => $userId,
                        'updated_at'       => $now,
                    ]);

                $newCase = DB::table('secondary_screenings')->where('id', $caseId)->first();

                Log::info('[SecondaryScreening][store] Case opened', [
                    'case_id'         => $caseId,
                    'notification_id' => $notifId,
                    'primary_id'      => $primaryId,
                    'poe_code'        => $assignment->poe_code,
                    'opened_by'       => $userId,
                ]);

                // Immediately notify national team that a new secondary screening was opened.
                if ($newCase) {
                    try {
                        \App\Services\NotificationDispatcher::dispatchSecondaryScreeningOpened($newCase, $userId);
                    } catch (\Throwable $e) {
                        Log::warning('[SecondaryScreening][store] dispatchSecondaryScreeningOpened failed: ' . $e->getMessage());
                    }
                }

                return $this->ok(
                    $this->formatCase($newCase),
                    'Secondary screening case opened successfully.',
                    [
                        'server_id'       => $caseId,
                        'notification_id' => $notifId,
                        'primary_id'      => $primaryId,
                        'idempotent'      => false,
                    ]
                );
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_screenings insert + notification transition');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /secondary-screenings
    // ═════════════════════════════════════════════════════════════════════

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id query parameter is required.', [
                'hint' => 'Append ?user_id={AUTH_DATA.id} to the request URL.',
            ]);
        }

        $user = $this->resolveUser($userId);
        if (! $user) {
            return $this->err(404, 'User not found.', ['user_id' => $userId]);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment found.', ['user_id' => $userId]);
        }

        try {
            $query = DB::table('secondary_screenings as ss')
                ->leftJoin('users as u', 'u.id', '=', 'ss.opened_by_user_id')
                ->leftJoin('notifications as n', 'n.id', '=', 'ss.notification_id')
                ->leftJoin('primary_screenings as ps', 'ps.id', '=', 'ss.primary_screening_id')
                ->whereNull('ss.deleted_at');

            $roleKey = $user->role_key ?? '';
            if (in_array($roleKey, ['POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_ADMIN', 'SCREENER'], true)) {
                $query->where('ss.poe_code', $assignment->poe_code);
            } elseif ($roleKey === 'DISTRICT_SUPERVISOR') {
                $query->where('ss.district_code', $assignment->district_code);
            } elseif ($roleKey === 'PHEOC_OFFICER') {
                $query->where('ss.pheoc_code', $assignment->pheoc_code);
            } else {
                $query->where('ss.country_code', $assignment->country_code);
            }

            if ($request->filled('case_status')) {
                $status = strtoupper($request->query('case_status'));
                if (in_array($status, self::VALID_CASE_STATUSES, true)) {
                    $query->where('ss.case_status', $status);
                }
            }
            if ($request->filled('date_from')) {
                $query->where('ss.opened_at', '>=', $request->query('date_from') . ' 00:00:00');
            }
            if ($request->filled('date_to')) {
                $query->where('ss.opened_at', '<=', $request->query('date_to') . ' 23:59:59');
            }
            if ($request->filled('risk_level')) {
                $rl = strtoupper($request->query('risk_level'));
                if (in_array($rl, self::VALID_RISK_LEVELS, true)) {
                    $query->where('ss.risk_level', $rl);
                }
            }
            if ($request->filled('syndrome')) {
                $syn = strtoupper($request->query('syndrome'));
                if (in_array($syn, self::VALID_SYNDROMES, true)) {
                    $query->where('ss.syndrome_classification', $syn);
                }
            }

            // FIX 12: screening_outcome filter via disposition_details prefix
            if ($request->filled('screening_outcome')) {
                $so = strtoupper($request->query('screening_outcome'));
                if (in_array($so, self::VALID_SCREENING_OUTCOMES, true)) {
                    $query->where('ss.disposition_details', 'like', "OUTCOME:{$so}%");
                }
            }
            // FIX 12: traveler_direction filter from primary_screenings
            if ($request->filled('traveler_direction')) {
                $td = strtoupper($request->query('traveler_direction'));
                if (in_array($td, self::VALID_TRAVELER_DIRECTIONS, true)) {
                    $query->where('ps.traveler_direction', $td);
                }
            }
            // FIX 12: updated_after cursor for incremental background sync
            if ($request->filled('updated_after')) {
                $after = $this->safeDatetime($request->query('updated_after'));
                if ($after) {
                    $query->where('ss.updated_at', '>', $after);
                }
            }

            $total   = (clone $query)->count();
            $perPage = min((int) $request->query('per_page', 50), self::MAX_PER_PAGE);
            $page    = max(1, (int) $request->query('page', 1));
            $offset  = ($page - 1) * $perPage;

            $cases = $query
                ->select([
                    'ss.*',
                    'u.full_name    as officer_name',
                    'n.status       as notification_status',
                    'n.priority     as notification_priority',
                    'ps.traveler_direction as primary_traveler_direction', // FIX 12
                ])
                ->orderBy('ss.opened_at', 'desc')
                ->skip($offset)
                ->take($perPage)
                ->get();

            return response()->json([
                'success' => true,
                'message' => 'Secondary screening cases retrieved.',
                'data'    => [
                    'items'    => $cases->map(function ($c) {
                        $formatted                       = $this->formatCase($c);
                        $formatted['traveler_direction'] = $c->primary_traveler_direction ?? null;
                        return $formatted;
                    })->values(),
                    'total'    => $total,
                    'per_page' => $perPage,
                    'page'     => $page,
                    'pages'    => (int) ceil($total / max(1, $perPage)),
                    'poe_code' => $assignment->poe_code,
                ],
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_screenings index');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /secondary-screenings/{id}
    // Full case detail with all child tables.
    // FIX 6: Notifications table queried once, assigned to variable.
    // ═════════════════════════════════════════════════════════════════════

    public function show(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id query parameter is required.', [
                'hint' => 'Append ?user_id={AUTH_DATA.id}',
            ]);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.', ['user_id' => $userId]);
        }

        try {
            $case = DB::table('secondary_screenings')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $case) {
                return $this->err(404, 'Secondary screening case not found.', [
                    'id'   => $id,
                    'hint' => 'The case may have been deleted or the ID is incorrect.',
                ]);
            }

            $scopeErr = $this->checkScope($case, $assignment, DB::table('users')->where('id', $userId)->first());
            if ($scopeErr) {
                return $scopeErr;
            }

            return $this->buildFullCaseResponse($case, (int) $case->notification_id);
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_screenings show');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // GET /secondary-screenings/{id}/verify
    //
    // In-app self-test for the mobile officer. Returns a compact, verification-
    // shaped payload grouping every UI-captured field into logical sections
    // (biodata, travel, vitals, triage, engine-generated suspected diseases,
    // syndrome / risk / disposition, child-row counts) so the view can
    // compare what the officer believes they sent against what the database
    // actually stored. No side effects, read-only.
    // ═════════════════════════════════════════════════════════════════════

    public function verify(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id query parameter is required.', [
                'hint' => 'Append ?user_id={AUTH_DATA.id}',
            ]);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.', ['user_id' => $userId]);
        }

        try {
            $case = DB::table('secondary_screenings')
                ->where('id', $id)->whereNull('deleted_at')->first();
            if (! $case) {
                return $this->err(404, 'Secondary screening case not found.', ['id' => $id]);
            }

            $scopeErr = $this->checkScope(
                $case,
                $assignment,
                DB::table('users')->where('id', $userId)->first()
            );
            if ($scopeErr) {
                return $scopeErr;
            }

            // Child collections — full rows for the diseases (the officer needs
            // to see the engine output landed verbatim) and counts for the rest
            // so the view can run row-count equality checks against IDB.
            $diseases = DB::table('secondary_suspected_diseases')
                ->where('secondary_screening_id', $id)
                ->orderBy('rank_order')
                ->get(['disease_code', 'rank_order', 'confidence', 'reasoning']);

            $symptomCount         = (int) DB::table('secondary_symptoms')->where('secondary_screening_id', $id)->count();
            $exposureCount        = (int) DB::table('secondary_exposures')->where('secondary_screening_id', $id)->count();
            $actionCount          = (int) DB::table('secondary_actions')->where('secondary_screening_id', $id)->count();
            $travelCountryCount   = (int) DB::table('secondary_travel_countries')->where('secondary_screening_id', $id)->count();
            $sampleCount          = (int) DB::table('secondary_samples')->where('secondary_screening_id', $id)->count();
            $diseaseCount         = $diseases->count();

            $alert = DB::table('alerts')
                ->where('secondary_screening_id', $id)
                ->whereNull('deleted_at')
                ->orderBy('id', 'desc')
                ->first(['id', 'alert_code', 'status', 'risk_level', 'routed_to_level', 'ihr_tier']);

            $payload = [
                'case_id'        => (int) $case->id,
                'client_uuid'    => $case->client_uuid,
                'case_status'    => $case->case_status,
                'sync_status'    => $case->sync_status,
                'record_version' => (int) $case->record_version,
                'updated_at'     => $case->updated_at,

                // Group 1 — Biodata (Step 1 bio section)
                'biodata' => [
                    'traveler_full_name'                => $case->traveler_full_name,
                    'traveler_gender'                   => $case->traveler_gender,
                    'traveler_age_years'                => $case->traveler_age_years,
                    'traveler_dob'                      => $case->traveler_dob,
                    'travel_document_type'              => $case->travel_document_type,
                    'travel_document_number'            => $case->travel_document_number,
                    'traveler_nationality_country_code' => $case->traveler_nationality_country_code,
                    'residence_country_code'            => $case->residence_country_code,
                    'phone_number'                      => $case->phone_number,
                ],

                // Group 2 — Travel / journey (Step 1 travel section)
                'travel' => [
                    'journey_start_country_code' => $case->journey_start_country_code,
                    'conveyance_type'            => $case->conveyance_type,
                    'conveyance_identifier'      => $case->conveyance_identifier,
                    'arrival_datetime'           => $case->arrival_datetime,
                    'purpose_of_travel'          => $case->purpose_of_travel,
                    'destination_district_code'  => $case->destination_district_code,
                    'travel_countries_count'     => $travelCountryCount,
                ],

                // Group 3 — Vitals + triage (Step 2)
                'vitals' => [
                    'temperature_value'       => $case->temperature_value,
                    'temperature_unit'        => $case->temperature_unit,
                    'pulse_rate'              => $case->pulse_rate,
                    'respiratory_rate'        => $case->respiratory_rate,
                    'bp_systolic'             => $case->bp_systolic,
                    'bp_diastolic'            => $case->bp_diastolic,
                    'oxygen_saturation'       => $case->oxygen_saturation,
                    'triage_category'         => $case->triage_category,
                    'emergency_signs_present' => (int) ($case->emergency_signs_present ?? 0),
                    'general_appearance'      => $case->general_appearance,
                ],

                // Group 4 — Engine output (Step 4) + Disposition (Step 5)
                'engine' => [
                    'syndrome_classification' => $case->syndrome_classification,
                    'risk_level'              => $case->risk_level,
                    'suspected_diseases'      => $diseases->map(fn ($r) => (array) $r)->values(),
                    'suspected_diseases_count' => $diseaseCount,
                    'alert_raised'            => $alert ? true : false,
                    'alert'                   => $alert ? (array) $alert : null,
                ],
                'disposition' => [
                    'final_disposition'       => $case->final_disposition,
                    'officer_notes'           => $case->officer_notes,
                    'followup_required'       => (int) ($case->followup_required ?? 0),
                    'followup_assigned_level' => $case->followup_assigned_level,
                    'dispositioned_at'        => $case->dispositioned_at,
                    'closed_at'               => $case->closed_at,
                ],

                // Group 5 — Child-table row counts (the view compares these
                // against IDB counts to catch silent sync drops)
                'child_counts' => [
                    'symptoms'           => $symptomCount,
                    'exposures'          => $exposureCount,
                    'actions'            => $actionCount,
                    'travel_countries'   => $travelCountryCount,
                    'suspected_diseases' => $diseaseCount,
                    'samples'            => $sampleCount,
                ],
            ];

            return $this->ok($payload, 'Verification snapshot retrieved.', [
                'endpoint'   => 'GET /secondary-screenings/{id}/verify',
                'purpose'    => 'In-app self-test: confirms every UI field landed in DB.',
                'generated'  => now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_screenings verify');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // PATCH /secondary-screenings/{id}
    // Partial field update. CLOSED cases rejected. Stale writes discarded.
    // FIX 2: screening_outcome in allowed fields list.
    // ═════════════════════════════════════════════════════════════════════

    public function update(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->input('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required in request body.', ['hint' => 'Send AUTH_DATA.id']);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.', ['user_id' => $userId]);
        }

        try {
            $case = DB::table('secondary_screenings')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $case) {
                return $this->err(404, 'Secondary case not found.', ['id' => $id]);
            }

            $scopeErr = $this->checkScope($case, $assignment, DB::table('users')->where('id', $userId)->first());
            if ($scopeErr) {
                return $scopeErr;
            }

            if ($case->case_status === 'CLOSED') {
                return $this->err(409, 'This case is CLOSED and cannot be updated.', [
                    'case_id'     => $id,
                    'case_status' => $case->case_status,
                    'closed_at'   => $case->closed_at,
                    'hint'        => 'Closed cases are immutable.',
                ]);
            }

            $incomingVersion = (int) $request->input('record_version', 0);
            if ($incomingVersion > 0 && $incomingVersion <= (int) $case->record_version) {
                Log::info('[SecondaryScreening][update] Stale write discarded', [
                    'case_id'          => $id,
                    'stored_version'   => $case->record_version,
                    'incoming_version' => $incomingVersion,
                ]);
                return response()->json([
                    'success' => true,
                    'message' => 'Stale write discarded — stored record is newer. No update applied.',
                    'data'    => $this->formatCase($case),
                    'meta'    => [
                        'stale_write'      => true,
                        'stored_version'   => $case->record_version,
                        'incoming_version' => $incomingVersion,
                    ],
                ]);
            }

            $allowed = [
                // Identity
                'traveler_full_name', 'traveler_initials', 'traveler_anonymous_code',
                'travel_document_type', 'travel_document_number',
                'traveler_gender', 'traveler_age_years', 'traveler_dob',
                'traveler_nationality_country_code', 'traveler_occupation',
                'residence_country_code', 'residence_address_text',
                'phone_number', 'alternative_phone', 'email',
                'destination_address_text', 'destination_district_code',
                'emergency_contact_name', 'emergency_contact_phone',
                // Conveyance
                'journey_start_country_code', 'embarkation_port_city',
                'conveyance_type', 'conveyance_identifier', 'seat_number',
                'arrival_datetime', 'departure_datetime',
                'purpose_of_travel', 'planned_length_of_stay_days',
                // Clinical
                'triage_category', 'emergency_signs_present', 'general_appearance',
                'temperature_value', 'temperature_unit',
                'pulse_rate', 'respiratory_rate',
                'bp_systolic', 'bp_diastolic', 'oxygen_saturation',
                'syndrome_classification', 'risk_level', 'officer_notes',
                // Disposition — FIX 2
                'final_disposition', 'disposition_details',
                'followup_required', 'followup_assigned_level',
                'screening_outcome',
                // Status / audit
                'case_status', 'dispositioned_at', 'closed_at',
                'platform', 'app_version', 'sync_status', 'synced_at',
            ];

            $updates = [];
            foreach ($allowed as $field) {
                if ($request->has($field)) {
                    $updates[$field] = $request->input($field);
                }
            }

            if (empty($updates)) {
                return $this->ok($this->formatCase($case), 'No updatable fields provided. No change made.', ['no_op' => true]);
            }

            $enumErrors = $this->validateEnums($updates);
            if (! empty($enumErrors)) {
                return $this->err(422, 'Invalid enum value in update payload.', [
                    'enum_errors' => $enumErrors,
                    'hint'        => 'See valid_values in each error entry.',
                ]);
            }

            // Defensive: coerce any enum value that would truncate against
            // the actual MySQL ENUM. See coerceForDbEnums() for rationale.
            $updates = $this->coerceForDbEnums($updates);

            // FIX 2: Encode screening_outcome into disposition_details
            if (isset($updates['screening_outcome'])) {
                $updates = $this->applyScreeningOutcome($updates, $case);
            }

            $updates['updated_at']     = now()->format('Y-m-d H:i:s');
            $updates['record_version'] = (int) $case->record_version + 1;

            DB::table('secondary_screenings')->where('id', $id)->update($updates);

            $updated = DB::table('secondary_screenings')->where('id', $id)->first();

            Log::info('[SecondaryScreening][update] Fields updated', [
                'case_id'     => $id,
                'fields'      => array_keys($updates),
                'new_version' => $updated->record_version,
            ]);

            return $this->ok($this->formatCase($updated), 'Secondary case updated.');
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_screenings update');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // PATCH /secondary-screenings/{id}/status
    // Enforces the case status machine. Atomically closes notification.
    // ═════════════════════════════════════════════════════════════════════

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->input('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id required.', ['hint' => 'Send AUTH_DATA.id']);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.', ['user_id' => $userId]);
        }

        $newStatus = strtoupper((string) $request->input('case_status', ''));
        if (! in_array($newStatus, self::VALID_CASE_STATUSES, true)) {
            return $this->err(422, 'Invalid case_status.', [
                'received'     => $request->input('case_status'),
                'valid_values' => self::VALID_CASE_STATUSES,
            ]);
        }

        try {
            $case = DB::table('secondary_screenings')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $case) {
                return $this->err(404, 'Secondary case not found.', ['id' => $id]);
            }

            $scopeErr = $this->checkScope($case, $assignment, DB::table('users')->where('id', $userId)->first());
            if ($scopeErr) {
                return $scopeErr;
            }

            $currentStatus = $case->case_status;

            if ($currentStatus === 'CLOSED') {
                return $this->err(409, 'Case is CLOSED. This is a terminal state — no transitions allowed.', [
                    'case_id'     => $id,
                    'case_status' => $currentStatus,
                    'closed_at'   => $case->closed_at,
                ]);
            }

            $valid = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
            if (! in_array($newStatus, $valid, true)) {
                return $this->err(409, "Transition from {$currentStatus} to {$newStatus} is not permitted by the case status machine.", [
                    'current_status'      => $currentStatus,
                    'requested_status'    => $newStatus,
                    'allowed_transitions' => $valid,
                    'hint'                => 'OPEN→IN_PROGRESS, IN_PROGRESS→DISPOSITIONED|CLOSED, DISPOSITIONED→CLOSED.',
                ]);
            }

            $now     = now()->format('Y-m-d H:i:s');
            $updates = ['case_status' => $newStatus, 'updated_at' => $now];

            if ($newStatus === 'DISPOSITIONED') {
                // BIO GATE — refuse to dispose of a case that has no real
                // traveller identity. Anonymous referrals must not reach a
                // terminal state. Mirrors the client-side guard in
                // SecondaryScreening.vue:saveStep1AndNext + dispositionCase.
                $bioMissing = [];
                if (mb_strlen(trim((string) ($case->traveler_full_name ?? ''))) < 2) {
                    $bioMissing[] = 'traveler_full_name';
                }
                if (! in_array(strtoupper((string) ($case->traveler_gender ?? '')), ['MALE','FEMALE','OTHER','UNKNOWN'], true)
                    || strtoupper((string) ($case->traveler_gender ?? '')) === 'UNKNOWN') {
                    $bioMissing[] = 'traveler_gender';
                }
                if (empty($case->traveler_age_years) && empty($case->traveler_dob)) {
                    $bioMissing[] = 'traveler_age_years_or_dob';
                }
                if (empty($case->traveler_nationality_country_code)) {
                    $bioMissing[] = 'traveler_nationality_country_code';
                }
                if (! empty($bioMissing)) {
                    return $this->err(422, 'Cannot finalise case — traveller identity / bio is incomplete.', [
                        'missing_bio_fields' => $bioMissing,
                        'hint'               => 'PATCH /secondary-screenings/{id} with the missing fields before transitioning to DISPOSITIONED.',
                    ]);
                }

                $missing = [];
                if (empty($case->syndrome_classification)) {
                    $missing[] = 'syndrome_classification';
                }

                if (empty($case->risk_level)) {
                    $missing[] = 'risk_level';
                }

                if (empty($case->final_disposition)) {
                    $missing[] = 'final_disposition';
                }

                if (! empty($missing)) {
                    return $this->err(422, 'Cannot reach DISPOSITIONED status. Required clinical fields are missing.', [
                        'missing_fields' => $missing,
                        'hint'           => 'Set syndrome_classification, risk_level, and final_disposition via PATCH /secondary-screenings/{id} before attempting to disposition the case.',
                    ]);
                }

                // FIX 10: NON_CASE bypasses HIGH_RISK action requirements
                $screeningOutcome = $this->extractScreeningOutcome(
                    $request->input('disposition_details') ?? $case->disposition_details ?? ''
                );
                $isNonCase = ($screeningOutcome === 'NON_CASE');

                if (! $isNonCase) {
                    $actionCount = DB::table('secondary_actions')
                        ->where('secondary_screening_id', $id)->where('is_done', 1)->count();
                    if ($actionCount === 0) {
                        return $this->err(422, 'At least one completed action (is_done=1) is required before DISPOSITIONED.', [
                            'secondary_screening_id' => $id,
                            'actions_completed'      => 0,
                        ]);
                    }

                    if (in_array($case->risk_level, ['HIGH', 'CRITICAL'], true)) {
                        $critAction = DB::table('secondary_actions')
                            ->where('secondary_screening_id', $id)
                            ->whereIn('action_code', self::HIGH_RISK_REQUIRED_ACTIONS)
                            ->where('is_done', 1)->exists();
                        if (! $critAction) {
                            return $this->err(422, "Risk level is {$case->risk_level}. ISOLATED or REFERRED_HOSPITAL required before DISPOSITIONED.", [
                                'risk_level'       => $case->risk_level,
                                'required_actions' => self::HIGH_RISK_REQUIRED_ACTIONS,
                                'hint'             => 'Waived when screening_outcome = NON_CASE.',
                            ]);
                        }
                    }
                }

                $updates['dispositioned_at'] = $request->input('dispositioned_at') ?? $now;

            } elseif ($newStatus === 'CLOSED') {
                if ($currentStatus === 'IN_PROGRESS') {
                    $notes = $request->input('officer_notes') ?? $case->officer_notes;
                    if (empty(trim((string) $notes))) {
                        return $this->err(422, 'officer_notes is required when closing directly from IN_PROGRESS.', [
                            'current_status' => $currentStatus,
                            'hint'           => 'Provide officer_notes explaining why the case is being closed without full disposition.',
                        ]);
                    }
                    if ($notes !== $case->officer_notes) {
                        $updates['officer_notes'] = $notes;
                    }
                    if (empty($case->final_disposition)) {
                        $updates['final_disposition'] = $request->input('final_disposition') ?? 'RELEASED';
                    }
                }
                $updates['closed_at'] = $request->input('closed_at') ?? $now;
                if (empty($case->dispositioned_at) && $currentStatus === 'IN_PROGRESS') {
                    $updates['dispositioned_at'] = $now;
                }
            }

            $updates['record_version'] = (int) $case->record_version + 1;

            return DB::transaction(function () use ($id, $updates, $case, $newStatus, $now) {
                DB::table('secondary_screenings')->where('id', $id)->update($updates);

                if ($newStatus === 'CLOSED') {
                    DB::table('notifications')
                        ->where('id', $case->notification_id)
                        ->whereIn('status', ['OPEN', 'IN_PROGRESS'])
                        // 2026-05-06: bump record_version so cross-device pull picks
                        // up the status change. Client's writeToIdb only updates
                        // mutable fields when incoming record_version > stored.
                        ->update([
                            'status'         => 'CLOSED',
                            'closed_at'      => $now,
                            'updated_at'     => $now,
                            'record_version' => DB::raw('COALESCE(record_version, 1) + 1'),
                        ]);
                }

                $updated = DB::table('secondary_screenings')->where('id', $id)->first();

                // FIX 9: When DISPOSITIONED, close the notification — referral is resolved.
                // The officer has made their clinical decision. The case may still go
                // through CLOSED later, but the referral queue item is resolved.
                if ($newStatus === 'DISPOSITIONED') {
                    $rSyn      = $updated->syndrome_classification ?? '';
                    $rRisk     = $updated->risk_level ?? '';
                    $dispCtx   = 'DISPOSITIONED' . ($rRisk ? ':' . $rRisk : '') . ($rSyn ? ':' . $rSyn : '');
                    $curReason = DB::table('notifications')->where('id', $case->notification_id)->value('reason_text') ?? '';
                    $cleanR    = preg_replace('/^DISPOSITIONED:[^|]+\|\s*/', '', $curReason);
                    $cleanR    = preg_replace('/^DISPOSITIONED:\S+$/', '', $cleanR);
                    $newReason = $dispCtx . (trim($cleanR) !== '' ? ' | ' . trim($cleanR) : '');
                    DB::table('notifications')->where('id', $case->notification_id)
                        ->whereIn('status', ['OPEN', 'IN_PROGRESS'])
                        ->update([
                            'status'         => 'CLOSED',
                            'closed_at'      => $now,
                            'reason_text'    => substr($newReason, 0, 255),
                            'updated_at'     => $now,
                            'record_version' => DB::raw('COALESCE(record_version, 1) + 1'),
                        ]);
                }

                // FIX 11: IHR tier check
                $topDisease = DB::table('secondary_suspected_diseases')
                    ->where('secondary_screening_id', $id)->where('rank_order', 1)->value('disease_code');
                $ihrTier1 = in_array($topDisease, self::IHR_TIER1_ALWAYS_NOTIFIABLE, true);
                $ihrTier2 = in_array($topDisease, self::IHR_TIER2_ANNEX2, true);

                Log::info('[SecondaryScreening][updateStatus] Status transition', [
                    'case_id'     => $id,
                    'from_status' => $case->case_status,
                    'to_status'   => $newStatus,
                    'top_disease' => $topDisease,
                    'ihr_tier1'   => $ihrTier1,
                    'ihr_tier2'   => $ihrTier2,
                ]);

                return $this->ok($this->formatCase($updated), "Case status updated to {$newStatus}.", [
                    'from_status'                => $case->case_status,
                    'to_status'                  => $newStatus,
                    'notification_closed'        => $newStatus === 'CLOSED',
                    'notification_dispositioned' => $newStatus === 'DISPOSITIONED',
                    'ihr_notification_required'  => $ihrTier1 || $ihrTier2,
                    'ihr_tier'                   => $ihrTier1 ? 'TIER_1_ALWAYS_NOTIFIABLE' : ($ihrTier2 ? 'TIER_2_ANNEX2' : null),
                    'top_disease'                => $topDisease,
                ]);
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_screenings updateStatus');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // DELETE /secondary-screenings/{id}
    // Soft-delete a secondary screening case and all child records.
    // Used by the Vue frontend to purge damaged/incomplete records
    // (e.g. records with no traveler name that cannot be remediated).
    //
    // Requires user_id query param for geographic scope enforcement.
    // Sets deleted_at on the case and hard-deletes child table rows.
    // ═════════════════════════════════════════════════════════════════════

    public function softDelete(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->query('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required.');
        }

        $user = $this->resolveUser($userId);
        if (! $user) {
            return $this->err(404, 'User not found.');
        }

        $case = DB::table('secondary_screenings')
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if (! $case) {
            // Already deleted or never existed — idempotent success
            return $this->ok(['id' => $id, 'already_deleted' => true], 'Case not found or already deleted.');
        }

        // Scope guard — foundational rule: NATIONAL sees all; PHEOC sees its
        // province + below; DISTRICT sees its district + below; POE sees its
        // POE only. Without this any authenticated user could purge another
        // POE's case (and its 6 child tables).
        $role = (string) $user->role_key;
        if ($role !== 'NATIONAL_ADMIN') {
            $asg = $this->resolvePrimaryAssignment($userId);
            if (! $asg) {
                return $this->err(403, 'No active assignment — cannot delete cases.');
            }
            $inScope = match ($role) {
                'PHEOC_OFFICER'       => $asg->province_code !== null
                                         && (string) $asg->province_code === (string) $case->province_code,
                'DISTRICT_SUPERVISOR' => $asg->district_code !== null
                                         && (string) $asg->district_code === (string) $case->district_code,
                'POE_ADMIN', 'POE_PRIMARY', 'POE_SECONDARY',
                'POE_DATA_OFFICER', 'SCREENER'
                                      => $asg->poe_code !== null
                                         && (string) $asg->poe_code === (string) $case->poe_code,
                default               => false,
            };
            if (! $inScope) {
                return $this->err(403, 'You are not authorised to delete this case.');
            }
        }

        try {
            return DB::transaction(function () use ($id, $case) {
                $now = now()->format('Y-m-d H:i:s');

                // Soft-delete the case
                DB::table('secondary_screenings')
                    ->where('id', $id)
                    ->update(['deleted_at' => $now, 'updated_at' => $now]);

                // Hard-delete all child records
                DB::table('secondary_symptoms')->where('secondary_screening_id', $id)->delete();
                DB::table('secondary_exposures')->where('secondary_screening_id', $id)->delete();
                DB::table('secondary_actions')->where('secondary_screening_id', $id)->delete();
                DB::table('secondary_samples')->where('secondary_screening_id', $id)->delete();
                DB::table('secondary_travel_countries')->where('secondary_screening_id', $id)->delete();
                DB::table('secondary_suspected_diseases')->where('secondary_screening_id', $id)->delete();

                // Close the linked notification if open
                if ($case->notification_id) {
                    DB::table('notifications')
                        ->where('id', $case->notification_id)
                        ->whereIn('status', ['OPEN', 'IN_PROGRESS'])
                        // 2026-05-06: bump record_version so cross-device pull picks
                        // up the status change. Client's writeToIdb only updates
                        // mutable fields when incoming record_version > stored.
                        ->update([
                            'status'         => 'CLOSED',
                            'closed_at'      => $now,
                            'updated_at'     => $now,
                            'record_version' => DB::raw('COALESCE(record_version, 1) + 1'),
                        ]);
                }

                Log::info('[SecondaryScreening] Soft-deleted case', [
                    'id'          => $id,
                    'client_uuid' => $case->client_uuid,
                    'reason'      => 'damaged_record_purge',
                ]);

                return $this->ok([
                    'id'          => $id,
                    'client_uuid' => $case->client_uuid,
                    'deleted_at'  => $now,
                ], 'Secondary screening case soft-deleted.');
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_screenings softDelete ' . $id);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /secondary-screenings/{id}/symptoms
    // FIX 4: Uses insertSymptom() helper. explicit_absent accepted,
    //        ready to persist once migration adds the column.
    // ═════════════════════════════════════════════════════════════════════

    public function syncSymptoms(Request $request, int $id): JsonResponse
    {
        $case = DB::table('secondary_screenings')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $case) {
            return $this->err(404, 'Case not found.', ['id' => $id]);
        }

        if ($case->case_status === 'CLOSED') {
            return $this->err(409, 'Case is CLOSED.', ['case_id' => $id]);
        }

        $items = $request->input('symptoms', []);
        if (! is_array($items)) {
            return $this->err(422, 'symptoms must be an array.', ['received_type' => gettype($items)]);
        }

        try {
            return DB::transaction(function () use ($id, $items) {
                DB::table('secondary_symptoms')->where('secondary_screening_id', $id)->delete();
                $inserted = 0;
                foreach ($items as $item) {
                    if (empty($item['symptom_code'])) {
                        continue;
                    }

                    $this->insertSymptom($id, $item);
                    $inserted++;
                }
                DB::table('secondary_screenings')
                    ->where('id', $id)->update(['updated_at' => now()->format('Y-m-d H:i:s')]);
                return $this->ok(
                    ['secondary_screening_id' => $id, 'inserted' => $inserted],
                    "Symptoms replaced. {$inserted} symptom(s) written."
                );
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_symptoms replace-all');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /secondary-screenings/{id}/exposures
    // ═════════════════════════════════════════════════════════════════════

    public function syncExposures(Request $request, int $id): JsonResponse
    {
        $case = DB::table('secondary_screenings')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $case) {
            return $this->err(404, 'Case not found.', ['id' => $id]);
        }

        if ($case->case_status === 'CLOSED') {
            return $this->err(409, 'Case is CLOSED.', ['case_id' => $id]);
        }

        $items = $request->input('exposures', []);
        if (! is_array($items)) {
            return $this->err(422, 'exposures must be an array.', ['received_type' => gettype($items)]);
        }

        try {
            return DB::transaction(function () use ($id, $items) {
                DB::table('secondary_exposures')->where('secondary_screening_id', $id)->delete();
                $inserted = 0;
                foreach ($items as $item) {
                    $code     = $item['exposure_code'] ?? null;
                    $response = strtoupper((string) ($item['response'] ?? 'UNKNOWN'));
                    if (empty($code)) {
                        continue;
                    }

                    if (! in_array($response, self::VALID_EXPOSURE_RESPONSES, true)) {
                        $response = 'UNKNOWN';
                    }

                    DB::table('secondary_exposures')->insert([
                        'secondary_screening_id' => $id,
                        'exposure_code'          => substr((string) $code, 0, 80),
                        'response'               => $response,
                        'details'                => isset($item['details']) ? substr((string) $item['details'], 0, 255) : null,
                    ]);
                    $inserted++;
                }
                DB::table('secondary_screenings')
                    ->where('id', $id)->update(['updated_at' => now()->format('Y-m-d H:i:s')]);
                return $this->ok(
                    ['secondary_screening_id' => $id, 'inserted' => $inserted],
                    "Exposures replaced. {$inserted} exposure(s) written."
                );
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_exposures replace-all');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /secondary-screenings/{id}/actions
    // ═════════════════════════════════════════════════════════════════════

    public function syncActions(Request $request, int $id): JsonResponse
    {
        $case = DB::table('secondary_screenings')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $case) {
            return $this->err(404, 'Case not found.', ['id' => $id]);
        }

        if ($case->case_status === 'CLOSED') {
            return $this->err(409, 'Case is CLOSED.', ['case_id' => $id]);
        }

        $items = $request->input('actions', []);
        if (! is_array($items)) {
            return $this->err(422, 'actions must be an array.', ['received_type' => gettype($items)]);
        }

        try {
            return DB::transaction(function () use ($id, $items) {
                DB::table('secondary_actions')->where('secondary_screening_id', $id)->delete();
                $inserted = 0;
                foreach ($items as $item) {
                    $code = $item['action_code'] ?? null;
                    if (empty($code)) {
                        continue;
                    }

                    DB::table('secondary_actions')->insert([
                        'secondary_screening_id' => $id,
                        'action_code'            => substr((string) $code, 0, 80),
                        'is_done'                => (int) ($item['is_done'] ?? 1),
                        'details'                => isset($item['details']) ? substr((string) $item['details'], 0, 255) : null,
                    ]);
                    $inserted++;
                }
                DB::table('secondary_screenings')
                    ->where('id', $id)->update(['updated_at' => now()->format('Y-m-d H:i:s')]);
                return $this->ok(
                    ['secondary_screening_id' => $id, 'inserted' => $inserted],
                    "Actions replaced. {$inserted} action(s) written."
                );
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_actions replace-all');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /secondary-screenings/{id}/samples
    // FIX 1: VALID_SAMPLE_TYPES actively enforced — invalid types skipped.
    // ═════════════════════════════════════════════════════════════════════

    public function syncSamples(Request $request, int $id): JsonResponse
    {
        $case = DB::table('secondary_screenings')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $case) {
            return $this->err(404, 'Case not found.', ['id' => $id]);
        }

        if ($case->case_status === 'CLOSED') {
            return $this->err(409, 'Case is CLOSED.', ['case_id' => $id]);
        }

        $items = $request->input('samples', []);
        if (! is_array($items)) {
            return $this->err(422, 'samples must be an array.', ['received_type' => gettype($items)]);
        }

        try {
            return DB::transaction(function () use ($id, $items) {
                DB::table('secondary_samples')->where('secondary_screening_id', $id)->delete();
                $inserted = 0;
                foreach ($items as $item) {
                    $collected   = (int) ($item['sample_collected'] ?? 0);
                    $sampleType  = isset($item['sample_type']) ? strtoupper((string) $item['sample_type']) : null;
                    $collectedAt = $this->safeDatetime($item['collected_at'] ?? null);

                    if ($collected === 1) {
                        if (empty($sampleType)) {
                            Log::warning('[SecondaryScreening][syncSamples] Skipping: collected=1 but no sample_type', [
                                'secondary_screening_id' => $id, 'item' => $item,
                            ]);
                            continue;
                        }
                        // FIX 1: Enforce valid sample types
                        if (! in_array($sampleType, self::VALID_SAMPLE_TYPES, true)) {
                            Log::warning('[SecondaryScreening][syncSamples] Skipping: invalid sample_type', [
                                'secondary_screening_id' => $id,
                                'sample_type'            => $sampleType,
                                'valid_types'            => self::VALID_SAMPLE_TYPES,
                            ]);
                            continue;
                        }
                    }

                    DB::table('secondary_samples')->insert([
                        'secondary_screening_id' => $id,
                        'sample_collected'       => $collected,
                        'sample_type'            => $sampleType,
                        'sample_identifier'      => isset($item['sample_identifier']) ? substr((string) $item['sample_identifier'], 0, 120) : null,
                        'lab_destination'        => isset($item['lab_destination']) ? substr((string) $item['lab_destination'], 0, 150) : null,
                        'collected_at'           => $collectedAt,
                    ]);
                    $inserted++;
                }
                DB::table('secondary_screenings')
                    ->where('id', $id)->update(['updated_at' => now()->format('Y-m-d H:i:s')]);
                return $this->ok(
                    ['secondary_screening_id' => $id, 'inserted' => $inserted],
                    "Samples replaced. {$inserted} sample(s) written."
                );
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_samples replace-all');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /secondary-screenings/{id}/travel
    // ═════════════════════════════════════════════════════════════════════

    public function syncTravel(Request $request, int $id): JsonResponse
    {
        $case = DB::table('secondary_screenings')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $case) {
            return $this->err(404, 'Case not found.', ['id' => $id]);
        }

        if ($case->case_status === 'CLOSED') {
            return $this->err(409, 'Case is CLOSED.', ['case_id' => $id]);
        }

        $items = $request->input('travel_countries', []);
        if (! is_array($items)) {
            return $this->err(422, 'travel_countries must be an array.', ['received_type' => gettype($items)]);
        }

        try {
            return DB::transaction(function () use ($id, $items) {
                DB::table('secondary_travel_countries')->where('secondary_screening_id', $id)->delete();
                $inserted = 0;
                $seen     = [];
                foreach ($items as $item) {
                    $cc        = strtoupper((string) ($item['country_code'] ?? ''));
                    $role      = strtoupper((string) ($item['travel_role'] ?? 'VISITED'));
                    $dedupeKey = "{$cc}:{$role}";
                    if (empty($cc) || ! in_array($role, self::VALID_TRAVEL_ROLES, true)) {
                        continue;
                    }

                    if (isset($seen[$dedupeKey])) {
                        continue;
                    }

                    $seen[$dedupeKey] = true;
                    DB::table('secondary_travel_countries')->insert([
                        'secondary_screening_id' => $id,
                        'country_code'           => substr($cc, 0, 10),
                        'travel_role'            => $role,
                        'arrival_date'           => $this->safeDate($item['arrival_date'] ?? null),
                        'departure_date'         => $this->safeDate($item['departure_date'] ?? null),
                    ]);
                    $inserted++;
                }
                DB::table('secondary_screenings')
                    ->where('id', $id)->update(['updated_at' => now()->format('Y-m-d H:i:s')]);
                return $this->ok(
                    ['secondary_screening_id' => $id, 'inserted' => $inserted],
                    "Travel countries replaced. {$inserted} entr(ies) written."
                );
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_travel_countries replace-all');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /secondary-screenings/{id}/diseases
    // ═════════════════════════════════════════════════════════════════════

    public function syncDiseases(Request $request, int $id): JsonResponse
    {
        $case = DB::table('secondary_screenings')->where('id', $id)->whereNull('deleted_at')->first();
        if (! $case) {
            return $this->err(404, 'Case not found.', ['id' => $id]);
        }

        if ($case->case_status === 'CLOSED') {
            return $this->err(409, 'Case is CLOSED.', ['case_id' => $id]);
        }

        $items = $request->input('suspected_diseases', []);
        if (! is_array($items)) {
            return $this->err(422, 'suspected_diseases must be an array.', ['received_type' => gettype($items)]);
        }

        try {
            return DB::transaction(function () use ($id, $items) {
                DB::table('secondary_suspected_diseases')->where('secondary_screening_id', $id)->delete();
                $inserted  = 0;
                $rank      = 1;
                $seenCodes = [];
                foreach ($items as $item) {
                    $code = $item['disease_code'] ?? null;
                    if (empty($code) || isset($seenCodes[$code])) {
                        continue;
                    }

                    $seenCodes[$code] = true;
                    $confidence       = isset($item['confidence'])
                        ? min(100.0, max(0.0, (float) $item['confidence'])) : null;
                    DB::table('secondary_suspected_diseases')->insert([
                        'secondary_screening_id' => $id,
                        'disease_code'           => substr((string) $code, 0, 80),
                        'rank_order'             => (int) ($item['rank_order'] ?? $rank),
                        'confidence'             => $confidence,
                        'reasoning'              => isset($item['reasoning']) ? substr((string) $item['reasoning'], 0, 255) : null,
                    ]);
                    $rank++;
                    $inserted++;
                }
                DB::table('secondary_screenings')
                    ->where('id', $id)->update(['updated_at' => now()->format('Y-m-d H:i:s')]);
                return $this->ok(
                    ['secondary_screening_id' => $id, 'inserted' => $inserted],
                    "Suspected diseases replaced. {$inserted} disease(s) written."
                );
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_suspected_diseases replace-all');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // POST /secondary-screenings/{id}/sync  —  FULL OFFLINE BATCH SYNC
    // FIX 2: screening_outcome in updateable fields.
    // FIX 3: State machine enforced BEFORE transaction via
    //        validateStatusTransitionForSync().
    // FIX 4: explicit_absent accepted via insertSymptom().
    // FIX 1: VALID_SAMPLE_TYPES enforced in samples block.
    // ═════════════════════════════════════════════════════════════════════

    public function fullSync(Request $request, int $id): JsonResponse
    {
        $userId = (int) $request->input('user_id', 0);
        if ($userId <= 0) {
            return $this->err(422, 'user_id is required.', ['hint' => 'Send AUTH_DATA.id']);
        }

        $assignment = $this->resolvePrimaryAssignment($userId);
        if (! $assignment) {
            return $this->err(403, 'No active assignment.', ['user_id' => $userId]);
        }

        try {
            $case = DB::table('secondary_screenings')->where('id', $id)->whereNull('deleted_at')->first();
            if (! $case) {
                return $this->err(404, 'Secondary case not found.', [
                    'id'   => $id,
                    'hint' => 'Use POST /secondary-screenings to create the case first.',
                ]);
            }

            $scopeErr = $this->checkScope($case, $assignment, DB::table('users')->where('id', $userId)->first());
            if ($scopeErr) {
                return $scopeErr;
            }

            if ($case->case_status === 'CLOSED') {
                return $this->err(409, 'Case is CLOSED. No sync accepted.', [
                    'case_id'   => $id,
                    'closed_at' => $case->closed_at,
                    'hint'      => 'Closed cases are immutable.',
                ]);
            }

            // FIX 3: Validate status transition BEFORE the DB transaction
            $requestedStatus = $request->has('case_status')
                ? strtoupper((string) $request->input('case_status'))
                : null;

            if ($requestedStatus && $requestedStatus !== $case->case_status) {
                $transitionError = $this->validateStatusTransitionForSync($case, $requestedStatus, $request);
                if ($transitionError) {
                    return $transitionError;
                }

            }

            return DB::transaction(function () use ($id, $case, $request, $requestedStatus, $userId) {
                $now           = now()->format('Y-m-d H:i:s');
                $incomingVer   = (int) $request->input('record_version', 0);
                $caseUpdated   = false;
                $statusChanged = false;

                $updateableFields = [
                    'traveler_full_name', 'traveler_initials', 'traveler_anonymous_code',
                    'travel_document_type', 'travel_document_number',
                    'traveler_gender', 'traveler_age_years', 'traveler_dob',
                    'traveler_nationality_country_code', 'traveler_occupation',
                    'residence_country_code', 'residence_address_text',
                    'phone_number', 'alternative_phone', 'email',
                    'destination_address_text', 'destination_district_code',
                    'emergency_contact_name', 'emergency_contact_phone',
                    'journey_start_country_code', 'embarkation_port_city',
                    'conveyance_type', 'conveyance_identifier', 'seat_number',
                    'arrival_datetime', 'departure_datetime',
                    'purpose_of_travel', 'planned_length_of_stay_days',
                    'triage_category', 'emergency_signs_present', 'general_appearance',
                    'temperature_value', 'temperature_unit',
                    'pulse_rate', 'respiratory_rate', 'bp_systolic', 'bp_diastolic', 'oxygen_saturation',
                    'syndrome_classification', 'risk_level', 'officer_notes',
                    'final_disposition', 'disposition_details',
                    'followup_required', 'followup_assigned_level',
                    'screening_outcome', // FIX 2
                    'case_status', 'dispositioned_at', 'closed_at',
                    'app_version', 'platform', 'sync_status', 'synced_at',
                ];

                $fieldUpdates = [];
                $staleWrite   = false;
                if ($incomingVer === 0 || $incomingVer > (int) $case->record_version) {
                    foreach ($updateableFields as $field) {
                        if ($request->has($field)) {
                            $fieldUpdates[$field] = $request->input($field);
                        }
                    }

                    if (! empty($fieldUpdates)) {
                        $enumErrors = $this->validateEnums($fieldUpdates);
                        if (! empty($enumErrors)) {
                            return $this->err(422, 'Invalid enum value in sync payload.', [
                                'enum_errors' => $enumErrors,
                            ]);
                        }

                        // Defensive: coerce any enum value that would truncate
                        // against the *actual* MySQL ENUM. Prevents the entire
                        // transaction (including child writes) from rolling
                        // back on a stale or out-of-band enum value.
                        $fieldUpdates = $this->coerceForDbEnums($fieldUpdates);

                        $newStatus = isset($fieldUpdates['case_status'])
                            ? strtoupper((string) $fieldUpdates['case_status']) : null;

                        if ($newStatus && $newStatus !== $case->case_status) {
                            $statusChanged = true;
                            // Stamp timestamps per status
                            if ($newStatus === 'DISPOSITIONED' && empty($fieldUpdates['dispositioned_at'])) {
                                $fieldUpdates['dispositioned_at'] = $now;
                            }
                            if ($newStatus === 'CLOSED') {
                                if (empty($fieldUpdates['closed_at'])) {
                                    $fieldUpdates['closed_at'] = $now;
                                }
                                if ($case->case_status === 'IN_PROGRESS' && empty($case->dispositioned_at)) {
                                    $fieldUpdates['dispositioned_at'] = $fieldUpdates['dispositioned_at'] ?? $now;
                                }
                                if ($case->case_status === 'IN_PROGRESS'
                                    && empty($case->final_disposition)
                                    && empty($fieldUpdates['final_disposition'])) {
                                    $fieldUpdates['final_disposition'] = 'RELEASED';
                                }
                            }
                        }

                        // FIX 2: Encode screening_outcome
                        if (isset($fieldUpdates['screening_outcome'])) {
                            $fieldUpdates = $this->applyScreeningOutcome($fieldUpdates, $case);
                        }

                        $fieldUpdates['updated_at']         = $now;
                        $fieldUpdates['sync_status']        = 'SYNCED';
                        $fieldUpdates['synced_at']          = $now;
                        $fieldUpdates['record_version']     = (int) $case->record_version + 1;
                        $fieldUpdates['server_received_at'] = $fieldUpdates['server_received_at'] ?? $now;

                        DB::table('secondary_screenings')->where('id', $id)->update($fieldUpdates);
                        $caseUpdated = true;
                    }
                } else {
                    // STALE-WRITE — the device sent record_version equal to or below
                    // the server's stored version. We silently skip case-field
                    // updates so we don't clobber a newer authoritative state.
                    //
                    // Why this matters: previously the mobile saw a success
                    // response, marked the case SYNCED, and assumed the close
                    // (or any field change) had landed. On the next case-open
                    // the cross-device hydrate pulled the server's older state
                    // BACK onto IDB, silently undoing the user's close. The
                    // $staleWrite flag below surfaces this so the mobile can
                    // pull the canonical server state and reconcile instead of
                    // pretending the close succeeded.
                    $staleWrite = true;
                    Log::info('[SecondaryScreening][fullSync] Case fields skipped — incoming version not newer', [
                        'case_id'          => $id,
                        'stored_version'   => $case->record_version,
                        'incoming_version' => $incomingVer,
                    ]);
                }

                // FIX 3: Close notification when case reaches CLOSED or DISPOSITIONED.
                // The referral is resolved once the officer has dispositioned — the case
                // may still transition to CLOSED later but the queue item is done.
                $newCaseStatus = $fieldUpdates['case_status'] ?? null;
                if ($statusChanged && in_array($newCaseStatus, ['CLOSED', 'DISPOSITIONED'], true)) {
                    DB::table('notifications')
                        ->where('id', $case->notification_id)
                        ->whereIn('status', ['OPEN', 'IN_PROGRESS'])
                        // 2026-05-06: bump record_version so cross-device pull picks
                        // up the status change. Client's writeToIdb only updates
                        // mutable fields when incoming record_version > stored.
                        ->update([
                            'status'         => 'CLOSED',
                            'closed_at'      => $now,
                            'updated_at'     => $now,
                            'record_version' => DB::raw('COALESCE(record_version, 1) + 1'),
                        ]);
                }

                // REPLACE-ALL CHILD TABLES
                $childResults = [];

                // SYMPTOMS — FIX 4: explicit_absent via insertSymptom()
                if ($request->has('symptoms')) {
                    DB::table('secondary_symptoms')->where('secondary_screening_id', $id)->delete();
                    $cnt = 0;
                    foreach ((array) $request->input('symptoms') as $s) {
                        if (empty($s['symptom_code'])) {
                            continue;
                        }

                        $this->insertSymptom($id, $s);
                        $cnt++;
                    }
                    $childResults['symptoms'] = $cnt;
                }

                // EXPOSURES
                if ($request->has('exposures')) {
                    DB::table('secondary_exposures')->where('secondary_screening_id', $id)->delete();
                    $cnt = 0;
                    foreach ((array) $request->input('exposures') as $e) {
                        $code     = $e['exposure_code'] ?? null;
                        $response = strtoupper((string) ($e['response'] ?? 'UNKNOWN'));
                        if (empty($code)) {
                            continue;
                        }

                        if (! in_array($response, self::VALID_EXPOSURE_RESPONSES, true)) {
                            $response = 'UNKNOWN';
                        }

                        DB::table('secondary_exposures')->insert([
                            'secondary_screening_id' => $id,
                            'exposure_code'          => substr((string) $code, 0, 80),
                            'response'               => $response,
                            'details'                => isset($e['details']) ? substr((string) $e['details'], 0, 255) : null,
                        ]);
                        $cnt++;
                    }
                    $childResults['exposures'] = $cnt;
                }

                // ACTIONS
                if ($request->has('actions')) {
                    DB::table('secondary_actions')->where('secondary_screening_id', $id)->delete();
                    $cnt = 0;
                    foreach ((array) $request->input('actions') as $a) {
                        $code = $a['action_code'] ?? null;
                        if (empty($code)) {
                            continue;
                        }

                        DB::table('secondary_actions')->insert([
                            'secondary_screening_id' => $id,
                            'action_code'            => substr((string) $code, 0, 80),
                            'is_done'                => (int) ($a['is_done'] ?? 1),
                            'details'                => isset($a['details']) ? substr((string) $a['details'], 0, 255) : null,
                        ]);
                        $cnt++;
                    }
                    $childResults['actions'] = $cnt;
                }

                // SAMPLES — FIX 1: VALID_SAMPLE_TYPES enforced
                if ($request->has('samples')) {
                    DB::table('secondary_samples')->where('secondary_screening_id', $id)->delete();
                    $cnt = 0;
                    foreach ((array) $request->input('samples') as $s) {
                        $collected  = (int) ($s['sample_collected'] ?? 0);
                        $sampleType = isset($s['sample_type']) ? strtoupper((string) $s['sample_type']) : null;
                        if ($collected === 1 && empty($sampleType)) {
                            continue;
                        }

                        if ($collected === 1 && ! in_array($sampleType, self::VALID_SAMPLE_TYPES, true)) {
                            continue;
                        }

                        DB::table('secondary_samples')->insert([
                            'secondary_screening_id' => $id,
                            'sample_collected'       => $collected,
                            'sample_type'            => $sampleType,
                            'sample_identifier'      => isset($s['sample_identifier']) ? substr((string) $s['sample_identifier'], 0, 120) : null,
                            'lab_destination'        => isset($s['lab_destination']) ? substr((string) $s['lab_destination'], 0, 150) : null,
                            'collected_at'           => $this->safeDatetime($s['collected_at'] ?? null),
                        ]);
                        $cnt++;
                    }
                    $childResults['samples'] = $cnt;
                }

                // TRAVEL COUNTRIES
                if ($request->has('travel_countries')) {
                    DB::table('secondary_travel_countries')->where('secondary_screening_id', $id)->delete();
                    $cnt  = 0;
                    $seen = [];
                    foreach ((array) $request->input('travel_countries') as $t) {
                        $cc   = strtoupper((string) ($t['country_code'] ?? ''));
                        $role = strtoupper((string) ($t['travel_role'] ?? 'VISITED'));
                        $key  = "{$cc}:{$role}";
                        if (empty($cc) || ! in_array($role, self::VALID_TRAVEL_ROLES, true) || isset($seen[$key])) {
                            continue;
                        }

                        $seen[$key] = true;
                        DB::table('secondary_travel_countries')->insert([
                            'secondary_screening_id' => $id,
                            'country_code'           => substr($cc, 0, 10),
                            'travel_role'            => $role,
                            'arrival_date'           => $this->safeDate($t['arrival_date'] ?? null),
                            'departure_date'         => $this->safeDate($t['departure_date'] ?? null),
                        ]);
                        $cnt++;
                    }
                    $childResults['travel_countries'] = $cnt;
                }

                // SUSPECTED DISEASES
                if ($request->has('suspected_diseases')) {
                    DB::table('secondary_suspected_diseases')->where('secondary_screening_id', $id)->delete();
                    $cnt       = 0;
                    $rank      = 1;
                    $seenCodes = [];
                    foreach ((array) $request->input('suspected_diseases') as $d) {
                        $code = $d['disease_code'] ?? null;
                        if (empty($code) || isset($seenCodes[$code])) {
                            continue;
                        }

                        $seenCodes[$code] = true;
                        $confidence       = isset($d['confidence']) ? min(100.0, max(0.0, (float) $d['confidence'])) : null;
                        DB::table('secondary_suspected_diseases')->insert([
                            'secondary_screening_id' => $id,
                            'disease_code'           => substr((string) $code, 0, 80),
                            'rank_order'             => (int) ($d['rank_order'] ?? $rank),
                            'confidence'             => $confidence,
                            'reasoning'              => isset($d['reasoning']) ? substr((string) $d['reasoning'], 0, 255) : null,
                        ]);
                        $rank++;
                        $cnt++;
                    }
                    $childResults['suspected_diseases'] = $cnt;
                }

                DB::table('secondary_screenings')->where('id', $id)->update(['updated_at' => $now]);

                $updatedCase = DB::table('secondary_screenings')->where('id', $id)->first();

                // FIX 11: IHR tier check for top disease
                $topDisease = DB::table('secondary_suspected_diseases')
                    ->where('secondary_screening_id', $id)->where('rank_order', 1)->value('disease_code');
                $ihrTier1 = in_array($topDisease, self::IHR_TIER1_ALWAYS_NOTIFIABLE, true);
                $ihrTier2 = in_array($topDisease, self::IHR_TIER2_ANNEX2, true);

                // FIX 9: DISPOSITIONED notification — add context to reason_text.
                // The notification was already closed by FIX 3 above.
                // This just enriches the reason_text with disposition context.
                $appliedStatus = $fieldUpdates['case_status'] ?? null;
                if ($statusChanged && $appliedStatus === 'DISPOSITIONED' && $case->notification_id) {
                    $rSyn      = $updatedCase->syndrome_classification ?? '';
                    $rRisk     = $updatedCase->risk_level ?? '';
                    $dispCtx   = 'DISPOSITIONED' . ($rRisk ? ':' . $rRisk : '') . ($rSyn ? ':' . $rSyn : '');
                    $curReason = DB::table('notifications')->where('id', $case->notification_id)->value('reason_text') ?? '';
                    $cleanR    = preg_replace('/^DISPOSITIONED:[^|]+\|\s*/', '', $curReason);
                    $cleanR    = preg_replace('/^DISPOSITIONED:\S+$/', '', $cleanR);
                    $newReason = $dispCtx . (trim($cleanR) !== '' ? ' | ' . trim($cleanR) : '');
                    DB::table('notifications')->where('id', $case->notification_id)
                        ->update(['reason_text' => substr($newReason, 0, 255), 'updated_at' => $now]);
                }

                Log::info('[SecondaryScreening][fullSync] Sync complete', [
                    'case_id'        => $id,
                    'case_updated'   => $caseUpdated,
                    'status_changed' => $statusChanged,
                    'child_counts'   => $childResults,
                    'top_disease'    => $topDisease,
                    'ihr_tier1'      => $ihrTier1,
                    'ihr_tier2'      => $ihrTier2,
                ]);

                // ═══════════════════════════════════════════════════════════
                // CASE-FILE EMAIL DISPATCH
                //
                // Fires whenever a sync settles a case at a non-NON_CASE
                // disposition. The dispatcher runs anti-spam suppression so
                // repeated fullSync calls within ALERT_CASE_FILE's window
                // (6h) don't re-blast the roster — but a fresh disposition
                // (status_changed) is honoured because the suppression key
                // is per template_code+entity_id+contact, not per call.
                // ═══════════════════════════════════════════════════════════
                $disp = strtoupper((string) ($updatedCase->final_disposition ?? ''));
                $isCase = ! in_array($disp, ['', 'NON_CASE', 'NOT_A_CASE', 'NONE'], true);
                if ($isCase && $statusChanged) {
                    try {
                        \App\Services\NotificationDispatcher::dispatchCaseFile($updatedCase, $userId);
                    } catch (\Throwable $e) {
                        Log::warning('[SecondaryScreening][fullSync] case-file dispatch failed: ' . $e->getMessage());
                    }
                }

                return $this->ok($this->formatCase($updatedCase), 'Full sync completed.', [
                    'server_id'                 => $id,
                    'case_updated'              => $caseUpdated,
                    'status_changed'            => $statusChanged,
                    'child_tables_sync'         => $childResults,
                    'idempotent'                => ! $caseUpdated && empty($childResults),
                    'ihr_notification_required' => $ihrTier1 || $ihrTier2,
                    'ihr_tier'                  => $ihrTier1 ? 'TIER_1_ALWAYS_NOTIFIABLE' : ($ihrTier2 ? 'TIER_2_ANNEX2' : null),
                    'top_disease'               => $topDisease,
                    // Surfaces "your record_version was not ahead of the server
                    // so we did NOT apply your case-field updates" so the mobile
                    // can reconcile against the canonical server state instead
                    // of trusting its locally-modified copy. Without this flag,
                    // closes silently regress to the server's older state on
                    // the next cross-device hydrate.
                    'stale_write'               => $staleWrite,
                    'stored_version'            => (int) $updatedCase->record_version,
                ]);
            });
        } catch (Throwable $e) {
            return $this->serverError($e, 'secondary_screenings fullSync');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // PRIVATE HELPERS
    // ═════════════════════════════════════════════════════════════════════

    /**
     * FIX 8: Build the full case response with all child tables.
     * Single source of truth for show(), showByNotification(), and store().
     * FIX 7: Includes traveler_direction from primary_screening.
     * FIX 11: IHR tier flag in meta.
     */
    private function buildFullCaseResponse(object $case, int $notifId, bool $idempotent = true): JsonResponse
    {
        $caseId    = (int) $case->id;
        $formatted = $this->formatCase($case);

        $formatted['symptoms'] = DB::table('secondary_symptoms')
            ->where('secondary_screening_id', $caseId)->orderBy('symptom_code')->get()
            ->map(fn($r) => (array) $r)->values();

        $formatted['exposures'] = DB::table('secondary_exposures')
            ->where('secondary_screening_id', $caseId)->orderBy('exposure_code')->get()
            ->map(fn($r) => (array) $r)->values();

        $formatted['actions'] = DB::table('secondary_actions')
            ->where('secondary_screening_id', $caseId)->orderBy('action_code')->get()
            ->map(fn($r) => (array) $r)->values();

        $formatted['samples'] = DB::table('secondary_samples')
            ->where('secondary_screening_id', $caseId)->orderBy('id')->get()
            ->map(fn($r) => (array) $r)->values();

        $formatted['travel_countries'] = DB::table('secondary_travel_countries')
            ->where('secondary_screening_id', $caseId)->orderBy('arrival_date', 'desc')->get()
            ->map(fn($r) => (array) $r)->values();

        $formatted['suspected_diseases'] = DB::table('secondary_suspected_diseases')
            ->where('secondary_screening_id', $caseId)->orderBy('rank_order')->get()
            ->map(fn($r) => (array) $r)->values();

        // FIX 6: Single query
        $notif                     = DB::table('notifications')->where('id', $notifId)->first();
        $formatted['notification'] = $notif ? (array) $notif : null;

        // FIX 7: Include traveler_direction from primary_screenings
        $primary = DB::table('primary_screenings')
            ->where('id', $case->primary_screening_id)->first();

        $formatted['primary_screening'] = $primary ? [
            'id'                  => $primary->id,
            'client_uuid'         => $primary->client_uuid,
            'gender'              => $primary->gender,
            'traveler_direction'  => $primary->traveler_direction ?? null,
            'temperature_value'   => $primary->temperature_value,
            'temperature_unit'    => $primary->temperature_unit,
            'symptoms_present'    => (bool) $primary->symptoms_present,
            'referral_created'    => (bool) $primary->referral_created,
            'captured_at'         => $primary->captured_at,
            'captured_timezone'   => $primary->captured_timezone,
            'record_status'       => $primary->record_status,
            'captured_by_user_id' => $primary->captured_by_user_id,
            'poe_code'            => $primary->poe_code,
            'platform'            => $primary->platform,
        ] : null;

        $alert = DB::table('alerts')
            ->where('secondary_screening_id', $caseId)
            ->orderBy('created_at', 'desc')->first();
        $formatted['alert'] = $alert ? (array) $alert : null;

        // FIX 11: IHR tier flag for top suspected disease
        $topDisease = $formatted['suspected_diseases'][0]['disease_code'] ?? null;
        $ihrTier1   = in_array($topDisease, self::IHR_TIER1_ALWAYS_NOTIFIABLE, true);
        $ihrTier2   = in_array($topDisease, self::IHR_TIER2_ANNEX2, true);

        return $this->ok($formatted, 'Secondary screening case retrieved.', [
            'server_id'                 => $caseId,
            'idempotent'                => $idempotent,
            'ihr_notification_required' => $ihrTier1 || $ihrTier2,
            'ihr_tier'                  => $ihrTier1 ? 'TIER_1_ALWAYS_NOTIFIABLE' : ($ihrTier2 ? 'TIER_2_ANNEX2' : null),
            'top_disease'               => $topDisease,
        ]);
    }

    /**
     * FIX 10: Extract screening_outcome from disposition_details prefix.
     */
    private function extractScreeningOutcome(string $dispositionDetails): ?string
    {
        if (preg_match('/^OUTCOME:([A-Z_]+)/', $dispositionDetails, $m)) {
            $candidate = $m[1];
            if (in_array($candidate, self::VALID_SCREENING_OUTCOMES, true)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * FIX 3: Validate a requested status transition for fullSync()

     * before the DB transaction starts. Returns a JsonResponse error
     * if the transition is invalid, null if it is allowed.
     * Checks the same rules as updateStatus().
     */
    private function validateStatusTransitionForSync(
        object $case,
        string $requestedStatus,
        Request $request
    ): ?JsonResponse {
        $currentStatus = $case->case_status;

        $valid = self::ALLOWED_TRANSITIONS[$currentStatus] ?? [];
        if (! in_array($requestedStatus, $valid, true)) {
            return $this->err(409, "Transition from {$currentStatus} to {$requestedStatus} is not permitted.", [
                'current_status'      => $currentStatus,
                'requested_status'    => $requestedStatus,
                'allowed_transitions' => $valid,
                'hint'                => 'OPEN→IN_PROGRESS, IN_PROGRESS→DISPOSITIONED|CLOSED, DISPOSITIONED→CLOSED.',
            ]);
        }

        if ($requestedStatus === 'DISPOSITIONED') {
            $syndromeCheck = $request->input('syndrome_classification') ?? $case->syndrome_classification;
            $riskCheck     = $request->input('risk_level') ?? $case->risk_level;
            $disposCheck   = $request->input('final_disposition') ?? $case->final_disposition;

            $missing = [];
            if (empty($syndromeCheck)) {
                $missing[] = 'syndrome_classification';
            }

            if (empty($riskCheck)) {
                $missing[] = 'risk_level';
            }

            if (empty($disposCheck)) {
                $missing[] = 'final_disposition';
            }

            if (! empty($missing)) {
                return $this->err(422, 'Cannot sync to DISPOSITIONED. Required clinical fields missing from payload and stored case.', [
                    'missing_fields' => $missing,
                    'hint'           => 'Include syndrome_classification, risk_level, and final_disposition in the sync payload.',
                ]);
            }

            // FIX 10: NON_CASE bypasses HIGH_RISK check
            $screeningOutcomeSync = $this->extractScreeningOutcome(
                $request->input('disposition_details') ?? $case->disposition_details ?? ''
            );
            $isNonCaseSync = ($screeningOutcomeSync === 'NON_CASE');

            if (! $isNonCaseSync) {
                $riskLevel = strtoupper((string) ($request->input('risk_level') ?? $case->risk_level ?? ''));
                if (in_array($riskLevel, ['HIGH', 'CRITICAL'], true)) {
                    $actionsInPayload = (array) $request->input('actions', []);
                    $payloadHasAction = collect($actionsInPayload)->contains(
                        fn($a) => in_array($a['action_code'] ?? '', self::HIGH_RISK_REQUIRED_ACTIONS, true)
                        && (int) ($a['is_done'] ?? 0) === 1
                    );
                    if (! $payloadHasAction) {
                        $serverHasAction = DB::table('secondary_actions')
                            ->where('secondary_screening_id', $case->id)
                            ->whereIn('action_code', self::HIGH_RISK_REQUIRED_ACTIONS)
                            ->where('is_done', 1)->exists();
                        if (! $serverHasAction) {
                            return $this->err(422, "Risk level is {$riskLevel}. ISOLATED or REFERRED_HOSPITAL (is_done=1) required before DISPOSITIONED.", [
                                'risk_level'       => $riskLevel,
                                'required_actions' => self::HIGH_RISK_REQUIRED_ACTIONS,
                                'hint'             => 'Waived when screening_outcome = NON_CASE.',
                            ]);
                        }
                    }
                }
            }
        }

        if ($requestedStatus === 'CLOSED' && $currentStatus === 'IN_PROGRESS') {
            $notes = $request->input('officer_notes') ?? $case->officer_notes;
            if (empty(trim((string) $notes))) {
                return $this->err(422, 'officer_notes required to sync CLOSED status from IN_PROGRESS.', [
                    'hint' => 'Include officer_notes in the sync payload.',
                ]);
            }
        }

        return null;
    }

    /**
     * FIX 4: Centralised symptom INSERT used by both syncSymptoms() and fullSync().
     *
     * The secondary_symptoms table currently has:
     *   id, secondary_screening_id, symptom_code, is_present, onset_date, details
     *
     * The Vue UI sends explicit_absent (0|1) on every symptom record.
     * This is critical for the disease scoring engine contradiction penalty
     * but the column does not yet exist in POE_2026.sql.
     *
     * TODO: Run migration then uncomment the explicit_absent line:
     *   ALTER TABLE secondary_symptoms
     *     ADD COLUMN `explicit_absent` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_present`;
     */
    private function insertSymptom(int $caseId, array $item): void
    {
        DB::table('secondary_symptoms')->insert([
            'secondary_screening_id' => $caseId,
            'symptom_code'           => substr((string) ($item['symptom_code'] ?? ''), 0, 80),
            'is_present'             => (int) ($item['is_present'] ?? 1),
            // TODO: Uncomment after migration:
            // 'explicit_absent'     => (int) ($item['explicit_absent'] ?? 0),
            'onset_date'             => $this->safeDate($item['onset_date'] ?? null),
            'details'                => isset($item['details']) ? substr((string) $item['details'], 0, 255) : null,
        ]);
    }

    /**
     * FIX 2: Handle screening_outcome sent by the Vue UI disposition tab.
     *
     * Column does not yet exist in secondary_screenings per POE_2026.sql.
     * Until the migration runs, the value is encoded as a structured prefix
     * in disposition_details: "OUTCOME:NON_CASE | [existing details]"
     * so zero data is lost and the value is human-readable.
     *
     * TODO: After migration:
     *   ALTER TABLE secondary_screenings
     *     ADD COLUMN `screening_outcome` VARCHAR(40) NULL AFTER `disposition_details`;
     *
     * Remove this helper and write directly to `screening_outcome` in all paths.
     * Run a one-time migration to decode existing OUTCOME: prefixes.
     */
    private function applyScreeningOutcome(array $updates, object $case): array
    {
        $outcome = strtoupper((string) ($updates['screening_outcome'] ?? ''));
        unset($updates['screening_outcome']); // column does not exist yet

        if (empty($outcome)) {
            return $updates;
        }

        if (! in_array($outcome, self::VALID_SCREENING_OUTCOMES, true)) {
            Log::warning('[SecondaryScreening][applyScreeningOutcome] Invalid screening_outcome stripped', [
                'received'     => $outcome,
                'valid_values' => self::VALID_SCREENING_OUTCOMES,
            ]);
            return $updates;
        }

        $existingDetails = $updates['disposition_details'] ?? $case->disposition_details ?? '';
        // Strip any prior OUTCOME prefix to avoid duplication on re-sync
        $cleanDetails = preg_replace('/^OUTCOME:[A-Z_]+\s*\|\s*/', '', (string) $existingDetails);
        $cleanDetails = preg_replace('/^OUTCOME:[A-Z_]+$/', '', (string) $cleanDetails);

        $encoded                        = "OUTCOME:{$outcome}" . (trim((string) $cleanDetails) !== '' ? " | {$cleanDetails}" : '');
        $updates['disposition_details'] = substr($encoded, 0, 255);

        return $updates;
    }

    /** Resolve a user from the users table. */
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

    /**
     * Resolve notification_id from server integer or client UUID string.
     * The device stores client_uuid as the IDB key and sends it as the FK
     * before the notification has a server id.
     */
    private function resolveNotificationId(mixed $value): ?int
    {
        if (empty($value)) {
            return null;
        }

        if (is_numeric($value) && (int) $value > 0) {
            $row = DB::table('notifications')->where('id', (int) $value)->value('id');
            return $row ? (int) $row : null;
        }
        $row = DB::table('notifications')->where('client_uuid', (string) $value)->value('id');
        return $row ? (int) $row : null;
    }

    /** Resolve primary_screening_id from integer or client UUID string. */
    private function resolvePrimaryScreeningId(mixed $value): ?int
    {
        if (empty($value)) {
            return null;
        }

        if (is_numeric($value) && (int) $value > 0) {
            $row = DB::table('primary_screenings')->where('id', (int) $value)->value('id');
            return $row ? (int) $row : null;
        }
        $row = DB::table('primary_screenings')->where('client_uuid', (string) $value)->value('id');
        return $row ? (int) $row : null;
    }

    /**
     * Check that the user has geographic scope over the given case.
     * Returns a 403 JsonResponse if denied, null if allowed.
     */
    private function checkScope(object $case, object $assignment, ?object $user): ?JsonResponse
    {
        $roleKey = $user->role_key ?? '';
        if (in_array($roleKey, ['POE_PRIMARY', 'POE_SECONDARY', 'POE_DATA_OFFICER', 'POE_ADMIN', 'SCREENER'], true)) {
            if ($case->poe_code !== $assignment->poe_code) {
                return $this->err(403, 'Access denied. This case belongs to a different POE.', [
                    'case_poe_code' => $case->poe_code,
                    'user_poe_code' => $assignment->poe_code,
                ]);
            }
        } elseif ($roleKey === 'DISTRICT_SUPERVISOR') {
            if ($case->district_code !== $assignment->district_code) {
                return $this->err(403, 'Access denied. This case is in a different district.', [
                    'case_district' => $case->district_code,
                    'user_district' => $assignment->district_code,
                ]);
            }
        } elseif ($roleKey === 'PHEOC_OFFICER') {
            if ($case->pheoc_code !== $assignment->pheoc_code) {
                return $this->err(403, 'Access denied. This case is in a different PHEOC region.', [
                    'case_pheoc' => $case->pheoc_code,
                    'user_pheoc' => $assignment->pheoc_code,
                ]);
            }
        } else {
            if ($case->country_code !== $assignment->country_code) {
                return $this->err(403, 'Access denied. This case is in a different country.', [
                    'case_country' => $case->country_code,
                    'user_country' => $assignment->country_code,
                ]);
            }
        }
        return null;
    }

    /**
     * Format a secondary_screenings DB row into the canonical API response shape.
     * FIX 2: Decodes screening_outcome from disposition_details prefix and returns
     *        it as a first-class field so the Vue UI can restore it correctly.
     */
    private function formatCase(object $case): array
    {
        // FIX 2: Decode screening_outcome from disposition_details prefix
        $rawDetails       = (string) ($case->disposition_details ?? '');
        $screeningOutcome = null;
        $cleanDetails     = $rawDetails;

        if (preg_match('/^OUTCOME:([A-Z_]+)\s*\|\s*(.*)/s', $rawDetails, $m)) {
            $decoded = $m[1];
            if (in_array($decoded, self::VALID_SCREENING_OUTCOMES, true)) {
                $screeningOutcome = $decoded;
                $cleanDetails     = trim($m[2]);
            }
        } elseif (preg_match('/^OUTCOME:([A-Z_]+)$/', $rawDetails, $m)) {
            $decoded = $m[1];
            if (in_array($decoded, self::VALID_SCREENING_OUTCOMES, true)) {
                $screeningOutcome = $decoded;
                $cleanDetails     = '';
            }
        }

        return [
            'id'                                => (int) $case->id,
            'client_uuid'                       => $case->client_uuid,
            'reference_data_version'            => $case->reference_data_version,
            'server_received_at'                => $case->server_received_at,
            'country_code'                      => $case->country_code,
            'province_code'                     => $case->province_code,
            'pheoc_code'                        => $case->pheoc_code,
            'district_code'                     => $case->district_code,
            'poe_code'                          => $case->poe_code,
            'primary_screening_id'              => (int) $case->primary_screening_id,
            'notification_id'                   => (int) $case->notification_id,
            'opened_by_user_id'                 => (int) $case->opened_by_user_id,
            'case_status'                       => $case->case_status,
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
            'journey_start_country_code'        => $case->journey_start_country_code,
            'embarkation_port_city'             => $case->embarkation_port_city,
            'conveyance_type'                   => $case->conveyance_type,
            'conveyance_identifier'             => $case->conveyance_identifier,
            'seat_number'                       => $case->seat_number,
            'arrival_datetime'                  => $case->arrival_datetime,
            'departure_datetime'                => $case->departure_datetime,
            'purpose_of_travel'                 => $case->purpose_of_travel,
            'planned_length_of_stay_days'       => $case->planned_length_of_stay_days !== null ? (int) $case->planned_length_of_stay_days : null,
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
            'syndrome_classification'           => $case->syndrome_classification,
            'risk_level'                        => $case->risk_level,
            'officer_notes'                     => $case->officer_notes,
            // FIX 2: screening_outcome decoded and returned as first-class field
            'screening_outcome'                 => $screeningOutcome,
            'final_disposition'                 => $case->final_disposition,
            'disposition_details'               => $cleanDetails !== '' ? $cleanDetails : null,
            'followup_required'                 => (bool) $case->followup_required,
            'followup_assigned_level'           => $case->followup_assigned_level,
            'opened_at'                         => $case->opened_at,
            'opened_timezone'                   => $case->opened_timezone,
            'dispositioned_at'                  => $case->dispositioned_at,
            'closed_at'                         => $case->closed_at,
            'device_id'                         => $case->device_id,
            'app_version'                       => $case->app_version,
            'platform'                          => $case->platform,
            'record_version'                    => (int) $case->record_version,
            'deleted_at'                        => $case->deleted_at,
            'sync_status'                       => $case->sync_status ?? 'SYNCED',
            'synced_at'                         => $case->synced_at,
            'sync_attempt_count'                => (int) ($case->sync_attempt_count ?? 0),
            'last_sync_error'                   => $case->last_sync_error,
            'created_at'                        => $case->created_at,
            'updated_at'                        => $case->updated_at,
        ];
    }

    /**
     * Validate enum fields in an update/sync payload.
     * FIX 2: screening_outcome added to enum map.
     */
    /**
     * Coerce / canonicalise enum field values against the *actual MySQL ENUM*
     * for each column so an unknown value cannot cause "Data truncated"
     * (SQLSTATE 01000, error 1265) inside the fullSync transaction.
     *
     * Rationale: validateEnums() accepts the PHP-whitelist union which is
     * intentionally wider than each MySQL ENUM (the controller has historically
     * added new canonical codes ahead of schema migrations). That mismatch
     * caused 35+ production rollbacks where final_disposition='RELEASED_NO_CONDITION'
     * truncated → the whole UPDATE rolled back → syndrome / risk_level / actions /
     * suspected_diseases were ALL silently lost even though the mobile app and
     * the validator both reported success.
     *
     * After this coercion runs, every enum field is either a value MySQL
     * accepts, or has been removed from the update payload (so the DB falls
     * back to its prior value rather than aborting the whole transaction).
     * The original value is logged + stashed in disposition_details when
     * the field is final_disposition so we never silently lose the operator's
     * intent.
     */
    private function coerceForDbEnums(array $updates): array
    {
        // Mirrors the actual MySQL ENUM definitions on secondary_screenings.
        // KEEP IN SYNC with database migrations — these are the values the
        // DB will actually accept. The post-2026-05-20 migration extends
        // final_disposition to the 14 codes below.
        $dbEnums = [
            'final_disposition' => [
                'RELEASED', 'DELAYED', 'QUARANTINED', 'ISOLATED', 'REFERRED',
                'TRANSFERRED', 'DENIED_BOARDING', 'OTHER',
                'RELEASED_NO_CONDITION', 'RELEASED_UNDER_FOLLOWUP',
                'REFERRED_HEALTH_FACILITY', 'ISOLATED_ADMITTED',
                'DECEASED_AT_POE', 'RETURN_TO_ORIGIN',
            ],
            'case_status'             => ['OPEN', 'IN_PROGRESS', 'DISPOSITIONED', 'CLOSED'],
            'traveler_gender'         => ['MALE', 'FEMALE', 'OTHER', 'UNKNOWN'],
            'risk_level'              => ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'],
            'triage_category'         => ['NON_URGENT', 'URGENT', 'EMERGENCY'],
            'general_appearance'      => ['WELL', 'UNWELL', 'SEVERELY_ILL'],
            'conveyance_type'         => ['AIR', 'LAND', 'SEA', 'OTHER'],
            'temperature_unit'        => ['C', 'F'],
            'followup_assigned_level' => ['POE', 'DISTRICT', 'PHEOC', 'NATIONAL'],
        ];

        // When a value is non-empty but not DB-valid, prefer a safe fallback
        // rather than dropping the update silently — the alternative is the
        // case ending up with a NULL where the officer chose SOMETHING.
        $fallbacks = [
            'final_disposition' => 'OTHER',
            'traveler_gender'   => 'UNKNOWN',
            'conveyance_type'   => 'OTHER',
            'temperature_unit'  => 'C',
        ];

        foreach ($dbEnums as $field => $validValues) {
            if (! array_key_exists($field, $updates)) {
                continue;
            }

            $raw = $updates[$field];
            // Preserve nulls / empty — they mean "officer hasn't filled this in"
            // and should not be coerced into a fake value.
            if ($raw === null) {
                continue;
            }
            if (is_string($raw) && trim($raw) === '') {
                continue;
            }

            $val = strtoupper((string) $raw);
            if (in_array($val, $validValues, true)) {
                // Canonicalise case for safety (DB stores uppercase).
                $updates[$field] = $val;
                continue;
            }

            // Value would truncate. Log and either fallback or strip.
            if (isset($fallbacks[$field])) {
                Log::warning('[SecondaryScreening][coerceForDbEnums] value not in DB enum — using fallback', [
                    'field'    => $field,
                    'received' => $raw,
                    'fallback' => $fallbacks[$field],
                ]);
                $updates[$field] = $fallbacks[$field];

                // For final_disposition specifically, preserve the operator's
                // original intent in disposition_details (a free-text column,
                // varchar). The dashboards already render disposition_details
                // when present, so the original code is not lost.
                if ($field === 'final_disposition' && empty($updates['disposition_details'])) {
                    $updates['disposition_details'] = 'ORIGINAL_DISPOSITION=' . substr((string) $raw, 0, 60);
                }
            } else {
                Log::warning('[SecondaryScreening][coerceForDbEnums] value not in DB enum — stripped from update', [
                    'field'    => $field,
                    'received' => $raw,
                ]);
                unset($updates[$field]);
            }
        }

        return $updates;
    }

    private function validateEnums(array $fields): array
    {
        $enumMap = [
            'traveler_gender'         => self::VALID_GENDERS,
            'triage_category'         => self::VALID_TRIAGE_CATEGORIES,
            'general_appearance'      => self::VALID_APPEARANCES,
            'risk_level'              => self::VALID_RISK_LEVELS,
            'final_disposition'       => self::VALID_DISPOSITIONS,
            'followup_assigned_level' => self::VALID_FOLLOWUP_LEVELS,
            'conveyance_type'         => self::VALID_CONVEYANCE_TYPES,
            'temperature_unit'        => self::VALID_TEMP_UNITS,
            'platform'                => self::VALID_PLATFORMS,
            'case_status'             => self::VALID_CASE_STATUSES,
            'syndrome_classification' => self::VALID_SYNDROMES,
            'screening_outcome'       => self::VALID_SCREENING_OUTCOMES, // FIX 2
        ];

        $errors = [];
        foreach ($enumMap as $field => $valid) {
            if (! isset($fields[$field])) continue;
            $raw = $fields[$field];
            // Treat null / empty-string / whitespace as "unset" rather than
            // an invalid enum value. The mobile client legitimately sends ''
            // for optional fields the officer hasn't filled in yet (e.g.
            // syndrome_classification before the analysis step). Refusing
            // those with a 422 broke the whole sync chain — see the
            // /sync 422 reports from the field 2026-05-05.
            if ($raw === null) continue;
            if (is_string($raw) && trim($raw) === '') continue;
            $val = strtoupper((string) $raw);
            if (! in_array($val, $valid, true)) {
                $errors[$field] = [
                    'received'     => $raw,
                    'valid_values' => $valid,
                ];
            }
        }
        return $errors;
    }

    /** Parse and return a safe DATE string (Y-m-d) or null. */
    private function safeDate(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    /** Parse and return a safe DATETIME string (Y-m-d H:i:s) or null. */
    private function safeDatetime(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        $ts = strtotime($value);
        return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
    }

    /** Build a structured success response. */
    private function ok(array $data, string $message, array $meta = []): JsonResponse
    {
        $body = ['success' => true, 'message' => $message, 'data' => $data];
        if (! empty($meta)) {
            $body['meta'] = $meta;
        }

        return response()->json($body, 200);
    }

    /** Build a structured error response with full developer detail. */
    private function err(int $status, string $message, array $errorDetail = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'error'   => $errorDetail,
        ], $status);
    }

    /** Build a structured 500 response with exception detail for developers. */
    private function serverError(Throwable $e, string $context): JsonResponse
    {
        Log::error("[SecondaryScreening][ERROR] {$context}", [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
        ]);

        return response()->json([
            'success' => false,
            'message' => "A server error occurred during: {$context}",
            'error' => [
                'context'   => $context,
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => basename($e->getFile()),
                'line'      => $e->getLine(),
                'trace'     => array_slice(
                    array_map(
                        fn($f) => ($f['class'] ?? '') . '::' . ($f['function'] ?? '') . ' L' . ($f['line'] ?? '?'),
                        $e->getTrace()
                    ),
                    0, 8
                ),
            ],
        ], 500);
    }
}

/*
 * ══════════════════════════════════════════════════════════════════════════
 * PENDING SCHEMA MIGRATIONS
 * ══════════════════════════════════════════════════════════════════════════
 *
 * ── MIGRATION 0: traveler_direction (ALREADY APPLIED by user) ──────────────
 *
 *   ALTER TABLE primary_screenings
 *     ADD COLUMN `traveler_direction`
 *       ENUM('ENTRY','EXIT','TRANSIT') NULL
 *       COMMENT 'IHR direction of travel at POE'
 *       AFTER `gender`;
 *
 *   No code change needed — buildFullCaseResponse() reads traveler_direction
 *   with a ?? null fallback so pre-migration NULL records are safe.
 *
 * ── MIGRATION 1: screening_outcome column ─────────────────────────────────
 *
 *   ALTER TABLE secondary_screenings
 *     ADD COLUMN `screening_outcome` VARCHAR(40) NULL
 *     COMMENT 'NON_CASE | SUSPECTED_CASE | PERSON_UNDER_SURVEILLANCE'
 *     AFTER `disposition_details`;
 *
 *   After running:
 *     1. Delete applyScreeningOutcome() helper.
 *     2. Add 'screening_outcome' to store() INSERT list.
 *     3. In formatCase(): read $case->screening_outcome directly.
 *     4. Run one-time data migration to decode existing OUTCOME:xxx prefixes
 *        out of disposition_details into the new column.
 *
 *
 * ── MIGRATION 2: explicit_absent column ───────────────────────────────────
 *
 *   ALTER TABLE secondary_symptoms
 *     ADD COLUMN `explicit_absent` TINYINT(1) NOT NULL DEFAULT 0
 *     COMMENT 'Officer confirmed symptom is absent — engine contradiction penalty'
 *     AFTER `is_present`;
 *
 *   After running:
 *     Uncomment the explicit_absent line in insertSymptom().
 *
 * ══════════════════════════════════════════════════════════════════════════
 */
