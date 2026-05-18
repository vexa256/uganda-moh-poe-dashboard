<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

final class CasesRegistryInsightEngine
{
    public function evaluate(array $payload): array
    {
        $insights = [];
        $total     = (int) ($payload['kpis']['total'] ?? 0);
        $pending   = (int) ($payload['kpis']['pending'] ?? 0);
        $confirmed = (int) ($payload['kpis']['confirmed'] ?? 0);
        $highRisk  = (int) ($payload['kpis']['high_risk'] ?? 0);

        if ($total < InsightThresholds::MIN_DENOMINATOR) {
            return [[
                'level' => 'note',
                'title' => 'Insufficient data',
                'body'  => sprintf('%d registry rows (n<%d). Conclusions suppressed.', $total, InsightThresholds::MIN_DENOMINATOR),
                'rule'  => 'SUPPRESS_LOW_N',
            ]];
        }

        if ($total > 0 && ($pending / $total) > 0.3) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Pending outcomes backlog',
                'body'  => sprintf('%.1f%% of registry cases are still pending final outcome. Review IN_PROGRESS queues and lab turnaround.', ($pending / $total) * 100),
                'rule'  => 'PENDING_GT_30',
            ];
        }

        if ($total > 0 && ($confirmed / $total) > 0.10) {
            $insights[] = [
                'level' => 'critical',
                'title' => 'High confirmed share in registry',
                'body'  => sprintf('%.1f%% of registry cases are confirmed notifiable. Escalate to IHR NFP.', ($confirmed / $total) * 100),
                'rule'  => 'CONFIRMED_GT_10',
            ];
        }

        if ($highRisk > 0 && ($highRisk / max(1, $total)) > 0.25) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'High-risk skew',
                'body'  => sprintf('%d of %d cases (%.1f%%) are tagged HIGH or CRITICAL risk. Verify triage thresholds.', $highRisk, $total, ($highRisk / $total) * 100),
                'rule'  => 'HIGH_RISK_SHARE',
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'level' => 'success',
                'title' => 'Registry metrics look healthy',
                'body'  => 'Pending, confirmed, and high-risk shares are within expected bands.',
                'rule'  => 'BASELINE_OK',
            ];
        }

        return $insights;
    }
}
