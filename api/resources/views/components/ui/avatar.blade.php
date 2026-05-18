{{--
  x-ui.avatar · squircle user avatar with initials fallback
  Props:
    name  full name (used to derive initials)
    src   optional image URL
    size  xs|sm|md|lg  (default md)
    tone  brand (default) | muted
--}}
@props([
    'name' => '',
    'src'  => null,
    'size' => 'md',
    'tone' => 'brand',
])

@php
    $sizeClass = match($size) {
        'xs' => 'h-6 w-6 text-[10px]',
        'sm' => 'h-7 w-7 text-[11px]',
        'lg' => 'h-10 w-10 text-base',
        default => 'h-9 w-9 text-sm',
    };
    $toneClass = $tone === 'muted' ? 'bg-muted text-muted-foreground' : 'bg-brand-hero text-white';

    $initials = '??';
    if (trim((string) $name) !== '') {
        $parts = preg_split('/\s+/', trim((string) $name));
        $chars = array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2));
        $initials = strtoupper(implode('', $chars)) ?: '??';
    }
@endphp

<span {{ $attributes->class([
    'relative grid place-items-center rounded-lg font-semibold shadow-elevation-1 overflow-hidden shrink-0',
    $sizeClass,
    $toneClass,
]) }}>
    @if($src)
        <img src="{{ $src }}" alt="{{ $name }}" class="absolute inset-0 h-full w-full object-cover">
    @else
        <span>{{ $initials }}</span>
    @endif
</span>
