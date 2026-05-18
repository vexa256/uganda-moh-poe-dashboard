<?php

declare(strict_types=1);

namespace App\Services\Reports\Insights;

use App\Services\Reports\InsightThresholds;

final class SymptomExposureInsightEngine
{
    public function evaluate(array $payload): array
    {
        $insights = [];
        $total = (int) ($payload['kpis']['secondary'] ?? 0);

        if ($total < InsightThresholds::MIN_DENOMINATOR) {
            return [[
                'level' => 'note',
                'title' => 'Insufficient data',
                'body'  => sprintf('%d secondary cases in window (n<%d). Conclusions suppressed.', $total, InsightThresholds::MIN_DENOMINATOR),
                'rule'  => 'SUPPRESS_LOW_N',
            ]];
        }

        $high = (int) ($payload['kpis']['high_risk'] ?? 0);
        if ($high >= 5) {
            $insights[] = [
                'level' => 'critical',
                'title' => 'High-risk exposure volume',
                'body'  => sprintf('%d cases carry ≥3 symptoms or a YES exposure to an outbreak indicator. Treat as outbreak-style scenario and enhance surveillance.', $high),
                'rule'  => 'HIGH_RISK_GTE_5',
            ];
        }

        foreach ($payload['tripwires'] ?? [] as $tw) {
            $insights[] = [
                'level' => 'warning',
                'title' => 'Symptom spike · ' . $tw['symptom'],
                'body'  => sprintf('Last-7-day ratio %.2fx trailing baseline. %d cases reported this symptom in the window.', $tw['ratio'], $tw['recent']),
                'rule'  => 'SYMPTOM_SPIKE_' . strtoupper(substr($tw['symptom'], 0, 16)),
            ];
        }

        foreach ($payload['classification_mix'] ?? [] as $key => $count) {
            if ($total > 0 && ($count / $total) >= 0.5) {
                $insights[] = [
                    'level' => 'warning',
                    'title' => 'Syndrome concentration · ' . $key,
                    'body'  => sprintf('%.1f%% of cases are classified as %s. Verify lab confirmation and rule out algorithm bias.', ($count / $total) * 100, $key),
                    'rule'  => 'SYNDROME_CONCENTRATION_GTE_50',
                ];
            }
        }

        if (empty($insights)) {
            $insights[] = [
                'level' => 'success',
                'title' => 'No outbreak flags',
                'body'  => 'Symptom and exposure distributions look within expected bands.',
                'rule'  => 'BASELINE_OK',
            ];
        }

        return $insights;
    }
}
