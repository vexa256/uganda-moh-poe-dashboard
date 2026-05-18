{{--
    Clinical Library Coach drawer — Paranoid v2 brief §7.

    Renders a "What would you like to know?" trigger and a slide-in panel
    that answers the ten brief-mandated questions for the current section,
    in domain voice for a non-clinician audience, from
    lang/en/clinical_coach.php.

    Usage (in any clin-* index.blade.php):

        @include('admin.clinical._coach', ['sectionKey' => 'clin-diseases'])
--}}
@php
    $coachAll    = trans('clinical_coach');
    $manifest    = $coachAll[$sectionKey] ?? null;
    $coachCommon = $coachAll['common'] ?? [];
    $glossary    = $coachAll['glossary_common'] ?? [];
@endphp
@if (is_array($manifest))
    <div x-data="{ coachOpen: false, glossaryOpen: false }" class="contents" data-clin-coach-root="{{ $sectionKey }}">
        <button
            type="button"
            class="inline-flex items-center gap-1.5 rounded-md border border-border bg-background px-2.5 py-1 text-[11px] font-medium text-foreground hover:bg-accent focus-visible:outline focus-visible:outline-2 focus-visible:outline-brand"
            aria-haspopup="dialog"
            aria-expanded="false"
            x-bind:aria-expanded="coachOpen.toString()"
            @click="coachOpen = true"
            @keydown.window.alt.h.prevent="coachOpen = true">
            {{ $coachCommon['invocation_label'] ?? 'What would you like to know?' }}
        </button>

        <div
            x-cloak
            x-show="coachOpen"
            x-transition.opacity
            class="fixed inset-0 z-[80] bg-black/40"
            @click="coachOpen = false"
            aria-hidden="true"></div>

        <aside
            x-cloak
            x-show="coachOpen"
            x-transition:enter="transition transform duration-150 ease-out"
            x-transition:enter-start="translate-x-full"
            x-transition:enter-end="translate-x-0"
            x-transition:leave="transition transform duration-150 ease-in"
            x-transition:leave-start="translate-x-0"
            x-transition:leave-end="translate-x-full"
            @keydown.escape.window="coachOpen = false"
            class="fixed inset-y-0 right-0 z-[81] flex w-full max-w-[560px] flex-col border-l border-border bg-background shadow-2xl"
            role="dialog"
            aria-modal="true"
            aria-labelledby="clin-coach-heading-{{ $sectionKey }}">

            <header class="flex items-start justify-between gap-3 border-b border-border px-5 py-4">
                <div>
                    <p class="text-[10px] uppercase tracking-wider text-muted-foreground">
                        {{ $coachCommon['drawer_heading'] ?? 'Reading the Clinical Library' }}
                    </p>
                    <h2 id="clin-coach-heading-{{ $sectionKey }}" class="mt-0.5 text-[15px] font-semibold text-foreground">
                        {{ $manifest['title'] ?? $sectionKey }}
                    </h2>
                    @if (! empty($manifest['audience']))
                        <p class="mt-0.5 text-[11px] text-muted-foreground">
                            For: {{ $manifest['audience'] }}
                        </p>
                    @endif
                </div>
                <button type="button"
                        class="rounded-md border border-border bg-background px-2 py-1 text-[11px] hover:bg-accent"
                        @click="coachOpen = false"
                        aria-label="{{ $coachCommon['close_label'] ?? 'Close coach' }}">
                    Close
                </button>
            </header>

            <div class="flex-1 overflow-y-auto px-5 py-4 text-[12.5px] leading-relaxed text-foreground">
                {{-- Read-only notice — surfaces on every clin-* coach drawer --}}
                @if (! empty($coachCommon['read_only_notice']))
                    <aside class="mb-4 rounded-md border border-border bg-muted/40 px-3 py-2 text-[12px]">
                        <p class="font-semibold">This is a read-only reference</p>
                        <p class="mt-1 text-muted-foreground">{{ $coachCommon['read_only_notice'] }}</p>
                    </aside>
                @endif

                {{-- 1. Where am I? --}}
                <section class="mb-5">
                    <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Where you are</h3>
                    <p>{{ $manifest['q1_where_am_i'] }}</p>
                </section>

                {{-- 2. What can I do here? --}}
                <section class="mb-5">
                    <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">What you can do here</h3>
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ((array) $manifest['q2_what_can_i_do'] as $item)
                            <li>{{ $item }}</li>
                        @endforeach
                    </ul>
                </section>

                {{-- 3. Tabs --}}
                @if (! empty($manifest['q3_tabs']) && is_array($manifest['q3_tabs']))
                    <section class="mb-5">
                        <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">What each tab means</h3>
                        <dl class="space-y-1.5">
                            @foreach ($manifest['q3_tabs'] as $tabName => $tabDesc)
                                <div>
                                    <dt class="font-semibold">{{ $tabName }}</dt>
                                    <dd class="text-muted-foreground">{{ $tabDesc }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endif

                {{-- 4. Charts --}}
                @if (! empty($manifest['q4_charts']))
                    <section class="mb-5">
                        <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">What each chart shows</h3>
                        <ul class="list-disc space-y-1 pl-5">
                            @foreach ((array) $manifest['q4_charts'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- 5. Where the eye lands first --}}
                <section class="mb-5">
                    <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">How to read this</h3>
                    <p>{{ $manifest['q5_eye_lands_first'] }}</p>
                </section>

                {{-- 6. Filters --}}
                <section class="mb-5">
                    <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">What the filters do</h3>
                    <p>{{ $manifest['q6_filters'] }}</p>
                </section>

                {{-- 7. Numbers --}}
                @if (! empty($manifest['q7_numbers']))
                    <section class="mb-5">
                        <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">What the numbers mean</h3>
                        <ul class="list-disc space-y-1 pl-5">
                            @foreach ((array) $manifest['q7_numbers'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    </section>
                @endif

                {{-- 8. Concerning patterns --}}
                <section class="mb-5">
                    <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">What to raise with the clinical team</h3>
                    <p>{{ $manifest['q8_concerning'] }}</p>
                </section>

                {{-- 9. Data quality --}}
                <section class="mb-5">
                    <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">Data quality on this view</h3>
                    <p>{{ $manifest['q9_data_quality'] }}</p>
                </section>

                {{-- 10. Next view --}}
                <section class="mb-5">
                    <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">What to look at next</h3>
                    <p>{{ $manifest['q10_next_view'] }}</p>
                </section>

                {{-- Glossary --}}
                @if (! empty($glossary))
                    <section class="mb-3 rounded-md border border-border bg-muted/30 p-3">
                        <button type="button"
                                @click="glossaryOpen = !glossaryOpen"
                                class="flex w-full items-center justify-between text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                            <span>Glossary</span>
                            <span x-text="glossaryOpen ? '−' : '+'"></span>
                        </button>
                        <dl x-show="glossaryOpen" x-cloak x-collapse class="mt-2 space-y-2">
                            @foreach ($glossary as $entry)
                                <div>
                                    <dt class="text-[12px] font-semibold">{{ $entry['term'] }}</dt>
                                    <dd class="text-[12px] text-muted-foreground">{{ $entry['plain'] }}</dd>
                                </div>
                            @endforeach
                        </dl>
                    </section>
                @endif

                <footer class="mt-4 space-y-1.5 border-t border-border pt-3 text-[11px] text-muted-foreground">
                    @if (! empty($coachCommon['simulation_notice']))
                        <p>{{ $coachCommon['simulation_notice'] }}</p>
                    @endif
                    @if (! empty($coachCommon['fallback_notice']))
                        <p>{{ $coachCommon['fallback_notice'] }}</p>
                    @endif
                </footer>
            </div>
        </aside>
    </div>
@endif
