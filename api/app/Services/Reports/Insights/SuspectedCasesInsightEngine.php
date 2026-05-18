<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

final class SuspectedCasesInsightEngine
{
    public function evaluate(array $payload): array
    {
        $insights = [];
        $total       = (int) ($payload['kpis']['total_suspected']   ?? 0);
        $confirmed   = (int) ($payload['kpis']['confirmed']         ?? 0);
        $pending     = (int) ($payload['kpis']['pending']           ?? 0);
        $unique      = (int) ($payload['kpis']['unique_conditions'] ?? 0);

        if ($total < InsightThresholds::MIN_DENOMINATOR) {
            return [[
                'level' => 'note',
                'title' => 'Insufficient data',
                'body'  => sprintf('Only %d suspected cases in this filter window (n<%d). Slice-level conclusions suppressed.', $total, InsightThresholds::MIN_DENOMINATOR),
                'rule'  => 'SUPPRESS_LOW_N',
            ]];
        }

        if ($total > 0 && ($pending / $total) > 0.5) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Investigation backlog',
                'body'  => sprintf('%d of %d suspected cases (%.1f%%) are still pending disposition. Review IN_PROGRESS queue.', $pending, $total, ($pending / $total) * 100),
                'rule'  => 'PENDING_GT_50PCT',
            ];
        }

        foreach ($payload['top_conditions'] ?? [] as $row) {
            if (($row['confirmed'] ?? 0) >= 5) {
                $insights[] = [
                    'level' => 'critical',
                    'title' => 'Potential cluster · ' . $row['disease_code'],
                    'body'  => sprintf('%d confirmed cases of "%s" in scope. Trigger line-list investigation and consider IHR Tier-1 notification.', $row['confirmed'], $row['disease_code']),
                    'rule'  => 'CLUSTER_CONFIRMED_GTE_5',
                ];
            }
        }

        if ($confirmed === 0 && $total >= 20) {
            $insights[] = [
                'level' => 'info',
                'title' => 'No confirmed cases yet',
                'body'  => sprintf('%d suspected cases without any confirmation. Track laboratory turnaround and close the loop on outstanding samples.', $total),
                'rule'  => 'NO_CONFIRMED_WITH_VOLUME',
            ];
        }

        if ($unique >= 8) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Wide condition spectrum',
                'body'  => sprintf('%d distinct suspected conditions in this filter window — consider whether the clinical algorithm is over-triggering.', $unique),
                'rule'  => 'CONDITION_DIVERSITY_HIGH',
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'level' => 'success',
                'title' => 'No surveillance flags',
                'body'  => 'Case-detection metrics are within expected ranges.',
                'rule'  => 'BASELINE_OK',
            ];
        }

        return $insights;
    }
}
