<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

final class ContactTracingInsightEngine
{
    public function evaluate(array $payload): array
    {
        $insights = [];
        $total = (int) ($payload['kpis']['total_screenings'] ?? 0);
        $score = (float) ($payload['kpis']['completeness_score'] ?? 0);
        $missingPhone = (float) ($payload['kpis']['missing_phone_pct'] ?? 0);

        if ($total < InsightThresholds::MIN_DENOMINATOR) {
            return [[
                'level' => 'note',
                'title' => 'Insufficient data',
                'body'  => sprintf('Only %d secondary screenings — conclusions suppressed (n<%d).', $total, InsightThresholds::MIN_DENOMINATOR),
                'rule'  => 'SUPPRESS_LOW_N',
            ]];
        }

        if ($score < InsightThresholds::COMPLETENESS_LOW) {
            $insights[] = [
                'level' => 'critical',
                'title' => 'Contact-tracing readiness CRITICAL',
                'body'  => sprintf('Completeness score %.1f%% is below the %d%% critical line. Active contact tracing will fail — prioritise field training and require contact fields in the secondary form.', $score * 100, (int) (InsightThresholds::COMPLETENESS_LOW * 100)),
                'rule'  => 'COMPLETENESS_LT_70',
            ];
        } elseif ($score < InsightThresholds::COMPLETENESS_OK) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Contact info below target',
                'body'  => sprintf('Completeness score %.1f%%, target ≥ %d%%.', $score * 100, (int) (InsightThresholds::COMPLETENESS_OK * 100)),
                'rule'  => 'COMPLETENESS_LT_90',
            ];
        }

        if ($missingPhone > 0.2 && $total >= 10) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Phone coverage gap',
                'body'  => sprintf('%.1f%% of cases have no phone number — contact tracing will rely on emergency contacts alone.', $missingPhone * 100),
                'rule'  => 'MISSING_PHONE_GT_20',
            ];
        }

        $weak = 0;
        foreach ($payload['screeners'] ?? [] as $s) {
            if (($s['completeness'] ?? 1) < 0.5 && ($s['cases'] ?? 0) >= 3) { $weak++; }
        }
        if ($weak >= 3) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Training gap across screeners',
                'body'  => sprintf('%d screeners are below 50%% field completeness with ≥3 cases each.', $weak),
                'rule'  => 'SCREENER_TRAINING_GAP',
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'level' => 'success',
                'title' => 'Contact-tracing ready',
                'body'  => 'Completeness metrics are at or above target in this window.',
                'rule'  => 'BASELINE_OK',
            ];
        }

        return $insights;
    }
}
