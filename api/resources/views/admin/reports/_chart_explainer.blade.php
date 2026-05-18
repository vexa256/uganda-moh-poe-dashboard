@php /*
    Per-chart explainer — the "?" button + 7-point wizard modal.
    Brief §8.1 mandates one of these on every chart in the rebuilt views.

    The 7-point wizard reads from lang/en/reports_coach.php under:
        '<reportKey>.charts.<chartKey>' => [
            'title'      => 'Headline of the chart',
            'shows'      => 'What this chart is showing, plain language.',
            'read'       => 'How to read it.',
            'good'       => 'What "good" looks like.',
            'concerning' => 'What "concerning" looks like.',
            'do'         => 'What to do if you see the concerning pattern.',
            'cant'       => 'What this chart cannot tell you.',
            'more'       => 'Where to learn more (deep-link copy).',
        ];

    Usage in a view:
        Mount once per view (any depth) using @include of this partial with
        the desired reportKey, e.g. reportKey => 'rpt-volume'.

        Then beside any chart card, a per-chart trigger:
        <button type="button" class="rpt-explain-btn"
                data-chart-key="funnel" aria-label="How to read this chart">?</button>

    The mounted partial owns ONE Alpine root + modal. Triggers are plain
    HTML buttons that fire a CustomEvent the partial listens for. So you
    can drop "?" buttons inside x-for loops without colliding x-data scopes.

    NOTE: Do not use Blade comments {{- - -}} for the docblock here — Blade
    treats the FIRST closing -- }} as the end of the outer comment, so any
    @include example inside became a real recursive include and OOM'd
    rendering. Using PHP comment instead.
*/ @endphp
@php
    $reportKey = $reportKey ?? '';
    $coachAll  = trans('reports_coach');
    $charts    = $coachAll[$reportKey]['charts'] ?? [];
@endphp
@if (is_array($charts) && ! empty($charts))
<div x-data="rptChartExplainer(@js($charts))"
     x-init="boot()"
     data-chart-explainer="{{ $reportKey }}">

    {{-- MODAL --}}
    <div x-cloak x-show="open"
         class="fixed inset-0 z-[85] bg-slate-950/70 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="open = false">
        <div class="bg-background w-full sm:max-w-lg sm:rounded-2xl border-l sm:border border-border shadow-2xl flex flex-col overflow-hidden max-h-[88vh]"
             @click.away="open = false"
             role="dialog" aria-modal="true" aria-labelledby="chart-explainer-h">
            <header class="px-5 sm:px-6 pt-5 pb-3 shrink-0 border-b border-border">
                <p class="text-[10.5px] uppercase tracking-[0.12em] text-muted-foreground font-semibold">How to read this chart</p>
                <h3 id="chart-explainer-h" class="mt-1 text-base sm:text-lg font-semibold" x-text="row?.title || 'Chart'"></h3>
            </header>
            <div class="overflow-y-auto px-5 sm:px-6 py-4 grow text-[12.5px] leading-relaxed text-foreground space-y-3">
                <p><span class="font-semibold">What it shows.</span> <span x-text="row?.shows"></span></p>
                <p><span class="font-semibold">How to read it.</span> <span x-text="row?.read"></span></p>
                <p><span class="font-semibold text-success">Good pattern.</span> <span x-text="row?.good"></span></p>
                <p><span class="font-semibold text-critical">Concerning pattern.</span> <span x-text="row?.concerning"></span></p>
                <p><span class="font-semibold">If concerning, do this.</span> <span x-text="row?.do"></span></p>
                <p><span class="font-semibold">It cannot tell you.</span> <span x-text="row?.cant"></span></p>
                <p class="text-[11.5px] text-muted-foreground pt-2 border-t border-border"
                   x-show="row?.more"><span class="font-semibold">Learn more.</span> <span x-text="row?.more"></span></p>
            </div>
            <footer class="px-5 sm:px-6 py-3 border-t border-border shrink-0 flex justify-end">
                <button type="button" class="btn btn-primary btn-sm" @click="open = false">Got it</button>
            </footer>
        </div>
    </div>
</div>

@push('scripts')
<script>
function rptChartExplainer(charts) {
    return {
        charts: charts || {},
        open: false,
        row: null,
        boot() {
            // Delegate listener — any element with [data-chart-key] anywhere
            // on the page opens the modal for that key. No collision with view
            // scope; works inside x-for loops, dynamic content, etc.
            document.addEventListener('click', (e) => {
                const btn = e.target.closest('[data-chart-key]');
                if (!btn) return;
                const key = btn.getAttribute('data-chart-key');
                if (!key) return;
                e.preventDefault();
                this.show(key);
            });
        },
        show(key) {
            const row = this.charts[key];
            if (!row) return;
            this.row  = row;
            this.open = true;
        },
    };
}
</script>
<style>
    /* The "?" button — visually consistent across views, always corner of the
       chart card. Uses theme tokens, no fixed colours. */
    .rpt-explain-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 22px; height: 22px; border-radius: 9999px;
        border: 1px solid var(--border);
        background: var(--background); color: var(--muted-foreground);
        font-size: 12px; font-weight: 700; line-height: 1;
        cursor: pointer; transition: all .12s ease;
    }
    .rpt-explain-btn:hover { background: var(--accent); color: var(--foreground); border-color: var(--foreground); }
    .rpt-explain-btn:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
</style>
@endpush
@endif
