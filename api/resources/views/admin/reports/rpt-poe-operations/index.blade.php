@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Point of Entry Operations')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
    .kpi-card        { background: hsl(var(--background)); border: 1px solid hsl(var(--border));
                       border-radius: 14px; padding: 14px 16px; min-height: 102px;
                       display: flex; flex-direction: column; gap: 4px; }
    .kpi-card-brand  { background: linear-gradient(135deg, hsl(var(--brand-soft)) 0%, hsl(var(--background)) 80%);
                       border-color: hsl(var(--brand) / .35); }
    .kpi-card-danger { background: hsl(var(--critical-soft)); border-color: hsl(var(--critical) / .35); }
    .kpi-card-good   { background: hsl(var(--success-soft));  border-color: hsl(var(--success) / .35);  }
    .kpi-eyebrow     { font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em;
                       color: hsl(var(--muted-foreground)); font-weight: 600; }
    .kpi-value       { font-size: 28px; font-weight: 700; line-height: 1.1; color: hsl(var(--foreground)); }
    .kpi-sub         { font-size: 11.5px; color: hsl(var(--muted-foreground)); line-height: 1.35; }

    .chart-card      { background: hsl(var(--background)); border: 1px solid hsl(var(--border));
                       border-radius: 14px; }
    .chart-card-head { display: flex; align-items: center; gap: 10px; padding: 12px 14px;
                       border-bottom: 1px solid hsl(var(--border) / .6); }
    .chart-card-body { padding: 14px; min-height: 320px; position: relative; }
    .chart-toolbar   { margin-left: auto; display: flex; gap: 6px; align-items: center; }
    .chart-toolbar button { font-size: 11px; padding: 3px 8px; border-radius: 6px;
                            border: 1px solid hsl(var(--border)); background: hsl(var(--background));
                            color: hsl(var(--muted-foreground)); font-weight: 600; cursor: pointer; }
    .chart-toolbar button:hover { background: hsl(var(--accent)); color: hsl(var(--foreground)); }

    .data-table         { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .data-table thead th{ text-align: left; padding: 9px 12px; background: hsl(var(--muted));
                           color: hsl(var(--muted-foreground)); text-transform: uppercase;
                           letter-spacing: .04em; font-size: 10.5px; font-weight: 700;
                           border-bottom: 1px solid hsl(var(--border)); }
    .data-table tbody td{ padding: 8px 12px; border-bottom: 1px solid hsl(var(--border) / .6); }
    .data-table tbody tr:hover td { background: hsl(var(--accent) / .5); }

    .ring-meter      { position: relative; display: inline-flex; align-items: center;
                       justify-content: center; width: 92px; height: 92px; }
    .ring-meter svg  { transform: rotate(-90deg); width: 100%; height: 100%; }
    .ring-meter-val  { position: absolute; font-size: 16px; font-weight: 700; color: hsl(var(--foreground)); }

    .rpt-fs-modal    { position: fixed; inset: 0; z-index: 100; background: hsl(var(--background));
                       display: flex; flex-direction: column; }
    .rpt-fs-head     { display: flex; align-items: center; gap: 12px; padding: 16px 24px;
                       border-bottom: 1px solid hsl(var(--border)); }
    .rpt-fs-body     { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1.2fr);
                       gap: 0; overflow: hidden; flex: 1; }
    @media (max-width: 1024px) {
        .rpt-fs-body { grid-template-columns: 1fr; grid-template-rows: auto 1fr; }
    }
    .rpt-fs-narrative { padding: 24px 28px; overflow-y: auto; border-right: 1px solid hsl(var(--border)); }
    .rpt-fs-narrative h4 { font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
                           color: hsl(var(--muted-foreground)); font-weight: 700; margin-top: 18px; }
    .rpt-fs-narrative h4:first-child { margin-top: 0; }
    .rpt-fs-narrative p  { font-size: 13.5px; line-height: 1.55; margin-top: 4px; }
    .rpt-fs-table-wrap { padding: 20px 24px; overflow: auto; }
</style>
@endpush

