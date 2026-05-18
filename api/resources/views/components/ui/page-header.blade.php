{{--
  x-ui.page-header · standardised H1 + subtitle + action area
  ---------------------------------------------------------------
  Use INSIDE @section('content') when the mother layout's default
  header needs to be replaced by a richer one. Most views won't
  need this — the admin.layout already provides a header section.

  Props:
    eyebrow   short label above the title
    title     H1
    subtitle  one-line caption under the title
    actions   slot — right-aligned action buttons
--}}
@props([
    'eyebrow' => null,
    'title'   => null,
    'subtitle'=> null,
])

<div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between mb-6">
    <div class="min-w-0 flex-1">
        @if($eyebrow)
            <p class="eyebrow">{{ $eyebrow }}</p>
        @endif
        @if($title)
            <h1 class="display-md mt-1 truncate text-foreground">{{ $title }}</h1>
        @endif
        @if($subtitle)
            <p class="description mt-1 max-w-3xl">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="shrink-0 flex items-center gap-2 flex-wrap">{{ $actions }}</div>
    @endisset
</div>
