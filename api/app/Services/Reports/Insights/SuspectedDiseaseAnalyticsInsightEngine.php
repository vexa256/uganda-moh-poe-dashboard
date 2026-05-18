<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

/**
 * R9 · rpt-suspected-disease-analytics
 *
 * Reads the per-disease analytics payload and runs deterministic outbreak
 * tripwires (Wave 2 §6.2) — versioned, hardcoded, marked "v1 — domain
 * sign-off pending". Tripwires never auto-fire downstream actions; they
 * surface as critical-level insights only.
 */
final class SuspectedDiseaseAnalyticsInsightEngine
{
    /**
     * Tripwire library — keyed by rule id, pinned to v1.
     * Each rule receives the full payload and returns a list of insight rows.
     */
    private const TRIPWIRE_VERSION = 'v1';

    public function evaluate(array $payload): array
    {
        $insights = [];
        $totalSuspected = (int) ($payload['kpis']['total_suspected'] ?? 0);

        if ($totalSuspected < InsightThresholds::MIN_DENOMINATOR) {
            return [[
                'level' => 'note',
                'title' => 'Insufficient suspected-case volume',
                'body'  => sprintf('Only %d suspected cases in this filter window (n<%d). Tripwires suppressed.', $totalSuspected, InsightThresholds::MIN_DENOMINATOR),
                'rule'  => 'SUPPRESS_LOW_N',
            ]];
        }

        // VHF cluster tripwire — any disease tagged VHF with ≥5 suspicions in 14 days at one POE.
        foreach ($payload['tripwires']['vhf_cluster'] ?? [] as $hit) {
            $insights[] = [
                'level' => 'critical',
                'title' => 'VHF cluster tripwire · ' . ($hit['poe_code'] ?? 'POE'),
                'body'  => sprintf(
                    '%d suspected %s cases at %s within 14 days. Trigger line-list investigation and consider IHR Tier-1 notification.',
                    (int) ($hit['count'] ?? 0),
                    (string) ($hit['disease_code'] ?? '—'),
                    (string) ($hit['poe_code'] ?? '—'),
                ),
                'rule'  => 'TRIPWIRE_VHF_CLUSTER_' . self::TRIPWIRE_VERSION,
            ];
        }

        // Cholera doubling tripwire — week-over-week doubling in any district.
        foreach ($payload['tripwires']['cholera_doubling'] ?? [] as $hit) {
            $insights[] = [
                'level' => 'critical',
                'title' => 'Cholera doubling tripwire · ' . ($hit['district_code'] ?? 'District'),
                'body'  => sprintf(
                    'Suspected cholera in %s rose from %d to %d week-over-week. Inspect water/sanitation triggers.',
                    (string) ($hit['district_code'] ?? '—'),
                    (int) ($hit['prev'] ?? 0),
                    (int) ($hit['curr'] ?? 0),
                ),
                'rule'  => 'TRIPWIRE_CHOLERA_DOUBLING_' . self::TRIPWIRE_VERSION,
            ];
        }

        // Wide spectrum — suspected diseases ≥ 8 distinct codes signals over-triggering.
        $unique = (int) ($payload['kpis']['unique_diseases'] ?? 0);
        if ($unique >= 8) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Wide suspected-disease spectrum',
                'body'  => sprintf('%d distinct diseases suspected in scope. Review whether the screening algorithm is over-firing.', $unique),
                'rule'  => 'WIDE_SPECTRUM_GTE_8',
            ];
        }

        if (empty($insights)) {
            $insights[] = [
                'level' => 'success',
                'title' => 'No tripwires fired',
                'body'  => 'Suspected-disease metrics are within normal operating ranges for this scope.',
                'rule'  => 'BASELINE_OK',
            ];
        }
        return $insights;
    }
}
