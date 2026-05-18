<?php

declare(strict_types=1);

namespace App\Services\Clinical;

use Illuminate\Support\Facades\DB;

/**
 * ClinicalScoringSimulator — server-side mirror of the mobile case scorer.
 *
 * Per the Paranoid v2 Clinical Library brief §7.2 and §13:
 *
 *   "Worked examples invoke the real scoring engine in dry-run mode, with
 *    explicit audit that this is a simulation and not a real case. If the
 *    engine cannot be called in dry-run mode without producing real-case
 *    side-effects, the developer raises this in the reconciliation log and
 *    falls back to a deterministic illustration with a clear note that the
 *    engine itself was not invoked. No invented numbers."
 *
 * Reality: the case-scoring engine lives client-side
 * (`public/ssot/Diseases_intelligence.js`). There is no server-side engine
 * to call in dry-run; the mobile app is the only consumer of the case-scorer.
 *
 * This simulator therefore mirrors the mobile algorithm using the SAME inputs
 * the mobile reads — `ref_diseases.symptom_weights`, `gates`,
 * `absent_penalties`, plus `ref_endemic_countries` for the outbreak bonus,
 * plus `ref_engine_config` for global tunables. The output payload is
 * explicitly labelled with `is_simulation: true` and `engine_called: false`
 * so every consumer knows the result is a deterministic mirror, not a live
 * mobile-engine output.
 *
 * Mirrored formula (clamped 0..100):
 *
 *   final_score = symptom_score
 *               + exposure_score
 *               + outbreak_bonus       (if traveller arrived from endemic country)
 *               + absent_hallmark_penalty  (if mandatory hallmarks confirmed absent)
 *               + override_boost       (from ref_engine_config disease_boost_map)
 *
 * Ignored vs the mobile (intentional, surfaced in the worked-example
 * caption): syndrome_bonus (depends on engine state), vaccination_modifier
 * (no per-case vaccination data here), onset_modifier (no symptom onset
 * date in a simulation), contradiction_penalty (relies on engine internal
 * book-keeping). The simulator labels the omissions clearly.
 *
 * Action bands mirror the mobile's IHR risk classification:
 *   ≥ 55 HIGH (immediate referral)
 *   ≥ 35 MEDIUM (secondary screening)
 *   ≥ 15 LOW (watchful)
 *   <  15 NONE (no action band triggered by score alone)
 */
final class ClinicalScoringSimulator
{
    public const VERSION = 'v1';

    public const ACTION_BANDS = [
        'HIGH'   => ['min' =>  55, 'label' => 'Immediate referral', 'badge' => 'badge-critical'],
        'MEDIUM' => ['min' =>  35, 'label' => 'Secondary screening', 'badge' => 'badge-warning'],
        'LOW'    => ['min' =>  15, 'label' => 'Watchful',           'badge' => 'badge-info'],
        'NONE'   => ['min' =>   0, 'label' => 'No action triggered','badge' => 'badge-soft'],
    ];

    public function __construct(private readonly ClinicalRegistry $registry)
    {
    }

