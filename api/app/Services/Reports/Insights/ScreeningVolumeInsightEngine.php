<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

final class ScreeningVolumeInsightEngine
{
    /**
     * @param  array  $payload  Output of ScreeningVolumeController::buildPayload()
     * @return array<int,array{level:string,title:string,body:string,rule:string}>
     */
    public function evaluate(array $payload): array
    {
        $insights = [];

        $primary       = (int) ($payload['kpis']['primary'] ?? 0);
        $secondary     = (int) ($payload['kpis']['secondary'] ?? 0);
        $notifiable    = (int) ($payload['kpis']['notifiable'] ?? 0);
        $referrals     = (int) ($payload['kpis']['facility_referrals'] ?? 0);
        $flagged_queue = (int) ($payload['kpis']['holding_flagged'] ?? 0);
        $spikeRatio    = (float) ($payload['kpis']['spike_ratio'] ?? 0);

        $notifPct = $secondary > 0 ? $notifiable / $secondary : 0.0;

        if ($secondary < InsightThresholds::MIN_DENOMINATOR) {
            $insights[] = [
                'level' => 'note',
                'title' => 'Insufficient data',
                'body'  => sprintf('Only %d secondary screenings in this filter window — slice-level conclusions are suppressed (rule: n<%d).', $secondary, InsightThresholds::MIN_DENOMINATOR),
                'rule'  => 'SUPPRESS_LOW_N',
            ];
        } else {
            if ($notifPct >= InsightThresholds::NOTIFIABLE_HIGH) {
                $insights[] = [
                    'level' => 'critical',
                    'title' => 'Notifiable rate exceeds CRITICAL threshold',
                    'body'  => sprintf('Notifiable share is %.1f%% of completed secondary screenings (n=%d), above the %d%% CRITICAL line. Engage IHR NFP and confirm Tier-1 notifications are flowing.', $notifPct * 100, $secondary, (int) (InsightThresholds::NOTIFIABLE_HIGH * 100)),
                    'rule'  => 'NOTIFIABLE_GTE_30',
                ];
            } elseif ($notifPct >= InsightThresholds::NOTIFIABLE_MED) {
                $insights[] = [
                    'level' => 'warning',
                    'title' => 'Notifiable rate above WARNING threshold',
                    'body'  => sprintf('Notifiable share is %.1f%% (n=%d), above the %d%% WARNING line — review case classifications.', $notifPct * 100, $secondary, (int) (InsightThresholds::NOTIFIABLE_MED * 100)),
                    'rule'  => 'NOTIFIABLE_GTE_15',
                ];
            }
        }

        if ($notifiable > 0 && $referrals === 0) {
            $insights[] = [
                'level' => 'critical',
                'title' => 'Notifiable cases without facility referrals',
                'body'  => sprintf('%d notifiable cases recorded but zero referrals to a hospital / facility. Confirm secondary_actions / final_disposition data quality.', $notifiable),
                'rule'  => 'NOTIFIABLE_NO_REFERRAL',
            ];
        }

        if ($flagged_queue >= 5) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Holding queue is backed up',
                'body'  => sprintf('%d secondary screenings have been open ≥%d minutes. Triage staffing and consider temporary surge support.', $flagged_queue, InsightThresholds::HOLDING_QUEUE_SLOW_MIN),
                'rule'  => 'HOLDING_QUEUE_SLOW',
            ];
        }

        if ($spikeRatio >= InsightThresholds::SPIKE_RATIO && $primary >= InsightThresholds::MIN_DENOMINATOR) {
            $insights[] = [
                'level' => 'info',
                'title' => 'Throughput spike detected',
                'body'  => sprintf('Last 7-day primary throughput is %.2fx the trailing 30-day baseline. Check whether a campaign, repatriation, or event is driving the increase.', $spikeRatio),
                'rule'  => 'SPIKE_VS_30D_BASELINE',
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'level' => 'success',
                'title' => 'No surveillance flags',
                'body'  => 'All thresholds are within expected ranges for the selected filter window.',
                'rule'  => 'BASELINE_OK',
            ];
        }

        return $insights;
    }
}
