{{--
    Alert Operations · Chart-explainer modal (7-point structure)
    Per Paranoid v2 Alert Operations brief §9 + §16.15.

    Required props:
      $explainerId   — unique slug (e.g. 'alert-followups.progress-ring')
      $title         — what this composition element shows in plain language
      $how           — how to read it
      $good          — what "good" looks like
      $concerning    — what "concerning" looks like
      $whatToDo      — what to do if you see the concerning pattern
      $cantTell      — what this element cannot tell you
      $whereToLearn  — link target (route name or url)
      $whereToLabel  — human label for the link
--}}
@php $explainerId = $explainerId ?? 'unknown-element'; @endphp
<div x-data="{ open: false }" class="contents">
    <button type="button"
            class="text-[11px] text-brand hover:underline focus-visible:outline focus-visible:outline-2"
            aria-label="Explain this"
            @click="open = true">
        Explain this
    </button>

    <div x-cloak x-show="open" x-transition.opacity
         class="fixed inset-0 z-[70] bg-black/40"
         @click="open = false" aria-hidden="true"></div>

    <div x-cloak x-show="open" x-transition
         class="fixed inset-x-0 top-1/2 z-[71] mx-auto w-full max-w-md -translate-y-1/2 rounded-lg border border-border bg-background p-5 shadow-2xl"
         role="dialog" aria-modal="true"
         aria-labelledby="exp-{{ $explainerId }}-h"
         @keydown.escape.window="open = false">
        <header class="mb-3 flex items-start justify-between gap-3">
            <div>
                <p class="text-[10px] uppercase tracking-wider text-muted-foreground">Reading this</p>
                <h3 id="exp-{{ $explainerId }}-h" class="mt-0.5 text-[14px] font-semibold">{{ $title ?? '—' }}</h3>
            </div>
            <button type="button"
                    class="rounded-md border border-border px-2 py-1 text-[11px] hover:bg-accent"
                    @click="open = false">Close</button>
        </header>

        <dl class="space-y-2.5 text-[12px] leading-relaxed">
            @if (! empty($how))
                <div><dt class="font-semibold">How to read it</dt><dd class="text-muted-foreground">{{ $how }}</dd></div>
            @endif
            @if (! empty($good))
                <div><dt class="font-semibold">What good looks like</dt><dd class="text-muted-foreground">{{ $good }}</dd></div>
            @endif
            @if (! empty($concerning))
                <div><dt class="font-semibold">What concerning looks like</dt><dd class="text-muted-foreground">{{ $concerning }}</dd></div>
            @endif
            @if (! empty($whatToDo))
                <div><dt class="font-semibold">What to do</dt><dd class="text-muted-foreground">{{ $whatToDo }}</dd></div>
            @endif
            @if (! empty($cantTell))
                <div><dt class="font-semibold">What this cannot tell you</dt><dd class="text-muted-foreground">{{ $cantTell }}</dd></div>
            @endif
            @if (! empty($whereToLearn))
                <div><dt class="font-semibold">Where to learn more</dt>
                    <dd class="text-muted-foreground">
                        <a href="{{ $whereToLearn }}" class="text-brand hover:underline">{{ $whereToLabel ?? 'Open related view' }}</a>
                    </dd>
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
