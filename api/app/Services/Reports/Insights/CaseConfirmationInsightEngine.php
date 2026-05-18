<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

/**
 * R10 · rpt-case-confirmation
 *
 * Reads the suspected→confirmed funnel built by CaseConfirmationController
 * off `alert_case_outcomes.case_classification` (Wave 2 §4.2 — authoritative
 * source). Six-state mapping: SUSPECTED/PROBABLE/CONFIRMED/DISCARDED map to
 * funnel stages; LOST_TO_FOLLOWUP, UNKNOWN, and missing rows fall into
 * "Pending classification".
 */
final class CaseConfirmationInsightEngine
{
    public function evaluate(array $payload): array
    {
        $insights = [];
        $kpis     = $payload['kpis'] ?? [];
        $total    = (int) ($kpis['total_alerts']     ?? 0);
        $confirmed = (int) ($kpis['confirmed']       ?? 0);
        $ruled    = (int) ($kpis['ruled_out']        ?? 0);
        $pending  = (int) ($kpis['pending']          ?? 0);
        $median   = (float) ($kpis['median_confirm_minutes'] ?? 0);

        if ($total < InsightThresholds::MIN_DENOMINATOR) {
            return [[
                'level' => 'note',
                'title' => 'Insufficient case volume',
                'body'  => sprintf('Only %d alerts with classification candidates in scope (n<%d).', $total, InsightThresholds::MIN_DENOMINATOR),
                'rule'  => 'SUPPRESS_LOW_N',
            ]];
        }

        if ($total > 0 && ($pending / $total) > 0.5) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Pending-classification backlog',
                'body'  => sprintf(
                    '%d of %d alerts (%.1f%%) lack a terminal classification. Lab pathway and lost-to-follow are likely contributors.',
                    $pending, $total, ($pending / $total) * 100,
                ),
                'rule'  => 'PENDING_GT_50PCT',
            ];
        }

        if ($confirmed >= 5) {
            $insights[] = [
                'level' => 'critical',
                'title' => sprintf('%d confirmed cases in window', $confirmed),
                'body'  => 'Confirmed cases at this volume warrant a line-list review and IHR Annex 2 reassessment for the affected diseases.',
                'rule'  => 'CONFIRMED_GTE_5',
            ];
        }

        if ($median > 0 && $median > 4320) { // 3 days
            $insights[] = [
                'level' => 'warning',
                'title' => 'Median time-to-confirm > 3 days',
                'body'  => sprintf('Median time-to-confirmation is %.0f minutes. Inspect Lab Routing tab — sample turnaround likely the bottleneck.', $median),
                'rule'  => 'MEDIAN_CONFIRM_GT_3D',
            ];
        }

        if ($total >= 20 && $confirmed === 0 && $ruled === 0) {
            $insights[] = [
                'level' => 'info',
                'title' => 'No terminal classifications',
                'body'  => 'No alerts in scope have reached confirmed or ruled-out. Verify the lab pathway is recording outcomes against alerts.',
                'rule'  => 'NO_TERMINAL_CLASS',
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'level' => 'success',
                'title' => 'Confirmation pathway healthy',
                'body'  => sprintf('%d confirmed · %d ruled-out · %d pending. No flags raised.', $confirmed, $ruled, $pending),
                'rule'  => 'BASELINE_OK',
            ];
        }
        return $insights;
    }
}
