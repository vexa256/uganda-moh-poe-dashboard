@extends('admin.layout')

@section('crumb', 'Quick Reports')
@section('title', 'Alert Outcomes')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
@include('admin.quick._styles')
@endpush

@section('content')
<div x-data="qrAlertOut()" x-init="boot()" class="qr-stack" :aria-busy="loading ? 'true' : 'false'">

    <div class="qr-progress" x-show="loading" x-cloak></div>

    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Quick Reports · qr-alert-out</p>
            <h1 class="text-[20px] font-semibold tracking-tight">Alert Outcomes</h1>
            <p class="help-text mt-1" x-text="headline()">How fast we acknowledge, how fast we close, and how the alerts actually end.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready" x-cloak><span class="status-dot status-dot-live"></span><span x-text="payload.window?.label || ''"></span></span>
            <span class="topbar-chip" x-show="ready && payload.scope?.label" x-cloak x-text="payload.scope?.access_label || payload.scope?.label"></span>
            <button type="button" class="btn btn-outline btn-xs" @click="exportCsvFull()" :disabled="!ready">Export</button>
        </div>
    </section>

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
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-5 gap-3 p-4">
            <div><label class="label block mb-1 text-[11px]">Window</label>
                <select class="select" x-model="filters.days">
                    <option value="7">Past 7 days</option><option value="14">Past 14 days</option>
                    <option value="30">Past 30 days</option><option value="60">Past 60 days</option><option value="90">Past 90 days</option>
                </select>
            </div>
            <div><label class="label block mb-1 text-[11px]">Point of entry</label>
                <select class="select" x-model="filters.poe"><option value="">All entry points</option>
                    <template x-for="(name, code) in (payload.meta?.poes || {})" :key="code"><option :value="code" x-text="name"></option></template>
                </select>
            </div>
            <div><label class="label block mb-1 text-[11px]">Status</label>
                <select class="select" x-model="filters.status"><option value="">All statuses</option>
                    <template x-for="s in (payload.meta?.statuses || [])" :key="s"><option :value="s" x-text="prettyStatus(s)"></option></template>
                </select>
            </div>
            <div><label class="label block mb-1 text-[11px]">Closure outcome</label>
                <select class="select" x-model="filters.close_category"><option value="">All closure outcomes</option>
                    <template x-for="c in (payload.meta?.close_categories || [])" :key="c"><option :value="c" x-text="prettyClose(c)"></option></template>
                </select>
            </div>
            <div><label class="label block mb-1 text-[11px]">Risk level</label>
                <select class="select" x-model="filters.risk"><option value="">All risk levels</option>
                    <template x-for="r in (payload.meta?.risks || [])" :key="r"><option :value="r" x-text="prettyRisk(r)"></option></template>
                </select>
            </div>
        </div>
    </section>

    <section>
        <template x-if="!ready">
            <div class="qr-kpi-grid">
                <div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div>
                <div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div>
            </div>
        </template>
        <div class="qr-kpi-grid" x-show="ready" x-cloak>
            <div class="qr-kpi"><p class="qr-kpi-label">Alerts in window</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.total_in_window)"></p><p class="qr-kpi-hint" x-text="payload.window?.label ? 'Within ' + payload.window.label : 'Within window'"></p></div>
            <div class="qr-kpi qr-kpi-info"><p class="qr-kpi-label">Median time to ack</p><p class="qr-kpi-value" x-text="payload.kpis?.median_ack_min != null ? payload.kpis.median_ack_min + ' min' : '—'"></p><p class="qr-kpi-hint">From opened → acknowledged</p></div>
            <div class="qr-kpi qr-kpi-info"><p class="qr-kpi-label">Median time to close</p><p class="qr-kpi-value" x-text="payload.kpis?.median_close_hr != null ? payload.kpis.median_close_hr + ' hr' : '—'"></p><p class="qr-kpi-hint">From opened → closed</p></div>
            <div class="qr-kpi"><p class="qr-kpi-label">Closed in 24 h</p><p class="qr-kpi-value" x-text="payload.kpis?.closed_within_24h_pct != null ? payload.kpis.closed_within_24h_pct + '%' : '—'"></p><p class="qr-kpi-hint" x-text="payload.kpis?.closed ? 'of ' + payload.kpis.closed + ' closed' : 'No closures yet'"></p></div>
            <div class="qr-kpi" :class="(payload.kpis?.unacked ?? 0) > 0 && 'qr-kpi-warn'"><p class="qr-kpi-label">Awaiting ack</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.unacked)"></p><p class="qr-kpi-hint">No officer has picked up</p></div>
            <div class="qr-kpi"><p class="qr-kpi-label">Reopened</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.reopened)"></p><p class="qr-kpi-hint">Closed then re-opened</p></div>
        </div>
    </section>

    <section class="qr-card" aria-label="Outcome / SLA chart">
        <div class="qr-card-head">
            <div class="min-w-0">
                <h2 class="qr-card-title" x-text="payload.chart?.title || 'Outcomes'"></h2>
                <p class="qr-card-sub" x-text="payload.chart?.subtitle || ''"></p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" class="qr-icon-btn" @click="openExplain()" x-ref="explainTrigger" title="Explain this chart" aria-label="Explain this chart">?</button>
                <button type="button" class="btn btn-outline btn-xs" @click="exportChartPng()" :disabled="!chartHasData">PNG</button>
                <button type="button" class="btn btn-outline btn-xs" @click="exportChartCsv()" :disabled="!chartHasData">CSV</button>
            </div>
        </div>
        <div class="p-4">
            <template x-if="!ready"><div class="qr-chart-skel"></div></template>
            <div class="qr-chart-wrap" x-show="ready && chartHasData" x-cloak><canvas x-ref="chart" role="img" aria-label="Alert outcomes"></canvas></div>
            <div class="qr-empty" x-show="ready && !chartHasData" x-cloak>
                <p>No alerts in this window.</p>
                <p class="mt-1 text-[11.5px]">Widen the date range or clear a filter.</p>
            </div>
        </div>
    </section>

    <section class="qr-card overflow-hidden" aria-label="Alert outcomes register">
        <div class="qr-card-head">
            <div class="min-w-0">
                <h2 class="qr-card-title">Outcome register</h2>
                <p class="qr-card-sub" x-text="tableSub()"></p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" class="btn btn-outline btn-xs" @click="exportCsvFull()" :disabled="!ready">Export full CSV</button>
            </div>
        </div>
        <div class="qr-table-wrap">
            <table class="qr-table">
                <thead><tr><th>Opened</th><th>Traveller</th><th>Risk</th><th>Status</th><th>Time to ack</th><th>Time to close</th><th>Closure outcome</th><th>Point of entry</th><th class="text-right pr-3">Case file</th></tr></thead>
                <tbody>
                    <template x-if="!ready"><template x-for="i in 6" :key="i"><tr><td colspan="9"><div class="h-5 my-1 rounded bg-muted/30 animate-pulse"></div></td></tr></template></template>

                    <template x-for="row in (payload.table || [])" :key="row.alert_id">
                        <tr>
                            <td>
                                <div class="qr-cell-primary" x-text="row.opened_at_label"></div>
                                <div class="qr-cell-mono" x-text="row.alert_code"></div>
                            </td>
                            <td><div class="qr-cell-primary" x-text="row.traveller_name"></div><div class="qr-cell-secondary" x-text="rowDemographics(row)"></div></td>
                            <td><span :class="riskPill(row.risk)" x-text="prettyRisk(row.risk)"></span></td>
                            <td><span :class="statusPill(row.status)" x-text="prettyStatus(row.status)"></span></td>
                            <td><span :class="ackPill(row.ack_min)" x-text="row.ack_min != null ? row.ack_min + ' min' : 'Not yet'"></span></td>
                            <td><span x-text="row.close_hr != null ? row.close_hr + ' hr' : '—'" :class="row.close_hr != null ? 'qr-cell-primary' : 'qr-cell-mono'"></span></td>
                            <td><span :class="closePill(row.close_category)" x-text="prettyClose(row.close_category) || '—'"></span></td>
                            <td x-text="row.poe_name || '—'"></td>
                            <td class="text-right pr-3"><a :href="row.case_file_url" class="qr-link-btn" aria-label="Open case file">View<svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M9 7h8v8"/></svg></a></td>
                        </tr>
                    </template>

                    <template x-if="ready && (payload.table?.length || 0) === 0">
                        <tr><td colspan="9"><div class="qr-empty my-2"><p>No alerts match the current filters.</p><p class="mt-1 text-[11.5px]">Try widening the window or clearing a filter.</p></div></td></tr>
                    </template>
                </tbody>
            </table>
        </div>
        <div class="qr-card-pad qr-divider flex items-center justify-between">
            <p class="qr-card-sub" x-text="tableSub()"></p>
            <p class="qr-card-sub">Updated <span x-text="lastLoadedLabel()"></span></p>
        </div>
    </section>

    <template x-teleport="body">
        <div x-show="explainOpen" x-cloak>
            <div class="qr-modal-bg" @click="closeExplain()" aria-hidden="true"></div>
            <div class="qr-modal-shell" @keydown.escape.window="closeExplain()">
                <div class="qr-modal" role="dialog" aria-modal="true">
                    <div class="qr-modal-head">
                        <div class="min-w-0"><p class="eyebrow">About this chart</p><h3 class="text-[15px] font-semibold mt-0.5 truncate" x-text="payload.chart?.title || 'Outcomes'"></h3></div>
                        <button type="button" class="qr-icon-btn" @click="closeExplain()" aria-label="Close"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                    </div>
                    <div class="qr-modal-body space-y-5">
                        <div class="qr-modal-section"><p>What this measures</p><p x-text="explainWhat()"></p></div>
                        <div class="qr-modal-section"><p>How to read it</p><p x-text="explainHowToRead()"></p></div>
                        <div class="qr-modal-section"><p>What to do next</p><p x-text="explainNext()"></p></div>
                        <div class="qr-modal-section">
                            <p>Source data</p>
                            <div class="qr-table-wrap mt-2 max-h-[280px]">
                                <table class="qr-table">
                                    <thead><tr><th x-text="explainCategoryHeader()"></th><th class="text-right pr-3">Alerts</th><th class="text-right pr-3">% share</th></tr></thead>
                                    <tbody>
                                        <template x-for="(lbl, i) in (payload.chart?.labels || [])" :key="i"><tr><td x-text="lbl"></td><td class="text-right pr-3 tabular-nums" x-text="payload.chart.values[i]"></td><td class="text-right pr-3 tabular-nums text-muted-foreground" x-text="explainPct(i)"></td></tr></template>
                                        <template x-if="(payload.chart?.labels?.length || 0) === 0"><tr><td colspan="3" class="text-center text-muted-foreground py-4">No data.</td></tr></template>
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
    const QR_AO = {
        endpointData:   @json(url('/admin/quick-reports/alert-outcomes/data')),
        endpointExport: @json(url('/admin/quick-reports/alert-outcomes/export')),
        statusLabels: { OPEN: 'Open', ACKNOWLEDGED: 'Acknowledged', IN_PROGRESS: 'In progress', CLOSED: 'Closed', REOPENED: 'Reopened' },
        riskLabels:   { LOW: 'Low', MEDIUM: 'Medium', HIGH: 'High', CRITICAL: 'Critical' },
        closeLabels:  {
            CONFIRMED_CASE: 'Confirmed case', CONFIRMED: 'Confirmed case',
            PROBABLE: 'Probable', DISCARDED: 'Ruled out',
            NO_CASE: 'No case', NOT_A_CASE: 'No case', FALSE_POSITIVE: 'False positive',
            LOST_TO_FOLLOWUP: 'Lost to follow-up', REFERRED: 'Referred elsewhere',
            DUPLICATE: 'Duplicate alert', OTHER: 'Other',
        },
    };

    function qrAlertOut() {
        return {
            ready: false, loading: false, chartHasData: false,
            explainOpen: false, chart: null, lastLoadedAt: null, abortCtrl: null,
            filters: { days: '7', poe: '', status: '', close_category: '', risk: '' },
            payload: { window: null, scope: null, kpis: {}, chart: { labels: [], values: [] }, table: [], total_rows: 0, shown_rows: 0, meta: {} },

            boot() {
                this.readFiltersFromUrl(); this.loadData();
                for (const k of ['days','poe','status','close_category','risk']) this.$watch(`filters.${k}`, () => this.loadData());
                if (typeof Chart === 'undefined') { const w = setInterval(() => { if (typeof Chart !== 'undefined') { clearInterval(w); this.renderChart(); } }, 60); }
            },
            readFiltersFromUrl() { const u = new URL(window.location.href); for (const k of Object.keys(this.filters)) { const v = u.searchParams.get(k); if (v !== null) this.filters[k] = v; } },
            writeFiltersToUrl() { const u = new URL(window.location.href); for (const [k, v] of Object.entries(this.filters)) v === '' || v == null ? u.searchParams.delete(k) : u.searchParams.set(k, v); window.history.replaceState({}, '', u); },

            async loadData() {
                this.writeFiltersToUrl();
                if (this.abortCtrl) this.abortCtrl.abort(); this.abortCtrl = new AbortController();
                const p = new URLSearchParams();
                for (const [k, v] of Object.entries(this.filters)) if (v !== '' && v != null) p.set(k, v);
                this.loading = true;
                try {
                    const res = await fetch(`${QR_AO.endpointData}?${p.toString()}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: this.abortCtrl.signal });
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    const body = await res.json(); if (!body || !body.success) throw new Error('bad response');
                    this.payload = body.data || this.payload;
                    this.lastLoadedAt = new Date(); this.ready = true;
                    Alpine.store('pageMeta').rows = this.payload.total_rows ?? null;
                    this.$nextTick(() => this.renderChart());
                } catch (e) { if (e.name !== 'AbortError') { console.error('[qr-alert-out]', e); this.ready = true; } }
                finally { this.loading = false; }
            },
            resetFilters() { this.filters = { days: '7', poe: '', status: '', close_category: '', risk: '' }; this.loadData(); },

            renderChart() {
                const labels = this.payload?.chart?.labels || []; const values = this.payload?.chart?.values || []; const colors = this.payload?.chart?.colors || [];
                this.chartHasData = labels.length > 0;
                if (!this.chartHasData) { if (this.chart) { this.chart.destroy(); this.chart = null; } return; }
                const wrap = this.$refs.chart?.parentElement; if (!wrap) return;
                wrap.style.height = Math.min(540, Math.max(280, labels.length * 36 + 60)) + 'px';
                const fill = colors.map(c => c || '#1E88E5'); const border = fill.map(c => this.darken(c, 18));
                if (this.chart) { this.chart.data.labels = labels; this.chart.data.datasets[0].data = values; this.chart.data.datasets[0].backgroundColor = fill; this.chart.data.datasets[0].borderColor = border; this.chart.update('none'); return; }
                this.chart = new Chart(this.$refs.chart.getContext('2d'), {
                    type: 'bar',
                    data: { labels, datasets: [{ label: 'Alerts', data: values, backgroundColor: fill, borderColor: border, borderWidth: 1.5, borderRadius: 4, barThickness: 'flex', maxBarThickness: 24, hoverBackgroundColor: fill.map(c => this.darken(c, 8)) }]},
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: { duration: 260 },
                        layout: { padding: { left: 4, right: 28, top: 4, bottom: 4 } },
                        scales: { x: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 }, color: '#475569' }, grid: { color: 'rgba(15,23,42,.06)', drawBorder: false } },
                                  y: { ticks: { autoSkip: false, font: { size: 11.5, weight: '500' }, color: '#0F172A' }, grid: { display: false, drawBorder: false } } },
                        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0F172A', titleFont: { weight: '600', size: 12 }, bodyFont: { size: 11.5 }, padding: 10, cornerRadius: 6,
                                   callbacks: { title: items => items[0].label, label: item => { const n = item.parsed.x; return `${n.toLocaleString()} ${n === 1 ? 'alert' : 'alerts'}`; } } } },
                    },
                });
            },
            darken(hex, pct) { const m = /^#?([0-9a-f]{6})$/i.exec(hex || ''); if (!m) return hex; const n = parseInt(m[1], 16); const r = Math.max(0, Math.round(((n >> 16) & 0xff) * (1 - pct/100))); const g = Math.max(0, Math.round(((n >>  8) & 0xff) * (1 - pct/100))); const b = Math.max(0, Math.round(( n & 0xff) * (1 - pct/100))); return '#' + [r,g,b].map(v => v.toString(16).padStart(2,'0')).join(''); },

            fmt(n) { return (n ?? 0).toLocaleString(); },
            prettyStatus(s) { return QR_AO.statusLabels[s] || (s || '—'); },
            prettyRisk(r)   { return QR_AO.riskLabels[r]   || (r || '—'); },
            prettyClose(c)  { return QR_AO.closeLabels[c]  || c || ''; },
            riskPill(r)   { const b = 'qr-pill'; if (r==='CRITICAL') return b+' qr-pill-crit'; if (r==='HIGH') return b+' qr-pill-high'; if (r==='MEDIUM') return b+' qr-pill-med'; if (r==='LOW') return b+' qr-pill-low'; return b+' qr-pill-muted'; },
            statusPill(s) { const b = 'qr-pill'; if (s==='OPEN') return b+' qr-pill-high'; if (s==='ACKNOWLEDGED') return b+' qr-pill-med'; if (s==='IN_PROGRESS') return b+' qr-pill-info'; if (s==='CLOSED') return b+' qr-pill-success'; if (s==='REOPENED') return b+' qr-pill-crit'; return b+' qr-pill-muted'; },
            ackPill(min) {
                const b = 'qr-pill';
                if (min == null) return b + ' qr-pill-muted';
                if (min < 60)    return b + ' qr-pill-low';
                if (min < 240)   return b + ' qr-pill-med';
                if (min < 1440)  return b + ' qr-pill-high';
                return b + ' qr-pill-crit';
            },
            closePill(c) {
                const b = 'qr-pill';
                if (!c) return b + ' qr-pill-muted';
                if (c === 'CONFIRMED_CASE' || c === 'CONFIRMED')         return b + ' qr-pill-crit';
                if (c === 'PROBABLE')                                    return b + ' qr-pill-high';
                if (c === 'DISCARDED' || c === 'NO_CASE' || c === 'NOT_A_CASE') return b + ' qr-pill-success';
                if (c === 'FALSE_POSITIVE')                              return b + ' qr-pill-info';
                return b + ' qr-pill-muted';
            },
            rowDemographics(row) { const bits = []; if (row.age != null) bits.push(row.age + 'y'); if (row.sex) bits.push(row.sex.charAt(0) + row.sex.slice(1).toLowerCase()); if (row.nationality) bits.push(row.nationality); return bits.join(' · ') || '—'; },

            headline() {
                if (!this.ready) return 'Measuring response times…';
                const t = this.payload.kpis?.total_in_window ?? 0;
                const c = this.payload.kpis?.closed ?? 0;
                const scope = this.payload.scope?.label || '';
                if (t === 0) return `No alerts${scope ? ' in ' + scope : ''}.`;
                if (c === 0) return `${t} alerts in window · none closed yet${scope ? ' · ' + scope : ''}`;
                return `${c} closed of ${t} alerts${scope ? ' · ' + scope : ''}`;
            },
            tableSub() { if (!this.ready) return ''; const t = this.payload.total_rows ?? 0, s = this.payload.shown_rows ?? 0; if (t === 0) return 'No alerts in this window.'; if (s >= t) return `All ${t.toLocaleString()} alerts shown.`; return `${s} most pressing of ${t.toLocaleString()} alerts · Export full CSV for the complete set.`; },
            filtersSummary() { const f = this.filters; const bits = []; if (f.days) bits.push(`past ${f.days} d`); if (f.poe) bits.push(`POE ${f.poe}`); if (f.status) bits.push(`status ${this.prettyStatus(f.status).toLowerCase()}`); if (f.close_category) bits.push(this.prettyClose(f.close_category).toLowerCase()); if (f.risk) bits.push(`risk ${f.risk.toLowerCase()}`); return bits.length ? '· ' + bits.join(' · ') : '· defaults'; },
            lastLoadedLabel() { if (!this.lastLoadedAt) return '—'; const p = n => String(n).padStart(2,'0'); return `${p(this.lastLoadedAt.getHours())}:${p(this.lastLoadedAt.getMinutes())}`; },

            explainCategoryHeader() { switch (this.payload.chart?.kind) { case 'closure': return 'Closure outcome'; case 'ack': return 'Time-to-ack bucket'; case 'status': return 'Status'; default: return 'Category'; } },
            explainWhat() {
                const w = this.payload.window?.label || 'this window';
                switch (this.payload.chart?.kind) {
                    case 'closure': return `How alerts ended in ${w}. Each closed alert carries a category recorded by the officer.`;
                    case 'ack':     return `How quickly officers acknowledged the alerts opened in ${w}.`;
                    case 'status':  return `Where the alerts sit right now — no closures have happened yet in ${w}.`;
                    default:        return `No alerts in ${w}.`;
                }
            },
            explainHowToRead() {
                switch (this.payload.chart?.kind) {
                    case 'closure': return 'Red = actually a case. Green = ruled out / no case. Blue = false positive. Purple = lost to follow-up. Grey = administrative.';
                    case 'ack':     return 'Green ≤ 1 hr, orange 1–4 hr, deep-orange 4–24 hr, red > 24 hr. Blue-grey = no ack at all yet.';
                    case 'status':  return 'Open = unacked. Acknowledged = picked up. In progress = under investigation. Closed = resolved. Reopened = needed second look.';
                    default:        return '';
                }
            },
            explainNext() {
                switch (this.payload.chart?.kind) {
                    case 'closure': return 'Confirmed-case bars warrant IHR notification check via the Alert Analysis report.';
                    case 'ack':     return 'Red and deep-orange buckets are your SLA misses — investigate officer load at those POEs.';
                    case 'status':  return 'Open alerts older than 24 h need supervisor follow-up.';
                    default:        return '';
                }
            },
            explainPct(i) { const v = this.payload.chart?.values?.[i] ?? 0; const t = (this.payload.chart?.values || []).reduce((s, x) => s + x, 0); return t ? ((v / t) * 100).toFixed(1) + '%' : '—'; },

            openExplain()  { this.explainOpen = true; },
            closeExplain() { this.explainOpen = false; this.$nextTick(() => this.$refs.explainTrigger?.focus()); },

            exportChartPng() { if (!this.chart) return; const a = document.createElement('a'); a.href = this.chart.toBase64Image('image/png', 1.0); a.download = `qr-alert-out-chart-${this.fileStamp()}.png`; document.body.appendChild(a); a.click(); a.remove(); },
            exportChartCsv() {
                const labels = this.payload?.chart?.labels || []; const values = this.payload?.chart?.values || [];
                const lines = ['Category,Alerts'];
                for (let i = 0; i < labels.length; i++) lines.push(`"${String(labels[i]).replace(/"/g,'""')}",${values[i] ?? 0}`);
                const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
                const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `qr-alert-out-chart-${this.fileStamp()}.csv`;
                document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 60_000);
            },
            exportCsvFull() { const p = new URLSearchParams(); for (const [k, v] of Object.entries(this.filters)) if (v !== '' && v != null) p.set(k, v); p.set('format', 'CSV'); window.location.href = `${QR_AO.endpointExport}?${p.toString()}`; },
            fileStamp() { const d = new Date(); const p = n => String(n).padStart(2,'0'); return `${d.getFullYear()}${p(d.getMonth()+1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}`; },
        };
    }
</script>
@endpush
@endsection
