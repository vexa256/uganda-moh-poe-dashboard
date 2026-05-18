{{--
  Private partial for x-ui.timeline — single row renderer.
  Not a public component. Included by components.ui.timeline.
--}}
@php
    $toneClass = match($ev['severity_tone'] ?? 'default') {
        'warning'  => 'bg-warning-soft text-warning ring-warning/30',
        'critical' => 'bg-critical-soft text-critical ring-critical/30',
        'info'     => 'bg-info-soft text-info ring-info/30',
        'success'  => 'bg-success-soft text-success ring-success/30',
        default    => 'bg-muted text-muted-foreground ring-border',
    };
    $iconPath = $icons[$ev['icon'] ?? 'circle'] ?? $icons['circle'];
@endphp
<li class="relative pl-7">
    <span class="absolute left-0 top-1 grid place-items-center h-5 w-5 rounded-full ring-1 {{ $toneClass }}">
        <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $iconPath }}"/></svg>
    </span>
    <div>
        <p class="text-[13px] font-semibold leading-snug">
            {{ $ev['event_label'] ?? 'Event' }}
            <span class="text-muted-foreground font-normal"> · {{ $ev['at_relative'] ?? '—' }}</span>
        </p>
        <p class="text-[12px] text-muted-foreground leading-snug mt-0.5">
            {{ $ev['actor_label'] ?? '—' }}@if(!empty($ev['summary'])) — {{ $ev['summary'] }}@endif
        </p>
    </div>
</li>
