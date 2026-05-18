{{--
  x-ui.kpi · KPI tile with value, delta, sparkline
  ---------------------------------------------------------------
  Accepts either:
   - a pre-built payload from KpiBuilder::build() via :payload
   - or individual props (label, value, delta, etc.)

  Layout follows UI_STANDARDS S6.2 (stats row on every page).
--}}
@props([
    'payload'    => null,   // array from KpiBuilder::build()
    'label'      => null,
    'value'      => null,
    'display'    => null,
    'delta'      => null,
    'formatted_delta' => null,
    'direction'  => 'flat',
    'delta_tone' => 'flat',
    'caption'    => null,
    'tone'       => 'default',
    'spark'      => [],
    'icon'       => null,
])

@php
    if (is_array($payload)) {
        $label      = $payload['label']      ?? $label;
        $value      = $payload['value']      ?? $value;
        $display    = $payload['display']    ?? $display;
        $delta      = $payload['delta']      ?? $delta;
        $formatted_delta = $payload['formatted_delta'] ?? $formatted_delta;
        $direction  = $payload['direction']  ?? $direction;
        $delta_tone = $payload['delta_tone'] ?? $delta_tone;
        $caption    = $payload['caption']    ?? $caption;
        $tone       = $payload['tone']       ?? $tone;
        $spark      = $payload['spark']      ?? $spark;
        $icon       = $payload['icon']       ?? $icon;
    }

    $display = $display ?? ($value === null ? '—' : (string) $value);

    $toneStrip = match($tone) {
        'brand'    => 'from-brand/10',
        'info'     => 'from-info/10',
        'success'  => 'from-success/10',
        'warning'  => 'from-warning/10',
        'critical' => 'from-critical/10',
        default    => 'from-brand/10',
    };

    $toneIcon = match($tone) {
        'brand'    => 'bg-brand-soft text-brand-ink',
        'info'     => 'bg-info-soft text-info',
        'success'  => 'bg-success-soft text-success',
        'warning'  => 'bg-warning-soft text-warning',
        'critical' => 'bg-critical-soft text-critical',
        default    => 'bg-muted text-muted-foreground',
    };

    $deltaClass = match($delta_tone) {
        'up'   => 'kpi-delta-up',
        'down' => 'kpi-delta-down',
        default => 'kpi-delta-flat',
    };

    $sparkClass = match($tone) {
        'brand'    => 'bg-brand/30',
        'info'     => 'bg-info/30',
        'success'  => 'bg-success/30',
        'warning'  => 'bg-warning/30',
        'critical' => 'bg-critical/30',
        default    => 'bg-brand/30',
    };

    $iconPath = match($icon) {
        'users'   => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
        'alerts'  => 'M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6 6 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5',
        'check'   => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944',
        'clock'   => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
        'bolt'    => 'M13 10V3L4 14h7v7l9-11h-7z',
        'heart'   => 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z',
        default   => null,
    };
@endphp

<div {{ $attributes->class(['kpi kpi-glow group']) }}>
    <div class="absolute inset-x-0 top-0 h-1 bg-gradient-to-r {{ $toneStrip }} to-transparent" aria-hidden="true"></div>

    <div class="flex items-start justify-between gap-2">
        <span class="kpi-label">{{ $label }}</span>
        @if($iconPath)
            <span class="grid place-items-center h-6 w-6 rounded-md {{ $toneIcon }}">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $iconPath }}"/></svg>
            </span>
        @endif
    </div>

    <p class="kpi-value">{{ $display }}</p>

    @if($formatted_delta || $caption)
        <div class="mt-1.5 flex items-center gap-1.5 text-[11px] flex-wrap">
            @if($formatted_delta)
                <span class="kpi-delta {{ $deltaClass }}">{{ $formatted_delta }}</span>
            @endif
            @if($caption)
                <span class="text-muted-foreground">{{ $caption }}</span>
            @endif
        </div>
    @endif

    @if(!empty($spark))
        <div class="mt-3 -mx-1 h-6 flex items-end gap-0.5 opacity-80" aria-hidden="true">
            @php
                $max = max($spark ?: [1]) ?: 1;
            @endphp
            @foreach($spark as $v)
                @php $h = max(8, (int) round(($v / $max) * 100)); @endphp
                <span class="flex-1 rounded-sm {{ $sparkClass }}" style="height: {{ $h }}%"></span>
            @endforeach
        </div>
    @endif
</div>
