@extends('admin.layout')
@section('crumb', 'My Reports')
@section('title', 'Symptom Distribution')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
[x-cloak]{display:none!important}
.rpt-kpi{padding:14px 16px}.rpt-kpi .v{font-size:22px;line-height:1.1;font-weight:700}.rpt-kpi .l{font-size:10.5px;font-weight:600;text-transform:uppercase;letter-spacing:.12em;color:hsl(var(--muted-foreground))}.rpt-kpi .d{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:600}
.rpt-action-btn{display:inline-flex;align-items:center;gap:6px;height:30px;padding:0 10px;border-radius:8px;border:1px solid hsl(var(--border));background:hsl(var(--background));color:hsl(var(--foreground));font-size:12px;font-weight:600;cursor:pointer}.rpt-action-btn:hover{background:hsl(var(--accent))}.rpt-action-btn .badge-count{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:hsl(var(--brand));color:white;font-size:10px;font-weight:700}
.rpt-tabs{display:inline-flex;padding:3px;gap:2px;border:1px solid hsl(var(--border));border-radius:9px;background:hsl(var(--muted)/.4)}.rpt-tabs button{height:28px;padding:0 12px;border-radius:6px;font-size:12px;font-weight:600;color:hsl(var(--muted-foreground));border:none;background:transparent;cursor:pointer}.rpt-tabs button[aria-selected="true"]{background:hsl(var(--background));color:hsl(var(--foreground));box-shadow:0 1px 2px hsl(var(--border))}
.rpt-chart-card{padding:14px 16px}.rpt-chart-title{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}.rpt-chart-title h3{font-size:13px;font-weight:600}.rpt-chart-title .actions{display:inline-flex;align-items:center;gap:6px}.rpt-chart-help{font-size:11.5px;color:hsl(var(--muted-foreground));margin-bottom:10px}
.rpt-modal-overlay{position:fixed;inset:0;background:rgb(0 0 0/.5);backdrop-filter:blur(2px);z-index:80;display:flex;align-items:center;justify-content:center;padding:16px}.rpt-modal{background:hsl(var(--background));border-radius:16px;border:1px solid hsl(var(--border));width:100%;max-width:760px;max-height:88vh;display:flex;flex-direction:column;overflow:hidden}.rpt-modal header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid hsl(var(--border))}.rpt-modal header h2{font-size:14px;font-weight:600}.rpt-modal header .close{width:32px;height:32px;border-radius:8px;border:1px solid hsl(var(--border));background:hsl(var(--background));display:inline-flex;align-items:center;justify-content:center;font-size:16px;font-weight:600;cursor:pointer}.rpt-modal .body{padding:18px;overflow-y:auto}
.rpt-sheet-overlay{position:fixed;inset:0;background:rgb(0 0 0/.45);z-index:80}.rpt-sheet{position:fixed;top:0;right:0;height:100vh;width:92vw;max-width:460px;background:hsl(var(--background));border-left:1px solid hsl(var(--border));display:flex;flex-direction:column;z-index:81}.rpt-sheet header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid hsl(var(--border))}.rpt-sheet .body{padding:18px;overflow-y:auto;flex:1}.rpt-sheet footer{padding:14px 18px;border-top:1px solid hsl(var(--border));display:flex;justify-content:flex-end;gap:8px}
.rpt-empty{padding:48px 16px;text-align:center;color:hsl(var(--muted-foreground));font-size:13px}.rpt-skeleton{background:linear-gradient(90deg,hsl(var(--muted)/.6),hsl(var(--muted)/.3),hsl(var(--muted)/.6));background-size:200% 100%;animation:rpt-shimmer 1.4s ease-in-out infinite;border-radius:6px}@keyframes rpt-shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
.rpt-explainer-table th{background:hsl(var(--muted)/.35)}.rpt-explainer-table td,.rpt-explainer-table th{padding:6px 10px;font-size:11.5px;border-bottom:1px solid hsl(var(--border))}.rpt-num{font-variant-numeric:tabular-nums;text-align:right}
</style>
@endpush

