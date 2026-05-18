@props([
    'label' => '',
    'accent' => 'brand', // brand|sky|rose|amber|emerald|violet|slate
])

@php
    $accentLine = match($accent) {
        'rose'    => 'from-rose-500/60',
        'sky'     => 'from-sky-500/60',
        'amber'   => 'from-amber-500/60',
        'emerald' => 'from-emerald-500/60',
        'violet'  => 'from-violet-500/60',
        'slate'   => 'from-slate-500/60',
        default   => 'from-brand-500/60',
    };
@endphp

<div>
    <div class="flex items-center gap-2 px-2 mb-2 transition-opacity"
         :class="(sidebar || drawer) ? 'opacity-100' : 'lg:opacity-0 lg:h-0 lg:mb-0 lg:overflow-hidden'">
        <span class="text-[10px] uppercase tracking-[0.18em] font-semibold text-ink-500">{{ $label }}</span>
        <span class="flex-1 h-px bg-gradient-to-r {{ $accentLine }} to-transparent"></span>
    </div>
    <ul class="space-y-0.5">
        {{ $slot }}
    </ul>
</div>
