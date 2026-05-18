<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

/**
 * R13 · rpt-country-analytics
 *
 * National-rollup narrative. Visible only to PHEOC, super, and the WHO
 * observer carve-out. Country-level exclusions (Wave 2 §4.4 — locked):
 *   • deleted_at IS NULL
 *   • sync_status != 'FAILED'
 *   • primary_screenings.record_status != 'VOIDED'
 *   • alerts.close_category NOT IN ('FALSE_ALARM','DUPLICATE') for outcome rollups
 */
final class CountryAnalyticsInsightEngine
{
    public function evaluate(array $payload): array
    {
        $insights = [];
        $kpis     = $payload['kpis'] ?? [];

        $screened    = (int)   ($kpis['total_screened']     ?? 0);
        $suspected   = (int)   ($kpis['total_suspected']    ?? 0);
        $confirmed   = (int)   ($kpis['total_confirmed']    ?? 0);
        $alerts      = (int)   ($kpis['total_alerts']       ?? 0);
        $ackRate     = (float) ($kpis['ack_rate']           ?? 0);
        $closureRate = (float) ($kpis['cycle_closure_rate'] ?? 0);
        $compliance  = (float) ($kpis['compliance_7_1_7']   ?? 0);

        if ($screened < InsightThresholds::MIN_DENOMINATOR && $alerts < InsightThresholds::MIN_DENOMINATOR) {
            return [[
                'level' => 'note',
                'title' => 'Insufficient national volume',
                'body'  => sprintf('Only %d screenings and %d alerts in window (n<%d).', $screened, $alerts, InsightThresholds::MIN_DENOMINATOR),
                'rule'  => 'SUPPRESS_LOW_N',
            ]];
        }

        if ($ackRate < 0.80 && $alerts > 0) {
            $insights[] = [
                'level' => 'critical',
                'title' => 'National acknowledgement rate below 80%',
                'body'  => sprintf('National ack rate is %.1f%%. Inspect the PHEOC league table for the worst contributors.', $ackRate * 100),
                'rule'  => 'NATIONAL_ACK_LT_80',
            ];
        }

        if ($closureRate < 0.70) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Cycle closure trailing target',
                'body'  => sprintf('National cycle-closure rate is %.1f%%. Drill into rpt-screening-outcomes for the breakdown.', $closureRate * 100),
                'rule'  => 'NATIONAL_CLOSURE_LT_70',
            ];
        }

        if ($compliance < 0.80) {
            $insights[] = [
                'level' => 'warning',
                'title' => '7-1-7 compliance below 80%',
                'body'  => sprintf('National 7-1-7 (notify-24h + respond-7d) compliance is %.1f%%. Drill into the compliance map tab.', $compliance * 100),
                'rule'  => 'COMPLIANCE_7_1_7_LT_80',
            ];
        }

        if ($suspected > 0 && $confirmed > 0 && ($confirmed / max(1, $suspected)) > 0.10) {
            $insights[] = [
                'level' => 'critical',
                'title' => 'Confirmation conversion above 10%',
                'body'  => sprintf(
                    '%d of %d suspected cases confirmed (%.1f%%). Above the 10%% national tripwire — verify outbreak posture.',
                    $confirmed, $suspected, ($confirmed / $suspected) * 100,
                ),
                'rule'  => 'CONFIRM_CONVERSION_GT_10',
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'level' => 'success',
                'title' => 'National signals within target',
                'body'  => sprintf(
                    '%d screened · %d suspected · %d confirmed · ack %.0f%% · closure %.0f%%.',
                    $screened, $suspected, $confirmed, $ackRate * 100, $closureRate * 100,
                ),
                'rule'  => 'BASELINE_OK',
            ];
        }
        return $insights;
    }
}
