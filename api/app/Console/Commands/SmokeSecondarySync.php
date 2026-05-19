<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\PrimaryScreeningController;
use App\Http\Controllers\SecondaryScreeningController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Aggressive end-to-end impersonation test for the mobile sync pipeline.
 *
 * What it proves:
 *   1. POST /primary-screenings accepts a synthetic primary screening row.
 *   2. POST /secondary-screenings opens a secondary case linked to it.
 *   3. POST /secondary-screenings/{id}/sync persists the FULL bundle:
 *      parent updates + 5 child tables + suspected_diseases.
 *   4. Re-posting the same bundle is idempotent (replace-all + UNIQUE).
 *   5. Soft-deletes the synthetic rows on the way out so the test
 *      leaves no residue in prod.
 *
 * Usage:
 *   php artisan app:smoke-secondary-sync                    # default POE / NATIONAL_ADMIN user
 *   php artisan app:smoke-secondary-sync --poe=UG-WAKISO-001
 *   php artisan app:smoke-secondary-sync --user=42
 *
 * The command exits 0 on full pass, non-zero on any assertion failure, so
 * deploy pipelines can chain it.
 */
class SmokeSecondarySync extends Command
{
    protected $signature = 'app:smoke-secondary-sync
                            {--poe= : POE code to anchor the screening (default: first active POE)}
                            {--user= : User id (default: first NATIONAL_ADMIN)}
                            {--keep : Do NOT clean up synthetic rows (leave for inspection)}';

    protected $description = 'Aggressive end-to-end test: posts a synthetic primary + secondary screening with all child data and verifies persistence.';

