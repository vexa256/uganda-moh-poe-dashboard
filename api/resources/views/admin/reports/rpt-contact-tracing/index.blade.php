@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Contact Tracing Readiness')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
<div x-data="reportContactTracing()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('rpt-contact', wizard.open)"
     class="space-y-5">

    <section class="flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="min-w-0">
            <p class="eyebrow">My Reports · rpt-contact-tracing</p>
            <h1 class="text-[18px] font-semibold">Contact Tracing Readiness</h1>
            <p class="help-text mt-0.5">Data quality check for rapid contact-tracing operations. Default window: last 30 days.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready"><span class="status-dot status-dot-live"></span><span x-text="windowLabel()"></span></span>
            <button type="button" class="btn btn-ghost btn-sm" @click="openWizard()">Filters</button>
            @include('admin.reports._coach', ['reportKey' => 'rpt-contact-tracing'])
            <div class="inline-flex rounded-md border overflow-hidden" role="group" aria-label="Export options">
                <button type="button" class="btn btn-ghost btn-sm rounded-none border-r" @click="exportAs('CSV')">CSV</button>
                <button type="button" class="btn btn-ghost btn-sm rounded-none border-r" @click="exportAs('XLSX')">Excel</button>
                <button type="button" class="btn btn-ghost btn-sm rounded-none" @click="exportAs('PDF')">Print / PDF</button>
            </div>
        </div>
    </section>

    <template x-if="ready">
        <section class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div class="kpi kpi-glow"><p class="kpi-label">Total screenings</p><p class="kpi-value tabular-nums" x-text="formatNum(kpis.total_screenings)"></p></div>
            <div class="kpi"><p class="kpi-label">Completeness score</p><p class="kpi-value tabular-nums" :class="scoreClass(kpis.completeness_score)" x-text="pctOf(kpis.completeness_score)"></p></div>
            <div class="kpi"><p class="kpi-label">Complete contact info</p><p class="kpi-value tabular-nums" x-text="pctOf(kpis.complete_contact_info_pct)"></p></div>
            <div class="kpi"><p class="kpi-label">Missing phone</p><p class="kpi-value tabular-nums text-warning" x-text="pctOf(kpis.missing_phone_pct)"></p></div>
            <div class="kpi"><p class="kpi-label">High-risk cases</p><p class="kpi-value tabular-nums text-critical" x-text="formatNum(kpis.high_risk_cases)"></p></div>
        </section>
    </template>

    <template x-if="!ready">
        <section class="card"><div class="card-content py-10 text-center space-y-3">
            <h2 class="text-[15px] font-semibold">Run the 30-day readiness check</h2>
            <p class="help-text">Default window is the last 30 days. Open filters to override.</p>
            <div class="flex items-center gap-2 justify-center">
                <button type="button" class="btn btn-ghost" @click="openWizard()">Open filter wizard</button>
                <button type="button" class="btn btn-primary" @click="runReport()">Run now</button>
            </div>
        </div></section>
    </template>

    <template x-if="ready">
        <section class="card"><div class="card-content !p-0">
            <nav class="flex flex-wrap gap-0 border-b" role="tablist" aria-label="Contact tracing tabs">
                <template x-for="t in tabs" :key="t.key">
                    <button type="button" :id="'tab-' + t.key" class="px-4 py-3 text-[12.5px] font-medium border-b-2 transition-colors"
                            :class="tab === t.key ? 'border-brand text-brand' : 'border-transparent text-muted-foreground hover:text-foreground'"
                            :aria-selected="tab === t.key ? 'true' : 'false'"
                            :aria-controls="'panel-' + t.key" role="tab" @click="tab = t.key"><span x-text="t.label"></span></button>
                </template>
            </nav>

            <div role="tabpanel" id="panel-overview" aria-labelledby="tab-overview" x-show="tab === 'overview'" class="p-4 sm:p-5 grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">Completeness gauge</h3>
                    <div class="relative h-[260px]"><canvas x-ref="gauge"></canvas></div>
                    <p class="text-center text-[11.5px] text-muted-foreground mt-2">
                        Score <span class="tabular-nums font-semibold" x-text="pctOf(kpis.completeness_score)"></span> · target ≥ 90 %
                    </p>
                </div></div>
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">Field-level completeness</h3>
                    <div class="relative h-[300px]"><canvas x-ref="fieldsBar"></canvas></div>
                </div></div>
            </div>

            <div role="tabpanel" id="panel-screeners" aria-labelledby="tab-screeners" x-show="tab === 'screeners'" x-cloak class="p-4 sm:p-5">
                <div class="table-wrap"><table class="table">
                    <thead><tr>
                        <th scope="col">Screener</th>
                        <th scope="col" class="text-right">Cases</th>
                        <th scope="col" class="text-right">Completeness</th>
                        <th scope="col" class="text-right">Missing phone</th>
                        <th scope="col" class="text-right">Missing address</th>
                    </tr></thead>
                    <tbody>
                        <template x-if="screeners.length === 0"><tr><td colspan="5" class="text-center text-muted-foreground py-6">No screeners in this window.</td></tr></template>
                        <template x-for="r in screeners" :key="r.user_id">
                            <tr>
                                <td class="font-medium" x-text="r.screener"></td>
                                <td class="text-right tabular-nums" x-text="formatNum(r.cases)"></td>
                                <td class="text-right tabular-nums" :class="scoreClass(r.completeness)" x-text="pctOf(r.completeness)"></td>
                                <td class="text-right tabular-nums text-warning" x-text="formatNum(r.missing_phone)"></td>
                                <td class="text-right tabular-nums" x-text="formatNum(r.missing_address)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table></div>
            </div>

            <div role="tabpanel" id="panel-fields" aria-labelledby="tab-fields" x-show="tab === 'fields'" x-cloak class="p-4 sm:p-5">
                <div class="table-wrap"><table class="table">
                    <thead><tr>
                        <th scope="col">Field</th>
                        <th scope="col" class="text-right">Present</th>
                        <th scope="col" class="text-right">Missing</th>
                        <th scope="col" class="text-right">Coverage</th>
                    </tr></thead>
                    <tbody>
                        <template x-if="fields.length === 0"><tr><td colspan="4" class="text-center text-muted-foreground py-6">No data.</td></tr></template>
                        <template x-for="r in fields" :key="r.field">
                            <tr>
                                <td class="font-mono text-[12px]" x-text="r.field"></td>
                                <td class="text-right tabular-nums" x-text="formatNum(r.present)"></td>
                                <td class="text-right tabular-nums text-critical" x-text="formatNum(r.missing)"></td>
                                <td class="text-right tabular-nums" :class="scoreClass(r.pct)" x-text="pctOf(r.pct)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table></div>
            </div>

            <div role="tabpanel" id="panel-poes" aria-labelledby="tab-poes" x-show="tab === 'poes'" x-cloak class="p-4 sm:p-5">
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">POE completeness radar</h3>
                    <div class="relative h-[360px]"><canvas x-ref="poeRadar"></canvas></div>
                </div></div>
                <div class="table-wrap mt-4"><table class="table">
                    <thead><tr>
                        <th scope="col">POE</th>
                        <th scope="col" class="text-right">Cases</th>
                        <th scope="col" class="text-right">Completeness</th>
                    </tr></thead>
                    <tbody>
                        <template x-if="poes.length === 0"><tr><td colspan="3" class="text-center text-muted-foreground py-6">No POE data.</td></tr></template>
                        <template x-for="r in poes" :key="r.poe">
                            <tr>
                                <td class="font-medium" x-text="r.poe"></td>
                                <td class="text-right tabular-nums" x-text="formatNum(r.cases)"></td>
                                <td class="text-right tabular-nums" :class="scoreClass(r.completeness)" x-text="pctOf(r.completeness)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table></div>
            </div>

            <div role="tabpanel" id="panel-trend" aria-labelledby="tab-trend" x-show="tab === 'trend'" x-cloak class="p-4 sm:p-5">
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">Completeness trend</h3>
                    <div class="relative h-[360px]"><canvas x-ref="trendLine"></canvas></div>
                </div></div>
            </div>
        </div></section>
    </template>

    <template x-if="ready">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @include('admin.reports._insights')
            @include('admin.reports._data_notes')
        </div>
    </template>

    @include('admin.reports._filter_wizard')
