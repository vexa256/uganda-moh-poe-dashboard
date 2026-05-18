{{--
  x-ui.sparkline · inline bar sparkline (CSS-only, no JS)
  ---------------------------------------------------------------
  Props:
    points  array<int|float>
    tone    brand (default) | success | warning | critical | info
    height  px (default 24)
--}}
@props([
    'points' => [],
    'tone'   => 'brand',
    'height' => 24,
])

@php
    $max = max($points ?: [1]) ?: 1;
    $toneBg = match($tone) {
        'success'  => 'bg-success/30',
        'warning'  => 'bg-warning/30',
        'critical' => 'bg-critical/30',
        'info'     => 'bg-info/30',
        default    => 'bg-brand/30',
    };
@endphp

<div {{ $attributes->class(['flex items-end gap-0.5']) }} style="height: {{ (int) $height }}px">
    @foreach($points as $p)
        @php $h = max(6, (int) round(((float) $p / $max) * 100)); @endphp
        <span class="flex-1 rounded-sm {{ $toneBg }}" style="height: {{ $h }}%" aria-hidden="true"></span>
    @endforeach
</div>