    public function handle(): int
    {
        $this->line('═══════════════════════════════════════════════════════════');
        $this->line(' Aggressive mobile-sync impersonation test');
        $this->line('═══════════════════════════════════════════════════════════');

        // ── 1. Resolve target user + POE ────────────────────────────────
        $userId  = (int) $this->option('user');
        $poeCode = (string) $this->option('poe');

        // Resolve the right user+POE pair. The smoke flow needs a user whose
        // active assignment actually carries a poe_code (a SCREENER, not a
        // supervisor-tier NATIONAL_ADMIN whose assignment is district-only).
        if ($userId <= 0) {
            $q = DB::table('users as u')
                ->join('user_assignments as ua', 'ua.user_id', '=', 'u.id')
                ->where('u.is_active', 1)
                ->where('ua.is_active', 1)
                ->whereNotNull('ua.poe_code');
            if ($poeCode !== '') { $q->where('ua.poe_code', $poeCode); }
            $row = $q->select('u.id as user_id', 'ua.poe_code')->orderBy('u.id')->first();
            if (! $row) { $this->error('No active user with a poe_code assignment found.'); return self::FAILURE; }
            $userId  = (int) $row->user_id;
            $poeCode = (string) $row->poe_code;
        } elseif ($poeCode === '') {
            $row = DB::table('user_assignments')->where('user_id', $userId)
                ->where('is_active', 1)->whereNotNull('poe_code')
                ->orderBy('is_primary', 'desc')->orderBy('id')->first();
            if (! $row) { $this->error("User {$userId} has no active POE assignment."); return self::FAILURE; }
            $poeCode = (string) $row->poe_code;
        }

        $poeRow = DB::table('ref_poes')->where('poe_code', $poeCode)->first();
        if (! $poeRow) { $this->error("POE {$poeCode} not found."); return self::FAILURE; }

        // Resolve the user's primary assignment row to fill geographic codes.
        $asg = DB::table('user_assignments')
            ->where('user_id', $userId)->where('is_active', 1)
            ->where('poe_code', $poeCode)
            ->orderBy('is_primary', 'desc')->orderBy('id')->first();
        $countryCode = $asg->country_code ?? $poeRow->country_code ?? 'UG';

        $this->info("✔ user_id={$userId} · poe={$poeCode} · country={$countryCode}");

        $clientUuidPrimary   = (string) Str::uuid();
        $clientUuidSecondary = (string) Str::uuid();
        $createdPrimaryId    = null;
        $createdSecondaryId  = null;

        $rc = self::SUCCESS;
        try {
            // ── 2. POST /primary-screenings ─────────────────────────────
            $primaryReq = Request::create('/primary-screenings', 'POST', [
                'client_uuid'            => $clientUuidPrimary,
                'reference_data_version' => '1',                              // must be string
                'captured_by_user_id'    => $userId,
                'traveler_direction'     => 'ENTRY',                          // enum: ENTRY|EXIT|TRANSIT
                'gender'                 => 'MALE',
                'traveler_full_name'     => 'SMOKE TEST · ' . substr($clientUuidPrimary, 0, 8),
                'temperature_value'      => 37.6,
                'temperature_unit'       => 'C',
                'symptoms_present'       => 1,
                'captured_at'            => now()->format('Y-m-d H:i:s'),     // MySQL datetime, not ISO 8601
                'captured_timezone'      => 'Africa/Kampala',
                'device_id'              => 'smoke-test',
                'app_version'            => 'smoke-1.0',
                'platform'               => 'ANDROID',                        // enum: ANDROID|IOS|WEB
                'record_version'         => 1,
                'country_code'           => $countryCode,
                'province_code'          => $asg->province_code ?? null,
                'pheoc_code'             => $asg->pheoc_code   ?? null,
                'district_code'          => $asg->district_code ?? $poeRow->district ?? null,
                'poe_code'               => $poeCode,
            ]);
            $primaryResp = app(PrimaryScreeningController::class)->store($primaryReq);
            $primaryBody = json_decode($primaryResp->getContent(), true);
            if (! ($primaryBody['success'] ?? false)) {
                $this->error('[1/5] primary store failed: '.json_encode($primaryBody));
                return self::FAILURE;
            }
            $createdPrimaryId = (int) ($primaryBody['data']['id'] ?? 0);
            $notifId          = (int) ($primaryBody['data']['notification']['id'] ?? 0);
            $this->info("[1/5] ✔ primary stored · id={$createdPrimaryId} · notification={$notifId}");

            // ── 3. POST /secondary-screenings (Phase 1 / case shell) ────
            $secReq = Request::create('/secondary-screenings', 'POST', [
                'client_uuid'            => $clientUuidSecondary,
                'reference_data_version' => '1',
                'notification_id'        => $notifId,
                'primary_screening_id'   => $createdPrimaryId,
                'opened_by_user_id'      => $userId,
                'opened_at'              => now()->format('Y-m-d H:i:s'),
                'opened_timezone'        => 'Africa/Kampala',
                'device_id'              => 'smoke-test',
                'app_version'            => 'smoke-1.0',
                'platform'               => 'ANDROID',
                'traveler_gender'        => 'MALE',
                'record_version'         => 1,
            ]);
            $secResp = app(SecondaryScreeningController::class)->store($secReq);
            $secBody = json_decode($secResp->getContent(), true);
            if (! ($secBody['success'] ?? false)) {
                $this->error('[2/5] secondary store failed: '.json_encode($secBody));
                return self::FAILURE;
            }
            $createdSecondaryId = (int) ($secBody['data']['id'] ?? 0);
            $this->info("[2/5] ✔ secondary shell created · id={$createdSecondaryId}");

            // ── 4. POST /secondary-screenings/{id}/sync · FULL bundle ───
            $fullBundle = [
                'user_id'                          => $userId,
                'record_version'                   => 0,
                'case_status'                      => 'IN_PROGRESS',
                'traveler_full_name'               => 'SMOKE · AYENAFD TIMOTHY',
                'traveler_gender'                  => 'MALE',
                'traveler_age_years'               => 25,
                'travel_document_type'             => 'PASSPORT',
                'travel_document_number'           => 'TEST12345',
                'traveler_nationality_country_code'=> 'KE',
                'residence_country_code'           => 'KE',
                'phone_number'                     => '+256700000000',
                'journey_start_country_code'       => 'KE',
                'conveyance_type'                  => 'AIR',
                'conveyance_identifier'            => 'KQ-412',
                'arrival_datetime'                 => now()->format('Y-m-d H:i:s'),
                'purpose_of_travel'                => 'BUSINESS',
                'destination_district_code'        => $asg->district_code ?? null,
                'temperature_value'                => 38.4,
                'temperature_unit'                 => 'C',
                'pulse_rate'                       => 96,
                'respiratory_rate'                 => 22,
                'bp_systolic'                      => 130,
                'bp_diastolic'                     => 82,
                'oxygen_saturation'                => 96,
                'triage_category'                  => 'URGENT',
                'emergency_signs_present'          => 0,
                'general_appearance'               => 'Looks unwell, mild diaphoresis',
                'syndrome_classification'          => 'ILI',
                'risk_level'                       => 'MEDIUM',
                'officer_notes'                    => 'Smoke test — synthetic case for sync validation.',
                // NOTE: codes below are drawn from prod ref_* tables. Real
                // mobile payloads use the same lower-case symptom/disease
                // codes; exposures are UPPER. Travel role enum on the
                // server is VISITED | TRANSIT only.
                'symptoms'           => [
                    ['symptom_code' => 'abdominal_pain', 'is_present' => 1, 'onset_date' => now()->subDay()->toDateString()],
                    ['symptom_code' => 'anorexia',       'is_present' => 1],
                    ['symptom_code' => 'back_pain',      'is_present' => 0],
                ],
                'exposures'          => [
                    ['exposure_code' => 'TRAVEL_OUTBREAK_AREA',  'response' => 'YES', 'details' => 'Family member ill'],
                    ['exposure_code' => 'CONTACT_SICK_PERSON',   'response' => 'NO'],
                ],
                'actions'            => [
                    ['action_code' => 'TEMPERATURE_RECHECK', 'is_done' => 1],
                ],
                'travel_countries'   => [
                    ['country_code' => 'KE', 'travel_role' => 'VISITED', 'arrival_date' => now()->subDays(2)->toDateString(), 'departure_date' => now()->subDays(1)->toDateString()],
                    ['country_code' => 'TZ', 'travel_role' => 'TRANSIT', 'arrival_date' => now()->subDays(1)->toDateString(), 'departure_date' => now()->toDateString()],
                ],
                'suspected_diseases' => [
                    ['disease_code' => 'cholera',     'rank_order' => 1, 'confidence' => 72.5, 'reasoning' => 'travel + abdominal pain'],
                    ['disease_code' => 'yellow_fever','rank_order' => 2, 'confidence' => 45.0],
                    ['disease_code' => 'polio',      'rank_order' => 3, 'confidence' => 18.0, 'reasoning' => 'differential'],
                ],
            ];
            $syncReq  = Request::create("/secondary-screenings/{$createdSecondaryId}/sync", 'POST', $fullBundle);
            $syncResp = app(SecondaryScreeningController::class)->fullSync($syncReq, $createdSecondaryId);
            $syncBody = json_decode($syncResp->getContent(), true);
            if (! ($syncBody['success'] ?? false)) {
                $this->error('[3/5] full sync failed: '.json_encode($syncBody));
                return self::FAILURE;
            }
            $this->info('[3/5] ✔ full sync OK · child counts: '.json_encode($syncBody['meta']['child_tables_sync'] ?? []));

            // ── 5. Verify every child table populated correctly ─────────
            $counts = [
                'symptoms'           => DB::table('secondary_symptoms')          ->where('secondary_screening_id', $createdSecondaryId)->count(),
                'exposures'          => DB::table('secondary_exposures')         ->where('secondary_screening_id', $createdSecondaryId)->count(),
                'actions'            => DB::table('secondary_actions')           ->where('secondary_screening_id', $createdSecondaryId)->count(),
                'travel_countries'   => DB::table('secondary_travel_countries')  ->where('secondary_screening_id', $createdSecondaryId)->count(),
                'suspected_diseases' => DB::table('secondary_suspected_diseases')->where('secondary_screening_id', $createdSecondaryId)->count(),
            ];
            $expected = ['symptoms' => 3, 'exposures' => 2, 'actions' => 1, 'travel_countries' => 2, 'suspected_diseases' => 3];
            $allOk = true;
            foreach ($expected as $k => $n) {
                $got  = $counts[$k] ?? 0;
                $mark = ($got === $n) ? '✔' : '✗';
                $this->line(sprintf('    %s %-20s expected=%d got=%d', $mark, $k, $n, $got));
                if ($got !== $n) { $allOk = false; }
            }
            if (! $allOk) { $rc = self::FAILURE; }
            $this->info('[4/5] '.($allOk ? '✔ counts match' : '✗ counts mismatched'));

            // ── 6. Idempotency: re-post the SAME bundle, expect counts unchanged ─
            $syncReq2  = Request::create("/secondary-screenings/{$createdSecondaryId}/sync", 'POST', $fullBundle);
            $syncResp2 = app(SecondaryScreeningController::class)->fullSync($syncReq2, $createdSecondaryId);
            $syncBody2 = json_decode($syncResp2->getContent(), true);
            $counts2 = [
                'symptoms'           => DB::table('secondary_symptoms')          ->where('secondary_screening_id', $createdSecondaryId)->count(),
                'exposures'          => DB::table('secondary_exposures')         ->where('secondary_screening_id', $createdSecondaryId)->count(),
                'actions'            => DB::table('secondary_actions')           ->where('secondary_screening_id', $createdSecondaryId)->count(),
                'travel_countries'   => DB::table('secondary_travel_countries')  ->where('secondary_screening_id', $createdSecondaryId)->count(),
                'suspected_diseases' => DB::table('secondary_suspected_diseases')->where('secondary_screening_id', $createdSecondaryId)->count(),
            ];
            $idempOk = ($counts === $counts2);
            $this->info('[5/5] '.($idempOk ? '✔ idempotent — no row inflation on re-sync' : '✗ counts changed on re-sync: '.json_encode($counts2)));
            if (! $idempOk) { $rc = self::FAILURE; }

        } finally {
            // ── 7. Clean-up (soft-delete; never hard-delete on prod) ────
            if (! $this->option('keep')) {
                if ($createdSecondaryId) {
                    DB::table('secondary_screenings')->where('id', $createdSecondaryId)->update([
                        'deleted_at'      => now(),
                        'last_sync_error' => 'smoke-test cleanup (app:smoke-secondary-sync)',
                    ]);
                }
                if ($createdPrimaryId) {
                    DB::table('primary_screenings')->where('id', $createdPrimaryId)->update([
                        'deleted_at'      => now(),
                        'last_sync_error' => 'smoke-test cleanup (app:smoke-secondary-sync)',
                    ]);
                }
                // Notification rows tied to the smoke primary get nulled out too.
                DB::table('notifications')
                    ->where('primary_screening_id', $createdPrimaryId)
                    ->update(['deleted_at' => now()]);
                $this->line('   ↺ synthetic rows soft-deleted.');
            } else {
                $this->line('   ↺ --keep set; synthetic rows preserved (primary='.$createdPrimaryId.', secondary='.$createdSecondaryId.')');
            }
        }

        $this->line('═══════════════════════════════════════════════════════════');
        $this->line($rc === self::SUCCESS ? ' RESULT: PASS ✔' : ' RESULT: FAIL ✗');
        $this->line('═══════════════════════════════════════════════════════════');
        return $rc;
    }
}
