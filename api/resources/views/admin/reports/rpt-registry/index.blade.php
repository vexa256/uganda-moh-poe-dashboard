@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'National Notifiable Cases Registry')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
<div x-data="reportRegistry()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('rpt-registry', wizard.open)"
     class="space-y-5">

    <section class="flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="min-w-0">
            <p class="eyebrow">My Reports · rpt-registry</p>
            <h1 class="text-[18px] font-semibold">National Notifiable Cases Registry</h1>
            <p class="help-text mt-0.5">Identifiable case database with outcome tracking. PII is masked below National / PHEOC tier.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready"><span class="status-dot status-dot-live"></span><span x-text="windowLabel()"></span></span>
            <button type="button" class="btn btn-ghost btn-sm" @click="openWizard()">Filters</button>
            @include('admin.reports._coach', ['reportKey' => 'rpt-registry'])
            <div class="inline-flex rounded-md border overflow-hidden" role="group" aria-label="Export options">
                <button type="button" class="btn btn-ghost btn-sm rounded-none border-r" @click="exportAs('CSV')">CSV</button>
                <button type="button" class="btn btn-ghost btn-sm rounded-none border-r" @click="exportAs('XLSX')">Excel</button>
                <button type="button" class="btn btn-ghost btn-sm rounded-none" @click="exportAs('PDF')">Print / PDF</button>
            </div>
        </div>
    </section>

    <template x-if="ready">
        <section class="grid grid-cols-2 sm:grid-cols-7 gap-3">
            <div class="kpi kpi-glow"><p class="kpi-label">Total</p><p class="kpi-value tabular-nums" x-text="formatNum(kpis.total)"></p></div>
            <div class="kpi"><p class="kpi-label">Suspected</p><p class="kpi-value tabular-nums" x-text="formatNum(kpis.suspected)"></p></div>
            <div class="kpi"><p class="kpi-label">Pending</p><p class="kpi-value tabular-nums text-warning" x-text="formatNum(kpis.pending)"></p></div>
            <div class="kpi"><p class="kpi-label">With outcome</p><p class="kpi-value tabular-nums text-success" x-text="formatNum(kpis.with_outcomes)"></p></div>
            <div class="kpi"><p class="kpi-label">Confirmed</p><p class="kpi-value tabular-nums text-critical" x-text="formatNum(kpis.confirmed)"></p></div>
            <div class="kpi"><p class="kpi-label">High risk</p><p class="kpi-value tabular-nums text-critical" x-text="formatNum(kpis.high_risk)"></p></div>
            <div class="kpi"><p class="kpi-label">Referrals</p><p class="kpi-value tabular-nums" x-text="formatNum(kpis.referrals)"></p></div>
        </section>
    </template>

    <template x-if="!ready">
        <section class="card"><div class="card-content py-10 text-center space-y-3">
            <h2 class="text-[15px] font-semibold">Load the registry</h2>
            <p class="help-text">Defaults to the current year. Open filters to adjust.</p>
            <div class="flex items-center gap-2 justify-center">
                <button type="button" class="btn btn-ghost" @click="openWizard()">Open filter wizard</button>
                <button type="button" class="btn btn-primary" @click="runReport()">Load</button>
            </div>
        </div></section>
    </template>

    <template x-if="ready">
        <section class="card"><div class="card-content !p-0">
            <nav class="flex flex-wrap gap-0 border-b" role="tablist" aria-label="Registry tabs">
                <template x-for="t in tabs" :key="t.key">
                    <button type="button" :id="'tab-' + t.key" class="px-4 py-3 text-[12.5px] font-medium border-b-2 transition-colors"
                            :class="tab === t.key ? 'border-brand text-brand' : 'border-transparent text-muted-foreground hover:text-foreground'"
                            :aria-selected="tab === t.key ? 'true' : 'false'"
                            :aria-controls="'panel-' + t.key" role="tab" @click="tab = t.key"><span x-text="t.label"></span></button>
                </template>
            </nav>

            <div role="tabpanel" id="panel-cases" aria-labelledby="tab-cases" x-show="tab === 'cases'" class="p-4 sm:p-5">
                <div class="flex flex-col sm:flex-row gap-2 mb-3">
                    <input type="search" class="input w-full sm:w-64" placeholder="Search name / document…" x-model.debounce.250ms="filters.q" @keydown.enter="runReport()">
                    <select class="select w-full sm:w-40" x-model="filters.classification">
                        <option value="">Any risk</option>
                        <option value="LOW">Low</option><option value="MEDIUM">Medium</option>
                        <option value="HIGH">High</option><option value="CRITICAL">Critical</option>
                    </select>
                    <select class="select w-full sm:w-44" x-model="filters.outcome">
                        <option value="">Any outcome</option>
                        <option value="RELEASED">Released</option><option value="REFERRED">Referred</option>
                        <option value="TRANSFERRED">Transferred</option><option value="QUARANTINED">Quarantined</option>
                        <option value="ISOLATED">Isolated</option><option value="DENIED_BOARDING">Denied boarding</option>
                    </select>
                    <button type="button" class="btn btn-primary" @click="runReport()">Apply</button>
                </div>
                <div class="table-wrap"><table class="table">
                    <thead><tr>
                        <th scope="col">Case #</th>
                        <th scope="col">Opened</th>
                        <th scope="col">Traveller</th>
                        <th scope="col">Age</th>
                        <th scope="col">Gender</th>
                        <th scope="col">Risk</th>
                        <th scope="col">EOC</th>
                        <th scope="col">POE</th>
                        <th scope="col">Outcome</th>
                        <th scope="col">Phone</th>
                    </tr></thead>
                    <tbody>
                        <template x-if="cases.length === 0"><tr><td colspan="10" class="text-center text-muted-foreground py-6">No cases matched your filters.</td></tr></template>
                        <template x-for="c in cases" :key="c.id">
                            <tr>
                                <td class="font-mono text-[11.5px]" x-text="'#' + c.id"></td>
                                <td x-text="c.opened_at"></td>
                                <td class="font-medium" x-text="c.traveler"></td>
                                <td x-text="c.age ?? '—'"></td>
                                <td x-text="c.gender"></td>
                                <td><span class="badge" :class="riskBadge(c.risk_level)" x-text="c.risk_level || '—'"></span></td>
                                <td class="truncate max-w-[140px]" x-text="c.province_code || '—'"></td>
                                <td class="truncate max-w-[140px]" x-text="c.poe_code"></td>
                                <td x-text="c.final_disposition || c.case_status"></td>
                                <td class="font-mono text-[11.5px]" x-text="c.phone_number || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table></div>
                <div class="flex items-center justify-between mt-3 text-[12px]" x-show="pagination.pages > 1">
                    <p class="text-muted-foreground">Page <span x-text="pagination.page"></span> of <span x-text="pagination.pages"></span> · <span x-text="formatNum(pagination.total)"></span> cases</p>
                    <div class="flex items-center gap-2">
                        <button type="button" class="btn btn-ghost btn-sm" :disabled="pagination.page <= 1" @click="goto(pagination.page - 1)">Prev</button>
                        <button type="button" class="btn btn-ghost btn-sm" :disabled="pagination.page >= pagination.pages" @click="goto(pagination.page + 1)">Next</button>
                    </div>
                </div>
            </div>

            <div role="tabpanel" id="panel-outcomes" aria-labelledby="tab-outcomes" x-show="tab === 'outcomes'" x-cloak class="p-4 sm:p-5">
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">Outcome funnel</h3>
                    <div class="relative h-[340px]"><canvas x-ref="outcomeBar"></canvas></div>
                    <details class="mt-3">
                        <summary class="text-[11px] cursor-pointer text-muted-foreground hover:text-foreground">View as table</summary>
                        <div class="table-wrap mt-2"><table class="table text-[12px]">
                            <thead><tr><th scope="col">Outcome</th><th scope="col" class="text-right">Cases</th></tr></thead>
                            <tbody>
                                <template x-for="(v, k) in outcomes" :key="k"><tr><td x-text="k"></td><td class="text-right tabular-nums" x-text="formatNum(v)"></td></tr></template>
                            </tbody>
                        </table></div>
                    </details>
                </div></div>
            </div>

            <div role="tabpanel" id="panel-geo" aria-labelledby="tab-geo" x-show="tab === 'geo'" x-cloak class="p-4 sm:p-5">
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">EOC × outcome heatmap</h3>
                    <template x-if="Object.keys(eocHeatmap).length === 0">
                        <p class="help-text">No EOC data.</p>
                    </template>
                    <template x-if="Object.keys(eocHeatmap).length > 0">
                        <div class="overflow-x-auto">
                            <table class="table text-[11.5px] min-w-[600px]">
                                <thead><tr>
                                    <th scope="col">EOC</th>
                                    <th scope="col" class="text-right">Pending</th>
                                    <th scope="col" class="text-right">Released</th>
                                    <th scope="col" class="text-right">Referred</th>
                                    <th scope="col" class="text-right">Isolated</th>
                                    <th scope="col" class="text-right">Other</th>
                                </tr></thead>
                                <tbody>
                                    <template x-for="(row, prov) in eocHeatmap" :key="prov">
                                        <tr>
                                            <td class="font-medium" x-text="prov"></td>
                                            <td class="text-right tabular-nums" :style="heatStyle(row.Pending)"   x-text="formatNum(row.Pending)"></td>
                                            <td class="text-right tabular-nums" :style="heatStyle(row.Released)"  x-text="formatNum(row.Released)"></td>
                                            <td class="text-right tabular-nums" :style="heatStyle(row.Referred)"  x-text="formatNum(row.Referred)"></td>
                                            <td class="text-right tabular-nums" :style="heatStyle(row.Isolated)"  x-text="formatNum(row.Isolated)"></td>
                                            <td class="text-right tabular-nums" :style="heatStyle(row.Other)"     x-text="formatNum(row.Other)"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </template>
                </div></div>
            </div>

            <div role="tabpanel" id="panel-poes" aria-labelledby="tab-poes" x-show="tab === 'poes'" x-cloak class="p-4 sm:p-5">
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">POE outcome comparison</h3>
                    <div class="relative h-[380px]"><canvas x-ref="poeCompare"></canvas></div>
                </div></div>
                <div class="table-wrap mt-4"><table class="table">
                    <thead><tr>
                        <th scope="col">POE</th>
                        <th scope="col" class="text-right">Cases</th>
                        <th scope="col" class="text-right">Pending</th>
                        <th scope="col" class="text-right">Referred</th>
                        <th scope="col" class="text-right">Isolated</th>
                    </tr></thead>
                    <tbody>
                        <template x-if="poes.length === 0"><tr><td colspan="5" class="text-center text-muted-foreground py-6">No POE comparisons.</td></tr></template>
                        <template x-for="r in poes" :key="r.poe">
                            <tr>
                                <td class="font-medium" x-text="r.poe"></td>
                                <td class="text-right tabular-nums" x-text="formatNum(r.total)"></td>
                                <td class="text-right tabular-nums text-warning" x-text="formatNum(r.pending)"></td>
                                <td class="text-right tabular-nums" x-text="formatNum(r.referred)"></td>
                                <td class="text-right tabular-nums" x-text="formatNum(r.isolated)"></td>
                            </tr>
                        </template>
                    </tbody>
                </table></div>
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

    function reportRegistry() {
        return {
            ready: false, tab: 'cases', notesOpen: false,
            tabs: [
                { key:'cases', label:'Case registry' }, { key:'outcomes', label:'Outcomes' },
                { key:'geo', label:'Geographic' }, { key:'poes', label:'POE analysis' },
            ],
            wizard: { open:false, step:1 },
            filters: { poe:'', sex:'', year:'', quarter:'', month:'', start_date:'', end_date:'', q:'', classification:'', outcome:'', page: 1, per_page: 25 },
            meta: { poes:{}, districts:{}, provinces:{}, years:[], quarters:{}, months:{}, genders:{} },
            kpis:{}, cases:[], pagination:{total:0,page:1,pages:1,per_page:25},
            outcomes:{}, eocHeatmap:{}, poes:[],
            insights:[], dataNotes:{}, window:{from:'',to:''}, charts:{},

            async boot() { this.restoreFiltersFromUrl(); await this.loadMeta(); /* auto-run disabled — user must click Apply */ },
            async loadMeta() { try { const r = await rptJson(@json(url('/admin/reports/meta'))); this.meta = Object.assign(this.meta, r?.data || {}); } catch (e) {} },
            urlHasRun() { return true; },
            restoreFiltersFromUrl() { const u = new URLSearchParams(window.location.search); for (const k of Object.keys(this.filters)) { const v = u.get(k); if (v !== null) this.filters[k] = v; } },
            writeFiltersToUrl() { const u = new URLSearchParams(); for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) u.set(k, v); u.set('run','1'); window.history.replaceState(null, '', window.location.pathname + '?' + u.toString()); },
            openWizard() { this.wizard.open = true; this.wizard.step = 1; },
            resetFilters() { this.filters = { poe:'', sex:'', year:'', quarter:'', month:'', start_date:'', end_date:'', q:'', classification:'', outcome:'', page:1, per_page:25 }; window.history.replaceState(null, '', window.location.pathname); },
            goto(p) { this.filters.page = p; this.runReport(); },

            async runReport() {
                this.writeFiltersToUrl();
                try {
                    const r = await rptJson(@json(url('/admin/reports/rpt-registry/data')), this.buildParams());
                    const d = r?.data || {};
                    this.kpis = d.kpis || {}; this.cases = d.cases || [];
                    this.pagination = d.pagination || this.pagination;
                    this.outcomes = d.outcomes || {}; this.eocHeatmap = d.eoc_heatmap || {}; this.poes = d.poes || [];
                    this.insights = d.insights || []; this.dataNotes = d.data_notes || {};
                    this.window = d.window || {}; this.ready = true;
                    this.$nextTick(() => this.renderCharts());
                } catch (e) { console.error(e); this.ready = false; }
            },
            buildParams() { const p = {}; for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) p[k] = v; return p; },
            exportAs(fmt) { const u = new URL(@json(url('/admin/reports/rpt-registry/export')), window.location.origin); for (const [k,v] of Object.entries(this.buildParams())) u.searchParams.set(k, v); u.searchParams.set('format', fmt); if (fmt === 'PDF') window.open(u.toString(), '_blank', 'noopener'); else window.location.href = u.toString(); },
            formatNum(v) { return (v == null) ? '—' : Number(v).toLocaleString(); },
            riskBadge(v) { if (!v) return ''; if (v === 'CRITICAL') return 'badge-critical'; if (v === 'HIGH') return 'badge-warning'; if (v === 'MEDIUM') return 'badge-outline'; return 'badge-outline'; },
            windowLabel() { return this.window.from ? (this.window.from + ' → ' + this.window.to) : 'No window'; },
            heatStyle(v) { const n = Number(v || 0); if (n === 0) return ''; const intensity = Math.min(1, Math.log10(n + 1) / 2.5); return 'background: rgba(99, 102, 241, ' + (0.1 + intensity * 0.5) + ');'; },

            renderCharts() {
                if (typeof Chart === 'undefined') return;
                Object.values(this.charts).forEach(c => { try { c.destroy(); } catch (e) {} });
                this.charts = {};
                if (this.$refs.outcomeBar) {
                    const entries = Object.entries(this.outcomes || {});
                    this.charts.outcomeBar = new Chart(this.$refs.outcomeBar, {
                        type: 'bar',
                        data: { labels: entries.map(e => e[0]),
                                datasets: [{ data: entries.map(e => e[1]),
                                             backgroundColor: ['#F59E0B', '#10B981', '#0EA5E9', '#6366F1', '#94A3B8', '#DC2626'] }] },
                        options: { indexAxis: 'y', plugins: { legend: { display:false } } },
                    });
                }
                if (this.$refs.poeCompare && this.poes.length) {
                    const top = this.poes.slice(0, 12);
                    this.charts.poeCompare = new Chart(this.$refs.poeCompare, {
                        type: 'bar',
                        data: { labels: top.map(r => r.poe),
                                datasets: [
                                    { label: 'Pending',  data: top.map(r => r.pending),  backgroundColor: '#F59E0B' },
                                    { label: 'Referred', data: top.map(r => r.referred), backgroundColor: '#0EA5E9' },
                                    { label: 'Isolated', data: top.map(r => r.isolated), backgroundColor: '#6366F1' },
                                ] },
                        options: { scales: { x: { stacked:true }, y: { stacked:true, beginAtZero:true } } },
                    });
                }
            },
        };
    }
</script>
@endpush
@endsection