@section('content')
<div x-data="rptSD()" x-init="boot()" class="space-y-4">
    <section class="flex flex-col sm:flex-row sm:items-center gap-3"><div class="min-w-0"><p class="eyebrow">Surveillance · rpt-symptom-distribution</p><h1 class="text-[18px] font-semibold leading-tight">Symptom Distribution</h1><p class="help-text mt-0.5">WHO-aligned symptom distribution from secondary screenings (primary tier records only the boolean).</p></div><div class="flex-1"></div></section>

    <section class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3">
        <div class="card rpt-kpi"><div class="l">Cases (cohort)</div><template x-if="ready"><div class="v" x-text="fmtNum(kpis.secondary_count)"></div></template><template x-if="!ready"><div class="rpt-skeleton h-7 w-20 mt-1"></div></template></div>
        <div class="card rpt-kpi"><div class="l">Distinct symptoms</div><template x-if="ready"><div class="v" x-text="fmtNum(kpis.distinct_symptoms)"></div></template><template x-if="!ready"><div class="rpt-skeleton h-7 w-16 mt-1"></div></template></div>
        <div class="card rpt-kpi"><div class="l">Distinct categories</div><template x-if="ready"><div class="v" x-text="fmtNum(kpis.distinct_categories)"></div></template><template x-if="!ready"><div class="rpt-skeleton h-7 w-16 mt-1"></div></template></div>
        <div class="card rpt-kpi"><div class="l">Red-flag symptoms</div><template x-if="ready"><div class="v text-critical" x-text="fmtNum(kpis.red_flag_count)"></div></template><template x-if="!ready"><div class="rpt-skeleton h-7 w-16 mt-1"></div></template></div>
        <div class="card rpt-kpi"><div class="l">Top symptom</div><template x-if="ready"><div><div class="v" style="font-size:14px;line-height:1.2;font-weight:700;" x-text="kpis.top_symptom?.label || '—'"></div><div class="d mt-1 text-muted-foreground" x-show="kpis.top_symptom"><span x-text="fmtNum(kpis.top_symptom?.count)"></span>&nbsp;cases</div></div></template><template x-if="!ready"><div class="rpt-skeleton h-12 w-32 mt-1"></div></template></div>
    </section>

    <section class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="rpt-action-btn" @click="filtersOpen = true">Filters<span class="badge-count" x-show="activeFilterCount() > 0" x-text="activeFilterCount()" x-cloak></span></button>
            <button type="button" class="rpt-action-btn" @click="modal = 'methodology'">How is this calculated?</button>
        </div>
        <div class="rpt-tabs"><button type="button" :aria-selected="tab==='charts'" @click="tab='charts'">Charts</button><button type="button" :aria-selected="tab==='table'" @click="tab='table'">Table</button></div>
    </section>

    <template x-if="tab === 'charts'"><section class="space-y-4">
        <article class="card rpt-chart-card"><div class="rpt-chart-title"><h3>Top symptoms (top 15)</h3><div class="actions"><button type="button" class="rpt-explain-btn" data-chart-key="top_symptoms" aria-label="?">?</button></div></div><div class="relative" :class="ready ? 'h-[420px]' : 'h-[420px] rpt-skeleton'"><canvas x-ref="topChart" x-show="ready" role="img" x-cloak></canvas></div></article>
        <article class="card rpt-chart-card"><div class="rpt-chart-title"><h3>Symptoms by category</h3><div class="actions"><button type="button" class="rpt-explain-btn" data-chart-key="symptoms_by_category" aria-label="?">?</button></div></div><div class="relative" :class="ready ? 'h-[300px]' : 'h-[300px] rpt-skeleton'"><canvas x-ref="catChart" x-show="ready" role="img" x-cloak></canvas></div></article>
    </section></template>

    <template x-if="tab === 'table'"><section class="card">
        <div class="flex flex-wrap items-center gap-2 px-4 py-3 border-b border-border"><input type="search" class="input flex-1 min-w-[200px] max-w-[320px]" placeholder="Search symptom code / label" x-model.debounce.250ms="table.q" @input.debounce.250ms="reloadTable()"><span class="text-[12px] text-muted-foreground">Showing <strong x-text="rangeFrom()"></strong>–<strong x-text="rangeTo()"></strong> of <strong x-text="table.pagination?.total ?? 0"></strong></span><div class="flex-1"></div><button type="button" class="rpt-action-btn" @click="exportCsv()">Export CSV</button></div>
        <div class="overflow-x-auto"><table class="table"><thead class="table-head"><tr><th class="table-head-th">#</th><th class="table-head-th">Symptom</th><th class="table-head-th">Code</th><th class="table-head-th">Category</th><th class="table-head-th text-right cursor-pointer" @click="sortBy('count')">Cases <span x-text="sortIndicator('count')"></span></th><th class="table-head-th text-right">Unique cases</th></tr></thead><tbody class="table-body">
            <template x-if="!ready || tableLoading"><template x-for="i in 6" :key="i"><tr class="table-row"><template x-for="j in 6" :key="j"><td class="table-cell"><div class="rpt-skeleton h-4 w-full"></div></td></template></tr></template></template>
            <template x-if="ready && !tableLoading"><template x-for="(r, i) in (table.rows || [])" :key="r.symptom_code"><tr class="table-row"><td class="table-cell text-muted-foreground" x-text="rangeFrom() + i"></td><td class="table-cell font-medium" x-text="r.label || r.symptom_code"></td><td class="table-cell"><code x-text="r.symptom_code"></code></td><td class="table-cell"><span class="badge badge-secondary" x-text="r.category || '—'"></span></td><td class="table-cell rpt-num" x-text="fmtNum(r.count)"></td><td class="table-cell rpt-num" x-text="fmtNum(r.uniq_cases ?? r.uniq)"></td></tr></template></template>
            <template x-if="ready && !tableLoading && (table.rows || []).length === 0"><tr><td colspan="6" class="rpt-empty">No symptoms in window.</td></tr></template>
        </tbody></table></div>
        <div class="flex items-center justify-between px-4 py-3 border-t border-border" x-show="ready" x-cloak><div class="text-[12px] text-muted-foreground">Page <strong x-text="table.pagination?.page ?? 1"></strong> of <strong x-text="table.pagination?.total_pages ?? 1"></strong></div><div class="flex items-center gap-1.5"><button type="button" class="rpt-action-btn" @click="gotoPage(1)" :disabled="(table.pagination?.page ?? 1) <= 1">First</button><button type="button" class="rpt-action-btn" @click="gotoPage((table.pagination?.page ?? 1) - 1)" :disabled="(table.pagination?.page ?? 1) <= 1">Prev</button><template x-for="p in pageCluster()" :key="p"><button type="button" class="rpt-action-btn" :class="p === (table.pagination?.page ?? 1) ? '!bg-brand !text-white !border-brand' : ''" @click="gotoPage(p)" x-text="p"></button></template><button type="button" class="rpt-action-btn" @click="gotoPage((table.pagination?.page ?? 1) + 1)" :disabled="(table.pagination?.page ?? 1) >= (table.pagination?.total_pages ?? 1)">Next</button><button type="button" class="rpt-action-btn" @click="gotoPage(table.pagination?.total_pages ?? 1)" :disabled="(table.pagination?.page ?? 1) >= (table.pagination?.total_pages ?? 1)">Last</button></div></div>
    </section></template>

    <template x-if="error"><section class="card"><div class="card-content py-8 text-center space-y-3"><p class="text-[13px] font-semibold text-critical">Couldn't load this report</p><p class="help-text" x-text="error"></p><button type="button" class="btn btn-brand btn-sm" @click="error=''; runReport()">Retry</button></div></section></template>

    <template x-if="filtersOpen"><div><div class="rpt-sheet-overlay" @click="filtersOpen = false"></div><aside class="rpt-sheet"><header><div><p class="eyebrow">Filters</p><h2 class="text-[14px] font-semibold">Refine</h2></div><button type="button" class="close" @click="filtersOpen = false">×</button></header><div class="body space-y-4"><div><label class="label block mb-1">Date preset</label><div class="flex flex-wrap gap-1.5"><template x-for="p in presets" :key="p.label"><button type="button" class="rpt-action-btn" @click="applyPreset(p.days)" x-text="p.label"></button></template></div></div><div class="grid grid-cols-2 gap-2"><label class="text-[11px] text-muted-foreground">Start<input type="date" class="input w-full mt-1" x-model="filters.start_date"></label><label class="text-[11px] text-muted-foreground">End<input type="date" class="input w-full mt-1" x-model="filters.end_date"></label></div><div><label class="text-[11px] text-muted-foreground block">POE<select class="select w-full mt-1" x-model="filters.poe"><option value="">All</option><template x-for="(name, code) in (meta.poes||{})" :key="code"><option :value="code" x-text="name"></option></template></select></label></div></div><footer><button type="button" class="btn btn-ghost btn-sm" @click="resetFilters()">Clear</button><button type="button" class="btn btn-brand btn-sm" @click="filtersOpen = false; runReport()">Apply</button></footer></aside></div></template>

    <template x-if="modal === 'methodology'"><div class="rpt-modal-overlay" @click.self="modal = ''" @keydown.escape.window="modal = ''"><div class="rpt-modal"><header><h2>How is this calculated?</h2><button type="button" class="close" @click="modal = ''">×</button></header><div class="body space-y-3 text-[12.5px]"><p class="text-muted-foreground">Symptoms recorded only at secondary tier (secondary_symptoms.is_present=1). Primary tier captures only the boolean symptoms_present. Cohort = secondary screenings opened in window, scope-filtered. Cross-collation safe (two-query lookup against ref_symptoms).</p></div></div></div></template>

    @include('admin.reports._chart_explainer', ['reportKey' => 'rpt-symptom-distribution'])
