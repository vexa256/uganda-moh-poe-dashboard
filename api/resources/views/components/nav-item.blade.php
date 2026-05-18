@props([
    'href' => '#',
    'label' => '',
    'active' => false,
    'badge' => null,
    'badgeTone' => 'slate',
    'icon' => null,
])

<li>
    <a href="{{ $href }}"
       @class([
           'nav-link group flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm font-medium transition',
           'text-ink-300 hover:text-white hover:bg-ink-800/60' => !$active,
           'is-active text-white' => $active,
       ])
       :title="(!sidebar && !drawer) ? '{{ addslashes($label) }}' : ''">

        {{-- Icon --}}
        <span @class([
            'shrink-0 h-9 w-9 grid place-items-center rounded-lg border transition',
            'border-ink-800 bg-ink-800/40 text-ink-400 group-hover:text-brand-300 group-hover:border-brand-500/40' => !$active,
            'border-brand-500/50 bg-brand-500/15 text-brand-200 shadow-[inset_0_0_0_1px_rgba(90,158,255,0.15)]' => $active,
        ])>
            <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                {{ $icon }}
            </svg>
        </span>

        {{-- Label --}}
        <span class="flex-1 truncate transition-opacity"
              :class="(sidebar || drawer) ? 'opacity-100' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
            {{ $label }}
        </span>

        {{-- Badge --}}
        @if($badge)
            <span @class([
                'text-[10px] font-bold font-mono px-1.5 py-0.5 rounded-md border shrink-0 transition',
                pheoc_badge_classes($badgeTone),
            ]) :class="(sidebar || drawer) ? '' : 'lg:hidden'">
                {{ $badge }}
            </span>
        @endif
    </a>
</li>
