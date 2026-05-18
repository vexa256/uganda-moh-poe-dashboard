{{--
  x-ui.status-dot · tiny semantic status indicator
  ---------------------------------------------------------------
  Props:
    tone  success (default) | warning | critical | info | muted
    live  bool — add breathing pulse animation
--}}
@props([
    'tone' => 'success',
    'live' => false,
])

@php
    $toneClass = match($tone) {
        'success'  => 'bg-success',
        'warning'  => 'bg-warning',
        'critical' => 'bg-critical',
        'info'     => 'bg-info',
        'danger'   => 'bg-danger',
        'brand'    => 'bg-brand',
        default    => 'bg-muted-foreground',
    };
    $ringClass = match($tone) {
        'success'  => 'shadow-[0_0_0_3px_hsl(var(--success)/.18)]',
        'critical' => 'shadow-[0_0_0_3px_hsl(var(--critical)/.22)]',
        default    => '',
    };
@endphp

<span {{ $attributes->class([
    'inline-block h-2 w-2 rounded-full shrink-0',
    $toneClass,
    $ringClass,
]) }} aria-hidden="true"></span>
