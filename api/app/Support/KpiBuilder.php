<?php

declare(strict_types=1);

namespace App\Support;

/**
 * KpiBuilder
 * ---------------------------------------------------------------------------
 * Composes a KPI descriptor: value, delta vs baseline, direction, tone,
 * inline sparkline points, optional target. Consumed by the <x-ui.kpi>
 * Blade component on the dashboard + module cockpits.
 *
 * Laws honoured:
 *   S6.2 — every page has a stats row
 *   S13  — 7-1-7 SLA tone rules
 *   S3.3 — tone is semantic (low/medium/high/critical/info/success/warning)
 *
 * A KPI payload shape:
 *   [
 *     'label'       => 'Caseload today',
 *     'value'       => 128,                // raw numeric value (or null)
 *     'display'     => '128',              // formatted string
 *     'baseline'    => 114,                // optional baseline for delta
 *     'delta'       => 14,                 // absolute
 *     'delta_pct'   => 12.28,              // percentage
 *     'direction'   => 'up'|'down'|'flat',
 *     'tone'        => 'brand|success|warning|critical|info|default',
 *     'caption'     => 'vs. 14-day baseline',
 *     'spark'       => [12, 14, 11, …],    // N points, newest last
 *     'target'      => 150,                // optional target
 *     'target_hit'  => true|false|null,
 *     'formatted_delta' => '+12%',
 *     'icon'        => 'users'|'alert'|'check'|'clock'|null,
 *   ]
 */
final class KpiBuilder
{
    /**
     * Build a KPI descriptor.
     *
     * @param  array{
     *     label:string,
     *     value:int|float|null,
     *     baseline?:int|float|null,
     *     spark?:array<int,int|float>,
     *     target?:int|float|null,
     *     caption?:string|null,
     *     tone?:string|null,
     *     icon?:string|null,
     *     format?:string|null,   // 'integer','decimal','percent','duration_seconds','duration_minutes'
     *     good?:string|null,     // 'up' or 'down' — which direction is good
     *   } $input
     * @return array<string,mixed>
     */
    public function build(array $input): array
    {
        $label    = $input['label'] ?? '';
        $value    = $input['value'] ?? null;
        $baseline = $input['baseline'] ?? null;
        $spark    = $input['spark'] ?? [];
        $target   = $input['target'] ?? null;
        $caption  = $input['caption'] ?? null;
        $tone     = $input['tone'] ?? 'default';
        $icon     = $input['icon'] ?? null;
        $format   = $input['format'] ?? 'integer';
        $good     = $input['good'] ?? 'up'; // 'up' means growth is good; 'down' means reduction is good

        // Formatted display
        $display = $value === null ? '—' : $this->formatValue($value, $format);

        // Delta vs baseline
        $delta      = null;
        $deltaPct   = null;
        $direction  = 'flat';
        if (is_numeric($value) && is_numeric($baseline) && (float) $baseline !== 0.0) {
            $delta     = (float) $value - (float) $baseline;
            $deltaPct  = ((float) $value - (float) $baseline) / abs((float) $baseline) * 100.0;
            $direction = $delta > 0.0001 ? 'up' : ($delta < -0.0001 ? 'down' : 'flat');
        }

        // Target hit
        $targetHit = null;
        if (is_numeric($value) && is_numeric($target)) {
            $targetHit = $good === 'down' ? ((float) $value <= (float) $target) : ((float) $value >= (float) $target);
        }

        // Sparkline: keep at most 24 points, coerce to ints/floats
        $spark = array_values(array_slice(array_map(fn ($n) => is_numeric($n) ? (float) $n : 0.0, $spark), -24));

        return [
            'label'            => $label,
            'value'            => $value,
            'display'          => $display,
            'baseline'         => $baseline,
            'delta'            => $delta,
            'delta_pct'        => $deltaPct,
            'direction'        => $direction,
            'delta_tone'       => $this->deltaTone($direction, $good),
            'tone'             => $tone,
            'caption'          => $caption,
            'spark'            => $spark,
            'target'           => $target,
            'target_hit'       => $targetHit,
            'formatted_delta'  => $this->formatDelta($delta, $deltaPct, $direction),
            'icon'             => $icon,
            'format'           => $format,
        ];
    }

    /**
     * Batch-build multiple KPIs at once. Each entry in $inputs is one payload.
     *
     * @param array<int,array> $inputs
     * @return array<int,array>
     */
    public function batch(array $inputs): array
    {
        return array_map(fn ($input) => $this->build($input), $inputs);
    }

    /* ────────────────────────────────────────────────────────────── */

    protected function formatValue(int|float $value, string $format): string
    {
        switch ($format) {
            case 'integer':
                return number_format((int) round($value));
            case 'decimal':
                return number_format((float) $value, 1);
            case 'percent':
                return rtrim(rtrim(number_format((float) $value, 1), '0'), '.') . '%';
            case 'duration_seconds':
                return $this->formatDuration((int) $value);
            case 'duration_minutes':
                return $this->formatDuration((int) ($value * 60));
            case 'decimal2':
                return number_format((float) $value, 2);
            default:
                return (string) $value;
        }
    }

    protected function formatDuration(int $seconds): string
    {
        if ($seconds < 60)     return $seconds . 's';
        if ($seconds < 3600)   return round($seconds / 60) . 'm';
        if ($seconds < 86400)  return round($seconds / 3600, 1) . 'h';
        return round($seconds / 86400, 1) . 'd';
    }

    protected function formatDelta(?float $delta, ?float $deltaPct, string $direction): string
    {
        if ($delta === null || $deltaPct === null) return '—';
        $arrow = $direction === 'up' ? '▲' : ($direction === 'down' ? '▼' : '—');
        $sign  = $delta >= 0 ? '+' : '';
        return $arrow . ' ' . $sign . number_format($deltaPct, 1) . '%';
    }

    protected function deltaTone(string $direction, string $good): string
    {
        if ($direction === 'flat') return 'flat';
        if ($good === 'down') {
            return $direction === 'down' ? 'up' : 'down';
        }
        return $direction; // good='up': up is good (shown green); down is bad (shown red)
    }
}
