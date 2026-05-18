<?php

declare(strict_types=1);

namespace App\Support;

/**
 * ChartPayloadBuilder
 * ---------------------------------------------------------------------------
 * Shapes Chart.js-compatible payloads with colour-blind-safe palettes,
 * sensible axis defaults, and per-dataset tone resolution against the
 * semantic token system (brand, low/medium/high/critical, info/success/
 * warning/danger, plus the --viz-1..--viz-8 categorical palette).
 *
 * Consumed by:
 *   <x-ui.chart type="line" :payload="$payload" />
 *   <x-ui.chart type="bar"  :payload="$payload" />
 *   <x-ui.chart type="doughnut" :payload="$payload" />
 *
 * Chart.js instantiation happens in the @push('scripts') block of the view.
 */
final class ChartPayloadBuilder
{
    /** Semantic tone → HSL var() wrapper (resolves live from theme tokens). */
    protected const TONE_COLOR = [
        'brand'     => 'hsl(var(--brand))',
        'low'       => 'hsl(var(--low))',
        'medium'    => 'hsl(var(--medium))',
        'high'      => 'hsl(var(--high))',
        'critical'  => 'hsl(var(--critical))',
        'info'      => 'hsl(var(--info))',
        'success'   => 'hsl(var(--success))',
        'warning'   => 'hsl(var(--warning))',
        'danger'    => 'hsl(var(--danger))',
        'muted'     => 'hsl(var(--muted-foreground))',
    ];

    /** Okabe-Ito / viridis-inspired colour-blind-safe categorical palette. */
    protected const VIZ_PALETTE = [
        'hsl(var(--viz-1))', // sky
        'hsl(var(--viz-2))', // emerald
        'hsl(var(--viz-3))', // amber
        'hsl(var(--viz-4))', // violet
        'hsl(var(--viz-5))', // rose
        'hsl(var(--viz-6))', // orange
        'hsl(var(--viz-7))', // teal
        'hsl(var(--viz-8))', // purple
    ];

    /** Line chart — time series. */
    public function line(array $series, array $labels, array $options = []): array
    {
        $datasets = [];
        $i = 0;
        foreach ($series as $name => $points) {
            $tone = $options['tones'][$name] ?? null;
            $color = $tone && isset(self::TONE_COLOR[$tone]) ? self::TONE_COLOR[$tone] : self::VIZ_PALETTE[$i % count(self::VIZ_PALETTE)];
            $datasets[] = [
                'label'           => $name,
                'data'            => array_values($points),
                'borderColor'     => $color,
                'backgroundColor' => $this->alpha($color, 0.12),
                'tension'         => 0.35,
                'pointRadius'     => 2,
                'pointHoverRadius'=> 5,
                'borderWidth'     => 2,
                'fill'            => (bool) ($options['fill'][$name] ?? true),
            ];
            $i++;
        }
        return [
            'type'    => 'line',
            'data'    => ['labels' => $labels, 'datasets' => $datasets],
            'options' => $this->commonOptions($options, 'line'),
        ];
    }

    /** Vertical bar chart — categorical comparison. */
    public function bar(array $series, array $labels, array $options = []): array
    {
        $datasets = [];
        $i = 0;
        foreach ($series as $name => $points) {
            $tone = $options['tones'][$name] ?? null;
            $color = $tone && isset(self::TONE_COLOR[$tone]) ? self::TONE_COLOR[$tone] : self::VIZ_PALETTE[$i % count(self::VIZ_PALETTE)];
            $datasets[] = [
                'label'           => $name,
                'data'            => array_values($points),
                'backgroundColor' => $this->alpha($color, 0.85),
                'borderColor'     => $color,
                'borderWidth'     => 0,
                'borderRadius'    => 6,
                'maxBarThickness' => 28,
            ];
            $i++;
        }
        return [
            'type'    => 'bar',
            'data'    => ['labels' => $labels, 'datasets' => $datasets],
            'options' => $this->commonOptions($options, 'bar'),
        ];
    }

