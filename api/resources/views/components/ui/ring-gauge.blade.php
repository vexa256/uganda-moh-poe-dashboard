{{--
  x-ui.ring-gauge · circular SVG gauge (used on 7-1-7 board, dashboards)
  ---------------------------------------------------------------
  Props:
    percent   0..100
    target    optional target label ("≤ 7 days")
    label     caption under the ring
    size      sm (48) | md (80) | lg (120)
    tone      success (default) | warning | critical | info | brand
--}}
@props([
    'percent' => 0,
    'target'  => null,
    'label'   => null,
    'size'    => 'md',
    'tone'    => 'success',
])

@php
    $percent = max(0, min(100, (float) $percent));
    $px = match($size) { 'sm' => 48, 'lg' => 120, default => 80 };
    $radius = ($px / 2) - 8;
    $circumference = 2 * M_PI * $radius;
    $offset = $circumference * (1 - $percent / 100);
    $ringStroke = 8;

    $toneVar = match($tone) {
        'warning'  => 'warning',
        'critical' => 'critical',
        'info'     => 'info',
        'brand'    => 'brand',
        'danger'   => 'critical',
        default    => 'success',
    };
@endphp

<div {{ $attributes->class(['flex flex-col items-center text-center gap-2']) }}>
    <div class="ring-gauge" style="height: {{ $px }}px; width: {{ $px }}px;">
        <svg viewBox="0 0 {{ $px }} {{ $px }}" class="-rotate-90" width="{{ $px }}" height="{{ $px }}" aria-hidden="true">
            <circle cx="{{ $px/2 }}" cy="{{ $px/2 }}" r="{{ $radius }}" fill="none" stroke="hsl(var(--muted))" stroke-width="{{ $ringStroke }}" />
            <circle cx="{{ $px/2 }}" cy="{{ $px/2 }}" r="{{ $radius }}" fill="none"
                    stroke="hsl(var(--{{ $toneVar }}))"
                    stroke-width="{{ $ringStroke }}" stroke-linecap="round"
                    stroke-dasharray="{{ number_format($circumference, 3) }}"
                    stroke-dashoffset="{{ number_format($offset, 3) }}" />
        </svg>
        <div class="ring-gauge-label">
            <span class="text-lg font-bold leading-none">{{ (int) round($percent) }}%</span>
            @if($target)
                <span class="text-[10px] text-muted-foreground mt-0.5">{{ $target }}</span>
            @endif
        </div>
    </div>
    @if($label)
        <p class="text-[12px] font-semibold">{{ $label }}</p>
    @endif
</div>