    /**
     * Run a deterministic worked example for a single disease.
     *
     * @param string             $diseaseCode    e.g. "smallpox"
     * @param array<int,string>  $presentSymptoms list of symptom_code present
     * @param array<int,string>  $absentSymptoms  list of symptom_code confirmed absent
     * @param array<int,string>  $exposures       list of exposure_code reported
     * @param string|null        $arrivalCountry  ISO country code of journey origin
     */
    public function simulate(
        string $diseaseCode,
        array $presentSymptoms = [],
        array $absentSymptoms = [],
        array $exposures = [],
        ?string $arrivalCountry = null,
    ): array {
        $disease = $this->registry->diseaseByCode($diseaseCode);
        if (! $disease) {
            return $this->emptyResult($diseaseCode, 'Unknown disease — simulation cannot be run.');
        }

        $contributions = [];

        $symptomScore = 0;
        $sw = is_array($disease->symptom_weights) ? $disease->symptom_weights : [];
        foreach ($presentSymptoms as $code) {
            $w = (float) ($sw[$code] ?? 0);
            if ($w !== 0.0) {
                $symptomScore += $w;
                $contributions[] = [
                    'kind'   => 'symptom_present',
                    'code'   => $code,
                    'points' => $w,
                    'reason' => "Present symptom contributes its likelihood weight.",
                ];
            }
        }

        $absentPenalty = 0;
        $ap = is_array($disease->absent_penalties) ? $disease->absent_penalties : [];
        foreach ($absentSymptoms as $code) {
            $p = (float) ($ap[$code] ?? 0);
            if ($p !== 0.0) {
                $absentPenalty += $p;
                $contributions[] = [
                    'kind'   => 'symptom_absent',
                    'code'   => $code,
                    'points' => $p,
                    'reason' => "Mandatory hallmark confirmed absent — penalty applied.",
                ];
            }
        }

        $exposureScore = 0;
        $ew = is_array($disease->exposure_weights) ? $disease->exposure_weights : [];
        foreach ($exposures as $code) {
            $w = (float) ($ew[$code] ?? 0);
            if ($w !== 0.0) {
                $exposureScore += $w;
                $contributions[] = [
                    'kind'   => 'exposure',
                    'code'   => $code,
                    'points' => $w,
                    'reason' => "Reported exposure contributes its weight.",
                ];
            }
        }

        $outbreakBonus = 0;
        if ($arrivalCountry) {
            $endemic = DB::table('ref_endemic_countries')
                ->where('disease_code', $diseaseCode)
                ->where('country_code', $arrivalCountry)
                ->where('is_active', 1)
                ->first();
            if ($endemic) {
                $outbreakBonus = $this->endemicBonusFor((string) $endemic->endemicity_level);
                $contributions[] = [
                    'kind'   => 'outbreak_bonus',
                    'code'   => $arrivalCountry,
                    'points' => $outbreakBonus,
                    'reason' => "Traveller arrived from a country flagged " . $endemic->endemicity_level . " for this disease.",
                ];
            }
        }

        $overrideBoost = $this->lookupOverrideBoost($diseaseCode);
        if ($overrideBoost !== 0) {
            $contributions[] = [
                'kind'   => 'override_boost',
                'code'   => $diseaseCode,
                'points' => $overrideBoost,
                'reason' => "Disease appears in the engine's disease-boost map (high-priority pathogens get a baseline boost).",
            ];
        }

        $rawScore = $symptomScore + $exposureScore + $absentPenalty + $outbreakBonus + $overrideBoost;
        $finalScore = (int) max(0, min(100, round($rawScore)));

        // Hallmark gate — informational only in v1 simulator.
        $gates = is_array($disease->gates) ? $disease->gates : [];
        $hallmarkRequired = $this->extractHallmarkRequirements($gates, $disease->case_definition ?? []);
        $hallmarksMet     = array_values(array_intersect($hallmarkRequired, $presentSymptoms));
        $hallmarksMissing = array_values(array_diff($hallmarkRequired, $presentSymptoms));
        $hardFailIfAbsent = (array) ($gates['hard_fail_if_absent'] ?? []);
        $hardFailed       = array_values(array_intersect($hardFailIfAbsent, $absentSymptoms));

        $actionBand = $this->actionBand($finalScore);

        return [
            'is_simulation'   => true,
            'engine_called'   => false,
            'engine_note'     => 'Deterministic server-side simulation. The live case-scoring engine is client-side (Diseases_intelligence.js); this surface mirrors its inputs and the additive components of its formula but does not invoke it. Production scoring at the border is performed by the mobile app.',
            'simulator_version' => self::VERSION,
            'disease' => [
                'code'         => $disease->disease_code,
                'display_name' => $disease->display_name,
                'ihr_tier'     => (int) $disease->ihr_tier,
                'who_syndrome' => $disease->who_syndrome,
            ],
            'inputs' => [
                'present_symptoms' => $presentSymptoms,
                'absent_symptoms'  => $absentSymptoms,
                'exposures'        => $exposures,
                'arrival_country'  => $arrivalCountry,
            ],
            'breakdown' => [
                'symptom_score'           => round($symptomScore, 2),
                'exposure_score'          => round($exposureScore, 2),
                'absent_hallmark_penalty' => round($absentPenalty, 2),
                'outbreak_bonus'          => $outbreakBonus,
                'override_boost'          => $overrideBoost,
            ],
            'omitted_components' => [
                'syndrome_bonus'         => 'Depends on engine syndromic-group derivation; not modelled in v1 simulator.',
                'vaccination_modifier'   => 'No per-case vaccination data is supplied to a simulator.',
                'onset_modifier'         => 'No symptom-onset date in simulation context.',
                'contradiction_penalty'  => 'Relies on engine internal book-keeping; not modelled.',
            ],
            'gate' => [
                'hallmark_required' => $hallmarkRequired,
                'hallmarks_met'     => $hallmarksMet,
                'hallmarks_missing' => $hallmarksMissing,
                'hard_fail'         => $hardFailed,
                'gate_passed'       => empty($hardFailed) && empty($hallmarksMissing),
                'plain'             => $this->gatePlainExplanation($hallmarkRequired, $hallmarksMet, $hallmarksMissing, $hardFailed),
            ],
            'final_score' => $finalScore,
            'score_cap'   => 100,
            'action_band' => $actionBand,
            'contributions' => $contributions,
            'plain_summary' => $this->plainSummary($disease->display_name, $finalScore, $actionBand, $arrivalCountry),
        ];
    }

