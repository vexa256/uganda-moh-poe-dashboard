{{--
  x-ui.alert · inline banner (not a modal)
  ---------------------------------------------------------------
  Props:
    tone   info (default) | success | warning | critical
    title  string
    icon   bool — show a tone-matched icon (default true)
--}}
@props([
    'tone'  => 'info',
    'title' => null,
    'icon'  => true,
])

@php
    $class = match($tone) {
        'success'  => 'alert-success',
        'warning'  => 'alert-warning',
        'critical' => 'alert-critical',
        default    => 'alert-info',
    };

    // SVG path for each tone
    $path = match($tone) {
        'success'  => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622',
        'warning'  => 'M12 9v2m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z',
        'critical' => 'M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        default    => 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    };
@endphp

<div {{ $attributes->class(['alert', $class]) }} role="alert">
    @if($icon)
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $path }}"/></svg>
    @endif
    @if($title)
        <div class="alert-title">{{ $title }}</div>
    @endif
    <div class="alert-description">{{ $slot }}</div>
</div>
