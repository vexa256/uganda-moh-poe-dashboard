{{--
    Alert Operations · Coach drawer + master tour wizard
    Per Paranoid v2 Alert Operations brief §9.

    Renders:
      · "Walk me through this view" master-tour trigger
      · "What would you like to do?" wizard launcher
      · A slide-in drawer answering the 10 brief-mandated questions for the
        current view in plain language for a non-public-health audience

    Usage in any alert-* view:
      @include('admin.alertops._coach', ['sectionKey' => 'alert-followups'])
--}}
@php
    $coachAll = trans('alertops_coach');
    $manifest = $coachAll[$sectionKey] ?? null;
    $common   = $coachAll['common'] ?? [];
@endphp
@if (is_array($manifest))
<div x-data="{ coachOpen: false, wizardOpen: false }" class="contents" data-alertops-coach-root="{{ $sectionKey }}">
    <button type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1 text-[11px] font-medium hover:bg-accent focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand"
            aria-haspopup="dialog"
            x-bind:aria-expanded="coachOpen.toString()"
            @click="coachOpen = true"
            @keydown.window.alt.h.prevent="coachOpen = true">
        {{ $common['tour_label'] ?? 'Walk me through this view' }}
    </button>

    <button type="button"
            class="inline-flex items-center gap-1.5 rounded-md bg-brand px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-brand/90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand"
            aria-haspopup="menu"
            x-bind:aria-expanded="wizardOpen.toString()"
            @click="wizardOpen = ! wizardOpen">
        {{ $common['invocation_label'] ?? 'What would you like to do?' }}
    </button>

    {{-- Wizard launcher menu — anchored under the button --}}
    <div x-cloak x-show="wizardOpen" x-transition.opacity
         class="fixed inset-0 z-[70]"
         @click="wizardOpen = false"></div>
    <div x-cloak x-show="wizardOpen" x-transition
         class="absolute right-0 mt-9 z-[71] w-72 rounded-md border border-border bg-background shadow-lg"
         role="menu">
        <div class="px-3 py-2 border-b border-border text-[11px] uppercase tracking-wide text-muted-foreground">
            Pick a path
        </div>
        <ul class="p-1">
            @foreach ($manifest['wizard_actions'] ?? [] as $a)
                <li>
                    <button type="button" role="menuitem"
                            class="w-full text-left px-3 py-2 rounded text-[12.5px] hover:bg-accent"
                            data-wizard-action="{{ $a['key'] }}"
                            @click="wizardOpen = false; window.dispatchEvent(new CustomEvent('alertops-wizard', { detail: { section: '{{ $sectionKey }}', action: '{{ $a['key'] }}' } }))">
                        {{ $a['label'] }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>

    {{-- Coach drawer --}}
    <div x-cloak x-show="coachOpen" x-transition.opacity
         class="fixed inset-0 z-[80] bg-black/40"
         @click="coachOpen = false" aria-hidden="true"></div>
    <aside x-cloak x-show="coachOpen"
           x-transition:enter="transition transform duration-150 ease-out"
           x-transition:enter-start="translate-x-full"
           x-transition:enter-end="translate-x-0"
           x-transition:leave="transition transform duration-150 ease-in"
           x-transition:leave-start="translate-x-0"
           x-transition:leave-end="translate-x-full"
           @keydown.escape.window="coachOpen = false"
           class="fixed inset-y-0 right-0 z-[81] flex w-full max-w-[560px] flex-col border-l border-border bg-background shadow-2xl"
           role="dialog" aria-modal="true" aria-labelledby="alertops-coach-h-{{ $sectionKey }}">
        <header class="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
            <div>
                <p class="text-[10px] uppercase tracking-wider text-muted-foreground">
                    {{ $common['drawer_heading'] ?? 'Reading Alert Operations' }}
                </p>
                <h2 id="alertops-coach-h-{{ $sectionKey }}" class="mt-0.5 text-[15px] font-semibold">
                    {{ $manifest['title'] ?? $sectionKey }}
                </h2>
                @if (! empty($manifest['audience']))
                    <p class="mt-0.5 text-[11px] text-muted-foreground">For: {{ $manifest['audience'] }}</p>
                @endif
            </div>
            <button type="button"
                    class="rounded-md border border-border bg-background px-2 py-1 text-[11px] hover:bg-accent"
                    @click="coachOpen = false"
                    aria-label="{{ $common['close_label'] ?? 'Close' }}">Close</button>
        </header>

        <div class="flex-1 overflow-y-auto px-5 py-4 text-[12.5px] leading-relaxed text-foreground">
            @foreach ([
                'q1_where_am_i'      => 'Where you are',
                'q2_what_can_i_do'   => 'What you can do here',
                'q3_anchor'          => 'What the anchor shows',
                'q4_eye_lands_first' => 'How to read it',
                'q5_filters'         => 'What the filters do',
                'q6_numbers'         => 'What the numbers mean',
                'q7_good'            => 'What good looks like',
                'q8_concerning'      => 'What concerning looks like',
                'q9_status_updates'  => 'When state changes',
                'q10_next_view'      => 'What to look at next',
            ] as $slot => $heading)
                @php $val = $manifest[$slot] ?? null; @endphp
                @if ($val)
                    <section class="mb-5">
                        <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">{{ $heading }}</h3>
                        @if (is_array($val))
                            <ul class="list-disc space-y-1 pl-5">
                                @foreach ($val as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        @else
                            <p>{{ $val }}</p>
                        @endif
                    </section>
                @endif
            @endforeach

            <footer class="mt-4 space-y-1.5 border-t border-border pt-3 text-[11px] text-muted-foreground">
                @if (! empty($common['pii_notice']))      <p>{{ $common['pii_notice'] }}</p> @endif
                @if (! empty($common['reminder_notice'])) <p>{{ $common['reminder_notice'] }}</p> @endif
                @if (! empty($common['simulation_notice']))<p>{{ $common['simulation_notice'] }}</p> @endif
                @if (! empty($common['fallback_notice'])) <p>{{ $common['fallback_notice'] }}</p> @endif
            </footer>
        </div>
    </aside>
</div>
@endif