@section('content')
<div x-data="rptPoeOperations()" x-init="boot()" class="space-y-4">

    {{-- HEADER --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Operations · rpt-poe-operations</p>
            <h1 class="text-[20px] font-semibold leading-tight">Point of Entry Operations</h1>
            <p class="help-text mt-1">A live look at what is happening at the counters right now: who is waiting, how fast cases are flowing, and where the load is sitting.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="payload.window?.label || ''"></span>
            </span>
            <span class="topbar-chip topbar-chip-mono" x-show="ready">
                Waiting now: <span class="ml-1" x-text="payload.holding?.total ?? 0"></span>
            </span>
        </div>
    </section>

    {{-- FILTERS --}}
    <section class="card">
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-border/60">
            <span class="text-[13px] font-semibold">Choose what to look at</span>
            <div class="flex items-center gap-1.5">
                <button type="button" class="btn btn-ghost btn-xs text-muted-foreground" @click="resetFilters()">Reset to past 7 days</button>
                <button type="button" class="btn btn-brand btn-xs" @click="runReport()">Apply</button>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 p-4">
            <div>
                <label class="label block mb-1">Point of Entry</label>
                <select class="select w-full" x-model="filters.poe">
                    <option value="">All entry points</option>
                    <template x-for="(name, code) in (payload.meta?.poes || {})" :key="code">
                        <option :value="code" x-text="name"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1">Start date</label>
                <input type="date" class="input w-full text-[12px]" x-model="filters.start_date">
            </div>
            <div>
                <label class="label block mb-1">End date</label>
                <input type="date" class="input w-full text-[12px]" x-model="filters.end_date">
            </div>
            <div>
                <label class="label block mb-1">Year</label>
                <select class="select w-full" x-model="filters.year">
                    <option value="">Any year</option>
                    <template x-for="y in [2024, 2025, 2026, 2027]" :key="y">
                        <option :value="y" x-text="y"></option>
                    </template>
                </select>
            </div>
        </div>
        <div class="px-4 pb-3 -mt-1 text-[11.5px] text-muted-foreground">
            Leave everything blank to see the past 7 days. The "waiting now" block is always the live state, regardless of the date filter.
        </div>
    </section>

    {{-- HOLDING QUEUE --}}
    <section class="card" x-show="ready">
        <div class="px-4 py-2.5 border-b border-border/60 flex items-center gap-2">
            <span class="text-[13px] font-semibold">Travellers waiting at the counter right now</span>
            <span class="text-[11.5px] text-muted-foreground">live state · 20 min is the watch threshold</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 p-4 items-center">
            <div class="flex items-center gap-3">
                <div class="ring-meter">
                    <svg viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="hsl(var(--muted))" stroke-width="3"></circle>
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="hsl(var(--brand))" stroke-width="3"
                                stroke-linecap="round"
                                :stroke-dasharray="(100 - Math.max(0, Math.min(100, payload.holding?.pct_flagged ?? 0))) + ' 100'"></circle>
                    </svg>
                    <span class="ring-meter-val" x-text="fmt(payload.holding?.total ?? 0)"></span>
                </div>
                <div>
                    <p class="text-[12.5px] font-semibold">Total waiting</p>
                    <p class="kpi-sub">Open or in-progress full checks across your geography.</p>
                </div>
            </div>
            <div class="kpi-card">
                <span class="kpi-eyebrow">Waiting under 20 min</span>
                <span class="kpi-value" x-text="fmt(payload.holding?.under_20 ?? 0)"></span>
                <span class="kpi-sub">Inside the normal triage window.</span>
            </div>
            <div class="kpi-card" :class="(payload.holding?.over_20 ?? 0) > 0 ? 'kpi-card-danger' : 'kpi-card-good'">
                <span class="kpi-eyebrow">Waiting over 20 min</span>
                <span class="kpi-value" x-text="fmt(payload.holding?.over_20 ?? 0)"></span>
                <span class="kpi-sub">Send a supervisor — these have been at the counter too long.</span>
            </div>
            <div class="kpi-card">
                <span class="kpi-eyebrow">Longest wait right now</span>
                <span class="kpi-value" x-text="fmt(payload.holding?.longest_wait_minutes ?? 0) + ' min'"></span>
                <span class="kpi-sub">Time since the oldest open case was opened.</span>
            </div>
        </div>
    </section>

    {{-- PROCESSING + VELOCITY KPIs --}}
    <section class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3" x-show="ready">
        <div class="kpi-card kpi-card-brand">
            <span class="kpi-eyebrow">Average processing time</span>
            <span class="kpi-value" x-text="(payload.processing?.avg_minutes ?? null) === null ? '— (n<5)' : fmt(payload.processing?.avg_minutes) + ' min'"></span>
            <span class="kpi-sub">From opening to closing a full check, across the date window.</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-eyebrow">Median processing time</span>
            <span class="kpi-value" x-text="(payload.processing?.median_minutes ?? null) === null ? '— (n<5)' : fmt(payload.processing?.median_minutes) + ' min'"></span>
            <span class="kpi-sub">Half of cases close faster than this, half take longer.</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-eyebrow">Entries per hour today</span>
            <span class="kpi-value" x-text="fmt(payload.velocity?.entries_per_hour ?? 0)"></span>
            <span class="kpi-sub">Average new full checks opened per hour so far today.</span>
        </div>
        <div class="kpi-card" :class="((payload.velocity?.net_per_hour ?? 0) > 0) ? 'kpi-card-danger' : 'kpi-card-good'">
            <span class="kpi-eyebrow">Net per hour today</span>
            <span class="kpi-value" x-text="fmt(payload.velocity?.net_per_hour ?? 0)"></span>
            <span class="kpi-sub">Entries minus exits. A positive number means the queue is growing.</span>
        </div>
    </section>

    {{-- CHART: HOURLY VELOCITY --}}
    <section class="chart-card" x-show="ready">
        <div class="chart-card-head">
            <span class="text-[13px] font-semibold">Hour-by-hour velocity today</span>
            <div class="chart-toolbar">
                <button type="button" @click="explainChart('velocity')">Explain</button>
                <button type="button" @click="exportPng('chart-velocity', 'hourly-velocity.png')">PNG</button>
                <button type="button" @click="exportChartCsv('velocity')">CSV</button>
            </div>
        </div>
        <div class="chart-card-body">
            <canvas id="chart-velocity"></canvas>
        </div>
    </section>

    {{-- CHART: DAILY TREND --}}
    <section class="chart-card" x-show="ready">
        <div class="chart-card-head">
            <span class="text-[13px] font-semibold">Day-by-day volume in this window</span>
            <div class="chart-toolbar">
                <button type="button" @click="explainChart('daily')">Explain</button>
                <button type="button" @click="exportPng('chart-daily', 'daily-volume.png')">PNG</button>
                <button type="button" @click="exportChartCsv('daily')">CSV</button>
            </div>
        </div>
        <div class="chart-card-body">
            <canvas id="chart-daily"></canvas>
        </div>
    </section>

    {{-- POE LEAGUE TABLE --}}
    <section class="card" x-show="ready">
        <div class="chart-card-head">
            <span class="text-[13px] font-semibold">Entry-point league</span>
            <div class="chart-toolbar">
                <button type="button" @click="explainChart('poe_league')">Explain</button>
                <button type="button" @click="exportTableCsv('poe_league')">CSV</button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Entry point</th>
                        <th>Province</th>
                        <th>District</th>
                        <th class="text-right">Booth screenings</th>
                        <th class="text-right">Full checks opened</th>
                        <th class="text-right">Closed</th>
                        <th class="text-right">Waiting now</th>
                        <th class="text-right">Over 20 min</th>
                        <th class="text-right">Avg minutes</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in (payload.poe_league || [])" :key="row.poe_code">
                        <tr>
                            <td x-text="row.poe_name"></td>
                            <td class="text-muted-foreground" x-text="row.province"></td>
                            <td class="text-muted-foreground" x-text="row.district"></td>
                            <td class="text-right tabular-nums" x-text="fmt(row.primary)"></td>
                            <td class="text-right tabular-nums" x-text="fmt(row.secondary_opened)"></td>
                            <td class="text-right tabular-nums" x-text="fmt(row.secondary_closed)"></td>
                            <td class="text-right tabular-nums" x-text="fmt(row.holding)"></td>
                            <td class="text-right tabular-nums" x-text="fmt(row.holding_over_20)"></td>
                            <td class="text-right tabular-nums" x-text="(row.avg_minutes ?? null) === null ? '—' : (row.avg_minutes + ' min')"></td>
                        </tr>
                    </template>
                    <tr x-show="(payload.poe_league || []).length === 0">
                        <td colspan="9" class="text-center text-muted-foreground py-6">No entry points reported screenings in this window.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    {{-- SCREENER TABLES --}}
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-4" x-show="ready">
        <div class="card">
            <div class="chart-card-head">
                <span class="text-[13px] font-semibold">Officers at the booth</span>
                <div class="chart-toolbar">
                    <button type="button" @click="exportTableCsv('primary_screeners')">CSV</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Officer</th><th class="text-right">Booth screenings</th><th class="text-right">Per hour</th></tr></thead>
                    <tbody>
                        <template x-for="r in (payload.screeners?.primary || [])" :key="r.user_id">
                            <tr>
                                <td x-text="r.name"></td>
                                <td class="text-right tabular-nums" x-text="fmt(r.count)"></td>
                                <td class="text-right tabular-nums" x-text="fmt(r.per_hour)"></td>
                            </tr>
                        </template>
                        <tr x-show="(payload.screeners?.primary || []).length === 0">
                            <td colspan="3" class="text-center text-muted-foreground py-6">No officer activity in this window.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="chart-card-head">
                <span class="text-[13px] font-semibold">Officers handling full checks</span>
                <div class="chart-toolbar">
                    <button type="button" @click="exportTableCsv('secondary_screeners')">CSV</button>
                </div>
            </div>
            <div class="overflow-x-auto">
                <table class="data-table">
                    <thead><tr><th>Officer</th><th class="text-right">Opened</th><th class="text-right">Closed</th><th class="text-right">Avg minutes</th></tr></thead>
                    <tbody>
                        <template x-for="r in (payload.screeners?.secondary || [])" :key="r.user_id">
                            <tr>
                                <td x-text="r.name"></td>
                                <td class="text-right tabular-nums" x-text="fmt(r.opened)"></td>
                                <td class="text-right tabular-nums" x-text="fmt(r.closed)"></td>
                                <td class="text-right tabular-nums" x-text="(r.avg_minutes ?? null) === null ? '—' : (r.avg_minutes + ' min')"></td>
                            </tr>
                        </template>
                        <tr x-show="(payload.screeners?.secondary || []).length === 0">
                            <td colspan="4" class="text-center text-muted-foreground py-6">No officer activity in this window.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- DATA NOTES --}}
    <section class="card p-4 text-[12px] text-muted-foreground space-y-2" x-show="ready">
        <p><span class="font-semibold text-foreground">What this page shows.</span> The "waiting now" block is the live queue across your geography. Everything else is bound to the date filter.</p>
        <p><span class="font-semibold text-foreground">When numbers are hidden.</span> If fewer than five travellers fall into a slice, averages and percentages show a dash. This protects against unreliable small-sample numbers.</p>
        <p><span class="font-semibold text-foreground">Where the data comes from.</span> Booth screenings come from the primary screening records; full checks and waiting times come from the secondary screening records. Officer names come from the user directory.</p>
    </section>

    {{-- LOADING --}}
    <div x-show="!ready" class="card p-8 text-center text-muted-foreground">
        <div class="animate-spin h-6 w-6 border-2 border-current border-r-transparent rounded-full mx-auto mb-2"></div>
        Loading the report&hellip;
    </div>

    {{-- FULL-SCREEN EXPLAIN MODAL --}}
    <template x-teleport="body">
        <div x-show="explain.open" x-cloak class="rpt-fs-modal" role="dialog" aria-modal="true"
             @keydown.escape.window="explain.open = false">
            <div class="rpt-fs-head">
                <div class="min-w-0">
                    <p class="eyebrow">How to read this</p>
                    <h2 class="text-[18px] font-semibold leading-tight" x-text="explain.title"></h2>
                </div>
                <div class="flex-1"></div>
                <button type="button" class="btn btn-outline btn-sm" @click="explain.open = false">Close</button>
            </div>
            <div class="rpt-fs-body">
                <div class="rpt-fs-narrative">
                    <h4>What it shows</h4>
                    <p x-text="explain.shows"></p>
                    <h4>How to read it</h4>
                    <p x-text="explain.read"></p>
                    <h4>What good looks like</h4>
                    <p x-text="explain.good"></p>
                    <h4>What is concerning</h4>
                    <p x-text="explain.concerning"></p>
                    <h4>What to do if it is concerning</h4>
                    <p x-text="explain.todo"></p>
                </div>
                <div class="rpt-fs-table-wrap">
                    <table class="data-table" x-show="explain.table?.rows?.length">
                        <thead>
                            <tr>
                                <template x-for="h in (explain.table?.headers || [])" :key="h">
                                    <th x-text="h"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, i) in (explain.table?.rows || [])" :key="i">
                                <tr>
                                    <template x-for="(cell, j) in row" :key="j">
                                        <td x-text="cell"></td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <p x-show="!(explain.table?.rows?.length)" class="text-muted-foreground text-center mt-8">No data in this slice yet.</p>
                </div>
            </div>
        </div>
    </template>