</div>

@push('scripts')
<script>
    if (typeof Chart !== 'undefined') { Chart.defaults.animation = false; Chart.defaults.maintainAspectRatio = false; }
    // Small fetch-based JSON client (we don't load axios globally).
    async function rptJson(url, params) {
        const u = new URL(url, window.location.origin);
        if (params) { for (const [k, v] of Object.entries(params)) { if (v !== '' && v !== null && v !== undefined) u.searchParams.set(k, v); } }
        const resp = await fetch(u.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!resp.ok) { throw new Error('HTTP ' + resp.status); }
        return await resp.json();
    }

    function reportContactTracing() {
        return {
            ready: false, tab: 'overview', notesOpen: false,
            tabs: [
                { key:'overview', label:'Overview' }, { key:'screeners', label:'Screeners' },
                { key:'fields', label:'Field coverage' }, { key:'poes', label:'POEs' },
                { key:'trend', label:'Trends' },
            ],
            wizard: { open:false, step:1 },
            filters: { poe:'', sex:'', year:'', quarter:'', month:'', start_date:'', end_date:'' },
            meta: { poes:{}, districts:{}, provinces:{}, years:[], quarters:{}, months:{}, genders:{} },
            kpis:{}, fields:[], screeners:[], poes:[], trend:[],
            insights:[], dataNotes:{}, window:{from:'',to:''}, charts:{},

            async boot() { this.restoreFiltersFromUrl(); await this.loadMeta(); /* auto-run disabled — user must click Apply */ },
            async loadMeta() { try { const r = await rptJson(@json(url('/admin/reports/meta'))); this.meta = Object.assign(this.meta, r?.data || {}); } catch (e) {} },
            urlHasRun() { return new URLSearchParams(window.location.search).get('run') === '1'; },
            restoreFiltersFromUrl() { const u = new URLSearchParams(window.location.search); for (const k of Object.keys(this.filters)) { const v = u.get(k); if (v !== null) this.filters[k] = v; } },
            writeFiltersToUrl() { const u = new URLSearchParams(); for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) u.set(k, v); u.set('run','1'); window.history.replaceState(null, '', window.location.pathname + '?' + u.toString()); },
            openWizard() { this.wizard.open = true; this.wizard.step = 1; },
            resetFilters() { this.filters = { poe:'', sex:'', year:'', quarter:'', month:'', start_date:'', end_date:'' }; window.history.replaceState(null, '', window.location.pathname); },

            async runReport() {
                this.writeFiltersToUrl();
                try {
                    const r = await rptJson(@json(url('/admin/reports/rpt-contact-tracing/data')), this.buildParams());
                    const d = r?.data || {};
                    this.kpis = d.kpis || {}; this.fields = d.fields || []; this.screeners = d.screeners || [];
                    this.poes = d.poes || []; this.trend = d.trend || [];
                    this.insights = d.insights || []; this.dataNotes = d.data_notes || {};
                    this.window = d.window || {}; this.ready = true;
                    this.$nextTick(() => this.renderCharts());
                } catch (e) { console.error(e); this.ready = false; }
            },
            buildParams() { const p = {}; for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) p[k] = v; return p; },
            exportAs(fmt) { const u = new URL(@json(url('/admin/reports/rpt-contact-tracing/export')), window.location.origin); for (const [k,v] of Object.entries(this.buildParams())) u.searchParams.set(k, v); u.searchParams.set('format', fmt); if (fmt === 'PDF') window.open(u.toString(), '_blank', 'noopener'); else window.location.href = u.toString(); },
            formatNum(v) { return (v == null) ? '—' : Number(v).toLocaleString(); },
            pctOf(v) { return (v == null) ? '—' : (v * 100).toFixed(1) + '%'; },
            scoreClass(v) { if (v == null) return ''; if (v >= 0.9) return 'text-success'; if (v >= 0.7) return 'text-warning'; return 'text-critical'; },
            windowLabel() { return this.window.from ? (this.window.from + ' → ' + this.window.to) : 'No window'; },

            renderCharts() {
                if (typeof Chart === 'undefined') return;
                Object.values(this.charts).forEach(c => { try { c.destroy(); } catch (e) {} });
                this.charts = {};
                const score = Number(this.kpis.completeness_score || 0);
                if (this.$refs.gauge) {
                    this.charts.gauge = new Chart(this.$refs.gauge, {
                        type: 'doughnut',
                        data: { labels: ['Complete', 'Missing'],
                                datasets: [{ data: [score, Math.max(0, 1 - score)],
                                             backgroundColor: [score >= 0.9 ? '#10B981' : score >= 0.7 ? '#F59E0B' : '#DC2626', '#E5E7EB'],
                                             borderWidth: 0 }] },
                        options: { circumference: 180, rotation: 270, cutout: '68%', plugins: { legend: { display: false } } },
                    });
                }
                if (this.$refs.fieldsBar && this.fields.length) {
                    this.charts.fieldsBar = new Chart(this.$refs.fieldsBar, {
                        type: 'bar',
                        data: { labels: this.fields.map(f => f.field),
                                datasets: [
                                    { label: 'Present', data: this.fields.map(f => f.present), backgroundColor: '#10B981' },
                                    { label: 'Missing', data: this.fields.map(f => f.missing), backgroundColor: '#DC2626' },
                                ] },
                        options: { indexAxis: 'y', scales: { x: { stacked:true, beginAtZero:true }, y: { stacked:true } } },
                    });
                }
                if (this.$refs.poeRadar && this.poes.length) {
                    const top = this.poes.slice(0, 8);
                    this.charts.poeRadar = new Chart(this.$refs.poeRadar, {
                        type: 'radar',
                        data: { labels: top.map(p => p.poe),
                                datasets: [{ label: 'Completeness', data: top.map(p => p.completeness * 100),
                                             backgroundColor: 'rgba(99, 102, 241, 0.25)', borderColor: '#6366F1', borderWidth: 2 }] },
                        options: { scales: { r: { beginAtZero:true, max:100 } } },
                    });
                }
                if (this.$refs.trendLine && this.trend.length) {
                    this.charts.trendLine = new Chart(this.$refs.trendLine, {
                        type: 'line',
                        data: { labels: this.trend.map(r => r.day),
                                datasets: [{ label: 'Completeness %', data: this.trend.map(r => r.completeness * 100),
                                             borderColor: '#6366F1', backgroundColor: 'rgba(99, 102, 241, 0.15)', tension: 0.3, fill: true }] },
                        options: { scales: { y: { beginAtZero:true, max:100 } } },
                    });
                }
            },
        };
    }
</script>
@endpush
@endsection
