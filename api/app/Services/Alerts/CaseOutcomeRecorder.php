<?php

declare(strict_types=1);

namespace App\Services\Alerts;

use App\Support\DiseaseIntel;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Captures WHO-aligned case outcomes whenever an alert closes.
 *
 * Mapping rules (rationale: keeps later analytics — "lab-confirmed VHF
 * by month", "CFR by disease", "discarded vs confirmed cholera per
 * district" — straight off ref_diseases joined through here):
 *
 *   close_category=RESOLVED  →  CONFIRMED if a positive lab result exists,
 *                                else PROBABLE if a top suspected disease
 *                                exists, else SUSPECTED.
 *                                clinical_outcome → RECOVERED.
 *   close_category=FALSE_POSITIVE → DISCARDED. clinical_outcome=UNKNOWN.
 *   close_category=DUPLICATE     → no row written (the merged-into alert
 *                                  carries the outcome).
 *   close_category=LOST_TO_FOLLOWUP → LOST_TO_FOLLOWUP / clinical=LOST_TO_FOLLOWUP.
 *   close_category=TRANSFERRED_OUT_OF_COUNTRY → SUSPECTED/PROBABLE,
 *                                  clinical_outcome=TRANSFERRED.
 *   close_category=DECEASED      → SUSPECTED/PROBABLE/CONFIRMED,
 *                                  clinical_outcome=DECEASED.
 *   close_category=OTHER         → UNKNOWN.
 *
 * IHR notification flag is driven by the disease's WHO tier:
 *   TIER_1_ALWAYS_NOTIFIABLE → ihr_notified=1 implicitly when a closure
 *   happens with a confirmed/probable classification.
 *
 * Failures are logged, never thrown — outcome capture is a side-effect
 * of closure and must never block the user-facing close path.
 */
final class CaseOutcomeRecorder
{
    public const SOURCE_WIZARD       = 'WIZARD';
    public const SOURCE_MASTER_CLOSE = 'MASTER_CLOSE';
    public const SOURCE_FALSE_ALARM  = 'FALSE_ALARM';
    public const SOURCE_LAB_RESULT   = 'LAB_RESULT';
    public const SOURCE_MANUAL       = 'MANUAL';

    /**
     * Records the outcome for a normal closure (RESOLVED / DECEASED / etc.).
     *
     * @param array<string,mixed> $payload  optional extra context for the JSON column
     */
    public function recordFromClose(
        int $alertId,
        string $closeCategory,
        ?string $closeNote,
        ?int $userId = null,
        array $payload = []
    ): ?int {
        // DUPLICATE merges into another alert — no outcome row.
        if ($closeCategory === 'DUPLICATE') return null;

        $alert  = $this->loadAlert($alertId);
        if (!$alert) return null;

        $disease = $this->topDiseaseCode($alert);
        $hasLabPositive = $this->hasLabPositive($alertId);

        $classification    = $this->classifyFromClose($closeCategory, $disease, $hasLabPositive);
        $clinicalOutcome   = $this->clinicalFromClose($closeCategory);
        $isTopTier         = $disease ? $this->isTopTier($disease) : false;

        return $this->upsert($alertId, [
            'case_classification'        => $classification,
            'case_classification_reason' => $closeNote ?: null,
            'lab_status'                 => $hasLabPositive ? 'POSITIVE' : 'NOT_TESTED',
            'lab_disease_code'           => $hasLabPositive ? $disease : null,
            'clinical_outcome'           => $clinicalOutcome,
            'clinical_outcome_at'        => Carbon::now(),
            'ph_action'                  => $this->phActionFor($classification, $isTopTier),
            'outbreak_status'            => 'NONE',
            'ihr_notified'               => $isTopTier && in_array($classification, ['CONFIRMED', 'PROBABLE'], true),
            'ihr_notified_at'            => ($isTopTier && in_array($classification, ['CONFIRMED', 'PROBABLE'], true)) ? Carbon::now() : null,
            'recorded_by_user_id'        => $userId ?? Auth::id(),
            'recorded_at'                => Carbon::now(),
            'source'                     => self::SOURCE_WIZARD,
            'notes'                      => $closeNote ?: null,
            'payload'                    => array_merge([
                'close_category'        => $closeCategory,
                'top_suspected_disease' => $disease,
            ], $payload),
        ]);
    }

    /**
     * Records the outcome for a false-alarm closure.
     */
    public function recordFromFalseAlarm(
        int $alertId,
        string $reason,
        ?int $userId = null
    ): ?int {
        $alert = $this->loadAlert($alertId);
        if (!$alert) return null;

        $disease = $this->topDiseaseCode($alert);

        return $this->upsert($alertId, [
            'case_classification'        => 'DISCARDED',
            'case_classification_reason' => $reason,
            'lab_status'                 => 'NOT_TESTED',
            'lab_disease_code'           => null,
            'clinical_outcome'           => 'UNKNOWN',
            'clinical_outcome_at'        => Carbon::now(),
            'ph_action'                  => 'STANDARD_SURVEILLANCE',
            'outbreak_status'            => 'NONE',
            'ihr_notified'               => false,
            'ihr_notified_at'            => null,
            'recorded_by_user_id'        => $userId ?? Auth::id(),
            'recorded_at'                => Carbon::now(),
            'source'                     => self::SOURCE_FALSE_ALARM,
            'notes'                      => $reason,
            'payload'                    => [
                'top_suspected_disease' => $disease,
            ],
        ]);
    }

    /**
     * Records the outcome for a NATIONAL_ADMIN master close.
     */
    public function recordFromMasterClose(
        int $alertId,
        string $closeCategory,
        ?string $closeNote,
        string $overrideReason,
        ?int $userId = null
    ): ?int {
        if ($closeCategory === 'DUPLICATE') return null;

        $alert = $this->loadAlert($alertId);
        if (!$alert) return null;

        $disease         = $this->topDiseaseCode($alert);
        $hasLabPositive  = $this->hasLabPositive($alertId);
        $classification  = $this->classifyFromClose($closeCategory, $disease, $hasLabPositive);
        $clinicalOutcome = $this->clinicalFromClose($closeCategory);
        $isTopTier       = $disease ? $this->isTopTier($disease) : false;

        return $this->upsert($alertId, [
            'case_classification'        => $classification,
            'case_classification_reason' => $closeNote ?: null,
            'lab_status'                 => $hasLabPositive ? 'POSITIVE' : 'NOT_TESTED',
            'lab_disease_code'           => $hasLabPositive ? $disease : null,
            'clinical_outcome'           => $clinicalOutcome,
            'clinical_outcome_at'        => Carbon::now(),
            'ph_action'                  => $this->phActionFor($classification, $isTopTier),
            'outbreak_status'            => 'NONE',
            'ihr_notified'               => $isTopTier && in_array($classification, ['CONFIRMED', 'PROBABLE'], true),
            'ihr_notified_at'            => ($isTopTier && in_array($classification, ['CONFIRMED', 'PROBABLE'], true)) ? Carbon::now() : null,
            'recorded_by_user_id'        => $userId ?? Auth::id(),
            'recorded_at'                => Carbon::now(),
            'source'                     => self::SOURCE_MASTER_CLOSE,
            'notes'                      => $closeNote ?: null,
            'payload'                    => [
                'close_category'        => $closeCategory,
                'override_reason'       => $overrideReason,
                'top_suspected_disease' => $disease,
            ],
        ]);
    }

    /**
     * Records / updates the lab portion of an outcome (called when the
     * LAB_CONFIRMATION wizard step is answered).
     */
    public function recordLabResult(
        int $alertId,
        string $labStatus,
        ?string $diseaseCode = null,
        ?string $testMethod = null,
        ?int $userId = null,
        ?string $reason = null
    ): ?int {
        $existing = $this->existing($alertId);

        $row = [
            'lab_status'         => $labStatus,
            'lab_disease_code'   => $diseaseCode,
            'lab_test_method'    => $testMethod,
            'lab_confirmed_at'   => $labStatus === 'POSITIVE' ? Carbon::now() : null,
            'recorded_by_user_id'=> $userId ?? Auth::id(),
            'recorded_at'        => Carbon::now(),
            'source'             => self::SOURCE_LAB_RESULT,
        ];

        if (!$existing) {
            // Lab result arriving before closure — seed a SUSPECTED row to anchor analytics.
            $row['case_classification'] = $labStatus === 'POSITIVE' ? 'CONFIRMED' : 'SUSPECTED';
            $row['case_classification_reason'] = $reason;
            $row['clinical_outcome']    = null;
            $row['outbreak_status']     = 'NONE';
            $row['ihr_notified']        = false;
        } else if ($labStatus === 'POSITIVE') {
            // Promote a SUSPECTED/PROBABLE to CONFIRMED on a positive lab.
            $row['case_classification'] = 'CONFIRMED';
        }

        return $this->upsert($alertId, $row);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(int $alertId): ?array
    {
        try {
            $row = DB::table('alert_case_outcomes')
                ->where('alert_id', $alertId)
                ->whereNull('deleted_at')
                ->first();
            return $row ? (array) $row : null;
        } catch (Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    //  Internals
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $row
     */
    private function upsert(int $alertId, array $row): ?int
    {
        try {
            $existing = $this->existing($alertId);
            $row = array_merge(['alert_id' => $alertId, 'updated_at' => Carbon::now()], $row);
            if (isset($row['payload']) && is_array($row['payload'])) {
                $row['payload'] = json_encode($row['payload'], JSON_UNESCAPED_UNICODE);
            }

            if ($existing) {
                DB::table('alert_case_outcomes')->where('id', $existing->id)->update($row);
                return (int) $existing->id;
            }

            $row['created_at'] = Carbon::now();
            return (int) DB::table('alert_case_outcomes')->insertGetId($row);
        } catch (Throwable $e) {
            Log::warning('[CaseOutcomeRecorder][upsert] ' . $e->getMessage(), [
                'alert_id' => $alertId,
            ]);
            return null;
        }
    }

    private function existing(int $alertId): ?object
    {
        try {
            return DB::table('alert_case_outcomes')
                ->where('alert_id', $alertId)
                ->whereNull('deleted_at')
                ->first(['id']);
        } catch (Throwable) {
            return null;
        }
    }

    private function loadAlert(int $alertId): ?object
    {
        try {
            return DB::table('alerts')->where('id', $alertId)->first([
                'id', 'secondary_screening_id', 'risk_level', 'ihr_tier',
                'poe_code', 'district_code', 'province_code',
            ]);
        } catch (Throwable) {
            return null;
        }
    }

    private function topDiseaseCode(object $alert): ?string
    {
        if (empty($alert->secondary_screening_id)) return null;
        try {
            $code = DB::table('secondary_suspected_diseases')
                ->where('secondary_screening_id', $alert->secondary_screening_id)
                ->where('rank_order', 1)
                ->value('disease_code');
            return $code ? (string) $code : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function hasLabPositive(int $alertId): bool
    {
        try {
            return (bool) DB::table('alert_case_outcomes')
                ->where('alert_id', $alertId)
                ->whereNull('deleted_at')
                ->where('lab_status', 'POSITIVE')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function isTopTier(string $diseaseCode): bool
    {
        $intel = DiseaseIntel::get($diseaseCode);
        return str_contains((string) ($intel['ihr_tier'] ?? ''), 'TIER_1');
    }

    private function classifyFromClose(string $closeCategory, ?string $diseaseCode, bool $hasLabPositive): string
    {
        return match ($closeCategory) {
            'RESOLVED' => $hasLabPositive
                ? 'CONFIRMED'
                : ($diseaseCode ? 'PROBABLE' : 'SUSPECTED'),
            'FALSE_POSITIVE'             => 'DISCARDED',
            'LOST_TO_FOLLOWUP'           => 'LOST_TO_FOLLOWUP',
            'TRANSFERRED_OUT_OF_COUNTRY' => $diseaseCode ? 'PROBABLE' : 'SUSPECTED',
            'DECEASED'                   => $hasLabPositive ? 'CONFIRMED' : ($diseaseCode ? 'PROBABLE' : 'SUSPECTED'),
            'OTHER'                      => 'UNKNOWN',
            default                      => 'SUSPECTED',
        };
    }

    private function clinicalFromClose(string $closeCategory): string
    {
        return match ($closeCategory) {
            'RESOLVED'                   => 'RECOVERED',
            'DECEASED'                   => 'DECEASED',
            'TRANSFERRED_OUT_OF_COUNTRY' => 'TRANSFERRED',
            'LOST_TO_FOLLOWUP'           => 'LOST_TO_FOLLOWUP',
            'FALSE_POSITIVE'             => 'UNKNOWN',
            default                      => 'UNKNOWN',
        };
    }

    private function phActionFor(string $classification, bool $isTopTier): string
    {
        if ($isTopTier && in_array($classification, ['CONFIRMED', 'PROBABLE'], true)) {
            return 'IHR_NOTIFIED';
        }
        return match ($classification) {
            'CONFIRMED', 'PROBABLE' => 'OUTBREAK_INVESTIGATION',
            'SUSPECTED'             => 'ENHANCED_SURVEILLANCE',
            default                 => 'STANDARD_SURVEILLANCE',
        };
    }
}
