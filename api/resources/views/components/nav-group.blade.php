@props([
    'title' => '',
    'open' => false,
    'count' => null,
    'icon' => null,
])

<li x-data="{ open: {{ $open ? 'true' : 'false' }} }">
    {{-- Group header button --}}
    <button type="button"
            @click="open = !open"
            class="nav-group-link w-full flex items-center gap-3 rounded-lg px-2.5 py-2 text-sm font-medium text-ink-300 hover:text-white hover:bg-ink-800/60 transition"
            :class="open ? 'text-white' : ''"
            :title="(!sidebar && !drawer) ? '{{ addslashes($title) }}' : ''"
            :aria-expanded="open.toString()">

        <span class="shrink-0 h-9 w-9 grid place-items-center rounded-lg border border-ink-800 bg-ink-800/40 text-ink-400 group-hover:text-brand-300 transition"
              :class="open ? 'border-brand-500/40 bg-brand-500/10 text-brand-300' : ''">
            <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                {{ $icon }}
            </svg>
        </span>

        <span class="flex-1 text-left truncate transition-opacity"
              :class="(sidebar || drawer) ? 'opacity-100' : 'lg:opacity-0 lg:w-0 lg:overflow-hidden'">
            {!! $title !!}
        </span>

        @if($count !== null)
            <span class="text-[10px] font-mono text-ink-500 shrink-0" :class="(sidebar || drawer) ? '' : 'lg:hidden'">{{ $count }}</span>
        @endif

        <svg class="h-4 w-4 text-ink-500 shrink-0 transition-transform duration-200"
             :class="[open ? 'rotate-90 text-ink-300' : '', (sidebar || drawer) ? '' : 'lg:hidden']"
             fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
    </button>

    {{-- Children --}}
    <ul x-show="open && (sidebar || drawer)"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 -translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        class="mt-0.5 ml-5 pl-3 border-l border-ink-800/70 space-y-0.5">
        {{ $slot }}
    </ul>
</li>
