@extends('admin.layout')

@section('crumb', 'Quick Reports')
@section('title', 'Confirmed Cases')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>

{{-- Local primitives mirror admin/quick/suspected/index.blade.php so the
     two surfaces are visually consistent. MUST be `text/tailwindcss` so
     the Tailwind Play CDN compiles the @apply directives at runtime. --}}
<style type="text/tailwindcss">
    .qr-stack       { @apply space-y-4; }
    .qr-card        { @apply rounded-xl border border-border/70 bg-card text-card-foreground shadow-elevation-1; }
    .qr-card-head   { @apply flex items-center justify-between gap-3 px-4 py-3 border-b border-border/70; }
    .qr-card-title  { @apply text-[13px] font-semibold tracking-tight text-foreground; }
    .qr-card-sub    { @apply text-[11px] text-muted-foreground; }
    .qr-divider     { @apply border-t border-border/70; }
    .qr-card-pad    { @apply px-4 py-3; }

    .qr-kpi-grid    { @apply grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5; }
    .qr-kpi         { @apply rounded-lg border border-border/70 bg-card px-3.5 py-2.5 transition-colors duration-150; }
    .qr-kpi-label   { @apply text-[10px] font-semibold uppercase tracking-[.12em] text-muted-foreground; }
    .qr-kpi-value   { @apply mt-1 text-[22px] leading-none font-semibold tabular-nums text-foreground; }
    .qr-kpi-hint    { @apply mt-1.5 text-[10.5px] text-muted-foreground; }
    .qr-kpi-warn    { @apply ring-1 ring-critical/30 bg-critical/[.03]; }
    .qr-kpi-warn .qr-kpi-value  { @apply text-critical; }
    .qr-kpi-info .qr-kpi-value  { @apply text-brand-ink; }
    .qr-kpi-skel    { @apply rounded-lg border border-dashed border-border/60 px-3.5 py-3 h-[78px] animate-pulse bg-muted/20; }

    .qr-table-wrap  { @apply relative w-full overflow-auto max-h-[640px]; }
    .qr-table       { @apply w-full text-[12.5px] border-separate; border-spacing: 0; }
    .qr-table thead th {
        @apply text-[10px] font-semibold uppercase tracking-[.10em] text-muted-foreground
               bg-muted/50 backdrop-blur-sm
               px-3 py-2 text-left whitespace-nowrap
               border-b border-border/70 sticky top-0 z-10
               select-none;
    }
    .qr-table tbody td {
        @apply px-3 py-1.5 border-b border-border/40 align-middle whitespace-nowrap;
    }
    .qr-table tbody tr { transition: background-color 120ms ease; }
    .qr-table tbody tr:hover td { @apply bg-muted/40; }
    .qr-table tbody tr:last-child td { @apply border-b-0; }
    .qr-cell-primary   { @apply font-medium text-foreground; }
    .qr-cell-secondary { @apply text-[10.5px] text-muted-foreground tabular-nums; }
    .qr-cell-mono      { @apply font-mono text-[11px] text-muted-foreground; }

    /* Classification pills — semantic, loud where it matters. */
    .qr-pill          { @apply inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap; }
    .qr-pill-confirmed{ @apply bg-critical text-critical-foreground; }
    .qr-pill-probable { @apply bg-critical/12 text-critical ring-1 ring-critical/30; }
    .qr-pill-suspect  { @apply bg-warning/15 text-warning ring-1 ring-warning/30; }
    .qr-pill-ruled    { @apply bg-success/12 text-success ring-1 ring-success/30; }
    .qr-pill-pending  { @apply bg-muted text-foreground/75 ring-1 ring-border/60; }
    .qr-pill-ihr      { @apply inline-flex items-center rounded bg-info/10 text-info ring-1 ring-info/25 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums; }
    .qr-pill-tier1    { @apply inline-flex items-center rounded bg-critical/12 text-critical ring-1 ring-critical/35 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums; }

    .qr-icon-btn {
        @apply inline-flex h-7 w-7 items-center justify-center rounded-md
               text-[11px] font-semibold text-muted-foreground
               border border-transparent hover:bg-muted hover:text-foreground
               focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-1
               transition-colors duration-150;
    }
    .qr-link-btn {
        @apply inline-flex items-center gap-1 rounded-md px-2 py-1
               text-[11.5px] font-semibold text-brand-ink
               border border-brand-soft bg-brand-soft hover:bg-brand-soft/70
               focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-1;
    }
    .qr-empty {
        @apply rounded-lg border border-dashed border-border/70 bg-muted/20
               py-10 text-center text-muted-foreground text-[12.5px];
    }

    .qr-chart-wrap  { @apply relative w-full overflow-x-hidden overflow-y-auto; min-height: 280px; max-height: 540px; }
    .qr-chart-skel  { @apply rounded-lg bg-muted/25 animate-pulse; height: 320px; }

    .qr-progress    { @apply fixed inset-x-0 top-0 h-[2px] z-[60] overflow-hidden pointer-events-none; }
    .qr-progress::after { content: ''; @apply absolute inset-y-0 left-0 bg-brand; width: 35%; animation: qr-slide 1.1s ease-in-out infinite; }
    @keyframes qr-slide { 0% { transform: translateX(-100%); } 100% { transform: translateX(320%); } }
    @media (prefers-reduced-motion: reduce) {
        .qr-progress::after, .qr-kpi-skel, .qr-chart-skel { animation: none !important; }
    }

    .qr-modal-bg    { @apply fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm; }
    .qr-modal-shell { @apply fixed inset-0 z-[81] flex items-stretch justify-stretch p-0 sm:p-8 sm:items-center sm:justify-center; }
    .qr-modal       { @apply relative w-full sm:max-w-3xl max-h-[100dvh] sm:max-h-[88dvh] flex flex-col bg-card border border-border shadow-elevation-5 sm:rounded-xl overflow-hidden; }
    .qr-modal-head  { @apply flex items-center justify-between gap-3 px-5 py-3 border-b border-border/70; }
    .qr-modal-body  { @apply flex-1 overflow-auto px-5 py-4 text-[13px] leading-relaxed; }
    .qr-modal-foot  { @apply flex items-center justify-end gap-2 px-5 py-3 border-t border-border/70 bg-muted/30; }
    .qr-modal-section { @apply space-y-1; }
    .qr-modal-section p:first-child { @apply text-[11px] font-semibold uppercase tracking-[.12em] text-muted-foreground; }
