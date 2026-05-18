@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Age & Gender Risk Intelligence')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
<div x-data="reportAgeGender()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('rpt-age-gender', wizard.open)"
     class="space-y-5">

    <section class="flex flex-col sm:flex-row sm:items-center gap-3">
        <div class="min-w-0">
            <p class="eyebrow">My Reports · rpt-age-gender</p>
            <h1 class="text-[18px] font-semibold">Age & Gender Risk Intelligence</h1>
            <p class="help-text mt-0.5">Epidemiological pattern detection across demographics.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready"><span class="status-dot status-dot-live"></span><span x-text="windowLabel()"></span></span>
            <button type="button" class="btn btn-ghost btn-sm" @click="openWizard()">Filters</button>
            @include('admin.reports._coach', ['reportKey' => 'rpt-age-gender'])
            <div class="inline-flex rounded-md border overflow-hidden" role="group" aria-label="Export options">
                <button type="button" class="btn btn-ghost btn-sm rounded-none border-r" @click="exportAs('CSV')">CSV</button>
                <button type="button" class="btn btn-ghost btn-sm rounded-none border-r" @click="exportAs('XLSX')">Excel</button>
                <button type="button" class="btn btn-ghost btn-sm rounded-none" @click="exportAs('PDF')">Print / PDF</button>
            </div>
        </div>
    </section>

    <template x-if="!ready">
        <section class="card"><div class="card-content py-10 text-center space-y-3">
            <h2 class="text-[15px] font-semibold">Configure filter window</h2>
            <button type="button" class="btn btn-primary" @click="openWizard()">Open filter wizard</button>
        </div></section>
    </template>

    <template x-if="ready">
        <section class="grid grid-cols-2 sm:grid-cols-5 gap-3">
            <div class="kpi kpi-glow"><p class="kpi-label">Primary</p><p class="kpi-value tabular-nums" x-text="formatNum(kpis.primary)"></p></div>
            <div class="kpi"><p class="kpi-label">Secondary</p><p class="kpi-value tabular-nums" x-text="formatNum(kpis.secondary)"></p></div>
            <div class="kpi"><p class="kpi-label">Referrals</p><p class="kpi-value tabular-nums" x-text="formatNum(kpis.referrals)"></p></div>
            <div class="kpi"><p class="kpi-label">Notifiable</p><p class="kpi-value tabular-nums text-warning" x-text="formatNum(kpis.notifiable)"></p></div>
            <div class="kpi"><p class="kpi-label">Completion %</p><p class="kpi-value tabular-nums" x-text="(kpis.completion_pct == null) ? '— (n<5)' : kpis.completion_pct + '%'"></p></div>
        </section>
    </template>

    <template x-if="ready">
        <section class="card"><div class="card-content !p-0">
            <nav class="flex flex-wrap gap-0 border-b" role="tablist" aria-label="Age and gender tabs">
                <template x-for="t in tabs" :key="t.key">
                    <button type="button" :id="'tab-' + t.key" class="px-4 py-3 text-[12.5px] font-medium border-b-2 transition-colors"
                            :class="tab === t.key ? 'border-brand text-brand' : 'border-transparent text-muted-foreground hover:text-foreground'"
                            :aria-selected="tab === t.key ? 'true' : 'false'"
                            :aria-controls="'panel-' + t.key" role="tab" @click="tab = t.key"><span x-text="t.label"></span></button>
                </template>
            </nav>

            <div role="tabpanel" id="panel-overview" aria-labelledby="tab-overview" x-show="tab === 'overview'" class="p-4 sm:p-5">
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">Age pyramid · male vs female</h3>
                    <div class="relative h-[380px]"><canvas x-ref="pyramid"></canvas></div>
                </div></div>
            </div>

            <div role="tabpanel" id="panel-risk" aria-labelledby="tab-risk" x-show="tab === 'risk'" x-cloak class="p-4 sm:p-5">
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">Notifiable rate by age band (threshold lines at 15 % and 30 %)</h3>
                    <div class="relative h-[360px]"><canvas x-ref="notifBar"></canvas></div>
                </div></div>
                <div class="table-wrap mt-4"><table class="table">
                    <thead><tr>
                        <th scope="col">Age band</th>
                        <th scope="col" class="text-right">Total</th>
                        <th scope="col" class="text-right">Notifiable</th>
                        <th scope="col" class="text-right">Notifiable %</th>
                    </tr></thead>
                    <tbody>
                        <template x-if="ageBands.length === 0"><tr><td colspan="4" class="text-center text-muted-foreground py-6">No data.</td></tr></template>
                        <template x-for="r in ageBands" :key="r.band">
                            <tr>
                                <td class="font-medium" x-text="r.band"></td>
                                <td class="text-right tabular-nums" x-text="formatNum(r.total)"></td>
                                <td class="text-right tabular-nums text-warning" x-text="formatNum(r.notifiable)"></td>
                                <td class="text-right tabular-nums" x-text="r.total < 5 ? '— (n<5)' : ((r.notifiable / Math.max(1, r.total)) * 100).toFixed(1) + '%'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table></div>
            </div>

            <div role="tabpanel" id="panel-gender" aria-labelledby="tab-gender" x-show="tab === 'gender'" x-cloak class="p-4 sm:p-5 grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">Gender disparity across age bands</h3>
                    <div class="relative h-[340px]"><canvas x-ref="disparity"></canvas></div>
                </div></div>
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">Gender totals</h3>
                    <div class="table-wrap"><table class="table text-[12px]">
                        <thead><tr>
                            <th scope="col">Gender</th>
                            <th scope="col" class="text-right">Total</th>
                            <th scope="col" class="text-right">Notifiable</th>
                            <th scope="col" class="text-right">Referrals</th>
                        </tr></thead>
                        <tbody>
                            <template x-for="(v, k) in gender" :key="k">
                                <tr>
                                    <td x-text="k"></td>
                                    <td class="text-right tabular-nums" x-text="formatNum(v.total)"></td>
                                    <td class="text-right tabular-nums text-warning" x-text="formatNum(v.notifiable)"></td>
                                    <td class="text-right tabular-nums" x-text="formatNum(v.referrals)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table></div>
                </div></div>
            </div>

            <div role="tabpanel" id="panel-outcomes" aria-labelledby="tab-outcomes" x-show="tab === 'outcomes'" x-cloak class="p-4 sm:p-5">
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">Referral (CFR proxy) by age band</h3>
                    <div class="relative h-[340px]"><canvas x-ref="cfrBar"></canvas></div>
                    <details class="mt-3">
                        <summary class="text-[11px] cursor-pointer text-muted-foreground hover:text-foreground">View as table</summary>
                        <div class="table-wrap mt-2"><table class="table text-[12px]">
                            <thead><tr>
                                <th scope="col">Age band</th>
                                <th scope="col" class="text-right">Secondary</th>
                                <th scope="col" class="text-right">Referred</th>
                                <th scope="col" class="text-right">Referral %</th>
                            </tr></thead>
                            <tbody>
                                <template x-for="r in ageBands" :key="r.band">
                                    <tr>
                                        <td x-text="r.band"></td>
                                        <td class="text-right tabular-nums" x-text="formatNum(r.total)"></td>
                                        <td class="text-right tabular-nums" x-text="formatNum(r.referrals)"></td>
                                        <td class="text-right tabular-nums" x-text="r.total < 5 ? '— (n<5)' : ((r.referrals / Math.max(1, r.total)) * 100).toFixed(1) + '%'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table></div>
                    </details>
                </div></div>
            </div>

            <div role="tabpanel" id="panel-patterns" aria-labelledby="tab-patterns" x-show="tab === 'patterns'" x-cloak class="p-4 sm:p-5">
                <div class="card"><div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-3">Vulnerable cohorts</h3>
                    <p class="text-[12.5px]">Under-5: <span class="font-semibold tabular-nums" x-text="summaryU5"></span>
                       · 65+: <span class="font-semibold tabular-nums" x-text="summary65"></span>
                       · total secondary: <span class="tabular-nums" x-text="formatNum(kpis.secondary)"></span></p>
                    <p class="help-text mt-2">Deterministic rule fires at ≥ 20 % combined share. See AI Insights below.</p>
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

    function reportAgeGender() {
        return {
            ready: false, tab: 'overview', notesOpen: false,
            tabs: [
                { key:'overview', label:'Overview' }, { key:'risk', label:'Risk Analysis' },
                { key:'gender', label:'Gender Analysis' }, { key:'outcomes', label:'Outcomes' },
                { key:'patterns', label:'Patterns' },
            ],
            wizard: { open:false, step:1 },
            filters: { poe:'', sex:'', year:'', quarter:'', month:'', start_date:'', end_date:'' },
            meta: { poes:{}, districts:{}, provinces:{}, years:[], quarters:{}, months:{}, genders:{} },
            kpis:{}, ageBands:[], gender:{}, disparity:[],
            get summaryU5() { return this.ageBands.find(r => r.band === '0–4')?.total ?? 0; },
            get summary65() { return this.ageBands.find(r => r.band === '65+')?.total ?? 0; },
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
                    const r = await rptJson(@json(url('/admin/reports/rpt-age-gender/data')), this.buildParams());
                    const d = r?.data || {};
                    this.kpis = d.kpis || {}; this.ageBands = d.age_bands || [];
                    this.gender = d.gender || {}; this.disparity = d.disparity || [];
                    this.insights = d.insights || []; this.dataNotes = d.data_notes || {};
                    this.window = d.window || {}; this.ready = true;
                    this.$nextTick(() => this.renderCharts());
                } catch (e) { console.error(e); this.ready = false; }
            },
            buildParams() { const p = {}; for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) p[k] = v; return p; },
            exportAs(fmt) { const u = new URL(@json(url('/admin/reports/rpt-age-gender/export')), window.location.origin); for (const [k,v] of Object.entries(this.buildParams())) u.searchParams.set(k, v); u.searchParams.set('format', fmt); if (fmt === 'PDF') window.open(u.toString(), '_blank', 'noopener'); else window.location.href = u.toString(); },
            formatNum(v) { return (v == null) ? '—' : Number(v).toLocaleString(); },
            windowLabel() { return this.window.from ? (this.window.from + ' → ' + this.window.to) : 'No window'; },

            renderCharts() {
                if (typeof Chart === 'undefined') return;
                Object.values(this.charts).forEach(c => { try { c.destroy(); } catch (e) {} });
                this.charts = {};
                const labels = this.ageBands.map(r => r.band);
                if (this.$refs.pyramid && this.ageBands.length) {
                    this.charts.pyramid = new Chart(this.$refs.pyramid, {
                        type: 'bar',
                        data: { labels,
                                datasets: [
                                    { label: 'Male',   data: this.ageBands.map(r => -r.male),   backgroundColor: '#3B82F6' },
                                    { label: 'Female', data: this.ageBands.map(r =>  r.female), backgroundColor: '#F472B6' },
                                ] },
                        options: { indexAxis: 'y', scales: { x: { stacked:true, ticks: { callback: v => Math.abs(v) } }, y: { stacked:true } } },
                    });
                }
                if (this.$refs.notifBar && this.ageBands.length) {
                    this.charts.notifBar = new Chart(this.$refs.notifBar, {
                        type: 'bar',
                        data: { labels,
                                datasets: [{ label: 'Notifiable %',
                                             data: this.ageBands.map(r => r.total < 5 ? null : (r.notifiable / Math.max(1, r.total)) * 100),
                                             backgroundColor: this.ageBands.map(r => {
                                                 if (r.total < 5) return '#94A3B8';
                                                 const pct = r.notifiable / Math.max(1, r.total);
                                                 if (pct >= 0.30) return '#DC2626';
                                                 if (pct >= 0.15) return '#F59E0B';
                                                 return '#10B981';
                                             }) }] },
                        options: { plugins: { legend: { display:false } }, scales: { y: { beginAtZero:true, max: 100,
                                    ticks: { callback: v => v + '%' },
                                    afterDataLimits: function(scale) { scale.max = Math.max(scale.max, 35); } } } },
                    });
                }
                if (this.$refs.disparity && this.disparity.length) {
                    this.charts.disparity = new Chart(this.$refs.disparity, {
                        type: 'bar',
                        data: { labels: this.disparity.map(r => r.band),
                                datasets: [
                                    { label: 'Male',   data: this.disparity.map(r => r.male),   backgroundColor: '#3B82F6' },
                                    { label: 'Female', data: this.disparity.map(r => r.female), backgroundColor: '#F472B6' },
                                ] },
                        options: { scales: { x: { stacked:false }, y: { beginAtZero:true } } },
                    });
                }
                if (this.$refs.cfrBar && this.ageBands.length) {
                    this.charts.cfrBar = new Chart(this.$refs.cfrBar, {
                        type: 'bar',
                        data: { labels,
                                datasets: [{ label: 'Referral %',
                                             data: this.ageBands.map(r => r.total < 5 ? null : (r.referrals / Math.max(1, r.total)) * 100),
                                             backgroundColor: '#6366F1' }] },
                        options: { plugins: { legend: { display:false } }, scales: { y: { beginAtZero:true, max:100, ticks: { callback: v => v + '%' } } } },
                    });
                }
            },
        };
    }
</script>
@endpush
@endsection
