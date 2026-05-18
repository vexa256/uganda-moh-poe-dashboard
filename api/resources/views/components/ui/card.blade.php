{{--
  x-ui.card · shadcn-identical card surface
  ---------------------------------------------------------------
  Usage:
    <x-ui.card>
      <x-slot:title>Headline</x-slot:title>
      <x-slot:description>One-line caption</x-slot:description>
      …body…
      <x-slot:footer>…actions…</x-slot:footer>
    </x-ui.card>

  Tone accent (optional): `tone="brand|info|success|warning|critical"`
  adds a 1 px top border strip to communicate state without shouting.

  Attributes forwarded via $attributes merge.
--}}
@props([
    'tone'        => null,              // brand|info|success|warning|critical|null
    'title'       => null,
    'description' => null,
    'hover'       => false,
    'compact'     => false,
    'footer'      => null,
])

@php
    $toneStrip = match($tone) {
        'brand'    => 'before:bg-brand',
        'info'     => 'before:bg-info',
        'success'  => 'before:bg-success',
        'warning'  => 'before:bg-warning',
        'critical' => 'before:bg-critical',
        default    => '',
    };
    $hoverClass = $hover    ? 'card-hover'    : '';
    $padContent = $compact  ? 'p-4'           : 'p-5 sm:p-6';
    $padHeader  = $compact  ? 'p-4 pb-0'      : 'p-5 pb-0 sm:p-6 sm:pb-0';
@endphp

<div {{ $attributes->class([
    'relative card',
    $hoverClass,
    $tone ? 'overflow-hidden before:absolute before:inset-x-0 before:top-0 before:h-1 ' . $toneStrip : '',
]) }}>
    @if($title || $description || isset($header))
        <div class="flex flex-col space-y-1 {{ $padHeader }}">
            @if($title)
                <p class="card-title">{{ $title }}</p>
            @endif
            @if($description)
                <p class="card-description">{{ $description }}</p>
            @endif
            {{ $header ?? '' }}
        </div>
    @endif

    @if(trim($slot))
        <div class="{{ $padContent }} {{ ($title || $description || isset($header)) ? 'pt-4' : '' }}">
            {{ $slot }}
        </div>
    @endif

    @isset($footer)
        <div class="flex items-center {{ $padContent }} pt-0 gap-2">
            {{ $footer }}
        </div>
    @endisset
</div>
