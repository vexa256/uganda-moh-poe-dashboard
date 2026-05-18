<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

/**
 * R8 · rpt-screening-outcomes
 *
 * Reads the cycle-closure payload built by ScreeningOutcomesController and
 * surfaces narrative warnings. Closure rule (Wave 2 §4.1, locked from live
 * schema): an alert cycle is closed iff `alerts.status='CLOSED' AND
 * closed_at IS NOT NULL`. DUPLICATE-merged rows are tracked separately
 * and excluded from outcome funnel KPIs.
 */
final class ScreeningOutcomesInsightEngine
{
    public function evaluate(array $payload): array
    {
        $insights = [];
        $kpis     = $payload['kpis'] ?? [];
        $total    = (int) ($kpis['total_cycles']        ?? 0);
        $closed   = (int) ($kpis['closed_cycles']       ?? 0);
        $median   = (float) ($kpis['median_close_minutes'] ?? 0);
        $overdue  = (int) ($kpis['overdue_open']        ?? 0);

        if ($total < InsightThresholds::MIN_DENOMINATOR) {
            return [[
                'level' => 'note',
                'title' => 'Insufficient cycle volume',
                'body'  => sprintf('Only %d alert cycles in this filter window (n<%d). Closure conclusions are suppressed.', $total, InsightThresholds::MIN_DENOMINATOR),
                'rule'  => 'SUPPRESS_LOW_N',
            ]];
        }

        $closureRate = $total > 0 ? ($closed / $total) : 0.0;

        if ($closureRate < 0.50) {
            $insights[] = [
                'level' => 'critical',
                'title' => 'Cycle closure below 50%',
                'body'  => sprintf('%d of %d cycles closed (%.1f%%). Triage the open queue and identify the primary stall reasons.', $closed, $total, $closureRate * 100),
                'rule'  => 'CLOSURE_RATE_LT_50',
            ];
        } elseif ($closureRate < 0.80) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Cycle closure trailing target',
                'body'  => sprintf('%.1f%% closure rate is short of the 80%% operational target.', $closureRate * 100),
                'rule'  => 'CLOSURE_RATE_LT_80',
            ];
        }

        if ($median > 240) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Median time-to-close > 4 hours',
                'body'  => sprintf('Median time-to-close is %.0f minutes. Inspect facility-arrival and lab-pathway tabs for the lag.', $median),
                'rule'  => 'MEDIAN_CLOSE_GT_240',
            ];
        }

        if ($overdue >= 5) {
            $insights[] = [
                'level' => 'critical',
                'title' => sprintf('%d cycles past closure-window guideline', $overdue),
                'body'  => 'Open cycles older than the closure-window guideline. Each carries an unresolved post-alert disposition.',
                'rule'  => 'OVERDUE_OPEN_GTE_5',
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'level' => 'success',
                'title' => 'Cycle closure within target',
                'body'  => sprintf('%.1f%% closure rate · median %.0f minutes. No closure flags raised.', $closureRate * 100, $median),
                'rule'  => 'BASELINE_OK',
            ];
        }
        return $insights;
    }
}