    /** Doughnut — category distribution. Pass `['tones' => [label => tone,…]]`. */
    public function doughnut(array $data, array $options = []): array
    {
        $labels = array_keys($data);
        $values = array_values($data);
        $colors = [];
        foreach ($labels as $i => $label) {
            $tone = $options['tones'][$label] ?? null;
            $colors[] = $tone && isset(self::TONE_COLOR[$tone]) ? self::TONE_COLOR[$tone] : self::VIZ_PALETTE[$i % count(self::VIZ_PALETTE)];
        }
        return [
            'type' => 'doughnut',
            'data' => [
                'labels'   => $labels,
                'datasets' => [[
                    'data'            => $values,
                    'backgroundColor' => $colors,
                    'borderColor'     => 'hsl(var(--background))',
                    'borderWidth'     => 2,
                ]],
            ],
            'options' => array_merge_recursive($this->commonOptions($options, 'doughnut'), [
                'cutout'    => '62%',
                'plugins'   => ['legend' => ['position' => 'bottom']],
            ]),
        ];
    }

    /** Horizontal bar — ranked lists. */
    public function rankedBar(array $data, array $options = []): array
    {
        $labels = array_keys($data);
        $values = array_values($data);
        return [
            'type' => 'bar',
            'data' => [
                'labels'   => $labels,
                'datasets' => [[
                    'label'           => $options['label'] ?? 'Count',
                    'data'            => $values,
                    'backgroundColor' => $options['color'] ?? self::TONE_COLOR['brand'],
                    'borderRadius'    => 6,
                    'maxBarThickness' => 20,
                ]],
            ],
            'options' => array_merge_recursive($this->commonOptions($options, 'bar'), [
                'indexAxis' => 'y',
                'plugins'   => ['legend' => ['display' => false]],
            ]),
        ];
    }

    /** Heatmap-style bar-series for per-POE/per-district activity. */
    public function heatmap(array $matrix, array $rowLabels, array $colLabels, array $options = []): array
    {
        // Chart.js doesn't do heatmaps natively; express as stacked bars.
        $datasets = [];
        foreach ($colLabels as $j => $col) {
            $color = self::VIZ_PALETTE[$j % count(self::VIZ_PALETTE)];
            $points = array_map(fn ($row) => $row[$j] ?? 0, $matrix);
            $datasets[] = [
                'label'           => $col,
                'data'            => $points,
                'backgroundColor' => $this->alpha($color, 0.75),
                'stack'           => 'stack',
            ];
        }
        return [
            'type'    => 'bar',
            'data'    => ['labels' => $rowLabels, 'datasets' => $datasets],
            'options' => array_merge_recursive($this->commonOptions($options, 'bar'), [
                'scales'  => ['x' => ['stacked' => true], 'y' => ['stacked' => true]],
            ]),
        ];
    }

    /* ────────────────────────────────────────────────────────────── */

    protected function commonOptions(array $options, string $type): array
    {
        return [
            'responsive'          => true,
            'maintainAspectRatio' => false,
            'interaction'         => ['mode' => 'index', 'intersect' => false],
            'plugins'             => [
                'legend'  => ['position' => $options['legend'] ?? 'top', 'labels' => ['boxWidth' => 10, 'padding' => 12]],
                'tooltip' => [
                    'backgroundColor' => 'hsl(var(--popover))',
                    'titleColor'      => 'hsl(var(--popover-foreground))',
                    'bodyColor'       => 'hsl(var(--popover-foreground))',
                    'borderColor'     => 'hsl(var(--border))',
                    'borderWidth'     => 1,
                    'padding'         => 10,
                    'boxPadding'      => 6,
                ],
            ],
            'scales' => $type === 'doughnut' ? [] : [
                'x' => [
                    'grid' => ['display' => false, 'drawBorder' => false],
                    'ticks' => ['color' => 'hsl(var(--muted-foreground))', 'font' => ['size' => 11]],
                ],
                'y' => [
                    'beginAtZero' => true,
                    'grid' => ['color' => 'hsl(var(--border))', 'drawBorder' => false],
                    'ticks' => ['color' => 'hsl(var(--muted-foreground))', 'font' => ['size' => 11]],
                ],
            ],
        ];
    }

    /** Wrap hsl(...) into an rgba-style semi-transparent form the browser can resolve. */
    protected function alpha(string $color, float $alpha): string
    {
        // Chart.js accepts raw CSS strings; we emit an `color-mix` fallback.
        // CSS `color-mix(in srgb, <color> <alpha>%, transparent)` is supported in all major browsers.
        $pct = (int) round($alpha * 100);
        return "color-mix(in srgb, $color $pct%, transparent)";
    }

    /** Expose the viz palette for Chart.js-less sparkline rendering. */
    public function palette(): array
    {
        return self::VIZ_PALETTE;
    }

    public function toneColor(string $tone): string
    {
        return self::TONE_COLOR[$tone] ?? self::TONE_COLOR['muted'];
    }
}
