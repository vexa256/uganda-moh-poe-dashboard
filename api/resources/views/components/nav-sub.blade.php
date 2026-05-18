@props([
    'href' => '#',
    'label' => '',
    'active' => false,
    'badge' => null,
    'badgeTone' => 'slate',
    'dotTone' => 'slate',
])

<li>
    <a href="{{ $href }}"
       @class([
           'group flex items-center gap-2.5 rounded-md px-2.5 py-1.5 text-[13px] transition',
           'text-ink-400 hover:text-white hover:bg-ink-800/50' => !$active,
           'text-white bg-ink-800/60' => $active,
       ])>
        <span @class(['h-1.5 w-1.5 rounded-full shrink-0 transition', pheoc_dot_classes($dotTone)])></span>
        <span class="flex-1 truncate">{{ $label }}</span>
        @if($badge)
            <span @class([
                'text-[10px] font-bold font-mono px-1.5 py-0.5 rounded-md border shrink-0',
                pheoc_badge_classes($badgeTone),
            ])>{{ $badge }}</span>
        @endif
    </a>
</li>