</div>
@endsection

@push('scripts')
<script>
if (typeof Chart !== 'undefined') { Chart.defaults.animation = false; Chart.defaults.maintainAspectRatio = false; Chart.defaults.font.family = 'Figtree, system-ui, sans-serif'; }
function tokenColor(t,f){try{const v=getComputedStyle(document.documentElement).getPropertyValue(t).trim();return v?`hsl(${v})`:(f||'#0EA5E9');}catch(e){return f||'#0EA5E9';}}
async function rptJson(url, params) { const u = new URL(url, window.location.origin); if (params) for (const [k,v] of Object.entries(params)) if (v !== '' && v != null) u.searchParams.set(k, v); const r = await fetch(u.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' }); if (!r.ok) throw new Error('HTTP ' + r.status); const ct = r.headers.get('content-type') || ''; if (!ct.includes('application/json')) throw new Error('Non-JSON'); return await r.json(); }

function rptSD() {
    return {
        ready: false, error: '', tab: new URLSearchParams(location.search).get('tab') || 'charts', modal: '', filtersOpen: false,
        filters: { poe: '', start_date: '', end_date: '' },
        meta: { poes: {} },
        kpis: {}, charts: { top: null, cat: null },
        table: { rows: [], pagination: { page: 1, per_page: 10, total: 0, total_pages: 1 }, sort: 'count', dir: 'desc', q: '' },
        tableLoading: false, chartObjects: {},
        presets: [{ label: '7d', days: 7 }, { label: '30d', days: 30 }, { label: '90d', days: 90 }, { label: '12m', days: 365 }],

        async boot() { this.restoreFiltersFromUrl(); this.$watch('tab', (t) => { const u = new URL(location); u.searchParams.set('tab', t); history.replaceState(null, '', u); if (t === 'charts') this.$nextTick(() => this.renderAllCharts()); }); try { await this.loadMeta(); } catch (e) {} await this.runReport(); },
        restoreFiltersFromUrl() { const u = new URLSearchParams(location.search); for (const k of Object.keys(this.filters)) { const v = u.get(k); if (v !== null) this.filters[k] = v; } },
        writeFiltersToUrl() { const u = new URLSearchParams(); for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) u.set(k, v); if (this.tab) u.set('tab', this.tab); history.replaceState(null, '', location.pathname + (u.toString() ? '?' + u.toString() : '')); },
        async loadMeta() { try { const r = await rptJson(@json(url('/admin/reports/rpt-symptom-distribution/meta'))); const d = r?.data || {}; this.meta.poes = d.poes || {}; } catch (e) {} },
        activeFilterCount() { return Object.values(this.filters).filter(v => v !== '' && v != null).length; },
        applyPreset(d) { const to = new Date(); const from = new Date(); from.setDate(from.getDate() - (d - 1)); const fmt = x => x.toISOString().slice(0, 10); this.filters.start_date = fmt(from); this.filters.end_date = fmt(to); },
        resetFilters() { this.filters = { poe: '', start_date: '', end_date: '' }; },

        async runReport() {
            this.error = '';
            try {
                const params = this.buildParams();
                const [k, c1, c2, t] = await Promise.all([
                    rptJson(@json(url('/admin/reports/rpt-symptom-distribution/kpis')), params),
                    rptJson(@json(url('/admin/reports/rpt-symptom-distribution/chart/top_symptoms')), params),
                    rptJson(@json(url('/admin/reports/rpt-symptom-distribution/chart/symptoms_by_category')), params),
                    rptJson(@json(url('/admin/reports/rpt-symptom-distribution/records')), Object.assign({}, params, { page: this.table.pagination.page, sort: this.table.sort, dir: this.table.dir, q: this.table.q })),
                ]);
                this.kpis = k?.data || {};
                this.charts.top = c1?.data || null;
                this.charts.cat = c2?.data || null;
                const td = t?.data || {};
                this.table = { rows: td.rows || [], pagination: td.pagination || this.table.pagination, sort: td.controls?.sort || this.table.sort, dir: td.controls?.dir || this.table.dir, q: td.controls?.q || this.table.q };
                this.ready = true;
                this.writeFiltersToUrl();
                this.$nextTick(() => this.renderAllCharts());
                Alpine.store('pageMeta', { rows: this.kpis.secondary_count ?? 0, version: null, kind: 'rpt-symptom-distribution' });
            } catch (e) { console.error(e); this.ready = false; this.error = e?.message || 'Network error'; }
        },
        buildParams() { const p = {}; for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) p[k] = v; return p; },

        async reloadTable() {
            if (!this.ready) return;
            this.tableLoading = true;
            try { const r = await rptJson(@json(url('/admin/reports/rpt-symptom-distribution/records')), Object.assign({}, this.buildParams(), { page: this.table.pagination.page, sort: this.table.sort, dir: this.table.dir, q: this.table.q })); const td = r?.data || {}; this.table = { rows: td.rows || [], pagination: td.pagination || this.table.pagination, sort: td.controls?.sort || this.table.sort, dir: td.controls?.dir || this.table.dir, q: td.controls?.q || this.table.q }; }
            catch (e) { console.error(e); }
            finally { this.tableLoading = false; }
        },
        gotoPage(p) { const m = this.table.pagination?.total_pages || 1; this.table.pagination.page = Math.max(1, Math.min(m, p)); this.reloadTable(); },
        sortBy(k) { if (this.table.sort === k) this.table.dir = this.table.dir === 'asc' ? 'desc' : 'asc'; else { this.table.sort = k; this.table.dir = 'desc'; } this.table.pagination.page = 1; this.reloadTable(); },
        sortIndicator(k) { return this.table.sort !== k ? '' : (this.table.dir === 'asc' ? '↑' : '↓'); },
        rangeFrom() { return this.table.pagination?.from || 0; },
        rangeTo() { return this.table.pagination?.to || 0; },
        pageCluster() { const c = this.table.pagination?.page ?? 1, m = this.table.pagination?.total_pages ?? 1; const o = []; const lo = Math.max(1, c - 2), hi = Math.min(m, c + 2); for (let i = lo; i <= hi; i++) o.push(i); return o; },
        exportCsv() { const u = new URL(@json(url('/admin/reports/rpt-symptom-distribution/chart/top_symptoms/csv')), location.origin); for (const [k,v] of Object.entries(this.buildParams())) u.searchParams.set(k, v); location.href = u.toString(); },

        fmtNum(v) { if (v == null) return '—'; const n = Number(v); if (!isFinite(n)) return '—'; if (Math.abs(n) >= 1e6) return (n/1e6).toFixed(1)+'M'; if (Math.abs(n) >= 1e4) return (n/1e3).toFixed(1)+'K'; return n.toLocaleString(); },
        truncLabel(s, n) { s = String(s || ''); return s.length > n ? s.slice(0, n - 1) + '…' : s; },
        compactTick(v) { v = Number(v||0); if (Math.abs(v) >= 1e6) return (v/1e6).toFixed(1)+'M'; if (Math.abs(v) >= 1e4) return (v/1e3).toFixed(1)+'K'; return v.toLocaleString(); },

        destroyCharts() { Object.values(this.chartObjects).forEach(c => { try { c.destroy(); } catch (e) {} }); this.chartObjects = {}; },
        renderAllCharts() { if (typeof Chart === 'undefined') return; this.destroyCharts(); requestAnimationFrame(() => { this.renderTop(); this.renderCat(); }); },

        renderTop() {
            const ref = this.$refs.topChart; if (!ref) return;
            const data = this.charts.top || {};
            const bars = (data.bars || data.rows || []);
            if (bars.length === 0) return;
            const labels = bars.map(b => this.truncLabel(b.label || b.symptom_code, 26));
            const values = bars.map(b => b.count || b.cases || b.value || 0);
            this.chartObjects.top = new Chart(ref, { type: 'bar', data: { labels, datasets: [{ data: values, backgroundColor: tokenColor('--viz-1'), borderRadius: 3 }] }, options: { indexAxis: 'y', plugins: { legend: { display: false }, tooltip: { callbacks: { title: i => bars[i[0].dataIndex]?.label || bars[i[0].dataIndex]?.symptom_code || '', label: c => `Cases: ${Number(c.parsed.x).toLocaleString()}` } } }, scales: { x: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground'), callback: v => this.compactTick(v), font: { size: 10 } }, grid: { color: tokenColor('--border') } }, y: { ticks: { color: tokenColor('--foreground'), font: { size: 11, weight: '600' } }, grid: { display: false } } } } });
        },
        renderCat() {
            const ref = this.$refs.catChart; if (!ref) return;
            const data = this.charts.cat || {};
            const bars = (data.bars || data.rows || []);
            if (bars.length === 0) return;
            const labels = bars.map(b => this.truncLabel(b.label || b.category || '—', 26));
            const values = bars.map(b => b.count || b.cases || b.value || 0);
            this.chartObjects.cat = new Chart(ref, { type: 'bar', data: { labels, datasets: [{ data: values, backgroundColor: tokenColor('--viz-2'), borderRadius: 3 }] }, options: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground'), callback: v => this.compactTick(v), font: { size: 10 } }, grid: { color: tokenColor('--border') } }, y: { ticks: { color: tokenColor('--foreground'), font: { size: 11, weight: '600' } }, grid: { display: false } } } } });
        },
    };
}
</script>
@endpush
