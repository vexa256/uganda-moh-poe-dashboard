{{--
  x-ui.section-heading · small-caps section divider inside a page
  ---------------------------------------------------------------
  Props:
    title    required
    caption  optional one-line caption
    action   slot — right-aligned action (button, link)
--}}
@props([
    'title' => null,
    'caption' => null,
])

<div {{ $attributes->class(['flex items-end justify-between gap-3 mb-3']) }}>
    <div class="min-w-0">
        @if($title)<p class="eyebrow">{{ $title }}</p>@endif
        @if($caption)<p class="text-[13px] text-muted-foreground">{{ $caption }}</p>@endif
    </div>
    @isset($action)
        <div class="shrink-0 flex items-center gap-1.5">{{ $action }}</div>
    @endisset
</div>