</style>
@endpush

@section('content')
<div x-data="qrConfirmed()" x-init="boot()" class="qr-stack" :aria-busy="loading ? 'true' : 'false'">

    <div class="qr-progress" x-show="loading" x-cloak></div>

    {{-- HEADER --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Quick Reports · qr-confirmed</p>
            <h1 class="text-[20px] font-semibold tracking-tight">Confirmed Cases</h1>
            <p class="help-text mt-1" x-text="headline()">Cases the laboratory has classified.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready" x-cloak>
                <span class="status-dot status-dot-live"></span>
                <span x-text="payload.window?.label || ''"></span>
            </span>
            <span class="topbar-chip" x-show="ready && payload.scope?.label" x-cloak x-text="payload.scope?.label"></span>
            <button type="button" class="btn btn-outline btn-xs" @click="exportCsvFull()" :disabled="!ready" aria-label="Export full CSV">
                Export
            </button>
        </div>
    </section>

    {{-- FILTERS --}}
    <section class="qr-card">
        <div class="qr-card-head">
            <div class="flex items-center gap-2 min-w-0">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 text-muted-foreground shrink-0"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
                <span class="qr-card-title">Filters</span>
                <span class="qr-card-sub truncate" x-text="filtersSummary()"></span>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" class="btn btn-ghost btn-xs text-muted-foreground" @click="resetFilters()">Reset</button>
                <button type="button" class="btn btn-brand btn-xs" @click="loadData()">Apply</button>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 p-4">
            <div>
                <label class="label block mb-1 text-[11px]" for="qrc-f-days">Window</label>
                <select id="qrc-f-days" class="select" x-model="filters.days">
                    <option value="7">Past 7 days</option>
                    <option value="14">Past 14 days</option>
                    <option value="30">Past 30 days</option>
                    <option value="60">Past 60 days</option>
                    <option value="90">Past 90 days</option>
                </select>
            </div>
            <div>
                <label class="label block mb-1 text-[11px]" for="qrc-f-poe">Point of entry</label>
                <select id="qrc-f-poe" class="select" x-model="filters.poe">
                    <option value="">All entry points</option>
                    <template x-for="(name, code) in (payload.meta?.poes || {})" :key="code">
                        <option :value="code" x-text="name"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1 text-[11px]" for="qrc-f-class">Lab classification</label>
                <select id="qrc-f-class" class="select" x-model="filters.class">
                    <option value="">All classifications</option>
                    <template x-for="c in (payload.meta?.classifications || [])" :key="c">
                        <option :value="c" x-text="prettyClass(c)"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1 text-[11px]" for="qrc-f-disease">Disease</label>
                <select id="qrc-f-disease" class="select" x-model="filters.disease">
                    <option value="">All diseases</option>
                    <template x-for="(label, code) in (payload.meta?.diseases || {})" :key="code">
                        <option :value="code" x-text="label"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1 text-[11px]" for="qrc-f-tier">IHR tier</label>
                <select id="qrc-f-tier" class="select" x-model="filters.ihr_tier">
                    <option value="">All IHR tiers</option>
                    <option value="1">Tier 1 — Always notifiable</option>
                    <option value="2">Tier 2 — Annex 2 review</option>
                    <option value="3">Tier 3 — Other</option>
                </select>
            </div>
            <div>
                <label class="label block mb-1 text-[11px]" for="qrc-f-q">Search</label>
                <input id="qrc-f-q" type="text" class="select" placeholder="Traveller, alert code, disease…" x-model="filters.q" @keydown.enter.prevent="loadData()">
            </div>
        </div>
    </section>

    {{-- KPI ROW --}}
    <section aria-label="Key indicators">
        <template x-if="!ready">
            <div class="qr-kpi-grid">
                <div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div>
                <div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div>
            </div>
        </template>
        <div class="qr-kpi-grid" x-show="ready" x-cloak>
            <div class="qr-kpi">
                <p class="qr-kpi-label">Under investigation</p>
                <p class="qr-kpi-value" x-text="fmt(payload.kpis?.total)"></p>
                <p class="qr-kpi-hint" x-text="payload.window?.label ? 'Within ' + payload.window.label : 'Within window'"></p>
            </div>
            <div class="qr-kpi" :class="(payload.kpis?.confirmed ?? 0) > 0 && 'qr-kpi-warn'">
                <p class="qr-kpi-label">Confirmed</p>
                <p class="qr-kpi-value" x-text="fmt(payload.kpis?.confirmed)"></p>
                <p class="qr-kpi-hint">Lab-classified CONFIRMED</p>
            </div>
            <div class="qr-kpi">
                <p class="qr-kpi-label">Probable</p>
                <p class="qr-kpi-value" x-text="fmt(payload.kpis?.probable)"></p>
                <p class="qr-kpi-hint">Strong clinical suspicion</p>
            </div>
            <div class="qr-kpi qr-kpi-info">
                <p class="qr-kpi-label">Ruled out</p>
                <p class="qr-kpi-value" x-text="fmt(payload.kpis?.ruled_out)"></p>
                <p class="qr-kpi-hint">Lab cleared the case</p>
            </div>
            <div class="qr-kpi">
                <p class="qr-kpi-label">Pending lab</p>
                <p class="qr-kpi-value" x-text="fmt(payload.kpis?.pending)"></p>
                <p class="qr-kpi-hint">Awaiting classification</p>
            </div>
            <div class="qr-kpi">
                <p class="qr-kpi-label">In last 24 hours</p>
                <p class="qr-kpi-value" x-text="fmt(payload.kpis?.last_24h)"></p>
                <p class="qr-kpi-hint">Opened since yesterday</p>
            </div>
        </div>
    </section>

    {{-- CHART --}}
    <section class="qr-card" aria-label="Lab confirmation chart">
        <div class="qr-card-head">
            <div class="min-w-0">
                <h2 class="qr-card-title" x-text="payload.chart?.title || 'Lab pipeline'"></h2>
                <p class="qr-card-sub" x-text="payload.chart?.subtitle || ''"></p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" class="qr-icon-btn" @click="openExplain()" title="Explain this chart" aria-label="Explain this chart" x-ref="explainTrigger">?</button>
                <button type="button" class="btn btn-outline btn-xs" @click="exportChartPng()" :disabled="!chartHasData">PNG</button>
                <button type="button" class="btn btn-outline btn-xs" @click="exportChartCsv()" :disabled="!chartHasData">CSV</button>
            </div>
        </div>
        <div class="p-4">
            <template x-if="!ready"><div class="qr-chart-skel"></div></template>
            <div class="qr-chart-wrap" x-show="ready && chartHasData" x-cloak>
                <canvas x-ref="chart" role="img" aria-label="Lab pipeline chart"></canvas>
            </div>
            <div class="qr-empty" x-show="ready && !chartHasData" x-cloak>
                <p>No alerts in this window.</p>
                <p class="mt-1 text-[11.5px]">Widen the date range or clear a filter.</p>
            </div>
        </div>
    </section>

    {{-- TABLE --}}
    <section class="qr-card overflow-hidden" aria-label="Case register">
        <div class="qr-card-head">
            <div class="min-w-0">
                <h2 class="qr-card-title">Case register</h2>
                <p class="qr-card-sub" x-text="tableSub()"></p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" class="btn btn-outline btn-xs" @click="exportCsvFull()" :disabled="!ready">Export full CSV</button>
            </div>
        </div>

        <div class="qr-table-wrap">
            <table class="qr-table">
                <thead>
                    <tr>
                        <th>Opened</th>
                        <th>Traveller</th>
                        <th>Disease (confirmed or suspected)</th>
                        <th>Lab status</th>
                        <th>Lab method</th>
                        <th>IHR tier</th>
                        <th>Clinical outcome</th>
                        <th>Point of entry</th>
                        <th class="text-right pr-3">Case file</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-if="!ready">
                        <template x-for="i in 6" :key="i">
                            <tr><td colspan="9"><div class="h-5 my-1 rounded bg-muted/30 animate-pulse"></div></td></tr>
                        </template>
                    </template>

                    <template x-for="row in (payload.table || [])" :key="row.alert_id">
                        <tr>
                            <td>
                                <div class="qr-cell-primary" x-text="row.opened_at_label"></div>
                                <div class="qr-cell-mono" x-show="row.alert_code" x-text="row.alert_code"></div>
                            </td>
                            <td>
                                <div class="qr-cell-primary" x-text="row.traveller_name || 'Unknown traveller'"></div>
                                <div class="qr-cell-secondary" x-text="rowDemographics(row)"></div>
                            </td>
                            <td>
                                <div class="qr-cell-primary" x-text="row.disease || 'Not yet diagnosed'"></div>
                                <div class="qr-cell-secondary" x-show="row.lab_confirmed_at_label" x-text="'Confirmed ' + row.lab_confirmed_at_label"></div>
                            </td>
                            <td><span :class="classPill(row.classification)" x-text="row.classification_label"></span></td>
                            <td x-text="row.lab_method || '—'"></td>
                            <td>
                                <span :class="row.ihr_tier === 1 ? 'qr-pill-tier1' : 'qr-pill-ihr'" x-show="row.ihr_tier" x-text="'Tier ' + row.ihr_tier"></span>
                                <span x-show="!row.ihr_tier" class="qr-cell-mono">—</span>
                            </td>
                            <td>
                                <span x-text="prettyOutcome(row.clinical_outcome)"></span>
                                <span class="qr-pill-ihr ml-1" x-show="row.ihr_notified" title="IHR notified">IHR ✓</span>
                            </td>
                            <td x-text="row.poe_name || '—'"></td>
                            <td class="text-right pr-3">
                                <a :href="row.case_file_url" class="qr-link-btn" aria-label="Open full case file">View
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M9 7h8v8"/></svg>
                                </a>
                            </td>
                        </tr>
                    </template>

                    <template x-if="ready && (payload.table?.length || 0) === 0">
                        <tr><td colspan="9">
                            <div class="qr-empty my-2">
                                <p>No alerts match the current filters.</p>
                                <p class="mt-1 text-[11.5px]">Try widening the window or clearing a filter.</p>
                            </div>
                        </td></tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="qr-card-pad qr-divider flex items-center justify-between">
            <p class="qr-card-sub" x-text="tableSub()"></p>
            <p class="qr-card-sub">Updated <span x-text="lastLoadedLabel()"></span></p>
        </div>
    </section>

    {{-- EXPLAIN MODAL --}}
    <template x-teleport="body">
        <div x-show="explainOpen" x-cloak>
            <div class="qr-modal-bg" @click="closeExplain()" aria-hidden="true"></div>
            <div class="qr-modal-shell" @keydown.escape.window="closeExplain()">
                <div class="qr-modal" role="dialog" aria-modal="true" aria-labelledby="qrc-explain-title">
                    <div class="qr-modal-head">
                        <div class="min-w-0">
                            <p class="eyebrow">About this chart</p>
                            <h3 id="qrc-explain-title" class="text-[15px] font-semibold mt-0.5 truncate" x-text="payload.chart?.title || 'Lab pipeline'"></h3>
                        </div>
                        <button type="button" class="qr-icon-btn" @click="closeExplain()" aria-label="Close">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="qr-modal-body space-y-5">
                        <div class="qr-modal-section"><p>What this measures</p><p x-text="explainWhat()"></p></div>
                        <div class="qr-modal-section"><p>How to read it</p><p x-text="explainHowToRead()"></p></div>
                        <div class="qr-modal-section"><p>What to do next</p><p x-text="explainNext()"></p></div>
                        <div class="qr-modal-section">
                            <p>Source data</p>
                            <div class="qr-table-wrap mt-2 max-h-[280px]">
                                <table class="qr-table">
                                    <thead><tr><th x-text="explainCategoryHeader()"></th><th class="text-right pr-3">Cases</th><th class="text-right pr-3">% share</th></tr></thead>
                                    <tbody>
                                        <template x-for="(lbl, i) in (payload.chart?.labels || [])" :key="i">
                                            <tr>
                                                <td x-text="lbl"></td>
                                                <td class="text-right pr-3 tabular-nums" x-text="payload.chart.values[i]"></td>
                                                <td class="text-right pr-3 tabular-nums text-muted-foreground" x-text="explainPct(i)"></td>
                                            </tr>
                                        </template>
                                        <template x-if="(payload.chart?.labels?.length || 0) === 0">
                                            <tr><td colspan="3" class="text-center text-muted-foreground py-4">No data in this window.</td></tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="qr-modal-foot">
                        <button type="button" class="btn btn-outline btn-xs" @click="exportChartCsv()" :disabled="!chartHasData">Download chart CSV</button>
                        <button type="button" class="btn btn-default btn-xs" @click="closeExplain()">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

@push('scripts')
<script>
    const QR_CONFIRMED = {
        endpointData:   @json(url('/admin/quick-reports/confirmed-cases/data')),
        endpointExport: @json(url('/admin/quick-reports/confirmed-cases/export')),
        classLabels: {
            CONFIRMED: 'Confirmed',
            PROBABLE:  'Probable',
            SUSPECTED: 'Suspected',
            DISCARDED: 'Ruled out',
            LOST_TO_FOLLOWUP: 'Lost to follow-up',
            UNKNOWN: 'Pending',
            PENDING: 'Pending lab',
        },
        outcomeLabels: {
            RECOVERED: 'Recovered',
            UNDER_TREATMENT: 'Under treatment',
            REFERRED: 'Referred',
            DIED: 'Died',
            UNKNOWN: '—',
        },
    };

    function qrConfirmed() {
        return {
            ready: false, loading: false, chartHasData: false,
            explainOpen: false, chart: null, lastLoadedAt: null, abortCtrl: null,
            filters: { days: '7', poe: '', class: '', disease: '', ihr_tier: '', ihr_notified: '', q: '' },
            payload: { window: null, scope: null, kpis: {}, chart: { labels: [], values: [] }, table: [], table_full: [], total_rows: 0, shown_rows: 0, meta: {} },

            boot() {
                this.readFiltersFromUrl();
                this.loadData();
                for (const k of ['days','poe','class','disease','ihr_tier']) {
                    this.$watch(`filters.${k}`, () => this.loadData());
                }
                if (typeof Chart === 'undefined') {
                    const wait = setInterval(() => { if (typeof Chart !== 'undefined') { clearInterval(wait); this.renderChart(); } }, 60);
                }
            },

            readFiltersFromUrl() {
                const u = new URL(window.location.href);
                for (const k of Object.keys(this.filters)) {
                    const v = u.searchParams.get(k);
                    if (v !== null) this.filters[k] = v;
                }
            },
            writeFiltersToUrl() {
                const u = new URL(window.location.href);
                for (const [k, v] of Object.entries(this.filters)) {
                    if (v === '' || v == null) u.searchParams.delete(k);
                    else                       u.searchParams.set(k, v);
                }
                window.history.replaceState({}, '', u);
            },

            async loadData() {
                this.writeFiltersToUrl();
                if (this.abortCtrl) this.abortCtrl.abort();
                this.abortCtrl = new AbortController();
                const params = new URLSearchParams();
                for (const [k, v] of Object.entries(this.filters)) {
                    if (v !== '' && v != null) params.set(k, v);
                }
                this.loading = true;
                try {
                    const res = await fetch(`${QR_CONFIRMED.endpointData}?${params.toString()}`, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin', signal: this.abortCtrl.signal,
                    });
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    const body = await res.json();
                    if (!body || !body.success) throw new Error('Bad response');
                    this.payload = body.data || this.payload;
                    this.lastLoadedAt = new Date();
                    this.ready = true;
                    Alpine.store('pageMeta').rows = this.payload.total_rows ?? null;
                    this.$nextTick(() => this.renderChart());
                } catch (e) {
                    if (e.name === 'AbortError') return;
                    console.error('[qr-confirmed] load failed', e);
                    this.ready = true;
                } finally {
                    this.loading = false;
                }
            },

            resetFilters() {
                this.filters = { days: '7', poe: '', class: '', disease: '', ihr_tier: '', ihr_notified: '', q: '' };
                this.loadData();
            },

            renderChart() {
                const labels = this.payload?.chart?.labels || [];
                const values = this.payload?.chart?.values || [];
                const colors = this.payload?.chart?.colors || [];
                this.chartHasData = labels.length > 0;
                if (!this.chartHasData) { if (this.chart) { this.chart.destroy(); this.chart = null; } return; }
                const wrap = this.$refs.chart?.parentElement; if (!wrap) return;
                const desired = Math.max(280, labels.length * 36 + 60);
                wrap.style.height = Math.min(540, desired) + 'px';
                const fill = colors.map(c => c || '#1E88E5');
                const border = fill.map(c => this.darken(c, 18));

                if (this.chart) {
                    this.chart.data.labels = labels;
                    this.chart.data.datasets[0].data = values;
                    this.chart.data.datasets[0].backgroundColor = fill;
                    this.chart.data.datasets[0].borderColor = border;
                    this.chart.update('none'); return;
                }

                this.chart = new Chart(this.$refs.chart.getContext('2d'), {
                    type: 'bar',
                    data: { labels, datasets: [{
                        label: 'Cases', data: values,
                        backgroundColor: fill, borderColor: border, borderWidth: 1.5,
                        borderRadius: 4, barThickness: 'flex', maxBarThickness: 24,
                        hoverBackgroundColor: fill.map(c => this.darken(c, 8)),
                    }]},
                    options: {
                        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                        animation: { duration: 260 },
                        layout: { padding: { left: 4, right: 28, top: 4, bottom: 4 } },
                        scales: {
                            x: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 }, color: '#475569' }, grid: { color: 'rgba(15, 23, 42, .06)', drawBorder: false } },
                            y: { ticks: { autoSkip: false, font: { size: 11.5, weight: '500' }, color: '#0F172A' }, grid: { display: false, drawBorder: false } },
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#0F172A', titleFont: { weight: '600', size: 12 }, bodyFont: { size: 11.5 },
                                padding: 10, cornerRadius: 6, displayColors: true,
                                callbacks: {
                                    title: items => items[0].label,
                                    label: item => { const n = item.parsed.x; return `${n.toLocaleString()} ${n === 1 ? 'case' : 'cases'}`; },
                                },
                            },
                        },
                    },
                });
            },

            darken(hex, pct) {
                const m = /^#?([0-9a-f]{6})$/i.exec(hex || ''); if (!m) return hex;
                const n = parseInt(m[1], 16);
                const r = Math.max(0, Math.round(((n >> 16) & 0xff) * (1 - pct/100)));
                const g = Math.max(0, Math.round(((n >>  8) & 0xff) * (1 - pct/100)));
                const b = Math.max(0, Math.round(( n        & 0xff) * (1 - pct/100)));
                return '#' + [r,g,b].map(v => v.toString(16).padStart(2, '0')).join('');
            },

            fmt(n) { return (n ?? 0).toLocaleString(); },
            prettyClass(c)   { return QR_CONFIRMED.classLabels[c]   || c || '—'; },
            prettyOutcome(o) { return QR_CONFIRMED.outcomeLabels[o] || o || '—'; },
            classPill(c) {
                const base = 'qr-pill';
                if (c === 'CONFIRMED')         return base + ' qr-pill-confirmed';
                if (c === 'PROBABLE')          return base + ' qr-pill-probable';
                if (c === 'SUSPECTED')         return base + ' qr-pill-suspect';
                if (c === 'DISCARDED')         return base + ' qr-pill-ruled';
                return base + ' qr-pill-pending';
            },
            rowDemographics(row) {
                const bits = [];
                if (row.age !== null && row.age !== undefined) bits.push(`${row.age}y`);
                if (row.sex)         bits.push(row.sex.charAt(0) + row.sex.slice(1).toLowerCase());
                if (row.nationality) bits.push(row.nationality);
                return bits.join(' · ') || '—';
            },
            headline() {
                if (!this.ready) return 'Loading confirmed cases…';
                const total = this.payload.total_rows ?? 0;
                const conf  = this.payload.kpis?.confirmed ?? 0;
                const scope = this.payload.scope?.label || '';
                if (total === 0) return `No alerts${scope ? ' in ' + scope : ''}.`;
                if (conf === 0)  return `${total.toLocaleString()} alerts under investigation${scope ? ' · ' + scope : ''}`;
                return `${conf.toLocaleString()} confirmed of ${total.toLocaleString()} alerts${scope ? ' · ' + scope : ''}`;
            },
            tableSub() {
                if (!this.ready) return '';
                const t = this.payload.total_rows ?? 0;
                const s = this.payload.shown_rows ?? 0;
                if (t === 0) return 'No alerts match the current filters.';
                if (s >= t)  return `All ${t.toLocaleString()} alerts shown.`;
                return `${s} of ${t.toLocaleString()} alerts · Export full CSV for the complete set.`;
            },
            filtersSummary() {
                const f = this.filters; const bits = [];
                if (f.days)     bits.push(`past ${f.days} d`);
                if (f.poe)      bits.push(`POE ${f.poe}`);
                if (f.class)    bits.push(this.prettyClass(f.class).toLowerCase());
                if (f.disease)  bits.push(f.disease);
                if (f.ihr_tier) bits.push(`tier ${f.ihr_tier}`);
                if (f.q)        bits.push(`q "${f.q}"`);
                return bits.length ? '· ' + bits.join(' · ') : '· defaults';
            },
            lastLoadedLabel() {
                if (!this.lastLoadedAt) return '—';
                const pad = n => String(n).padStart(2, '0');
                return `${pad(this.lastLoadedAt.getHours())}:${pad(this.lastLoadedAt.getMinutes())}`;
            },
            explainPct(i) {
                const v = this.payload.chart?.values?.[i] ?? 0;
                const t = (this.payload.chart?.values || []).reduce((s, x) => s + x, 0);
                if (!t) return '—';
                return ((v / t) * 100).toFixed(1) + '%';
            },
            explainCategoryHeader() {
                switch (this.payload.chart?.kind) {
                    case 'confirmed_diseases': return 'Confirmed disease';
                    case 'classification':     return 'Classification';
                    case 'poe':                return 'Point of entry';
                    case 'day':                return 'Day';
                    default:                   return 'Category';
                }
            },
            explainWhat() {
                const w = this.payload.window?.label || 'this window';
                switch (this.payload.chart?.kind) {
                    case 'confirmed_diseases': return `Which diseases the laboratory has actually confirmed across alerts closed with a CONFIRMED classification in ${w}.`;
                    case 'classification':     return `Every alert opened in ${w}, grouped by what the laboratory has told us so far. The lab pipeline is still maturing — most alerts sit in PENDING until a result lands.`;
                    case 'poe':                return `Where the alerts came from in ${w}. The lab pipeline hasn't yet produced classifications.`;
                    case 'day':                return `When the alerts were opened, day by day, across ${w}.`;
                    default:                   return `No alerts in ${w}.`;
                }
            },
            explainHowToRead() {
                switch (this.payload.chart?.kind) {
                    case 'confirmed_diseases': return 'Bars are sorted from most-confirmed to least. The longer the bar, the more confirmations for that disease.';
                    case 'classification':     return 'Bar colour reads the urgency: red = Confirmed (act now), deep orange = Probable, orange = Suspected, green = Ruled-out (good news), grey = Pending.';
                    case 'poe':                return 'Bars are sorted from busiest entry point to quietest.';
                    case 'day':                return 'Day-on-day spikes deserve a quick look — they often precede a confirmed cluster.';
                    default:                   return '';
                }
            },
            explainNext() {
                switch (this.payload.chart?.kind) {
                    case 'confirmed_diseases': return 'Each confirmed disease needs an immediate IHR review. Click any row to open the full case file.';
                    case 'classification':     return 'Confirmed and Probable bars demand same-day clinical review. Pending stalled > 7 days needs lab follow-up.';
                    case 'poe':                return 'Entry points generating the most alerts warrant supervisor outreach.';
                    case 'day':                return 'Investigate spikes against arrivals to rule out artefacts.';
                    default:                   return '';
                }
            },

            openExplain()  { this.explainOpen = true; },
            closeExplain() { this.explainOpen = false; this.$nextTick(() => this.$refs.explainTrigger?.focus()); },

            exportChartPng() {
                if (!this.chart) return;
                const url = this.chart.toBase64Image('image/png', 1.0);
                const a = document.createElement('a');
                a.href = url; a.download = `qr-confirmed-chart-${this.fileStamp()}.png`;
                document.body.appendChild(a); a.click(); a.remove();
            },
            exportChartCsv() {
                const labels = this.payload?.chart?.labels || [];
                const values = this.payload?.chart?.values || [];
                const lines  = ['Category,Cases'];
                for (let i = 0; i < labels.length; i++) {
                    const safe = `"${String(labels[i]).replace(/"/g, '""')}"`;
                    lines.push(`${safe},${values[i] ?? 0}`);
                }
                const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `qr-confirmed-chart-${this.fileStamp()}.csv`;
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(() => URL.revokeObjectURL(a.href), 60_000);
            },
            exportCsvFull() {
                const params = new URLSearchParams();
                for (const [k, v] of Object.entries(this.filters)) {
                    if (v !== '' && v != null) params.set(k, v);
                }
                params.set('format', 'CSV');
                window.location.href = `${QR_CONFIRMED.endpointExport}?${params.toString()}`;
            },
            fileStamp() {
                const d = new Date();
                const pad = n => String(n).padStart(2, '0');
                return `${d.getFullYear()}${pad(d.getMonth()+1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}`;
            },
        };
    }
</script>
@endpush
@endsection
