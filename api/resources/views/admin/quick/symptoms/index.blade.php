@extends('admin.layout')

@section('crumb', 'Quick Reports')
@section('title', 'Symptom Spread')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
@include('admin.quick._styles')
@endpush

@section('content')
<div x-data="qrSymptoms()" x-init="boot()" class="qr-stack" :aria-busy="loading ? 'true' : 'false'">

    <div class="qr-progress" x-show="loading" x-cloak></div>

    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Quick Reports · qr-symptoms</p>
            <h1 class="text-[20px] font-semibold tracking-tight">Symptom Spread</h1>
            <p class="help-text mt-1" x-text="headline()">Which symptoms screeners are seeing — and which red-flags need immediate attention.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready" x-cloak><span class="status-dot status-dot-live"></span><span x-text="payload.window?.label || ''"></span></span>
            <span class="topbar-chip" x-show="ready && payload.scope?.label" x-cloak x-text="payload.scope?.label"></span>
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
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 p-4">
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
            <div><label class="label block mb-1 text-[11px]">Red-flag only</label>
                <select class="select" x-model="filters.breached"><option value="">All cases</option><option value="1">Cases with red-flag symptoms</option></select>
            </div>
            <div><label class="label block mb-1 text-[11px]">Search</label>
                <input type="text" class="select" placeholder="Traveller, symptom, POE…" x-model="filters.q" @keydown.enter.prevent="loadData()">
            </div>
        </div>
    </section>

    <section>
        <template x-if="!ready"><div class="qr-kpi-grid"><div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div><div class="qr-kpi-skel"></div></div></template>
        <div class="qr-kpi-grid" x-show="ready" x-cloak>
            <div class="qr-kpi"><p class="qr-kpi-label">Cases with symptoms</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.cases_with_symptoms)"></p><p class="qr-kpi-hint" x-text="payload.window?.label ? 'Within ' + payload.window.label : 'Within window'"></p></div>
            <div class="qr-kpi qr-kpi-info"><p class="qr-kpi-label">YES symptoms recorded</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.total_yes_symptoms)"></p><p class="qr-kpi-hint">Total individual symptom reports</p></div>
            <div class="qr-kpi" :class="(payload.kpis?.red_flag_cases ?? 0) > 0 && 'qr-kpi-warn'"><p class="qr-kpi-label">With red-flag symptoms</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.red_flag_cases)"></p><p class="qr-kpi-hint">Bleeding, altered consciousness, etc.</p></div>
            <div class="qr-kpi"><p class="qr-kpi-label">Top symptom</p><p class="qr-kpi-value text-[14px]" x-text="payload.kpis?.top_symptom || '—'"></p><p class="qr-kpi-hint">Most-reported</p></div>
            <div class="qr-kpi"><p class="qr-kpi-label">Median per case</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.median_symptoms)"></p><p class="qr-kpi-hint">Typical symptom count</p></div>
            <div class="qr-kpi"><p class="qr-kpi-label">In last 24 hours</p><p class="qr-kpi-value" x-text="fmt(payload.kpis?.last_24h)"></p><p class="qr-kpi-hint">Opened since yesterday</p></div>
        </div>
    </section>

    <section class="qr-card">
        <div class="qr-card-head">
            <div class="min-w-0"><h2 class="qr-card-title" x-text="payload.chart?.title || 'Symptoms'"></h2><p class="qr-card-sub" x-text="payload.chart?.subtitle || ''"></p></div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" class="qr-icon-btn" @click="openExplain()" x-ref="explainTrigger" aria-label="Explain">?</button>
                <button type="button" class="btn btn-outline btn-xs" @click="exportChartPng()" :disabled="!chartHasData">PNG</button>
                <button type="button" class="btn btn-outline btn-xs" @click="exportChartCsv()" :disabled="!chartHasData">CSV</button>
            </div>
        </div>
        <div class="p-4">
            <template x-if="!ready"><div class="qr-chart-skel"></div></template>
            <div class="qr-chart-wrap" x-show="ready && chartHasData" x-cloak><canvas x-ref="chart" role="img" aria-label="Symptom distribution"></canvas></div>
            <div class="qr-empty" x-show="ready && !chartHasData" x-cloak>
                <p>No symptoms recorded YES in this window.</p>
                <p class="mt-1 text-[11.5px]">Widen the date range or clear a filter.</p>
            </div>
        </div>
    </section>

    <section class="qr-card overflow-hidden">
        <div class="qr-card-head">
            <div class="min-w-0"><h2 class="qr-card-title">Case register · with symptoms</h2><p class="qr-card-sub" x-text="tableSub()"></p></div>
            <div class="flex items-center gap-1.5 shrink-0"><button type="button" class="btn btn-outline btn-xs" @click="exportCsvFull()" :disabled="!ready">Export full CSV</button></div>
        </div>
        <div class="qr-table-wrap">
            <table class="qr-table">
                <thead><tr><th>Opened</th><th>Traveller</th><th>YES symptoms</th><th>Red-flag</th><th>Point of entry</th><th class="text-right pr-3">Case file</th></tr></thead>
                <tbody>
                    <template x-if="!ready"><template x-for="i in 6" :key="i"><tr><td colspan="6"><div class="h-5 my-1 rounded bg-muted/30 animate-pulse"></div></td></tr></template></template>

                    <template x-for="row in (payload.table || [])" :key="row.id">
                        <tr>
                            <td><div class="qr-cell-primary" x-text="row.opened_at_label"></div></td>
                            <td><div class="qr-cell-primary" x-text="row.traveller_name"></div><div class="qr-cell-secondary" x-text="rowDemographics(row)"></div></td>
                            <td>
                                <div class="flex flex-wrap max-w-[340px]">
                                    <template x-for="(s, i) in (row.symptoms || []).slice(0, 4)" :key="i">
                                        <span :class="(row.red_flags || []).includes(s) ? 'qr-pill qr-pill-crit' : 'qr-pill qr-pill-info'" class="mr-1 mb-0.5" x-text="s"></span>
                                    </template>
                                    <span class="qr-pill qr-pill-muted mr-1 mb-0.5" x-show="(row.symptoms?.length || 0) > 4" x-text="'+' + (row.symptoms.length - 4)"></span>
                                </div>
                            </td>
                            <td><span class="qr-pill qr-pill-crit" x-show="row.red_flag_count > 0" x-text="row.red_flag_count"></span><span x-show="!row.red_flag_count" class="qr-cell-mono">—</span></td>
                            <td x-text="row.poe_name || '—'"></td>
                            <td class="text-right pr-3"><a :href="row.case_file_url" class="qr-link-btn">View<svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M9 7h8v8"/></svg></a></td>
                        </tr>
                    </template>

                    <template x-if="ready && (payload.table?.length || 0) === 0">
                        <tr><td colspan="6"><div class="qr-empty my-2"><p>No cases match the filters.</p><p class="mt-1 text-[11.5px]">Try widening the window.</p></div></td></tr>
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
                    <div class="qr-modal-head"><div class="min-w-0"><p class="eyebrow">About this chart</p><h3 class="text-[15px] font-semibold mt-0.5 truncate" x-text="payload.chart?.title || 'Symptoms'"></h3></div><button type="button" class="qr-icon-btn" @click="closeExplain()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg></button></div>
                    <div class="qr-modal-body space-y-5">
                        <div class="qr-modal-section"><p>What this measures</p><p>How often each symptom was flagged YES across in-scope secondary screenings opened in <span x-text="payload.window?.label || 'this window'"></span>.</p></div>
                        <div class="qr-modal-section"><p>How to read it</p><p>Red bars are <strong>red-flag symptoms</strong> from the clinical-engine catalogue (bleeding, altered consciousness, etc.) — they warrant immediate clinical action. Other bars cycle through the Material palette.</p></div>
                        <div class="qr-modal-section"><p>What to do next</p><p>Click any case below to open the full case file. Use the <em>Red-flag only</em> filter to focus on the highest-priority cases first.</p></div>
                        <div class="qr-modal-section">
                            <p>Source data</p>
                            <div class="qr-table-wrap mt-2 max-h-[280px]"><table class="qr-table"><thead><tr><th>Symptom</th><th class="text-right pr-3">Reports</th><th class="text-right pr-3">% share</th></tr></thead><tbody>
                                <template x-for="(lbl, i) in (payload.chart?.labels || [])" :key="i"><tr><td x-text="lbl"></td><td class="text-right pr-3 tabular-nums" x-text="payload.chart.values[i]"></td><td class="text-right pr-3 tabular-nums text-muted-foreground" x-text="explainPct(i)"></td></tr></template>
                                <template x-if="(payload.chart?.labels?.length || 0) === 0"><tr><td colspan="3" class="text-center text-muted-foreground py-4">No data.</td></tr></template>
                            </tbody></table></div>
                        </div>
                    </div>
                    <div class="qr-modal-foot"><button type="button" class="btn btn-outline btn-xs" @click="exportChartCsv()" :disabled="!chartHasData">Download chart CSV</button><button type="button" class="btn btn-default btn-xs" @click="closeExplain()">Close</button></div>
                </div>
            </div>
        </div>
    </template>
