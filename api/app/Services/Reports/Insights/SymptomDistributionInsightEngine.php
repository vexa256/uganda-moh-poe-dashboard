<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

/**
 * R12 · rpt-symptom-distribution
 *
 * Aggregate-only narrative engine over the symptom payload built by
 * SymptomDistributionController. Never emits PII — drilldown stops at the
 * suspected-disease level (Wave 2 §5).
 */
final class SymptomDistributionInsightEngine
{
    public function evaluate(array $payload): array
    {
        $insights = [];
        $totalCases = (int) ($payload['kpis']['cases_with_symptoms'] ?? 0);
        $coverage   = (float) ($payload['kpis']['dictionary_coverage'] ?? 0);

        if ($totalCases < InsightThresholds::MIN_DENOMINATOR) {
            return [[
                'level' => 'note',
                'title' => 'Insufficient symptom volume',
                'body'  => sprintf('Only %d cases with recorded symptoms (n<%d). Distribution suppressed.', $totalCases, InsightThresholds::MIN_DENOMINATOR),
                'rule'  => 'SUPPRESS_LOW_N',
            ]];
        }

        // Hallmark/red-flag symptom dominance — flag top 3 symptoms with prevalence ≥ 25%.
        $top = array_slice($payload['frequency'] ?? [], 0, 3);
        foreach ($top as $row) {
            $rate = (float) ($row['rate'] ?? 0);
            if ($rate >= 25.0) {
                $insights[] = [
                    'level' => 'warning',
                    'title' => 'High-prevalence symptom · ' . ($row['symptom_code'] ?? '—'),
                    'body'  => sprintf(
                        '%s present in %.1f%% of cases. Cross-check Symptom × Disease tab to confirm whether this matches expected case definitions.',
                        (string) ($row['symptom_code'] ?? '—'),
                        $rate,
                    ),
                    'rule'  => 'SYMPTOM_PREVALENCE_GTE_25',
                ];
            }
        }

        if ($coverage > 0 && $coverage < 60.0) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Symptom-dictionary coverage below 60%',
                'body'  => sprintf('Only %.1f%% of cases have any symptom recorded. Inspect screener data-entry adherence.', $coverage),
                'rule'  => 'DICTIONARY_COVERAGE_LT_60',
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'level' => 'success',
                'title' => 'Symptom distribution within expected ranges',
                'body'  => sprintf('%d cases with symptom records · %.1f%% dictionary coverage.', $totalCases, $coverage),
                'rule'  => 'BASELINE_OK',
            ];
        }
        return $insights;
    }
}
