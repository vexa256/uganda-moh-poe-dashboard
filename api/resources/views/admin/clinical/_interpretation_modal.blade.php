{{--
    Chart-Interpretation Modal — Paranoid v2 Clinical Library brief §8.

    Generic seven-point interpretation modal. Wraps any chart on a clin-*
    view. Opens by default the first time the user encounters the chart on
    each session (via $chartId persisted in sessionStorage).

    Required props:
      $chartId   string — unique slug per chart, used as the storage key
      $title     string — what this chart shows, in one plain sentence
      $how       string — how to read it
      $informative string — what an informative pattern looks like
      $concerning  string — what a concerning pattern looks like
      $whatToDo    string — what to do if you see the concerning pattern
      $cantTell    string — what this chart cannot tell you
      $source      string — where the data comes from in plain language

    Usage:
      @include('admin.clinical._interpretation_modal', [
          'chartId'    => 'clin-diseases.tier-donut',
          'title'      => '...',
          'how'        => '...',
          'informative'=> '...',
          'concerning' => '...',
          'whatToDo'   => '...',
          'cantTell'   => '...',
          'source'     => '...',
      ])
--}}
@php $chartId = $chartId ?? 'unknown-chart'; @endphp

<div
    x-data="{
        open: false,
        seen: false,
        bootInterpretation() {
            try {
                this.seen = !! window.sessionStorage.getItem('clin-int-seen.' + @json($chartId));
            } catch (e) { this.seen = true; }
            if (! this.seen) {
                this.open = true;
                try { window.sessionStorage.setItem('clin-int-seen.' + @json($chartId), '1'); } catch (e) {}
            }
        }
    }"
    x-init="bootInterpretation()"
    class="contents"
>
    <button type="button"
            class="text-[11px] text-brand hover:underline focus-visible:outline focus-visible:outline-2"
            aria-label="How to read this chart"
            @click="open = true">
        How to read this
    </button>

    <div x-cloak x-show="open" x-transition.opacity
         class="fixed inset-0 z-[70] bg-black/40"
         @click="open = false" aria-hidden="true"></div>

    <div x-cloak x-show="open" x-transition
         class="fixed inset-x-0 top-1/2 z-[71] mx-auto w-full max-w-md -translate-y-1/2 rounded-lg border border-border bg-background p-5 shadow-2xl"
         role="dialog" aria-modal="true"
         aria-labelledby="int-{{ $chartId }}-h"
         @keydown.escape.window="open = false">
        <header class="mb-3 flex items-start justify-between gap-3">
            <div>
                <p class="text-[10px] uppercase tracking-wider text-muted-foreground">How to read this chart</p>
                <h3 id="int-{{ $chartId }}-h" class="mt-0.5 text-[14px] font-semibold">{{ $title ?? '—' }}</h3>
            </div>
            <button type="button"
                    class="rounded-md border border-border px-2 py-1 text-[11px] hover:bg-accent"
                    @click="open = false"
                    aria-label="Close">Close</button>
        </header>

        <dl class="space-y-2.5 text-[12px] leading-relaxed">
            @if (! empty($how))
                <div>
                    <dt class="font-semibold">How to read it</dt>
                    <dd class="text-muted-foreground">{{ $how }}</dd>
                </div>
            @endif
            @if (! empty($informative))
                <div>
                    <dt class="font-semibold">What "informative" looks like</dt>
                    <dd class="text-muted-foreground">{{ $informative }}</dd>
                </div>
            @endif
            @if (! empty($concerning))
                <div>
                    <dt class="font-semibold">What "concerning" looks like</dt>
                    <dd class="text-muted-foreground">{{ $concerning }}</dd>
                </div>
            @endif
            @if (! empty($whatToDo))
                <div>
                    <dt class="font-semibold">What to do if you see the concerning pattern</dt>
                    <dd class="text-muted-foreground">{{ $whatToDo }}</dd>
                </div>
            @endif
            @if (! empty($cantTell))
                <div>
                    <dt class="font-semibold">What this chart cannot tell you</dt>
                    <dd class="text-muted-foreground">{{ $cantTell }}</dd>
                </div>
            @endif
            @if (! empty($source))
                <div>
                    <dt class="font-semibold">Where the data comes from</dt>
                    <dd class="text-muted-foreground">{{ $source }}</dd>
                </div>
            @endif
        </dl>

        <footer class="mt-4 flex justify-end">
            <button type="button"
                    class="rounded-md bg-brand px-3 py-1.5 text-[12px] font-semibold text-white hover:bg-brand/90"
                    @click="open = false">Got it</button>
        </footer>
    </div>
</div>
