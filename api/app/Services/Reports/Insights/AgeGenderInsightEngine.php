<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

final class AgeGenderInsightEngine
{
    public function evaluate(array $payload): array
    {
        $insights = [];
        $total = (int) ($payload['kpis']['secondary'] ?? 0);

        if ($total < InsightThresholds::MIN_DENOMINATOR) {
            return [[
                'level' => 'note', 'title' => 'Insufficient data',
                'body' => sprintf('%d secondary cases (n<%d). Slice-level conclusions suppressed.', $total, InsightThresholds::MIN_DENOMINATOR),
                'rule' => 'SUPPRESS_LOW_N',
            ]];
        }

        foreach ($payload['age_bands'] ?? [] as $row) {
            $den = (int) $row['total'];
            $notif = (int) $row['notifiable'];
            if ($den < InsightThresholds::MIN_DENOMINATOR) { continue; }
            $pct = $notif / $den;
            if ($pct >= InsightThresholds::NOTIFIABLE_HIGH) {
                $insights[] = [
                    'level' => 'critical',
                    'title' => 'Age band ' . $row['band'] . ' above CRITICAL notifiable line',
                    'body'  => sprintf('%.1f%% of age-band %s secondary cases are notifiable (n=%d).', $pct * 100, $row['band'], $den),
                    'rule'  => 'AGE_NOTIFIABLE_GTE_30',
                ];
            } elseif ($pct >= InsightThresholds::NOTIFIABLE_MED) {
                $insights[] = [
                    'level' => 'warning',
                    'title' => 'Age band ' . $row['band'] . ' above WARNING line',
                    'body'  => sprintf('%.1f%% of age-band %s cases are notifiable.', $pct * 100, $row['band']),
                    'rule'  => 'AGE_NOTIFIABLE_GTE_15',
                ];
            }
        }

        $m = $payload['gender']['MALE']    ?? ['notifiable' => 0, 'total' => 0];
        $f = $payload['gender']['FEMALE']  ?? ['notifiable' => 0, 'total' => 0];
        if ($m['total'] >= InsightThresholds::MIN_DENOMINATOR && $f['total'] >= InsightThresholds::MIN_DENOMINATOR) {
            $mPct = $m['notifiable'] / max(1, $m['total']);
            $fPct = $f['notifiable'] / max(1, $f['total']);
            $delta = abs($mPct - $fPct);
            if ($delta > InsightThresholds::GENDER_DISPARITY) {
                $insights[] = [
                    'level' => 'warning',
                    'title' => 'Gender disparity in notifiable rate',
                    'body'  => sprintf('%.1fpp disparity · male %.1f%% vs female %.1f%%. Review whether the clinical algorithm is biasing detection.', $delta * 100, $mPct * 100, $fPct * 100),
                    'rule'  => 'GENDER_DISPARITY_GT_10PP',
                ];
            }
        }

        $under5 = $payload['age_bands_summary']['U5']      ?? 0;
        $over65 = $payload['age_bands_summary']['65+']     ?? 0;
        if ($total > 0 && (($under5 + $over65) / $total) > 0.20) {
            $insights[] = [
                'level' => 'info',
                'title' => 'Vulnerable cohorts over-represented',
                'body'  => sprintf('Under-5 and 65+ cohorts make up %.1f%% of secondary cases. Prioritise care pathways and language support.', (($under5 + $over65) / $total) * 100),
                'rule'  => 'VULNERABLE_COHORTS_GT_20',
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'level' => 'success',
                'title' => 'No demographic flags',
                'body'  => 'Age-band and gender metrics are within expected bands.',
                'rule'  => 'BASELINE_OK',
            ];
        }

        return $insights;
    }
}