</div>

@php $dataEndpoint = url('/admin/reports/rpt-poe-operations/data'); @endphp

@push('scripts')
<script>
function rptPoeOperations() {
    return {
        endpoint: @json($dataEndpoint),
        ready: false, payload: {}, charts: {},
        filters: { poe: '', start_date: '', end_date: '', year: '' },
        explain: { open: false, title: '', shows: '', read: '', good: '', concerning: '', todo: '', table: { headers: [], rows: [] } },

        boot() { this.hydrateFiltersFromUrl(); this.runReport(); },

        hydrateFiltersFromUrl() {
            try {
                const sp = new URLSearchParams(window.location.search);
                for (const k of Object.keys(this.filters)) {
                    if (sp.has(k)) this.filters[k] = sp.get(k) || '';
                }
            } catch (e) {}
        },

        async runReport() {
            this.ready = false;
            const params = new URLSearchParams();
            for (const [k, v] of Object.entries(this.filters)) {
                if (v !== '' && v !== null && v !== undefined) params.append(k, v);
            }
            try {
                const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
                window.history.replaceState({}, '', newUrl);
            } catch (e) {}
            try {
                const res = await fetch(this.endpoint + (params.toString() ? '?' + params.toString() : ''), {
                    headers: { 'Accept': 'application/json' }, credentials: 'same-origin',
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const body = await res.json();
                this.payload = body.data || {};
                await this.$nextTick();
                this.renderAllCharts();
            } catch (e) {
                console.error('[rpt-poe-operations] failed to load', e);
                this.payload = { kpis: {}, meta: {} };
            } finally {
                this.ready = true;
            }
        },

        resetFilters() {
            this.filters = { poe: '', start_date: '', end_date: '', year: '' };
            this.runReport();
        },

        fmt(n) {
            if (n === null || n === undefined) return '—';
            const v = Number(n);
            return Number.isFinite(v) ? v.toLocaleString() : '—';
        },

        themeColour(name) {
            const v = getComputedStyle(document.documentElement).getPropertyValue('--' + name).trim();
            return v ? `hsl(${v})` : '#888';
        },
        themeAlpha(name, a) {
            const v = getComputedStyle(document.documentElement).getPropertyValue('--' + name).trim();
            return v ? `hsl(${v} / ${a})` : `rgba(120,120,120,${a})`;
        },

        destroyChart(key) {
            if (this.charts[key]) { this.charts[key].destroy(); delete this.charts[key]; }
        },

        commonOpts(timeAxis = false) {
            const isMobile = window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
            const text = this.themeColour('muted-foreground');
            const grid = this.themeAlpha('border', .8);
            const font = { size: isMobile ? 10 : 11 };
            return {
                responsive: true, maintainAspectRatio: false,
                layout: { padding: { left: 4, right: 12, top: 4, bottom: 4 } },
                animation: { duration: 280 },
                plugins: {
                    legend: { position: 'bottom', labels: { color: text, font, boxWidth: 12, padding: 14 } },
                    tooltip: {
                        backgroundColor: this.themeColour('foreground'),
                        titleColor: this.themeColour('background'),
                        bodyColor:  this.themeColour('background'),
                        padding: 10, borderRadius: 8,
                        callbacks: { title: items => items.length ? String(items[0].label) : '' },
                    },
                },
                scales: {
                    x: { ticks: { color: text, font, autoSkip: true,
                         maxTicksLimit: timeAxis ? (isMobile ? 6 : 12) : undefined,
                         maxRotation: 0, minRotation: 0 },
                         grid: { color: grid, drawBorder: false } },
                    y: { beginAtZero: true,
                         ticks: { color: text, font },
                         grid: { color: grid, drawBorder: false } },
                },
            };
        },

        renderAllCharts() { this.renderVelocity(); this.renderDaily(); },

        renderVelocity() {
            this.destroyChart('velocity');
            const rows = this.payload.velocity?.hourly_curve || [];
            const ctx = document.getElementById('chart-velocity');
            if (!ctx || !rows.length) return;
            this.charts.velocity = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: rows.map(r => r.label),
                    datasets: [
                        { label: 'Cases opened', data: rows.map(r => r.entries),
                          backgroundColor: this.themeAlpha('brand', .85), borderRadius: 4 },
                        { label: 'Cases closed', data: rows.map(r => r.exits),
                          backgroundColor: this.themeAlpha('success', .8), borderRadius: 4 },
                    ],
                },
                options: this.commonOpts(true),
            });
        },

        renderDaily() {
            this.destroyChart('daily');
            const rows = this.payload.daily_trend || [];
            const ctx = document.getElementById('chart-daily');
            if (!ctx || !rows.length) return;
            this.charts.daily = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: rows.map(r => r.label),
                    datasets: [
                        { label: 'Booth screenings',   data: rows.map(r => r.primary),
                          borderColor: this.themeColour('brand'),
                          backgroundColor: this.themeAlpha('brand', .15),
                          tension: .25, fill: true, pointRadius: 3, pointHoverRadius: 5 },
                        { label: 'Full checks opened', data: rows.map(r => r.secondary),
                          borderColor: this.themeColour('brand-ink'),
                          backgroundColor: this.themeAlpha('brand-ink', .12),
                          tension: .25, fill: false, pointRadius: 3, pointHoverRadius: 5 },
                        { label: 'Full checks closed', data: rows.map(r => r.closures),
                          borderColor: this.themeColour('success'),
                          backgroundColor: this.themeAlpha('success', .12),
                          tension: .25, fill: false, pointRadius: 3, pointHoverRadius: 5 },
                    ],
                },
                options: this.commonOpts(true),
            });
        },

        exportPng(canvasId, fileName) {
            const ctx = document.getElementById(canvasId); if (!ctx) return;
            const out = document.createElement('canvas');
            out.width = ctx.width; out.height = ctx.height;
            const g = out.getContext('2d');
            g.fillStyle = this.themeColour('background');
            g.fillRect(0, 0, out.width, out.height);
            g.drawImage(ctx, 0, 0);
            const url = out.toDataURL('image/png');
            const a = document.createElement('a');
            a.href = url; a.download = fileName || 'chart.png';
            document.body.appendChild(a); a.click(); a.remove();
        },

        _downloadCsv(name, headers, rows) {
            const esc = v => v === null || v === undefined ? '' : (/[",\n]/.test(String(v)) ? '"' + String(v).replace(/"/g,'""') + '"' : String(v));
            const lines = [headers.map(esc).join(',')];
            for (const r of rows) lines.push(r.map(esc).join(','));
            const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
            URL.revokeObjectURL(url);
        },

        _table(key) {
            const p = this.payload || {};
            if (key === 'velocity') {
                const rows = (p.velocity?.hourly_curve || []).map(r => [r.label, r.entries, r.exits]);
                return { fileName: 'hourly-velocity.csv', headers: ['Hour', 'Cases opened', 'Cases closed'], rows };
            }
            if (key === 'daily') {
                const rows = (p.daily_trend || []).map(r => [r.label, r.primary, r.secondary, r.closures]);
                return { fileName: 'daily-volume.csv', headers: ['Date', 'Booth screenings', 'Full checks opened', 'Full checks closed'], rows };
            }
            if (key === 'poe_league') {
                const rows = (p.poe_league || []).map(r => [
                    r.poe_name, r.poe_code, r.province, r.district, r.poe_type,
                    r.primary, r.secondary_opened, r.secondary_closed,
                    r.holding, r.holding_over_20,
                    r.avg_minutes === null ? '—' : r.avg_minutes,
                ]);
                return { fileName: 'entry-point-operations.csv',
                    headers: ['Entry point','Code','Province','District','Type','Booth screenings','Full checks opened','Closed','Waiting now','Over 20 min','Avg minutes'],
                    rows };
            }
            if (key === 'primary_screeners') {
                const rows = (p.screeners?.primary || []).map(r => [r.name, r.count, r.per_hour]);
                return { fileName: 'officers-booth.csv', headers: ['Officer', 'Booth screenings', 'Per hour'], rows };
            }
            if (key === 'secondary_screeners') {
                const rows = (p.screeners?.secondary || []).map(r => [r.name, r.opened, r.closed, r.avg_minutes === null ? '—' : r.avg_minutes]);
                return { fileName: 'officers-full-check.csv', headers: ['Officer', 'Opened', 'Closed', 'Avg minutes'], rows };
            }
            return null;
        },

        exportChartCsv(k) { const t = this._table(k); if (t) this._downloadCsv(t.fileName, t.headers, t.rows); },
        exportTableCsv(k) { const t = this._table(k); if (t) this._downloadCsv(t.fileName, t.headers, t.rows); },

        _explainDict() {
            return {
                velocity: {
                    title: 'Hour-by-hour velocity today',
                    shows: 'For each hour so far today, two bars: the number of full checks opened that hour, and the number closed.',
                    read: 'Read left to right across the day. When the opened bar is taller than the closed bar, the queue is growing in that hour.',
                    good: 'Opened and closed bars roughly the same size across busy hours. The queue is moving.',
                    concerning: 'Opened consistently taller than closed for multiple hours, especially in the late morning or before shift change.',
                    todo: 'Move officers to the full-check counter, or open an extra counter. Check the "waiting over 20 min" number — that is where pressure shows up first.',
                },
                daily: {
                    title: 'Day-by-day volume in this window',
                    shows: 'For each day in the window, three lines: travellers at the booth, full checks opened, and full checks closed.',
                    read: 'A widening gap between the opened and closed lines means cases are accumulating. A widening gap between the booth and opened lines means a smaller share of travellers are being sent for full checks.',
                    good: 'The three lines move together with the closed line tracking the opened line closely.',
                    concerning: 'A sustained gap, or a sudden drop in booth volume that no business reason explains.',
                    todo: 'Cross-check with the entry-point league below — usually one or two points of entry drive the change.',
                },
                poe_league: {
                    title: 'Entry-point league',
                    shows: 'For each entry point that handled any cases, side-by-side counts of booth screenings, full checks opened and closed, the live queue, and average processing time.',
                    read: 'Sorted with the busiest entry point at the top. The "Over 20 min" column flags where queues are sitting.',
                    good: 'Activity spread across entry points without outliers; "Over 20 min" close to zero everywhere.',
                    concerning: 'One entry point dominating the waiting-now column, or a very large gap between opened and closed.',
                    todo: 'Tell the supervisor at that entry point. Surface staffing or a temporary closure as needed.',
                },
            };
        },

        explainChart(key) {
            const dict = this._explainDict()[key]; if (!dict) return;
            const tbl = this._table(key);
            this.explain.title      = dict.title;
            this.explain.shows      = dict.shows;
            this.explain.read       = dict.read;
            this.explain.good       = dict.good;
            this.explain.concerning = dict.concerning;
            this.explain.todo       = dict.todo;
            this.explain.table      = tbl ? { headers: tbl.headers, rows: tbl.rows } : { headers: [], rows: [] };
            this.explain.open       = true;
        },
    };
}
</script>
@endpush

@endsection
