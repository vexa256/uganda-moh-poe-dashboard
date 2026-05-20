@extends('admin.layout')

@section('crumb', 'Quick Reports')
@section('title', 'Alert Analysis')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
@include('admin.quick._styles')
@endpush

@section('content')
<div x-data="qrAlertIntel()" x-init="boot()" class="qr-stack" :aria-busy="loading ? 'true' : 'false'">

    <div class="qr-progress" x-show="loading" x-cloak></div>

    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Quick Reports · qr-alert-intel</p>
            <h1 class="text-[20px] font-semibold tracking-tight">Alert Analysis</h1>
            <p class="help-text mt-1" x-text="headline()">Pattern detection across the alert stream — severity mix, IHR tier exposure, surges.</p>
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
            <div><label class="label block mb-1 text-[11px]">Risk level</label>
                <select class="select" x-model="filters.risk"><option value="">All risk levels</option>
                    <template x-for="r in (payload.meta?.risks || [])" :key="r"><option :value="r" x-text="prettyRisk(r)"></option></template>
                </select>
            </div>
            <div><label class="label block mb-1 text-[11px]">IHR tier</label>
                <select class="select" x-model="filters.ihr_tier"><option value="">All IHR tiers</option>
                    <option value="1">Tier 1 — Always notifiable</option><option value="2">Tier 2 — Annex 2 review</option><option value="3">Tier 3 — Routine</option>
                </select>
            </div>
            <div><label class="label block mb-1 text-[11px]">Status</label>
                <select class="select" x-model="filters.status"><option value="">All statuses</option>
                    <template x-for="s in (payload.meta?.statuses || [])" :key="s"><option :value="s" x-text="prettyStatus(s)"></option></template>
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
            <div class="qr-kpi"><p class="qr-kpi-label">Alerts</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.total)"></p><p class="qr-kpi-hint" x-text="payload.window?.label ? 'Within ' + payload.window.label : 'Within window'"></p></div>
            <div class="qr-kpi" :class="(payload.kpis?.critical_high ?? 0) > 0 && 'qr-kpi-warn'"><p class="qr-kpi-label">Critical / High</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.critical_high)"></p><p class="qr-kpi-hint">Top-tier severity</p></div>
            <div class="qr-kpi" :class="(payload.kpis?.ihr_tier1 ?? 0) > 0 && 'qr-kpi-warn'"><p class="qr-kpi-label">IHR tier 1</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.ihr_tier1)"></p><p class="qr-kpi-hint">Always-notifiable diseases</p></div>
            <div class="qr-kpi qr-kpi-info"><p class="qr-kpi-label">False-positive %</p><p class="qr-kpi-value" x-text="payload.kpis?.false_positive_pct !== null && payload.kpis?.false_positive_pct !== undefined ? payload.kpis.false_positive_pct + '%' : '—'"></p><p class="qr-kpi-hint">Of closed alerts</p></div>
            <div class="qr-kpi"><p class="qr-kpi-label">Closed in window</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.closed)"></p><p class="qr-kpi-hint">Sample size for the FP %</p></div>
            <div class="qr-kpi"><p class="qr-kpi-label">In last 24 hours</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.last_24h)"></p><p class="qr-kpi-hint">Opened since yesterday</p></div>
        </div>
    </section>

    <section class="qr-card" aria-label="Alert pattern chart">
        <div class="qr-card-head">
            <div class="min-w-0">
                <h2 class="qr-card-title" x-text="payload.chart?.title || 'Alert pattern'"></h2>
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
            <div class="qr-chart-wrap" x-show="ready && chartHasData" x-cloak><canvas x-ref="chart" role="img" aria-label="Alert pattern"></canvas></div>
            <div class="qr-empty" x-show="ready && !chartHasData" x-cloak>
                <p>No alerts to analyse in this window.</p>
                <p class="mt-1 text-[11.5px]">Widen the date range or clear a filter.</p>
            </div>
        </div>
    </section>

    <section class="qr-card overflow-hidden" aria-label="High-priority alert spotlight">
        <div class="qr-card-head">
            <div class="min-w-0">
                <h2 class="qr-card-title">High-priority spotlight</h2>
                <p class="qr-card-sub" x-text="tableSub()"></p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" class="btn btn-outline btn-xs" @click="exportCsvFull()" :disabled="!ready">Export full CSV</button>
            </div>
        </div>
        <div class="qr-table-wrap">
            <table class="qr-table">
                <thead><tr><th>Opened</th><th>Alert</th><th>Traveller</th><th>Risk</th><th>IHR</th><th>Status</th><th>Point of entry</th><th class="text-right pr-3">Case file</th></tr></thead>
                <tbody>
                    <template x-if="!ready"><template x-for="i in 6" :key="i"><tr><td colspan="8"><div class="h-5 my-1 rounded bg-muted/30 animate-pulse"></div></td></tr></template></template>

                    <template x-for="row in (payload.table || [])" :key="row.alert_id">
                        <tr>
                            <td><div class="qr-cell-primary" x-text="row.opened_at_label"></div></td>
                            <td><div class="qr-cell-mono" x-text="row.alert_code || '#' + row.alert_id"></div></td>
                            <td><div class="qr-cell-primary" x-text="row.traveller_name"></div><div class="qr-cell-secondary" x-text="rowDemographics(row)"></div></td>
                            <td><span :class="riskPill(row.risk)" x-text="prettyRisk(row.risk)"></span></td>
                            <td><span class="qr-pill qr-pill-crit" x-show="row.ihr_tier === 1" x-text="'Tier 1'"></span><span class="qr-pill qr-pill-info" x-show="row.ihr_tier === 2" x-text="'Tier 2'"></span><span class="qr-pill qr-pill-success" x-show="row.ihr_tier === 3" x-text="'Tier 3'"></span><span x-show="!row.ihr_tier" class="qr-cell-mono">—</span></td>
                            <td><span :class="statusPill(row.status)" x-text="prettyStatus(row.status)"></span></td>
                            <td x-text="row.poe_name || '—'"></td>
                            <td class="text-right pr-3"><a :href="row.case_file_url" class="qr-link-btn" aria-label="Open case file">View<svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M9 7h8v8"/></svg></a></td>
                        </tr>
                    </template>

                    <template x-if="ready && (payload.table?.length || 0) === 0">
                        <tr><td colspan="8"><div class="qr-empty my-2"><p>No HIGH / CRITICAL / Tier-1 alerts in this window.</p><p class="mt-1 text-[11.5px]">That's the quiet news — the chart above still summarises the full alert stream.</p></div></td></tr>
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
                        <div class="min-w-0"><p class="eyebrow">About this chart</p><h3 class="text-[15px] font-semibold mt-0.5 truncate" x-text="payload.chart?.title || 'Alert pattern'"></h3></div>
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
    const QR_AI = {
        endpointData:   @json(url('/admin/quick-reports/alert-analysis/data')),
        endpointExport: @json(url('/admin/quick-reports/alert-analysis/export')),
        statusLabels: { OPEN: 'Open', ACKNOWLEDGED: 'Acknowledged', IN_PROGRESS: 'In progress', CLOSED: 'Closed', REOPENED: 'Reopened' },
        riskLabels:   { LOW: 'Low', MEDIUM: 'Medium', HIGH: 'High', CRITICAL: 'Critical' },
    };

    function qrAlertIntel() {
        return {
            ready: false, loading: false, chartHasData: false,
            explainOpen: false, chart: null, lastLoadedAt: null, abortCtrl: null,
            filters: { days: '7', poe: '', risk: '', status: '', ihr_tier: '' },
            payload: { window: null, scope: null, kpis: {}, chart: { labels: [], values: [] }, table: [], total_rows: 0, shown_rows: 0, meta: {} },

            boot() {
                this.readFiltersFromUrl(); this.loadData();
                for (const k of ['days','poe','risk','status','ihr_tier']) this.$watch(`filters.${k}`, () => this.loadData());
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
                    const res = await fetch(`${QR_AI.endpointData}?${p.toString()}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: this.abortCtrl.signal });
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    const body = await res.json(); if (!body || !body.success) throw new Error('bad response');
                    this.payload = body.data || this.payload;
                    this.lastLoadedAt = new Date(); this.ready = true;
                    Alpine.store('pageMeta').rows = this.payload.total_rows ?? null;
                    this.$nextTick(() => this.renderChart());
                } catch (e) { if (e.name !== 'AbortError') { console.error('[qr-alert-intel]', e); this.ready = true; } }
                finally { this.loading = false; }
            },
            resetFilters() { this.filters = { days: '7', poe: '', risk: '', status: '', ihr_tier: '' }; this.loadData(); },

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
            darken(hex, pct) {
                const m = /^#?([0-9a-f]{6})$/i.exec(hex || ''); if (!m) return hex;
                const n = parseInt(m[1], 16);
                const r = Math.max(0, Math.round(((n >> 16) & 0xff) * (1 - pct/100)));
                const g = Math.max(0, Math.round(((n >>  8) & 0xff) * (1 - pct/100)));
                const b = Math.max(0, Math.round(( n        & 0xff) * (1 - pct/100)));
                return '#' + [r,g,b].map(v => v.toString(16).padStart(2,'0')).join('');
            },

            fmt(n) { return (n ?? 0).toLocaleString(); },
            prettyStatus(s) { return QR_AI.statusLabels[s] || (s || '—'); },
            prettyRisk(r)   { return QR_AI.riskLabels[r]   || (r || '—'); },
            riskPill(r)   { const b = 'qr-pill'; if (r==='CRITICAL') return b+' qr-pill-crit'; if (r==='HIGH') return b+' qr-pill-high'; if (r==='MEDIUM') return b+' qr-pill-med'; if (r==='LOW') return b+' qr-pill-low'; return b+' qr-pill-muted'; },
            statusPill(s) { const b = 'qr-pill'; if (s==='OPEN') return b+' qr-pill-high'; if (s==='ACKNOWLEDGED') return b+' qr-pill-med'; if (s==='IN_PROGRESS') return b+' qr-pill-info'; if (s==='CLOSED') return b+' qr-pill-success'; if (s==='REOPENED') return b+' qr-pill-crit'; return b+' qr-pill-muted'; },
            rowDemographics(row) { const bits = []; if (row.age != null) bits.push(row.age + 'y'); if (row.sex) bits.push(row.sex.charAt(0) + row.sex.slice(1).toLowerCase()); if (row.nationality) bits.push(row.nationality); return bits.join(' · ') || '—'; },

            headline() {
                if (!this.ready) return 'Analysing alerts…';
                const t = this.payload.total_rows ?? 0, ch = this.payload.kpis?.critical_high ?? 0;
                const scope = this.payload.scope?.label || '';
                if (t === 0) return `No alerts${scope ? ' in ' + scope : ''}.`;
                if (ch === 0) return `${t} alerts · none in the High / Critical tier${scope ? ' · ' + scope : ''}`;
                return `${ch} of ${t} alerts in High / Critical${scope ? ' · ' + scope : ''}`;
            },
            tableSub() { if (!this.ready) return ''; const t = this.payload.total_rows ?? 0, s = this.payload.shown_rows ?? 0; if (t === 0) return 'No alerts in this window.'; if (s === 0) return `No HIGH / CRITICAL / Tier-1 spotlight among the ${t} alerts.`; return `${s} spotlight ${s === 1 ? 'alert' : 'alerts'} of ${t} total.`; },
            filtersSummary() { const f = this.filters; const bits = []; if (f.days) bits.push(`past ${f.days} d`); if (f.poe) bits.push(`POE ${f.poe}`); if (f.risk) bits.push(`risk ${f.risk.toLowerCase()}`); if (f.status) bits.push(`status ${this.prettyStatus(f.status).toLowerCase()}`); if (f.ihr_tier) bits.push(`tier ${f.ihr_tier}`); return bits.length ? '· ' + bits.join(' · ') : '· defaults'; },
            lastLoadedLabel() { if (!this.lastLoadedAt) return '—'; const p = n => String(n).padStart(2,'0'); return `${p(this.lastLoadedAt.getHours())}:${p(this.lastLoadedAt.getMinutes())}`; },

            explainCategoryHeader() { switch (this.payload.chart?.kind) { case 'day_risk': return 'Day'; case 'risk': return 'Risk level'; case 'tier': return 'IHR tier'; default: return 'Category'; } },
            explainWhat() {
                const w = this.payload.window?.label || 'this window';
                switch (this.payload.chart?.kind) {
                    case 'day_risk': return `Daily alert volume across ${w}. Bar colour = dominant risk tier on that day.`;
                    case 'risk':     return `Severity distribution across ${w}.`;
                    case 'tier':     return `WHO IHR tier exposure across ${w}.`;
                    default:         return `No alerts in ${w}.`;
                }
            },
            explainHowToRead() {
                switch (this.payload.chart?.kind) {
                    case 'day_risk': return 'A tall red bar = many alerts that day and the worst one was Critical. A tall green bar = many but routine.';
                    case 'risk':     return 'Critical and High demand same-day clinical attention. Low / Not-set lean routine.';
                    case 'tier':     return 'Tier 1 is the IHR always-notifiable list — WHO must be alerted. Tier 2 needs Annex 2 review.';
                    default:         return '';
                }
            },
            explainNext() {
                switch (this.payload.chart?.kind) {
                    case 'day_risk': return 'Investigate the tallest red/orange bar first — that\'s your cluster signal.';
                    case 'risk':     return 'Drill into Critical + High rows below; cross-check against the disease catalogue.';
                    case 'tier':     return 'Confirm Tier-1 cases have been WHO-notified via Alert Outcomes.';
                    default:         return '';
                }
            },
            explainPct(i) { const v = this.payload.chart?.values?.[i] ?? 0; const t = (this.payload.chart?.values || []).reduce((s, x) => s + x, 0); return t ? ((v / t) * 100).toFixed(1) + '%' : '—'; },

            openExplain()  { this.explainOpen = true; },
            closeExplain() { this.explainOpen = false; this.$nextTick(() => this.$refs.explainTrigger?.focus()); },

            exportChartPng() { if (!this.chart) return; const a = document.createElement('a'); a.href = this.chart.toBase64Image('image/png', 1.0); a.download = `qr-alert-intel-chart-${this.fileStamp()}.png`; document.body.appendChild(a); a.click(); a.remove(); },
            exportChartCsv() {
                const labels = this.payload?.chart?.labels || []; const values = this.payload?.chart?.values || [];
                const lines = ['Category,Alerts'];
                for (let i = 0; i < labels.length; i++) lines.push(`"${String(labels[i]).replace(/"/g,'""')}",${values[i] ?? 0}`);
                const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
                const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `qr-alert-intel-chart-${this.fileStamp()}.csv`;
                document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 60_000);
            },
            exportCsvFull() { const p = new URLSearchParams(); for (const [k, v] of Object.entries(this.filters)) if (v !== '' && v != null) p.set(k, v); p.set('format', 'CSV'); window.location.href = `${QR_AI.endpointExport}?${p.toString()}`; },
            fileStamp() { const d = new Date(); const p = n => String(n).padStart(2,'0'); return `${d.getFullYear()}${p(d.getMonth()+1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}`; },
        };
    }
</script>
@endpush
@endsection
