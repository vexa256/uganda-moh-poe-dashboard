{{--
  x-ui.copilot-card · premium Copilot greeting strip
  ---------------------------------------------------------------
  Every primary page renders one of these at the top (UI_STANDARDS S6.1).

  Props:
    eyebrow      e.g. "PHEOC Copilot · National"
    greeting     one-sentence greeting (first name is injected by the
                 layout into a span with x-text; callers pass the trail)
    body         one-paragraph narrative
    tone         brand (default) | info | success | warning | critical
    module       label to show in a brand badge (optional)
    reasoning    array of rule citations; rendered as a tooltip / popover
                 (optional)
--}}
@props([
    'eyebrow'    => 'PHEOC Copilot',
    'greeting'   => null,     // e.g. "here's your operational brief"
    'body'       => null,
    'tone'       => 'brand',
    'module'     => null,
    'reasoning'  => [],
])

@php
    $toneGrad = match($tone) {
        'info'     => 'from-info/90 to-info/70',
        'success'  => 'from-success/90 to-success/70',
        'warning'  => 'from-warning/90 to-warning/70',
        'critical' => 'from-critical/90 to-critical/60',
        default    => 'from-brand to-info',
    };
@endphp

<section {{ $attributes->class(['relative overflow-hidden rounded-2xl border shadow-elevation-2 mb-6']) }}>
    <div class="absolute inset-0 bg-gradient-to-br {{ $toneGrad }}" aria-hidden="true"></div>
    <div class="absolute inset-0 bg-grid-soft opacity-20 mix-blend-overlay" aria-hidden="true"></div>
    <div class="absolute -top-20 -right-20 h-60 w-60 rounded-full bg-white/20 blur-3xl" aria-hidden="true"></div>

    <div class="relative p-5 sm:p-7 text-white">
        <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-5">

            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="eyebrow !text-white/75 !tracking-[.16em]">{{ $eyebrow }}</span>
                    @if($module)
                        <span class="badge !text-white !bg-white/20 !border-white/25 backdrop-blur-sm">
                            <span class="h-1.5 w-1.5 rounded-full bg-success"></span>
                            {{ $module }}
                        </span>
                    @endif
                </div>

                <h2 class="mt-2 text-xl sm:text-2xl font-semibold leading-tight">
                    <span x-text="'Good ' + (new Date().getHours() < 12 ? 'morning' : (new Date().getHours() < 18 ? 'afternoon' : 'evening')) + ', ' + (copilotFirstName() || 'there') + '.'"></span>
                    @if($greeting)
                        <span class="text-white/90 font-normal"> {{ $greeting }}</span>
                    @endif
                </h2>

                @if($body)
                    <p class="mt-2 text-[14px] sm:text-[15px] leading-relaxed text-white/90 max-w-3xl">
                        {{ $body }}
                    </p>
                @endif

                @isset($aside)
                    <div class="mt-4">{{ $aside }}</div>
                @endisset
            </div>

            @isset($right)
                <div class="shrink-0">{{ $right }}</div>
            @endisset
        </div>

        @if(!empty($reasoning))
            <details class="mt-4 text-[11px] text-white/75 leading-snug">
                <summary class="cursor-pointer hover:text-white">Why the Copilot says this</summary>
                <ul class="mt-2 ml-4 list-disc space-y-0.5">
                    @foreach($reasoning as $r)
                        <li>{{ $r }}</li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
</section>
