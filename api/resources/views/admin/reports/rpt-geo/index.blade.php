@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Geographic Risk Intelligence')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
<div x-data="reportGeo()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('rpt-geo', wizard.open)"
     class="space-y-5">

    <section class="flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="min-w-0">
            <p class="eyebrow">My Reports · rpt-geo</p>
            <h1 class="text-[18px] font-semibold">Geographic Risk Intelligence</h1>
            <p class="help-text mt-0.5">Epidemic preparedness through origin & transit analysis.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready"><span class="status-dot status-dot-live"></span><span x-text="windowLabel()"></span></span>
            <button type="button" class="btn btn-ghost btn-sm" @click="openWizard()">Filters</button>
            @include('admin.reports._coach', ['reportKey' => 'rpt-geo'])
            <div class="inline-flex rounded-md border overflow-hidden" role="group" aria-label="Export options">
                <button type="button" class="btn btn-ghost btn-sm rounded-none border-r" @click="exportAs('CSV')">CSV</button>
                <button type="button" class="btn btn-ghost btn-sm rounded-none border-r" @click="exportAs('XLSX')">Excel</button>
                <button type="button" class="btn btn-ghost btn-sm rounded-none" @click="exportAs('PDF')">Print / PDF</button>
            </div>
        </div>
    </section>

    <template x-if="!ready">
        <section class="card"><div class="card-content py-10 text-center space-y-3">
            <h2 class="text-[15px] font-semibold">Configure your filter window</h2>
            <button type="button" class="btn btn-primary" @click="openWizard()">Open filter wizard</button>
        </div></section>
    </template>

    <template x-if="ready">
        <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="kpi kpi-glow"><p class="kpi-label">High-risk arrivals</p><p class="kpi-value tabular-nums text-critical" x-text="formatNum(kpis.high_risk_arrivals)"></p></div>
            <div class="kpi"><p class="kpi-label">Distinct origins</p><p class="kpi-value tabular-nums" x-text="formatNum(kpis.distinct_origins)"></p></div>
            <div class="kpi"><p class="kpi-label">Endemic origins</p><p class="kpi-value tabular-nums text-warning" x-text="formatNum(kpis.endemic_origins)"></p></div>
            <div class="kpi"><p class="kpi-label">POEs touched</p><p class="kpi-value tabular-nums" x-text="formatNum(kpis.poes_touched)"></p></div>
        </section>
    </template>

    <template x-if="ready">
        <section class="card"><div class="card-content !p-0">
            <nav class="flex flex-wrap gap-0 border-b" role="tablist" aria-label="Geographic risk tabs">
                <template x-for="t in tabs" :key="t.key">
                    <button type="button" :id="'tab-' + t.key" class="px-4 py-3 text-[12.5px] font-medium border-b-2 transition-colors"
                            :class="tab === t.key ? 'border-brand text-brand' : 'border-transparent text-muted-foreground hover:text-foreground'"
                            :aria-selected="tab === t.key ? 'true' : 'false'"
                            :aria-controls="'panel-' + t.key" role="tab" @click="tab = t.key"><span x-text="t.label"></span></button>
                </template>
            </nav>

            <div role="tabpanel" id="panel-highrisk" aria-labelledby="tab-highrisk" x-show="tab === 'highrisk'" class="p-4 sm:p-5">
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">Symptomatic arrivals by origin</h3>
                    <div class="relative h-[380px]"><canvas x-ref="originBar"></canvas></div>
                </div></div>
            </div>

            <div role="tabpanel" id="panel-origins" aria-labelledby="tab-origins" x-show="tab === 'origins'" x-cloak class="p-4 sm:p-5">
                <div class="table-wrap"><table class="table">
                    <thead><tr>
                        <th scope="col">Origin country</th>
                        <th scope="col" class="text-right">Total arrivals</th>
                        <th scope="col" class="text-right">Symptomatic</th>
                        <th scope="col">Endemic zone</th>
                    </tr></thead>
                    <tbody>
                        <template x-if="origins.length === 0"><tr><td colspan="4" class="text-center text-muted-foreground py-6">No origin data.</td></tr></template>
                        <template x-for="r in origins" :key="r.country">
                            <tr>
                                <td class="font-medium" x-text="r.country"></td>
                                <td class="text-right tabular-nums" x-text="formatNum(r.total)"></td>
                                <td class="text-right tabular-nums text-warning" x-text="formatNum(r.symptomatic)"></td>
                                <td><span class="badge" :class="r.endemic ? 'badge-critical' : 'badge-outline'" x-text="r.endemic ? 'YES' : 'no'"></span></td>
                            </tr>
                        </template>
                    </tbody>
                </table></div>
            </div>

            <div role="tabpanel" id="panel-routes" aria-labelledby="tab-routes" x-show="tab === 'routes'" x-cloak class="p-4 sm:p-5">
                <div class="table-wrap"><table class="table">
                    <thead><tr>
                        <th scope="col">Origin → POE</th>
                        <th scope="col" class="text-right">Total</th>
                        <th scope="col" class="text-right">Symptomatic</th>
                    </tr></thead>
                    <tbody>
                        <template x-if="routes.length === 0"><tr><td colspan="3" class="text-center text-muted-foreground py-6">No transit data.</td></tr></template>
                        <template x-for="(r, i) in routes" :key="i">
                            <tr>
                                <td><span class="font-mono text-[11.5px]" x-text="r.origin + ' → ' + r.poe"></span></td>
                                <td class="text-right tabular-nums" x-text="formatNum(r.total)"></td>
                                <td class="text-right tabular-nums text-warning" x-text="formatNum(r.symptomatic)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table></div>
            </div>

            <div role="tabpanel" id="panel-endemic" aria-labelledby="tab-endemic" x-show="tab === 'endemic'" x-cloak class="p-4 sm:p-5">
                <div class="table-wrap"><table class="table">
                    <thead><tr>
                        <th scope="col">Endemic origin</th>
                        <th scope="col" class="text-right">Total</th>
                        <th scope="col" class="text-right">Symptomatic</th>
                    </tr></thead>
                    <tbody>
                        <template x-if="endemic.length === 0"><tr><td colspan="3" class="text-center text-muted-foreground py-6">No endemic-zone arrivals in this window.</td></tr></template>
                        <template x-for="r in endemic" :key="r.country">
                            <tr>
                                <td class="font-medium" x-text="r.country"></td>
                                <td class="text-right tabular-nums" x-text="formatNum(r.total)"></td>
                                <td class="text-right tabular-nums text-critical" x-text="formatNum(r.symptomatic)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table></div>
            </div>

            <div role="tabpanel" id="panel-borders" aria-labelledby="tab-borders" x-show="tab === 'borders'" x-cloak class="p-4 sm:p-5 grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                <template x-if="borders.length === 0"><div class="card lg:col-span-3"><div class="card-content text-center text-muted-foreground py-8">No border profiles in this window.</div></div></template>
                <template x-for="b in borders" :key="b.poe">
                    <div class="card"><div class="card-content space-y-2">
                        <h3 class="text-[13px] font-semibold truncate" x-text="b.poe"></h3>
                        <dl class="grid grid-cols-2 gap-y-1 text-[11.5px]">
                            <dt class="text-muted-foreground">Primary</dt><dd class="text-right tabular-nums" x-text="formatNum(b.primary)"></dd>
                            <dt class="text-muted-foreground">Secondary</dt><dd class="text-right tabular-nums" x-text="formatNum(b.secondary)"></dd>
                            <dt class="text-muted-foreground">High-risk</dt><dd class="text-right tabular-nums text-critical" x-text="formatNum(b.high_risk)"></dd>
                            <dt class="text-muted-foreground">Symptomatic</dt><dd class="text-right tabular-nums text-warning" x-text="formatNum(b.symptomatic)"></dd>
                        </dl>
                    </div></div>
                </template>
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

    function reportGeo() {
        return {
            ready: false, tab: 'highrisk', notesOpen: false,
            tabs: [
                { key:'highrisk', label:'High-Risk Arrivals' }, { key:'origins', label:'Origin Analysis' },
                { key:'routes',   label:'Transit Routes' },     { key:'endemic', label:'Endemic Zones' },
                { key:'borders',  label:'Border Profiles' },
            ],
            wizard: { open:false, step:1 },
            filters: { poe:'', sex:'', year:'', quarter:'', month:'', start_date:'', end_date:'' },
            meta: { poes:{}, districts:{}, provinces:{}, years:[], quarters:{}, months:{}, genders:{} },
            kpis:{}, origins:[], routes:[], endemic:[], borders:[],
            insights:[], dataNotes:{}, window:{from:'',to:''}, charts:{},

            async boot() { this.restoreFiltersFromUrl(); await this.loadMeta(); if (this.urlHasRun() || this.anyFilter()) await this.runReport(); },
            anyFilter() { return Object.values(this.filters).some(v => v !== '' && v !== null); },
            async loadMeta() { try { const r = await rptJson(@json(url('/admin/reports/meta'))); this.meta = Object.assign(this.meta, r?.data || {}); } catch (e) {} },
            urlHasRun() { return new URLSearchParams(window.location.search).get('run') === '1'; },
            restoreFiltersFromUrl() { const u = new URLSearchParams(window.location.search); for (const k of Object.keys(this.filters)) { const v = u.get(k); if (v !== null) this.filters[k] = v; } },
            writeFiltersToUrl() { const u = new URLSearchParams(); for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) u.set(k, v); u.set('run','1'); window.history.replaceState(null, '', window.location.pathname + '?' + u.toString()); },
            openWizard() { this.wizard.open = true; this.wizard.step = 1; },
            resetFilters() { this.filters = { poe:'', sex:'', year:'', quarter:'', month:'', start_date:'', end_date:'' }; window.history.replaceState(null, '', window.location.pathname); },

            async runReport() {
                this.writeFiltersToUrl();
                try {
                    const r = await rptJson(@json(url('/admin/reports/rpt-geo/data')), this.buildParams());
                    const d = r?.data || {};
                    this.kpis = d.kpis || {}; this.origins = d.origins || []; this.routes = d.routes || [];
                    this.endemic = d.endemic || []; this.borders = d.borders || [];
                    this.insights = d.insights || []; this.dataNotes = d.data_notes || {};
                    this.window = d.window || {}; this.ready = true;
                    this.$nextTick(() => this.renderCharts());
                } catch (e) { console.error(e); this.ready = false; }
            },
            buildParams() { const p = {}; for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) p[k] = v; return p; },
            exportAs(fmt) { const u = new URL(@json(url('/admin/reports/rpt-geo/export')), window.location.origin); for (const [k,v] of Object.entries(this.buildParams())) u.searchParams.set(k, v); u.searchParams.set('format', fmt); if (fmt === 'PDF') window.open(u.toString(), '_blank', 'noopener'); else window.location.href = u.toString(); },
            formatNum(v) { return (v == null) ? '—' : Number(v).toLocaleString(); },
            windowLabel() { return this.window.from ? (this.window.from + ' → ' + this.window.to) : 'No window'; },

            renderCharts() {
                if (typeof Chart === 'undefined') return;
                Object.values(this.charts).forEach(c => { try { c.destroy(); } catch (e) {} });
                this.charts = {};
                if (this.$refs.originBar && this.origins.length) {
                    const top = this.origins.slice(0, 15);
                    this.charts.originBar = new Chart(this.$refs.originBar, {
                        type: 'bar',
                        data: { labels: top.map(r => r.country),
                                datasets: [
                                    { label: 'Symptomatic', data: top.map(r => r.symptomatic), backgroundColor: '#DC2626' },
                                    { label: 'Other',       data: top.map(r => Math.max(0, r.total - r.symptomatic)), backgroundColor: '#94A3B8' },
                                ] },
                        options: { indexAxis: 'y', scales: { x: { stacked:true, beginAtZero:true }, y: { stacked:true } } },
                    });
                }
            },
        };
    }
</script>
@endpush
@endsection
