@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Country Analytics')

@section('content')
{{--
    Country Analytics — REBUILD.
    Question: "Uganda's surveillance signal in one composition — what's
    strong, what's weak, where to look first?"

    Anchor vocabulary: multi-dimensional scorecard composition. NOT a tab
    grid; NOT a Chart.js line chart. The composition is its own shape:
    three large rings on top (Reach · Response · Closure), three named
    leagues immediately below (PHEOC · District · POE), and a single
    eight-week trend strip at the bottom. The eye reads top-to-bottom in
    one motion, no tab clicking required for the headline answer.

    Reuse: BaseReportController data flow + filter wizard + coach drawer +
    data notes + insights + export buttons. New: inline-SVG ring + league
    + trend-strip primitives, per-chart explainer wizard.
--}}
<div x-data="rptCountryAnalytics()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('rpt-country-analytics', wizard.open || ask.open)"
     class="space-y-5">

    {{-- HEADER · executive band, no tabs visible at this level --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">National rollup · rpt-country-analytics</p>
            <h1 class="text-[18px] font-semibold">Uganda surveillance signal</h1>
            <p class="help-text mt-0.5">One composition. Three rings answer "is the system reaching, responding, closing?" Three leagues name where the issue is.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready"><span class="status-dot status-dot-live"></span><span x-text="windowLabel()"></span></span>
            @include('admin.reports._coach', ['reportKey' => 'rpt-country-analytics'])
            <button type="button" class="btn btn-ghost btn-sm" @click="ask.open = true">What would you like to know?</button>
            <button type="button" class="btn btn-ghost btn-sm" @click="openWizard()">Filters</button>
            <div class="inline-flex rounded-md border overflow-hidden" role="group" aria-label="Export options">
                <button type="button" class="btn btn-ghost btn-sm rounded-none border-r" @click="exportAs('CSV')">CSV</button>
                <button type="button" class="btn btn-ghost btn-sm rounded-none border-r" @click="exportAs('XLSX')">Excel</button>
                <button type="button" class="btn btn-ghost btn-sm rounded-none" @click="exportAs('PDF')">Print / PDF</button>
            </div>
        </div>
    </section>

    {{-- COLD STATE --}}
    <template x-if="!ready">
        <section class="card"><div class="card-content py-10 text-center space-y-3">
            <h2 class="text-[15px] font-semibold">Configure the period for this composition</h2>
            <p class="help-text">A period is required. The composition reads eight weeks behind it for the trend strip.</p>
            <button type="button" class="btn btn-primary" @click="openWizard()">Open filter wizard</button>
        </div></section>
    </template>

    {{-- ============================================================== --}}
    {{-- ANCHOR · the three rings · this is the dominant element        --}}
    {{-- ============================================================== --}}
    <template x-if="ready">
        <section class="rounded-3xl border border-border bg-gradient-to-br from-slate-50 to-white p-5 sm:p-7 shadow-sm">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 sm:gap-6">

                {{-- REACH RING --}}
                <article class="relative">
                    <button type="button" class="rpt-explain-btn absolute right-0 top-0" data-chart-key="reach_ring" aria-label="How to read the Reach ring">?</button>
                    <p class="text-[10.5px] uppercase tracking-[0.12em] text-muted-foreground font-semibold">Reach</p>
                    <p class="text-[12.5px] text-foreground mb-2">Are we screening the travellers we should be?</p>
                    <div class="flex items-end gap-4">
                        <svg viewBox="0 0 120 120" class="w-32 h-32 -rotate-90 shrink-0" aria-label="Reach ring chart">
                            <circle cx="60" cy="60" r="50" fill="none" stroke="rgba(15,23,42,0.08)" stroke-width="14"/>
                            <circle cx="60" cy="60" r="50" fill="none" :stroke="ringStroke(reach.pct)" stroke-width="14"
                                    stroke-linecap="round"
                                    :stroke-dasharray="`${ringDash(reach.pct)} ${ringCirc()}`" />
                        </svg>
                        <div class="min-w-0">
                            <p class="text-[36px] sm:text-[44px] font-semibold tabular-nums leading-none" x-text="pctText(reach.pct)"></p>
                            <p class="text-[11.5px] text-muted-foreground mt-1" x-text="`${formatNum(reach.numerator)} of ${formatNum(reach.target)} expected`"></p>
                            <p class="text-[11px] mt-1" :class="bandTone(reach.pct, 90, 70)" x-text="bandLabel(reach.pct, 90, 70)"></p>
                        </div>
                    </div>
                </article>

                {{-- RESPONSE RING --}}
                <article class="relative">
                    <button type="button" class="rpt-explain-btn absolute right-0 top-0" data-chart-key="response_ring" aria-label="How to read the Response ring">?</button>
                    <p class="text-[10.5px] uppercase tracking-[0.12em] text-muted-foreground font-semibold">Response</p>
                    <p class="text-[12.5px] text-foreground mb-2">When the alarm sounded, did anyone answer in time?</p>
                    <div class="flex items-end gap-4">
                        <svg viewBox="0 0 120 120" class="w-32 h-32 -rotate-90 shrink-0">
                            <circle cx="60" cy="60" r="50" fill="none" stroke="rgba(15,23,42,0.08)" stroke-width="14"/>
                            <circle cx="60" cy="60" r="50" fill="none" :stroke="ringStroke(response.pct)" stroke-width="14"
                                    stroke-linecap="round"
                                    :stroke-dasharray="`${ringDash(response.pct)} ${ringCirc()}`" />
                        </svg>
                        <div class="min-w-0">
                            <p class="text-[36px] sm:text-[44px] font-semibold tabular-nums leading-none" x-text="pctText(response.pct)"></p>
                            <p class="text-[11.5px] text-muted-foreground mt-1" x-text="`${formatNum(response.numerator)} of ${formatNum(response.denominator)} alerts`"></p>
                            <p class="text-[11px] mt-1" :class="bandTone(response.pct, 90, 80)" x-text="bandLabel(response.pct, 90, 80)"></p>
                        </div>
                    </div>
                </article>

                {{-- CLOSURE RING --}}
                <article class="relative">
                    <button type="button" class="rpt-explain-btn absolute right-0 top-0" data-chart-key="closure_ring" aria-label="How to read the Closure ring">?</button>
                    <p class="text-[10.5px] uppercase tracking-[0.12em] text-muted-foreground font-semibold">Closure</p>
                    <p class="text-[12.5px] text-foreground mb-2">Did the cycles we opened actually close?</p>
                    <div class="flex items-end gap-4">
                        <svg viewBox="0 0 120 120" class="w-32 h-32 -rotate-90 shrink-0">
                            <circle cx="60" cy="60" r="50" fill="none" stroke="rgba(15,23,42,0.08)" stroke-width="14"/>
                            <circle cx="60" cy="60" r="50" fill="none" :stroke="ringStroke(closure.pct)" stroke-width="14"
                                    stroke-linecap="round"
                                    :stroke-dasharray="`${ringDash(closure.pct)} ${ringCirc()}`" />
                        </svg>
                        <div class="min-w-0">
                            <p class="text-[36px] sm:text-[44px] font-semibold tabular-nums leading-none" x-text="pctText(closure.pct)"></p>
                            <p class="text-[11.5px] text-muted-foreground mt-1" x-text="`${formatNum(closure.numerator)} of ${formatNum(closure.denominator)} cycles`"></p>
                            <p class="text-[11px] mt-1" :class="bandTone(closure.pct, 80, 60)" x-text="bandLabel(closure.pct, 80, 60)"></p>
                        </div>
                    </div>
                </article>

            </div>
        </section>
    </template>

    {{-- ============================================================== --}}
    {{-- THREE-COLUMN LEAGUE — PHEOC · District · POE                   --}}
    {{-- Asymmetric vs the rings: rings are one row, leagues are one row --}}
    {{-- of three vertical columns. No tabs, no inner navigation.        --}}
    {{-- ============================================================== --}}
    <template x-if="ready">
        <section class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <template x-for="col in leagueCols" :key="col.key">
                <article class="card">
                    <div class="card-content !p-4">
                        <div class="flex items-center justify-between mb-3">
                            <div>
                                <p class="text-[10.5px] uppercase tracking-[0.12em] text-muted-foreground font-semibold" x-text="col.eyebrow"></p>
                                <h3 class="text-[14px] font-semibold mt-0.5" x-text="col.title"></h3>
                            </div>
                            <button type="button" class="rpt-explain-btn" data-chart-key="leagues" aria-label="How to read this league">?</button>
                        </div>

                        <ol class="space-y-1.5 text-[12.5px]">
                            <template x-if="!col.rows || col.rows.length === 0">
                                <li class="text-muted-foreground italic py-2">No data in scope for this period.</li>
                            </template>
                            <template x-for="(r, i) in (col.rows || []).slice(0, 7)" :key="r.code">
                                <li>
                                    <button type="button"
                                            class="w-full flex items-center gap-2 rounded-md px-2 py-1.5 hover:bg-accent transition text-left"
                                            @click="filterTo(col.key, r.code)">
                                        <span class="inline-flex items-center justify-center w-5 h-5 rounded-full text-[10.5px] font-semibold tabular-nums shrink-0"
                                              :class="rankClass(i, col.rows.length)"
                                              x-text="i + 1"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="block font-medium truncate" x-text="r.code"></span>
                                            <span class="block text-[11px] text-muted-foreground" x-text="`${formatNum(r.screened)} screened · ${r.ack_rate}% ack · ${r.closure_rate}% closed`"></span>
                                        </span>
                                        <span class="font-semibold tabular-nums text-[13px]" x-text="r.score ?? '—'"></span>
                                    </button>
                                </li>
                            </template>
                        </ol>

                        <p class="text-[11px] text-muted-foreground mt-3 italic" x-show="(col.rows || []).length > 7" x-text="`+ ${col.rows.length - 7} more — open ${col.eyebrow.toLowerCase()} drill-down`"></p>
                    </div>
                </article>
            </template>
        </section>
    </template>

    {{-- ============================================================== --}}
    {{-- TREND STRIP — eight-week sparklines, three lanes                --}}
    {{-- ============================================================== --}}
    <template x-if="ready">
        <section class="card">
            <div class="card-content !p-4">
                <div class="flex items-center justify-between mb-3">
                    <div>
                        <p class="text-[10.5px] uppercase tracking-[0.12em] text-muted-foreground font-semibold">Last eight weeks</p>
                        <h3 class="text-[14px] font-semibold mt-0.5">Direction of each headline</h3>
                    </div>
                    <button type="button" class="rpt-explain-btn" data-chart-key="trend_strip" aria-label="How to read the trend strip">?</button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <template x-for="lane in trendLanes" :key="lane.key">
                        <article class="rounded-lg border border-border p-3">
                            <div class="flex items-baseline justify-between">
                                <span class="text-[12px] font-medium" x-text="lane.label"></span>
                                <span class="text-[11px]"
                                      :class="lane.direction === 'up'   ? 'text-success' :
                                              lane.direction === 'down' ? 'text-critical' : 'text-muted-foreground'"
                                      x-text="lane.directionLabel"></span>
                            </div>
                            <svg viewBox="0 0 100 30" preserveAspectRatio="none" class="w-full h-10 mt-1.5">
                                <polyline :points="lane.points" fill="none" :stroke="lane.color" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                <template x-for="(p, i) in lane.dots" :key="i">
                                    <circle :cx="p.x" :cy="p.y" r="1.6" :fill="lane.color"/>
                                </template>
                            </svg>
                            <p class="text-[11px] text-muted-foreground mt-1" x-text="lane.caption"></p>
                        </article>
                    </template>
                </div>
            </div>
        </section>
    </template>

    {{-- ============================================================== --}}
    {{-- INSIGHTS + DATA NOTES — kept, not the focus                     --}}
    {{-- ============================================================== --}}
    <template x-if="ready">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @include('admin.reports._insights')
            @include('admin.reports._data_notes')
        </div>
    </template>

    {{-- WIZARDS · filter (existing) + Ask (new) --}}
    @include('admin.reports._filter_wizard')

    {{-- "What would you like to know?" launcher --}}
    <div x-show="ask.open" x-cloak
         class="fixed inset-0 z-[80] bg-slate-950/70 backdrop-blur-md flex items-end sm:items-center justify-center"
         @keydown.escape.window="ask.open = false">
        <div class="bg-background w-full sm:max-w-xl sm:rounded-3xl shadow-2xl flex flex-col overflow-hidden max-h-[88vh]" @click.away="ask.open = false">
            <header class="px-5 sm:px-7 pt-5 pb-3 shrink-0 border-b border-border">
                <p class="eyebrow">Country Analytics</p>
                <h3 class="mt-1 text-lg font-semibold">What would you like to know?</h3>
                <p class="help-text mt-1">Each option focuses the composition. Nothing is hidden — only re-prioritised.</p>
            </header>
            <div class="overflow-y-auto px-5 sm:px-7 py-4 grow">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                    <template x-for="o in askOptions" :key="o.code">
                        <button type="button"
                                class="text-left rounded-2xl border border-border bg-background p-4 hover:border-foreground hover:shadow-sm transition"
                                @click="ask.open = false; runAsk(o.code)">
                            <p class="text-[13px] font-semibold" x-text="o.label"></p>
                            <p class="text-[11.5px] text-muted-foreground mt-1" x-text="o.help"></p>
                        </button>
                    </template>
                </div>
            </div>
            <footer class="px-5 sm:px-7 py-3 border-t border-border shrink-0 flex justify-end">
                <button type="button" class="btn btn-ghost btn-sm" @click="ask.open = false">Cancel</button>
            </footer>
        </div>
    </div>

    @include('admin.reports._chart_explainer', ['reportKey' => 'rpt-country-analytics'])
</div>

@push('scripts')
<script>
async function rptJson(url, params) {
    const u = new URL(url, window.location.origin);
    if (params) for (const [k,v] of Object.entries(params)) if (v !== '' && v !== null && v !== undefined) u.searchParams.set(k, v);
    const r = await fetch(u.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return await r.json();
}

function rptCountryAnalytics() {
    const RING_R    = 50;
    const RING_CIRC = 2 * Math.PI * RING_R;

    return {
        ready: false,
        wizard: { open: false, step: 1 },
        ask:    { open: false },
        filters: { poe:'', sex:'', year:'', quarter:'', month:'', start_date:'', end_date:'' },
        meta:    { poes:{}, districts:{}, provinces:{}, years:[], quarters:{}, months:{}, genders:{} },

        // Headline rings — fed from the same data API, derived locally so the
        // visual layer is the only thing changing.
        reach:    { pct: null, numerator: 0, target: 0 },
        response: { pct: null, numerator: 0, denominator: 0 },
        closure:  { pct: null, numerator: 0, denominator: 0 },

        // Three leagues — each a column in the layout.
        leagueCols: [],

        // Trend strip — three lanes, each a sparkline.
        trendLanes: [],

        insights:[], dataNotes:{}, window:{ from:'', to:'' },

        askOptions: [
            { code:'WHERE_REACH_LOW',    label:'Where is Reach lowest?',
              help:'Reorder the PHEOC and POE leagues by Reach (screening rate vs target).' },
            { code:'WHERE_RESPONSE_LOW', label:'Where is Response lowest?',
              help:'Reorder the leagues by acknowledgement compliance.' },
            { code:'WHERE_CLOSURE_LOW',  label:'Where is Closure lowest?',
              help:'Reorder the leagues by cycle-closure rate.' },
            { code:'IS_TREND_DECLINING', label:'Is anything trending down?',
              help:'Highlight the lane(s) declining in the trend strip and surface the underlying league.' },
            { code:'NARROW_TO_PHEOC',    label:'Show me one PHEOC',
              help:'Open the filter wizard pre-set to the PHEOC selector.' },
        ],

        async boot() {
            this.restoreFiltersFromUrl();
            await this.loadMeta();
            if (this.urlHasRun() || this.anyFilter()) await this.runReport();
        },

        anyFilter() { return Object.values(this.filters).some(v => v !== '' && v !== null); },
        urlHasRun() { return new URLSearchParams(window.location.search).get('run') === '1'; },
        restoreFiltersFromUrl() { const u = new URLSearchParams(window.location.search); for (const k of Object.keys(this.filters)) { const v = u.get(k); if (v !== null) this.filters[k] = v; } },
        writeFiltersToUrl() { const u = new URLSearchParams(); for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) u.set(k, v); u.set('run','1'); window.history.replaceState(null, '', window.location.pathname + '?' + u.toString()); },

        async loadMeta() { try { const r = await rptJson(@json(url('/admin/reports/meta'))); this.meta = Object.assign(this.meta, r?.data || {}); } catch (e) {} },
        openWizard() { this.wizard.open = true; this.wizard.step = 1; },
        resetFilters() { this.filters = { poe:'', sex:'', year:'', quarter:'', month:'', start_date:'', end_date:'' }; window.history.replaceState(null, '', window.location.pathname); },

        async runReport() {
            this.writeFiltersToUrl();
            try {
                const r = await rptJson(@json(url('/admin/reports/rpt-country-analytics/data')), this.buildParams());
                const d = r?.data || {};
                this.window      = d.window || {};
                this.insights    = d.insights || [];
                this.dataNotes   = d.data_notes || {};
                this.computeRings(d);
                this.computeLeagues(d);
                this.computeTrendStrip(d);
                this.ready = true;
            } catch (e) { console.error(e); this.ready = false; }
        },

        // ── Derive the three rings from the kpis payload ──
        computeRings(d) {
            const k = d.kpis || {};
            // Reach: screened against target. If no target, fall back to a ratio
            // that tells the operator the data is sparse rather than 0%.
            const target = Number(k.reach_target ?? 0);
            const screened = Number(k.total_screened ?? 0);
            this.reach = {
                pct:  target > 0 ? Math.min(999, Math.round((screened / target) * 1000) / 10) : null,
                numerator: screened, target: target,
            };
            this.response = {
                pct: k.ack_rate != null ? Math.round(k.ack_rate * 1000) / 10 : null,
                numerator:   Number(k.acknowledged_on_time ?? 0),
                denominator: Number(k.total_alerts ?? 0),
            };
            this.closure = {
                pct: k.cycle_closure_rate != null ? Math.round(k.cycle_closure_rate * 1000) / 10 : null,
                numerator:   Number(k.closed_cycles ?? 0),
                denominator: Number(k.opened_cycles ?? Math.max(Number(k.total_alerts ?? 0), 0)),
            };
        },

        // ── Build the three columns ──
        computeLeagues(d) {
            const score = r => {
                // Composite: 0.4 ack + 0.4 closure + 0.2 7-1-7 — same v1
                // formula the dead view documented; surfaces in the tile.
                const a = Number(r.ack_rate ?? 0) / 100;
                const c = Number(r.closure_rate ?? 0) / 100;
                const s = Number(r.compliance_7_1_7 ?? 0) / 100;
                return Math.round((0.4 * a + 0.4 * c + 0.2 * s) * 100);
            };
            const decorate = rows => (rows || []).map(r => ({ ...r, score: score(r) })).sort((a,b) => b.score - a.score);
            this.leagueCols = [
                { key:'pheoc',    eyebrow:'PHEOC',    title:'Provincial response centres', rows: decorate(d.by_pheoc) },
                { key:'district', eyebrow:'District', title:'District health offices',     rows: decorate(d.by_district) },
                { key:'poe',      eyebrow:'PoE',      title:'Points of entry',             rows: decorate(d.by_poe) },
            ];
        },

        // ── Build the eight-week trend strip ──
        computeTrendStrip(d) {
            const tr = d.trends || {};
            const screened = tr.weekly_screened || {};
            const alerts   = tr.weekly_alerts   || {};
            const acks     = tr.weekly_acks     || {};
            const closed   = tr.weekly_closed   || {};

            // Eight most-recent weeks across all series.
            const allWeeks = Array.from(new Set([
                ...Object.keys(screened), ...Object.keys(alerts),
                ...Object.keys(acks),     ...Object.keys(closed),
            ])).sort().slice(-8);

            const seriesTo = (name, src, target) => {
                const arr = allWeeks.map(w => Number(src[w] ?? 0));
                const max = Math.max(1, ...arr, target ? Number(target) : 1);
                const points = arr.map((v, i) => {
                    const x = (i / Math.max(1, allWeeks.length - 1)) * 100;
                    const y = 30 - (v / max) * 28 - 1;
                    return `${x.toFixed(1)},${y.toFixed(1)}`;
                });
                const dots   = arr.map((v, i) => ({
                    x: (i / Math.max(1, allWeeks.length - 1)) * 100,
                    y: 30 - (v / max) * 28 - 1,
                }));
                // Direction: compare last 3 weeks against the prior 3.
                const tail3 = arr.slice(-3).reduce((a,b) => a+b, 0);
                const prev3 = arr.slice(-6, -3).reduce((a,b) => a+b, 0);
                let direction = 'flat', directionLabel = 'flat';
                if (tail3 > prev3 * 1.1)      { direction = 'up';   directionLabel = '▲ rising'; }
                else if (tail3 < prev3 * 0.9) { direction = 'down'; directionLabel = '▼ falling'; }
                return { points: points.join(' '), dots, direction, directionLabel };
            };

            this.trendLanes = [
                { key:'reach',    label:'Reach',    color:'#0EA5E9', caption: 'Weekly screenings.', ...seriesTo('reach', screened) },
                { key:'response', label:'Response', color:'#10B981', caption: 'Weekly acknowledged on time.', ...seriesTo('response', acks) },
                { key:'closure',  label:'Closure',  color:'#F59E0B', caption: 'Weekly closed cycles.', ...seriesTo('closure', closed) },
            ];
        },

        // ── Ring math ──
        ringCirc() { return RING_CIRC; },
        ringDash(pct) {
            if (pct == null) return 0;
            const clamped = Math.max(0, Math.min(100, Number(pct)));
            return (clamped / 100) * RING_CIRC;
        },
        ringStroke(pct) {
            if (pct == null) return '#94a3b8';
            if (pct >= 90) return '#10b981';
            if (pct >= 70) return '#0ea5e9';
            if (pct >= 50) return '#f59e0b';
            return '#ef4444';
        },
        pctText(pct)   { return pct == null ? '— %' : pct.toFixed(1) + '%'; },
        bandTone(pct, good, ok) {
            if (pct == null)     return 'text-muted-foreground';
            if (pct >= good)     return 'text-success';
            if (pct >= ok)       return 'text-warning';
            return 'text-critical';
        },
        bandLabel(pct, good, ok) {
            if (pct == null)     return 'No data';
            if (pct >= good)     return 'In the green';
            if (pct >= ok)       return 'On the edge';
            return 'Below target';
        },

        rankClass(i, n) {
            if (i === 0)          return 'bg-emerald-100 text-emerald-700';
            if (i === 1 || i === 2) return 'bg-emerald-50 text-emerald-700';
            if (i >= n - 3 && n > 3) return 'bg-rose-50 text-rose-700';
            return 'bg-slate-100 text-slate-600';
        },

        filterTo(colKey, code) {
            // Hand off to filter wizard for a one-row drill.
            if (colKey === 'poe') {
                this.filters.poe = code;
                this.runReport();
            } else {
                // For PHEOC + district, keep the user oriented by opening the
                // filter wizard rather than secretly mutating state.
                this.openWizard();
            }
        },

        runAsk(code) {
            switch (code) {
                case 'WHERE_REACH_LOW':
                    this.leagueCols.forEach(c => c.rows = (c.rows || []).slice().sort((a,b) => Number(a.screened) - Number(b.screened)));
                    break;
                case 'WHERE_RESPONSE_LOW':
                    this.leagueCols.forEach(c => c.rows = (c.rows || []).slice().sort((a,b) => Number(a.ack_rate) - Number(b.ack_rate)));
                    break;
                case 'WHERE_CLOSURE_LOW':
                    this.leagueCols.forEach(c => c.rows = (c.rows || []).slice().sort((a,b) => Number(a.closure_rate) - Number(b.closure_rate)));
                    break;
                case 'IS_TREND_DECLINING':
                    // The lanes already carry their own direction tags; just
                    // scroll the trend strip into view.
                    document.querySelector('[data-chart-key="trend_strip"]')?.scrollIntoView({ behavior:'smooth', block:'center' });
                    break;
                case 'NARROW_TO_PHEOC':
                    this.openWizard();
                    break;
            }
        },

        buildParams() { const p = {}; for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) p[k] = v; return p; },
        exportAs(fmt) {
            const u = new URL(@json(url('/admin/reports/rpt-country-analytics/export')), window.location.origin);
            for (const [k,v] of Object.entries(this.buildParams())) u.searchParams.set(k, v);
            u.searchParams.set('format', fmt);
            if (fmt === 'PDF') window.open(u.toString(), '_blank', 'noopener');
            else window.location.href = u.toString();
        },
        formatNum(v)   { return (v == null) ? '—' : Number(v).toLocaleString(); },
        windowLabel()  { return this.window.from ? (this.window.from + ' → ' + this.window.to) : 'No window'; },
    };
}
</script>
@endpush
@endsection
