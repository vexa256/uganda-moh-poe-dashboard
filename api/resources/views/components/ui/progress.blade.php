{{--
  x-ui.progress · thin progress bar
  Props:
    percent  0..100
    tone     brand (default) | success | warning | critical | info
    size     sm (default 2px) | md (3px) | lg (8px)
--}}
@props([
    'percent' => 0,
    'tone'    => 'brand',
    'size'    => 'md',
])

@php
    $percent = max(0, min(100, (float) $percent));
    $sizeClass = match($size) {
        'sm'    => 'h-1.5 progress-sm',
        'lg'    => 'h-3 progress-lg',
        default => '',
    };
    $toneBg = match($tone) {
        'success'  => 'bg-success',
        'warning'  => 'bg-warning',
        'critical' => 'bg-critical',
        'info'     => 'bg-info',
        default    => 'bg-brand',
    };
@endphp

<div {{ $attributes->class(['progress', $sizeClass]) }} role="progressbar" aria-valuenow="{{ (int) $percent }}" aria-valuemin="0" aria-valuemax="100">
    <div class="progress-bar {{ $toneBg }}" style="width: {{ $percent }}%"></div>
</div>
