{{--
  x-ui.button · shadcn-identical button
  ---------------------------------------------------------------
  Props:
    variant  default | brand | destructive | success | outline | secondary | soft-brand | soft-info | ghost | link
    size     xs | sm | md | lg | icon | icon-xs | icon-lg
    as       button (default) | a | submit
    href     (when as="a") link target
    disabled bool
    loading  bool — shows a spinner and disables clicks

  All other attributes are forwarded.
--}}
@props([
    'variant'  => 'default',
    'size'     => 'sm',
    'as'       => 'button',
    'href'     => null,
    'disabled' => false,
    'loading'  => false,
    'type'     => 'button',
])

@php
    $variantClass = match($variant) {
        'brand'        => 'btn-brand',
        'destructive'  => 'btn-destructive',
        'success'      => 'btn-success',
        'outline'      => 'btn-outline',
        'secondary'    => 'btn-secondary',
        'soft-brand'   => 'btn-soft-brand',
        'soft-info'    => 'btn-soft-info',
        'ghost'        => 'btn-ghost',
        'link'         => 'btn-link',
        default        => 'btn-default',
    };
    $sizeClass = match($size) {
        'xs'      => 'btn-xs',
        'md'      => 'btn-md',
        'lg'      => 'btn-lg',
        'icon'    => 'btn-icon',
        'icon-xs' => 'btn-icon-xs',
        'icon-lg' => 'btn-icon-lg',
        default   => 'btn-sm',
    };
    $finalClass = trim("btn {$variantClass} {$sizeClass}");

    $tag = $as === 'a' ? 'a' : 'button';
@endphp

@if($as === 'a')
    <a
        href="{{ $href ?? '#' }}"
        @if($disabled) aria-disabled="true" tabindex="-1" @endif
        {{ $attributes->class([$finalClass, 'opacity-50 pointer-events-none' => $disabled || $loading]) }}
    >
        @if($loading)
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
        @endif
        {{ $slot }}
    </a>
@else
    <button
        type="{{ $type }}"
        @disabled($disabled || $loading)
        {{ $attributes->class([$finalClass]) }}
    >
        @if($loading)
            <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12a9 9 0 11-6.219-8.56"/></svg>
        @endif
        {{ $slot }}
    </button>
@endif