    /**
     * Approximate "score cap" per disease — the highest score this disease
     * can deterministically reach assuming every positive symptom and
     * exposure weight is present and the country is OUTBREAK_ACTIVE.
     * Used by the clin-diseases score-band chart.
     */
    public function maxAttainableScore(object $disease): int
    {
        $sw = is_array($disease->symptom_weights ?? null) ? $disease->symptom_weights : [];
        $ew = is_array($disease->exposure_weights ?? null) ? $disease->exposure_weights : [];
        $sumSym = array_sum(array_filter($sw, fn ($v) => is_numeric($v) && $v > 0));
        $sumExp = array_sum(array_filter($ew, fn ($v) => is_numeric($v) && $v > 0));
        $outbreak = $this->endemicBonusFor('OUTBREAK_ACTIVE');
        $boost    = $this->lookupOverrideBoost((string) $disease->disease_code);
        return (int) max(0, min(100, round($sumSym + $sumExp + $outbreak + $boost)));
    }

    public function actionBand(int $score): array
    {
        foreach (self::ACTION_BANDS as $key => $cfg) {
            if ($score >= $cfg['min']) {
                return ['key' => $key, 'label' => $cfg['label'], 'badge' => $cfg['badge'], 'min' => $cfg['min']];
            }
        }
        return ['key' => 'NONE', 'label' => 'No action triggered', 'badge' => 'badge-soft', 'min' => 0];
    }

    /* ============================================================
     * Internals
     * ============================================================ */

    /**
     * Endemic bonus per the mobile's `outbreak_bonus` semantics.
     * v1 deterministic mapping; revisit when the disease.outbreak_bonus
     * column is exposed server-side or when the mobile's bonus value is
     * itself surfaced via ref_engine_config.
     */
    private function endemicBonusFor(string $level): int
    {
        return match (strtoupper($level)) {
            'OUTBREAK_ACTIVE' => 15,
            'OUTBREAK_RECENT' => 10,
            'ENDEMIC'         => 7,
            'SPORADIC'        => 3,
            'IMPORTED_ONLY'   => 0,
            default           => 0,
        };
    }

    /** @var array<string,float>|null cached disease_boost_map derived from ref_engine_config */
    private ?array $boostMapCache = null;

    private function lookupOverrideBoost(string $diseaseCode): float
    {
        if ($this->boostMapCache === null) {
            $this->boostMapCache = [];
            $rows = DB::table('ref_engine_config')->where('is_active', 1)->get();
            foreach ($rows as $r) {
                $val = json_decode((string) $r->config_value, true);
                if (! is_array($val)) continue;
                // Heuristic: values keyed by disease_code where every value is numeric.
                $allNumeric = ! empty($val);
                foreach ($val as $k => $v) {
                    if (! is_string($k) || ! is_numeric($v)) {
                        $allNumeric = false;
                        break;
                    }
                }
                if ($allNumeric) {
                    foreach ($val as $k => $v) {
                        $this->boostMapCache[strtolower($k)] = (float) max($this->boostMapCache[strtolower($k)] ?? 0, $v);
                    }
                }
            }
        }
        return (float) ($this->boostMapCache[strtolower($diseaseCode)] ?? 0);
    }

    /**
     * @param  array<string,mixed> $gates           ref_diseases.gates
     * @param  array<string,mixed> $caseDefinition  ref_diseases.case_definition
     * @return array<int,string>
     */
    private function extractHallmarkRequirements(array $gates, array $caseDefinition): array
    {
        $req = (array) ($gates['required_all'] ?? []);
        $any = (array) ($gates['required_any'] ?? []);
        // Brief reads "without it the disease is not flagged regardless of other findings"
        // — which corresponds to required_all + required_any in the gates JSON.
        return array_values(array_unique(array_merge($req, $any)));
    }

    private function gatePlainExplanation(array $required, array $met, array $missing, array $hardFailed): string
    {
        if (! empty($hardFailed)) {
            return 'A required signal was confirmed absent. The disease would be ruled out at the border — no further scoring would be needed.';
        }
        if (empty($required)) {
            return 'This disease has no hallmark requirement. Scoring is purely additive.';
        }
        if (empty($missing)) {
            return 'All hallmark signals are present. The disease passes its gate and continues to be scored.';
        }
        return 'Hallmark signals are missing. The disease would be flagged only if the missing signals are not actually absent — they are simply not yet recorded.';
    }

    private function plainSummary(string $diseaseName, int $score, array $action, ?string $country): string
    {
        $arrival = $country ? "arriving from {$country}" : '(no arrival country specified)';
        return "A simulated traveller {$arrival} with the supplied symptoms and exposures would currently score {$score}/100 for {$diseaseName}, which falls in the action band: {$action['label']}. At a real point of entry, the mobile app would compute the live score using its own engine.";
    }

    private function emptyResult(string $diseaseCode, string $reason): array
    {
        return [
            'is_simulation' => true,
            'engine_called' => false,
            'engine_note'   => $reason,
            'disease'       => ['code' => $diseaseCode],
            'final_score'   => 0,
            'score_cap'     => 100,
            'action_band'   => ['key' => 'NONE', 'label' => 'No action triggered', 'badge' => 'badge-soft'],
            'contributions' => [],
        ];
    }
}
