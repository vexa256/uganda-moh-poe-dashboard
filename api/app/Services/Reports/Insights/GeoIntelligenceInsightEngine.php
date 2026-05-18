<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

final class GeoIntelligenceInsightEngine
{
    public function evaluate(array $payload): array
    {
        $insights = [];
        $total = (int) ($payload['kpis']['high_risk_arrivals'] ?? 0);
        $originCount = count($payload['origins'] ?? []);

        if ($total < InsightThresholds::MIN_DENOMINATOR) {
            return [[
                'level' => 'note',
                'title' => 'Insufficient data',
                'body'  => sprintf('Only %d high-risk arrivals (n<%d). Conclusions suppressed.', $total, InsightThresholds::MIN_DENOMINATOR),
                'rule'  => 'SUPPRESS_LOW_N',
            ]];
        }

        foreach ($payload['origins'] ?? [] as $row) {
            if ($row['symptomatic'] >= 3) {
                $insights[] = [
                    'level' => 'warning',
                    'title' => 'Origin cluster · ' . $row['country'],
                    'body'  => sprintf('%d symptomatic arrivals from %s. Share the signal with IHR NFP counterpart.', $row['symptomatic'], $row['country']),
                    'rule'  => 'ORIGIN_CLUSTER_GTE_3',
                ];
            }
        }

        foreach ($payload['endemic'] ?? [] as $row) {
            if ($row['symptomatic'] >= 5) {
                $insights[] = [
                    'level' => 'critical',
                    'title' => 'Endemic-zone arrivals ' . $row['country'],
                    'body'  => sprintf('%d arrivals from an endemic zone (%s) presented symptoms. Activate enhanced surveillance at the receiving POEs.', $row['symptomatic'], $row['country']),
                    'rule'  => 'ENDEMIC_ARRIVALS_GTE_5',
                ];
            }
        }

        if ($originCount >= 10 && $total >= 20) {
            $insights[] = [
                'level' => 'info',
                'title' => 'Wide origin footprint',
                'body'  => sprintf('%d distinct origin countries in %d arrivals. Review whether POE coverage and translator capacity match.', $originCount, $total),
                'rule'  => 'ORIGIN_DIVERSITY_HIGH',
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'level' => 'success',
                'title' => 'No geo risk flags',
                'body'  => 'No origin, transit, or endemic-zone anomalies detected in this window.',
                'rule'  => 'BASELINE_OK',
            ];
        }

        return $insights;
    }
}