</div>

@push('scripts')
<script>
    const QR_SYM = {
        endpointData:   @json(url('/admin/quick-reports/symptom-spread/data')),
        endpointExport: @json(url('/admin/quick-reports/symptom-spread/export')),
    };
    function qrSymptoms() {
        return {
            ready: false, loading: false, chartHasData: false, explainOpen: false, chart: null, lastLoadedAt: null, abortCtrl: null,
            filters: { days: '7', poe: '', breached: '', q: '' },
            payload: { window: null, scope: null, kpis: {}, chart: { labels: [], values: [] }, table: [], total_rows: 0, shown_rows: 0, meta: {} },
            boot() { this.readFiltersFromUrl(); this.loadData(); for (const k of ['days','poe','breached']) this.$watch(`filters.${k}`, () => this.loadData()); if (typeof Chart === 'undefined') { const w = setInterval(() => { if (typeof Chart !== 'undefined') { clearInterval(w); this.renderChart(); } }, 60); } },
            readFiltersFromUrl() { const u = new URL(window.location.href); for (const k of Object.keys(this.filters)) { const v = u.searchParams.get(k); if (v !== null) this.filters[k] = v; } },
            writeFiltersToUrl() { const u = new URL(window.location.href); for (const [k, v] of Object.entries(this.filters)) v === '' || v == null ? u.searchParams.delete(k) : u.searchParams.set(k, v); window.history.replaceState({}, '', u); },
            async loadData() {
                this.writeFiltersToUrl();
                if (this.abortCtrl) this.abortCtrl.abort(); this.abortCtrl = new AbortController();
                const p = new URLSearchParams();
                for (const [k, v] of Object.entries(this.filters)) if (v !== '' && v != null) p.set(k, v);
                this.loading = true;
                try {
                    const res = await fetch(`${QR_SYM.endpointData}?${p.toString()}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', signal: this.abortCtrl.signal });
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    const body = await res.json(); if (!body || !body.success) throw new Error('bad');
                    this.payload = body.data || this.payload;
                    this.lastLoadedAt = new Date(); this.ready = true;
                    Alpine.store('pageMeta').rows = this.payload.total_rows ?? null;
                    this.$nextTick(() => this.renderChart());
                } catch (e) { if (e.name !== 'AbortError') { console.error('[qr-symptoms]', e); this.ready = true; } }
                finally { this.loading = false; }
            },
            resetFilters() { this.filters = { days: '7', poe: '', breached: '', q: '' }; this.loadData(); },

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
                    data: { labels, datasets: [{ label: 'Reports', data: values, backgroundColor: fill, borderColor: border, borderWidth: 1.5, borderRadius: 4, barThickness: 'flex', maxBarThickness: 24, hoverBackgroundColor: fill.map(c => this.darken(c, 8)) }]},
                    options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, animation: { duration: 260 },
                        layout: { padding: { left: 4, right: 28, top: 4, bottom: 4 } },
                        scales: { x: { beginAtZero: true, ticks: { precision: 0, font: { size: 11 }, color: '#475569' }, grid: { color: 'rgba(15,23,42,.06)', drawBorder: false } },
                                  y: { ticks: { autoSkip: false, font: { size: 11.5, weight: '500' }, color: '#0F172A' }, grid: { display: false, drawBorder: false } } },
                        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#0F172A', titleFont: { weight: '600', size: 12 }, bodyFont: { size: 11.5 }, padding: 10, cornerRadius: 6, callbacks: { title: items => items[0].label, label: item => { const n = item.parsed.x; return `${n.toLocaleString()} ${n === 1 ? 'report' : 'reports'}`; } } } },
                    },
                });
            },
            darken(hex, pct) { const m = /^#?([0-9a-f]{6})$/i.exec(hex || ''); if (!m) return hex; const n = parseInt(m[1], 16); const r = Math.max(0, Math.round(((n >> 16) & 0xff) * (1 - pct/100))); const g = Math.max(0, Math.round(((n >>  8) & 0xff) * (1 - pct/100))); const b = Math.max(0, Math.round(( n & 0xff) * (1 - pct/100))); return '#' + [r,g,b].map(v => v.toString(16).padStart(2,'0')).join(''); },

            fmt(n) { return (n ?? 0).toLocaleString(); },
            rowDemographics(row) { const bits = []; if (row.age != null) bits.push(row.age + 'y'); if (row.sex) bits.push(row.sex.charAt(0) + row.sex.slice(1).toLowerCase()); if (row.nationality) bits.push(row.nationality); return bits.join(' · ') || '—'; },
            headline() { if (!this.ready) return 'Loading symptoms…'; const c = this.payload.kpis?.cases_with_symptoms ?? 0; const r = this.payload.kpis?.red_flag_cases ?? 0; const scope = this.payload.scope?.label || ''; if (c === 0) return `No symptoms recorded${scope ? ' in ' + scope : ''}.`; return r ? `${r} of ${c} cases carry a red-flag symptom${scope ? ' · ' + scope : ''}` : `${c} cases with symptoms${scope ? ' · ' + scope : ''}`; },
            tableSub() { if (!this.ready) return ''; const t = this.payload.total_rows ?? 0, s = this.payload.shown_rows ?? 0; if (t === 0) return 'No cases match the filters.'; if (s >= t) return `All ${t.toLocaleString()} cases shown.`; return `${s} most pressing of ${t.toLocaleString()} cases · Export full CSV for the complete set.`; },
            filtersSummary() { const f = this.filters; const bits = []; if (f.days) bits.push(`past ${f.days} d`); if (f.poe) bits.push(`POE ${f.poe}`); if (f.breached === '1') bits.push('red-flag only'); if (f.q) bits.push(`q "${f.q}"`); return bits.length ? '· ' + bits.join(' · ') : '· defaults'; },
            lastLoadedLabel() { if (!this.lastLoadedAt) return '—'; const p = n => String(n).padStart(2,'0'); return `${p(this.lastLoadedAt.getHours())}:${p(this.lastLoadedAt.getMinutes())}`; },

            explainPct(i) { const v = this.payload.chart?.values?.[i] ?? 0; const t = (this.payload.chart?.values || []).reduce((s, x) => s + x, 0); return t ? ((v / t) * 100).toFixed(1) + '%' : '—'; },
            openExplain()  { this.explainOpen = true; },
            closeExplain() { this.explainOpen = false; this.$nextTick(() => this.$refs.explainTrigger?.focus()); },

            exportChartPng() { if (!this.chart) return; const a = document.createElement('a'); a.href = this.chart.toBase64Image('image/png', 1.0); a.download = `qr-symptoms-chart-${this.fileStamp()}.png`; document.body.appendChild(a); a.click(); a.remove(); },
            exportChartCsv() { const labels = this.payload?.chart?.labels || []; const values = this.payload?.chart?.values || []; const lines = ['Symptom,Reports']; for (let i = 0; i < labels.length; i++) lines.push(`"${String(labels[i]).replace(/"/g,'""')}",${values[i] ?? 0}`); const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }); const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = `qr-symptoms-chart-${this.fileStamp()}.csv`; document.body.appendChild(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(a.href), 60_000); },
            exportCsvFull() { const p = new URLSearchParams(); for (const [k, v] of Object.entries(this.filters)) if (v !== '' && v != null) p.set(k, v); p.set('format', 'CSV'); window.location.href = `${QR_SYM.endpointExport}?${p.toString()}`; },
            fileStamp() { const d = new Date(); const p = n => String(n).padStart(2,'0'); return `${d.getFullYear()}${p(d.getMonth()+1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}`; },
        };
    }
</script>
@endpush
@endsection
