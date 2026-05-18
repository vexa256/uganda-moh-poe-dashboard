{{--
  x-ui.timeline · vertical event feed (uses TimelineBuilder payload)
  ---------------------------------------------------------------
  Props:
    events  array of event rows from TimelineBuilder::build()
    grouped bool — if true, expects TimelineBuilder::grouped() payload
--}}
@props([
    'events'  => [],
    'grouped' => false,
])

@php
    $render = function ($events) {
        return $events; // convenience
    };

    // Per-icon SVG path, keyed to TimelineBuilder `icon` field
    $icons = [
        'check'        => 'M9 12l2 2 4-4',
        'check-circle' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944',
        'alert'        => 'M12 9v2m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z',
        'repeat'       => 'M4 4v5h.01M20 20v-5h-.01M20 9a9 9 0 10-2.4 6.65L20 15',
        'message'      => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
        'clipboard'    => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
        'check-square' => 'M9 12l2 2 4-4m6-2a9 9 0 11-18 0 9 9 0 0118 0z',
        'shield'       => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622',
        'mail'         => 'M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z',
        'sparkle'      => 'M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z',
        'heart'        => 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z',
        'circle'       => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    ];

    $renderItems = function ($list) use ($icons) {
        return $list;
    };
@endphp

<div {{ $attributes->class(['relative']) }}>
    @if($grouped)
        @foreach($events as $groupKey => $groupEvents)
            <div class="mb-5">
                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">{{ $groupKey }}</p>
                <ul class="relative space-y-3 ml-3 before:absolute before:inset-y-1 before:left-[11px] before:w-px before:bg-border">
                    @foreach($groupEvents as $ev)
                        @include('components.ui.__timeline-item', ['ev' => $ev, 'icons' => $icons])
                    @endforeach
                </ul>
            </div>
        @endforeach
    @else
        <ul class="relative space-y-3 ml-3 before:absolute before:inset-y-1 before:left-[11px] before:w-px before:bg-border">
            @foreach($events as $ev)
                @include('components.ui.__timeline-item', ['ev' => $ev, 'icons' => $icons])
            @endforeach
        </ul>
    @endif

    @if(empty($events))
        <x-ui.empty-state icon="inbox" title="No events recorded yet." description="Timeline entries land here as soon as the alert gains activity." />
    @endif
</div>
