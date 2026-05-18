<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

/**
 * R11 · rpt-alert-acknowledgement
 *
 * Wave 2 §6.4 SLA matrix (hardcoded — confirm against M2 War Room before sign-off):
 *   CRITICAL → 60 minutes
 *   HIGH     → 240 minutes (4 hours)
 *   MEDIUM   → 1440 minutes (24 hours)
 *   LOW      → 1440 minutes (24 hours)
 */
final class AlertAcknowledgementInsightEngine
{
    public const SLA_MINUTES = [
        'CRITICAL' => 60,
        'HIGH'     => 240,
        'MEDIUM'   => 1440,
        'LOW'      => 1440,
    ];

    public function evaluate(array $payload): array
    {
        $insights = [];
        $kpis     = $payload['kpis'] ?? [];
        $total    = (int) ($kpis['total_alerts'] ?? 0);
        $acked    = (int) ($kpis['acknowledged'] ?? 0);
        $unacked  = (int) ($kpis['unacknowledged'] ?? 0);
        $median   = (float) ($kpis['median_ack_minutes'] ?? 0);
        $breaches = (int) ($kpis['sla_breaches'] ?? 0);

        if ($total < InsightThresholds::MIN_DENOMINATOR) {
            return [[
                'level' => 'note',
                'title' => 'Insufficient alert volume',
                'body'  => sprintf('Only %d alerts in this filter window (n<%d).', $total, InsightThresholds::MIN_DENOMINATOR),
                'rule'  => 'SUPPRESS_LOW_N',
            ]];
        }

        if ($total > 0 && ($unacked / $total) > 0.20) {
            $insights[] = [
                'level' => 'critical',
                'title' => 'Unacknowledged-alert rate > 20%',
                'body'  => sprintf(
                    '%d of %d alerts (%.1f%%) lack an acknowledgement timestamp. Each is an unanswered alarm — page the responder coverage owner.',
                    $unacked, $total, ($unacked / $total) * 100,
                ),
                'rule'  => 'UNACK_GT_20PCT',
            ];
        }

        if ($breaches >= 1) {
            $insights[] = [
                'level' => 'warning',
                'title' => sprintf('%d SLA breaches in scope', $breaches),
                'body'  => 'Alerts that exceeded the per-risk-level acknowledgement SLA. Inspect Breach Reasons tab.',
                'rule'  => 'SLA_BREACHES_GTE_1',
            ];
        }

        if ($median > 0 && $median > 60) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Median ack time > 60 minutes',
                'body'  => sprintf('Median acknowledgement time is %.0f minutes across all risk levels. Critical alerts have a 60-minute SLA.', $median),
                'rule'  => 'MEDIAN_ACK_GT_60',
            ];
        }

        if (empty($insights) && $acked > 0) {
            $insights[] = [
                'level' => 'success',
                'title' => 'Acknowledgement health within target',
                'body'  => sprintf('%d of %d alerts acknowledged · median %.0f minutes · 0 SLA breaches.', $acked, $total, $median),
                'rule'  => 'BASELINE_OK',
            ];
        }
        return $insights;
    }
}
