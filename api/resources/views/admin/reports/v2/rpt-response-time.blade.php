@extends('admin.layout')

@section('crumb', 'Reports')
@section('title', 'Response Timeliness')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style type="text/tailwindcss">
    .rpt-tile { @apply rounded-xl border bg-card text-card-foreground shadow-elevation-1 p-4 transition-shadow hover:shadow-elevation-3; }
    .rpt-tile-brand    { @apply border-brand/30 bg-brand-soft/50; }
    .rpt-tile-info     { @apply border-info/25 bg-info-soft/35; }
    .rpt-tile-success  { @apply border-success/25 bg-success-soft/40; }
    .rpt-tile-warning  { @apply border-warning/30 bg-warning-soft/40; }
    .rpt-tile-danger   { @apply border-danger/30 bg-danger-soft/40; }
    .rpt-tile-critical { @apply border-critical/30 bg-critical-soft/40; }
    .rpt-tile-neutral  { @apply bg-card; }
    .rpt-section { @apply rounded-lg border bg-card; }
    .rpt-section-head { @apply flex items-center justify-between px-4 py-3 cursor-pointer text-[13px] font-semibold; }
    .rpt-section-body { @apply px-4 pb-4; }
    .rpt-chip { @apply inline-flex items-center gap-1.5 h-7 rounded-full border px-2.5 text-[11.5px] font-medium transition-colors cursor-pointer; }
    .rpt-chip-active { @apply bg-brand-soft border-brand/40 text-brand-ink; }
    .rpt-chip-idle   { @apply bg-card border-border/60 text-muted-foreground hover:bg-muted/60; }
    .rpt-th-sort { @apply inline-flex items-center gap-1 cursor-pointer select-none; }
    .rpt-th-sort:hover { @apply text-foreground; }
    .rpt-skel { @apply animate-pulse bg-muted/60 rounded; }
    .rpt-sla-bar { @apply relative h-2 overflow-hidden rounded-full bg-muted; }
    .rpt-sla-fill { @apply absolute inset-y-0 left-0 rounded-full transition-[width]; }
</style>
@endpush

@section('content')
{{--
    R4 · Response Timeliness — for two audiences.
    Executive: Are we acknowledging fast enough? Are we resolving fast enough?
    Where are the bottlenecks?  (KPIs + 2 charts)
    Technical: Per-alert ack/resolution timing, SLA status, owner, lifecycle
    timeline, follow-up completion. (drill modal)
--}}
<div x-data="rptResponseTime()" x-init="boot()" class="space-y-4">

    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-info/80">Alert Analytics · R4</p>
            <h1 class="text-[22px] font-bold tracking-tight">Response Timeliness</h1>
            <p class="text-sm text-muted-foreground mt-0.5">How quickly we acknowledge and resolve alerts, and where bottlenecks live.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-md border border-border/70 bg-card px-2.5 py-1 text-[11.5px] font-mono text-foreground/80" x-show="window_label"><span class="status-dot status-dot-live"></span><span x-text="window_label"></span></span>
            <button class="btn btn-outline btn-xs" @click="filtersOpen = !filtersOpen" x-text="filtersOpen ? 'Hide Filters' : 'Show Filters'"></button>
        </div>
    </section>

    <section class="card" x-show="filtersOpen" x-cloak>
        <div class="card-content py-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Point of Entry</label>
                    <select class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm" x-model="filters.poe">
                        <option value="">All POEs</option>
                        <template x-for="(name, code) in (meta.poes || {})" :key="code"><option :value="code" x-text="name"></option></template>
                    </select>
                </div>
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">From</label>
                    <input type="date" class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm" x-model="filters.start_date">
                </div>
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">To</label>
                    <input type="date" class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm" x-model="filters.end_date">
                </div>
                <div class="flex items-end gap-2">
                    <button class="btn btn-brand btn-md flex-1" @click="apply()">Apply</button>
                    <button class="btn btn-outline btn-md" @click="resetFilters()">Reset</button>
                </div>
            </div>
        </div>
    </section>

    <section class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        <template x-for="k in kpis" :key="k.key">
            <div class="rpt-tile" :class="'rpt-tile-' + (k.tone || 'neutral')">
                <p class="kpi-label" x-text="k.label"></p>
                <p class="kpi-value mt-1" x-text="k.value"></p>
                <p class="text-[11px] text-muted-foreground mt-1 leading-snug" x-text="k.hint"></p>
            </div>
        </template>
        <template x-if="!kpis.length">
            <template x-for="i in 5" :key="i">
                <div class="rpt-tile rpt-tile-neutral"><div class="rpt-skel h-4 w-24"></div><div class="rpt-skel h-8 w-16 mt-2"></div><div class="rpt-skel h-3 w-32 mt-2"></div></div>
            </template>
        </template>
    </section>

    {{-- TAB STRIP --}}
    <div class="flex items-center gap-1 border-b border-border/60">
        <button type="button" class="px-4 py-2 text-[13px] font-semibold border-b-2 transition-colors" :class="tab === 'charts' ? 'border-brand text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'" @click="tab = 'charts'">Charts</button>
        <button type="button" class="px-4 py-2 text-[13px] font-semibold border-b-2 transition-colors" :class="tab === 'records' ? 'border-brand text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'" @click="tab = 'records'">Timing Log <span class="ml-1 font-mono opacity-60 text-[11px]" x-text="pagination.total ? `(${pagination.total})` : ''"></span></button>
    </div>

    <section x-show="tab === 'charts'" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @foreach ([
            ['target' => 'ack', 'serverKey' => 'ack_time_distribution', 'title' => 'Acknowledgement Time Distribution', 'subtitle' => 'How long alerts wait before someone acknowledges them'],
            ['target' => 'median', 'serverKey' => 'median_resolution_by_poe', 'title' => 'Median Resolution by POE', 'subtitle' => 'Top 10 slowest POEs · median + mean hours to close'],
        ] as $c)
        <div class="card overflow-hidden">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-4 border-b border-border/60">
                <div class="min-w-0">
                    <h3 class="text-[14px] font-semibold tracking-tight">{{ $c['title'] }}</h3>
                    <p class="text-[12px] text-muted-foreground">{{ $c['subtitle'] }}</p>
                </div>
                <div class="flex items-center gap-1.5 flex-wrap">
                    <button class="btn btn-soft-info btn-xs gap-1.5" @click="openExplainer('{{ $c['target'] }}')" title="What this chart shows + underlying data table"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg> Explain</button>
                    <button class="btn btn-outline btn-xs gap-1.5" @click="exportChart('{{ $c['target'] }}', 'png')"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg> PNG</button>
                    <button class="btn btn-outline btn-xs gap-1.5" @click="exportChart('{{ $c['target'] }}', 'csv')"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 17H5a2 2 0 01-2-2V5a2 2 0 012-2h10l4 4v3M14 3v4h4M9 13h6m-6 4h4"/></svg> CSV</button>
                </div>
            </div>
            <div class="card-content py-4"><div class="relative h-[280px]"><canvas x-ref="chart_{{ $c['target'] }}"></canvas></div></div>
        </div>
        @endforeach
    </section>

    <section x-show="tab === 'records'" class="card overflow-hidden">
        <div class="flex flex-col gap-3 p-4 border-b border-border/60">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-[14px] font-semibold tracking-tight">Per-Alert Timing Log</h3>
                    <p class="text-[12px] text-muted-foreground">Click any row for the alert lifecycle, owner, and follow-up audit trail.</p>
                </div>
                <span class="text-[11px] font-mono text-muted-foreground" x-text="(pagination.total ?? 0).toLocaleString() + ' alerts'"></span>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                <div class="relative flex-1 max-w-md">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="search" placeholder="Search code, title, POE, owner..." class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-sm" x-model.debounce.300ms="search" @input="loadRecords(1)">
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="rpt-chip" :class="cat === 'all' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('all')">All <span class="font-mono opacity-70" x-text="categoryCounts.all || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'pending' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('pending')">Pending <span class="font-mono opacity-70" x-text="categoryCounts.pending || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'closed' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('closed')">Closed <span class="font-mono opacity-70" x-text="categoryCounts.closed || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'breached' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('breached')">SLA Breached <span class="font-mono opacity-70" x-text="categoryCounts.breached || 0"></span></span>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead class="table-head sticky top-0">
                    <tr>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('alert_code')">Alert <span x-html="sortIcon('alert_code')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('poe_code')">POE <span x-html="sortIcon('poe_code')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('risk_level')">Risk <span x-html="sortIcon('risk_level')"></span></span></th>
                        <th class="table-head-th">Owner</th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('ack_minutes')">Ack Time <span x-html="sortIcon('ack_minutes')"></span></span></th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('res_minutes')">Resolution <span x-html="sortIcon('res_minutes')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('status')">Status <span x-html="sortIcon('status')"></span></span></th>
                    </tr>
                </thead>
                <tbody class="table-body">
                    <template x-if="loading">
                        <template x-for="i in 10" :key="i">
                            <tr class="border-b border-border/40">
                                <td class="table-cell"><div class="rpt-skel h-4 w-48"></div><div class="rpt-skel h-3 w-32 mt-1.5"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-20"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-5 w-16"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-28"></div></td>
                                <td class="table-cell text-right"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell text-right"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-5 w-20"></div></td>
                            </tr>
                        </template>
                    </template>
                    <template x-if="!loading">
                        <template x-for="r in rows" :key="r.id">
                            <tr class="table-row cursor-pointer" :class="r.sla_breached ? 'bg-critical-soft/20' : ''" @click="openDrill(r.id)">
                                <td class="table-cell">
                                    <div class="font-medium" x-text="r.alert_title"></div>
                                    <div class="text-[11px] text-muted-foreground font-mono" x-text="r.alert_code || ('Alert #' + r.id)"></div>
                                </td>
                                <td class="table-cell text-[12px]" x-text="r.poe_code || '—'"></td>
                                <td class="table-cell"><span class="badge" :class="badgeForRisk(r.risk_level)" x-text="r.risk_level"></span></td>
                                <td class="table-cell text-[12.5px]" x-text="r.owner_name || '—'"></td>
                                <td class="table-cell text-right font-mono" :class="r.ack_minutes !== null && r.ack_minutes > 60 ? 'text-warning' : ''" x-text="formatMinutes(r.ack_minutes)"></td>
                                <td class="table-cell text-right font-mono" :class="r.res_minutes !== null && r.res_minutes > (r.sla_hours * 60) ? 'text-critical' : ''" x-text="formatMinutes(r.res_minutes)"></td>
                                <td class="table-cell">
                                    <span class="badge" :class="badgeForStatus(r.status)" x-text="r.status"></span>
                                    <span x-show="r.sla_breached" class="badge badge-critical ml-1">BREACH</span>
                                </td>
                            </tr>
                        </template>
                    </template>
                    <tr x-show="!loading && !rows.length"><td colspan="7" class="table-cell py-12 text-center text-muted-foreground">No alerts match the current filters.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 p-4 border-t border-border/60">
            <span class="text-[12px] text-muted-foreground">Showing <span class="font-mono" x-text="(pagination.from ?? 0).toLocaleString()"></span>–<span class="font-mono" x-text="(pagination.to ?? 0).toLocaleString()"></span> of <span class="font-mono" x-text="(pagination.total ?? 0).toLocaleString()"></span></span>
            <div class="flex items-center gap-1">
                <button class="btn btn-outline btn-xs" :disabled="pagination.page <= 1" @click="loadRecords(1)">«</button>
                <button class="btn btn-outline btn-xs" :disabled="pagination.page <= 1" @click="loadRecords(pagination.page - 1)">‹</button>
                <template x-for="p in pageCluster()" :key="p"><button class="btn btn-xs" :class="p === pagination.page ? 'btn-brand' : 'btn-outline'" @click="loadRecords(p)" x-text="p"></button></template>
                <button class="btn btn-outline btn-xs" :disabled="pagination.page >= pagination.total_pages" @click="loadRecords(pagination.page + 1)">›</button>
                <button class="btn btn-outline btn-xs" :disabled="pagination.page >= pagination.total_pages" @click="loadRecords(pagination.total_pages)">»</button>
            </div>
        </div>
    </section>

    {{-- DRILL MODAL --}}
    <template x-teleport="body">
        <div x-show="drill.open" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" @keydown.escape.window="drill.open = false">
            <div class="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm" @click="drill.open = false"></div>
            <div class="relative z-50 w-full sm:w-[96vw] sm:max-w-[1400px] h-[94vh] sm:max-h-[94vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl border border-border bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-border/60 bg-white/95 backdrop-blur px-5 py-3.5">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-info/80">Response Lifecycle</p>
                        <h2 class="text-[17px] font-bold tracking-tight truncate" x-text="drill.data?.alert?.title || ('Alert #' + (drill.alertId || ''))"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="drill.open = false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </div>
                <div class="p-5 space-y-4" x-show="drill.data">
                    {{-- ════════ Case Walkthrough — deterministic 7-step wizard ════════ --}}
                    <div class="rounded-xl border-2 border-info/30 bg-gradient-to-br from-info-soft/40 to-brand-soft/30 p-4 sm:p-5" x-show="wizard.open">
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-info">Case Walkthrough · Deterministic AI</p>
                                <h3 class="text-[16px] font-bold tracking-tight">A guided tour of this alert's response</h3>
                            </div>
                            <button class="btn btn-ghost btn-xs text-muted-foreground" @click="wizard.open = false">Skip walkthrough</button>
                        </div>
                        <div class="flex items-center gap-1 sm:gap-1.5 overflow-x-auto pb-2 mb-3" role="tablist">
                            <template x-for="s in wizardSteps()" :key="s.id">
                                <button class="flex-shrink-0 flex items-center gap-1.5 rounded-full border px-2.5 h-7 text-[11px] font-medium transition-colors"
                                        :class="s.id === wizard.step ? 'bg-brand text-white border-brand' : (s.id < wizard.step ? 'bg-success-soft border-success/30 text-success' : 'bg-card border-border/60 text-muted-foreground hover:bg-muted/60')"
                                        @click="wizard.step = s.id">
                                    <span class="font-mono" x-text="s.id"></span>
                                    <span x-text="s.title"></span>
                                </button>
                            </template>
                        </div>
                        <template x-for="s in wizardSteps()" :key="s.id">
                            <div x-show="wizard.step === s.id" class="space-y-3">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground" x-text="'Step ' + s.id + ' of 7'"></p>
                                <p class="text-[15px] sm:text-[16px] font-semibold leading-snug" x-text="s.headline"></p>
                                <p class="text-[13.5px] leading-relaxed text-foreground/90" x-text="s.narrative"></p>
                                <div class="flex flex-wrap gap-1.5" x-show="s.highlights && s.highlights.length">
                                    <template x-for="(h, hi) in (s.highlights || [])" :key="hi">
                                        <span class="inline-flex items-center gap-1.5 rounded-md border bg-card px-2 py-1 text-[11.5px]"
                                              :class="h.tone === 'critical' ? 'border-critical/40 text-critical' : (h.tone === 'warning' ? 'border-warning/40 text-warning' : (h.tone === 'success' ? 'border-success/40 text-success' : (h.tone === 'info' ? 'border-info/40 text-info' : 'border-border/60 text-foreground/80')))">
                                            <span class="text-[10px] uppercase tracking-wider opacity-70" x-text="h.label"></span>
                                            <span class="font-semibold" x-text="h.value"></span>
                                        </span>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <div class="flex items-center justify-between mt-4 pt-3 border-t border-border/60">
                            <button class="btn btn-outline btn-sm" :disabled="wizard.step <= 1" @click="wizard.step = Math.max(1, wizard.step - 1)">‹ Prev</button>
                            <span class="text-[11.5px] font-mono text-muted-foreground" x-text="wizard.step + ' / 7'"></span>
                            <button class="btn btn-brand btn-sm" :disabled="wizard.step >= 7" @click="wizard.step = Math.min(7, wizard.step + 1)">Next ›</button>
                        </div>
                    </div>
                    <button x-show="!wizard.open" class="btn btn-soft-brand btn-xs w-full" @click="wizard.open = true; wizard.step = 1">Re-open the case walkthrough</button>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                        <div class="rpt-tile p-3" :class="'rpt-tile-' + (drill.data?.alert?.ack_minutes && drill.data.alert.ack_minutes > 60 ? 'warning' : 'success')">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Ack Time</p>
                            <p class="text-xl font-bold mt-0.5" x-text="formatMinutes(drill.data?.alert?.ack_minutes)"></p>
                        </div>
                        <div class="rpt-tile p-3" :class="'rpt-tile-' + (drill.data?.alert?.sla_breached ? 'critical' : (drill.data?.alert?.res_minutes ? 'success' : 'info'))">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Resolution</p>
                            <p class="text-xl font-bold mt-0.5" x-text="formatMinutes(drill.data?.alert?.res_minutes)"></p>
                        </div>
                        <div class="rpt-tile p-3" :class="'rpt-tile-' + (drill.data?.alert?.sla_breached ? 'critical' : 'success')">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">SLA</p>
                            <p class="text-xl font-bold mt-0.5" x-text="formatMinutes(drill.data?.alert?.minutes_open ?? (drill.data?.alert?.hours_open * 60)) + ' / ' + (drill.data?.alert?.sla_hours ?? '?') + 'h'"></p>
                            <p class="text-[10.5px] mt-0.5" x-text="drill.data?.alert?.sla_breached ? 'Breached' : 'Within SLA'"></p>
                        </div>
                        <div class="rpt-tile p-3" :class="'rpt-tile-' + riskTone(drill.data?.alert?.risk_level)">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Risk</p>
                            <p class="text-xl font-bold mt-0.5" x-text="drill.data?.alert?.risk_level"></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        <details class="rpt-section" open>
                            <summary class="rpt-section-head"><span>SLA Progress</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                            <div class="rpt-section-body space-y-3">
                                <div class="rpt-sla-bar"><div class="rpt-sla-fill" :class="drill.data?.alert?.sla_breached ? 'bg-critical' : 'bg-warning'" :style="'width:' + Math.min(100, ((drill.data?.alert?.hours_open ?? 0) / Math.max(1, drill.data?.alert?.sla_hours ?? 24)) * 100) + '%'"></div></div>
                                <p class="text-[12px] text-muted-foreground"><span class="font-mono" x-text="formatMinutes(drill.data?.alert?.minutes_open ?? (drill.data?.alert?.hours_open * 60))"></span> of the <span class="font-mono" x-text="(drill.data?.alert?.sla_hours ?? '?') + 'h'"></span> SLA window.</p>
                                <p class="text-[11px] text-muted-foreground">SLA tiers: CRITICAL 4h · HIGH 24h · MEDIUM/LOW 48h.</p>
                            </div>
                        </details>
                        <details class="rpt-section" open>
                            <summary class="rpt-section-head"><span>Follow-up Status</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                            <div class="rpt-section-body"><div class="relative h-[180px]"><canvas x-ref="d_followup"></canvas></div></div>
                        </details>
                    </div>

                    <details class="rpt-section" open>
                        <summary class="rpt-section-head"><span>Lifecycle Timestamps (technical)</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 gap-3 text-[13px]">
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Code</p><p class="font-mono text-[11.5px]" x-text="drill.data?.alert?.code || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">POE</p><p x-text="drill.data?.alert?.poe_code || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Reopens</p><p x-text="drill.data?.alert?.reopen_count ?? 0"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Created</p><p x-text="drill.data?.alert?.created_at ? new Date(drill.data.alert.created_at).toLocaleString() : '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Acknowledged</p><p x-text="drill.data?.alert?.acknowledged_at ? new Date(drill.data.alert.acknowledged_at).toLocaleString() : 'Not yet'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Closed</p><p x-text="drill.data?.alert?.closed_at ? new Date(drill.data.alert.closed_at).toLocaleString() : 'Open'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Closure Reason</p><p x-text="drill.data?.alert?.close_category || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Ack Minutes</p><p class="font-mono" x-text="drill.data?.alert?.ack_minutes ?? '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Resolution Minutes</p><p class="font-mono" x-text="drill.data?.alert?.res_minutes ?? '—'"></p></div>
                        </div>
                    </details>

                    <details class="rpt-section" x-show="drill.data?.owner">
                        <summary class="rpt-section-head"><span>Acknowledged By</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 gap-3 text-[13px]">
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Name</p><p class="font-medium" x-text="drill.data?.owner?.full_name || drill.data?.owner?.username || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Username</p><p class="font-mono text-[11.5px]" x-text="drill.data?.owner?.username || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Role</p><p x-text="drill.data?.owner?.role_key || '—'"></p></div>
                        </div>
                    </details>

                    <details class="rpt-section" x-show="drill.data?.traveller">
                        <summary class="rpt-section-head"><span>Traveller Snapshot</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 gap-3 text-[13px]">
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Name</p><p x-text="drill.data?.traveller?.name || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Gender</p><p x-text="drill.data?.traveller?.gender || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Age</p><p x-text="drill.data?.traveller?.age ?? '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Risk</p><p x-text="drill.data?.traveller?.risk_level || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Triage</p><p x-text="drill.data?.traveller?.triage || '—'"></p></div>
                        </div>
                    </details>

                    <details class="rpt-section">
                        <summary class="rpt-section-head"><span>Follow-ups <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.followups?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body space-y-1.5">
                            <template x-for="(f, i) in (drill.data?.followups ?? [])" :key="i">
                                <div class="flex items-center justify-between border-t border-border/40 pt-2 first:border-t-0 first:pt-0">
                                    <div class="min-w-0">
                                        <p class="text-[13px] font-medium truncate"><span x-show="f.blocks_closure" class="badge badge-warning text-[9px] mr-1">BLOCKS</span><span x-text="f.action_label"></span></p>
                                        <p class="text-[11px] text-muted-foreground" x-text="f.due_at ? ('Due ' + new Date(f.due_at).toLocaleString()) : 'No due date'"></p>
                                    </div>
                                    <span class="badge" :class="badgeForFollowup(f.status)" x-text="f.status"></span>
                                </div>
                            </template>
                            <p x-show="!(drill.data?.followups?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No follow-up tasks recorded.</p>
                        </div>
                    </details>

                    <details class="rpt-section">
                        <summary class="rpt-section-head"><span>Audit Timeline <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.timeline?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body space-y-2">
                            <template x-for="(t, i) in (drill.data?.timeline ?? [])" :key="i">
                                <div class="border-l-2 pl-3" :class="t.severity === 'CRITICAL' ? 'border-critical' : (t.severity === 'WARN' ? 'border-warning' : 'border-brand/40')">
                                    <p class="text-[12px] font-semibold" x-text="t.event_code"></p>
                                    <p class="text-[11.5px] text-muted-foreground" x-text="t.summary || '—'"></p>
                                    <p class="text-[10.5px] text-muted-foreground/80" x-text="(t.actor_name || 'system') + ' · ' + (t.event_category || '') + ' · ' + new Date(t.created_at).toLocaleString()"></p>
                                </div>
                            </template>
                            <p x-show="!(drill.data?.timeline?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No timeline events recorded.</p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </template>

    {{-- EXPLAINER MODAL --}}
    <template x-teleport="body">
        <div x-show="explainer.open" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" @keydown.escape.window="explainer.open = false">
            <div class="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm" @click="explainer.open = false"></div>
            <div class="relative z-50 w-full sm:w-[96vw] sm:max-w-[1400px] h-[94vh] sm:max-h-[94vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl border border-border bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-border/60 bg-white/95 backdrop-blur px-5 py-3.5">
                    <div class="min-w-0"><p class="text-[11px] font-semibold uppercase tracking-[.14em] text-info">About this chart</p><h2 class="text-[17px] font-bold tracking-tight truncate" x-text="explainer.spec?.title || ''"></h2></div>
                    <div class="flex items-center gap-1.5">
                        <button class="btn btn-outline btn-xs gap-1.5" @click="exportChart(explainer.key, 'csv')" x-show="explainer.key"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 17H5a2 2 0 01-2-2V5a2 2 0 012-2h10l4 4v3M14 3v4h4M9 13h6m-6 4h4"/></svg> Export CSV</button>
                        <button class="btn btn-ghost btn-icon-xs" @click="explainer.open = false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                    </div>
                </div>
                <div class="p-5 grid grid-cols-1 lg:grid-cols-5 gap-5">
                    <div class="lg:col-span-2 space-y-4 text-[13.5px] leading-relaxed">
                        <div><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">What it shows</p><p x-text="explainer.spec?.what"></p></div>
                        <div><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">How to read it</p><p x-text="explainer.spec?.how"></p></div>
                        <div><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Decisions it supports</p><ul class="list-disc pl-5 space-y-1"><template x-for="d in (explainer.spec?.decisions || [])" :key="d"><li x-text="d"></li></template></ul></div>
                        <div><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Data source</p><p class="font-mono text-[12px] text-muted-foreground" x-text="explainer.spec?.source"></p></div>
                        <div x-show="explainer.spec?.caveats" class="rounded-md border border-warning/30 bg-warning-soft/40 px-3 py-2"><p class="text-[10px] font-semibold uppercase tracking-wider text-warning">Caveats</p><p x-text="explainer.spec?.caveats"></p></div>
                    </div>
                    <div class="lg:col-span-3">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Underlying data</p>
                            <span class="text-[11px] font-mono text-muted-foreground" x-text="(explainerRows().length || 0).toLocaleString() + ' rows'"></span>
                        </div>
                        <div class="rounded-lg border border-border bg-white overflow-hidden">
                            <div class="max-h-[72vh] overflow-y-auto">
                                <table class="w-full text-[13px]">
                                    <thead class="bg-muted/50 sticky top-0"><tr><template x-for="(h, i) in explainerHeaders()" :key="i"><th class="text-left px-3 py-2 text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground border-b border-border" x-text="h"></th></template></tr></thead>
                                    <tbody>
                                        <template x-for="(row, ri) in explainerRows()" :key="ri">
                                            <tr class="border-b border-border/40 hover:bg-muted/30">
                                                <template x-for="(cell, ci) in row" :key="ci">
                                                    <td class="px-3 py-2" :class="ci === 0 ? 'font-medium' : 'text-right font-mono'" x-text="cell === null || cell === undefined ? '—' : (typeof cell === 'number' ? cell.toLocaleString() : cell)"></td>
                                                </template>
                                            </tr>
                                        </template>
                                        <tr x-show="!explainerRows().length"><td :colspan="explainerHeaders().length || 1" class="px-3 py-12 text-center text-muted-foreground">No rows in the current window.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function rptResponseTime() {
    return {
        tab: 'charts', // 'charts' | 'records'
        filters: { poe: '', start_date: '', end_date: '' },
        meta: {}, kpis: [], rows: [],
        pagination: { page: 1, per_page: 10, total: 0, total_pages: 1, from: 0, to: 0 },
        controls: { sort: 'created_at', dir: 'desc', q: '', cat: 'all' },
        categoryCounts: {},
        search: '', cat: 'all', window_label: '',
        loading: false, filtersOpen: false,
        chartObjs: { ack: null, median: null, dFollowup: null },
        chartData: { ack: null, median: null },
        drill: { open: false, alertId: null, data: null },
        wizard: { open: true, step: 1 },
        explainer: { open: false, key: null, spec: null },
        explainerSpecs: {
            ack: {
                title: 'Acknowledgement Time Distribution',
                what: 'Histogram of how long alerts wait before someone clicks "acknowledge". Buckets: ≤30 min · 30–60 min · 1–4 h · 4–24 h · >24 h.',
                how: 'Tall bars on the left = fast response culture. Tall bars on the right = officers ignoring alerts or pager fatigue.',
                decisions: ['Identify a slow-response baseline.', 'Spot pager-fatigue or rota issues.', 'Set realistic SLA targets from real distribution.'],
                source: 'alerts WHERE acknowledged_at IS NOT NULL, bucketed via TIMESTAMPDIFF on (created_at → acknowledged_at).',
                caveats: 'Excludes alerts never acknowledged — those are "Pending" in the table chip filter.',
            },
            median: {
                title: 'Median Resolution by POE',
                what: 'Top 10 POEs ranked by mean hours to close. Each row shows the median and mean closing time, plus the count of closed alerts.',
                how: 'Look at the difference between median and mean — if mean is much higher, a few outliers are dragging the average. Long bars are bottleneck POEs.',
                decisions: ['Target slow POEs for SLA coaching.', 'Investigate root causes of outlier alerts.', 'Set per-POE response benchmarks.'],
                source: 'alerts WHERE closed_at IS NOT NULL GROUP BY poe_code; median via SUBSTRING_INDEX(GROUP_CONCAT) trick (MySQL has no MEDIAN()).',
                caveats: 'Median trick is approximate at very large N — within 1 hour for most workloads. POEs with <2 closed alerts may show median = max.',
            },
        },

        async boot() { await this.loadMeta(); await this.apply(); },
        async loadMeta() { const r = await fetch('{{ url('/admin/reports/rpt-response-time/meta') }}'); const j = await r.json(); this.meta = j.data || {}; },
        async apply() { this.loading = true; await Promise.all([this.loadKpis(), this.loadChart('ack_time_distribution', 'ack'), this.loadChart('median_resolution_by_poe', 'median'), this.loadRecords(1)]); this.loading = false; },
        resetFilters() { this.filters = { poe: '', start_date: '', end_date: '' }; this.search = ''; this.cat = 'all'; this.apply(); },
        qs() { const p = new URLSearchParams(); for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v); return p.toString(); },
        async loadKpis() { const r = await fetch('{{ url('/admin/reports/rpt-response-time/kpis') }}?' + this.qs()); const j = await r.json(); this.kpis = j.data?.kpis || []; this.window_label = j.data?.window?.label || ''; },
        async loadChart(key, target) { const r = await fetch('{{ url('/admin/reports/rpt-response-time/chart') }}/' + key + '?' + this.qs()); const j = await r.json(); this.chartData[target] = j.data; this.renderChart(target, j.data); },

        renderChart(target, payload) {
            if (!payload || !this.$refs['chart_' + target]) return;
            if (this.chartObjs[target]) this.chartObjs[target].destroy();
            const isHistogram = target === 'ack';
            const palette = isHistogram ? ['#10b981', '#22c55e', '#f59e0b', '#ef4444', '#7f1d1d'] : ['#3b82f6', '#8b5cf6'];
            const datasets = (payload.datasets || []).map((d, i) => ({
                label: d.label, data: d.data,
                backgroundColor: isHistogram ? palette.map(c => c + 'cc') : palette[i % 2] + 'cc',
                borderColor: isHistogram ? palette : palette[i % 2],
                borderWidth: 0, borderRadius: 6, borderSkipped: false,
            }));
            this.chartObjs[target] = new Chart(this.$refs['chart_' + target].getContext('2d'), {
                type: 'bar',
                data: { labels: payload.labels, datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: target === 'median' ? 'y' : 'x',
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: target === 'median', position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } }, tooltip: { backgroundColor: 'rgba(15,23,42,.95)', padding: 10, cornerRadius: 8 } },
                    scales: {
                        x: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } },
                        y: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 }, autoSkip: false } },
                    },
                },
            });
        },

        async loadRecords(page) {
            this.loading = true;
            const p = new URLSearchParams({ page, q: this.search, sort: this.controls.sort, dir: this.controls.dir, cat: this.cat });
            for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v);
            const r = await fetch('{{ url('/admin/reports/rpt-response-time/records') }}?' + p.toString());
            const j = await r.json();
            this.rows = j.data?.rows || [];
            this.pagination = j.data?.pagination || this.pagination;
            this.controls = j.data?.controls || this.controls;
            this.categoryCounts = j.data?.category_counts || {};
            this.loading = false;
        },
        setCat(c) { this.cat = c; this.loadRecords(1); },
        toggleSort(col) { if (this.controls.sort === col) { this.controls.dir = this.controls.dir === 'asc' ? 'desc' : 'asc'; } else { this.controls.sort = col; this.controls.dir = 'desc'; } this.loadRecords(1); },
        sortIcon(col) { if (this.controls.sort !== col) return '<svg class="h-3 w-3 text-muted-foreground/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 10l5-5 5 5M7 14l5 5 5-5"/></svg>'; return this.controls.dir === 'asc' ? '<svg class="h-3 w-3 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 14l5-5 5 5"/></svg>' : '<svg class="h-3 w-3 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 10l5 5 5-5"/></svg>'; },
        pageCluster() { const p = this.pagination.page, total = this.pagination.total_pages; const out = []; const start = Math.max(1, p - 2), end = Math.min(total, p + 2); for (let i = start; i <= end; i++) out.push(i); return out; },

        exportChart(target, fmt) {
            if (fmt === 'png') { const c = this.chartObjs[target]; if (!c) return; const a = document.createElement('a'); a.href = c.toBase64Image('image/png', 1); a.download = 'rpt-response-time__' + target + '__' + this.stamp() + '.png'; a.click(); }
            else { const map = { ack: 'ack_time_distribution', median: 'median_resolution_by_poe' }; window.location.href = '{{ url('/admin/reports/rpt-response-time/chart') }}/' + map[target] + '/csv?' + this.qs(); }
        },
        stamp() { const d = new Date(); const p = n => String(n).padStart(2, '0'); return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()); },

        openExplainer(key) { this.explainer.open = true; this.explainer.key = key; this.explainer.spec = this.explainerSpecs[key] || null; },
        explainerHeaders() { const k = this.explainer.key; return (k && this.chartData[k]?.csv_headers) || []; },
        explainerRows()    { const k = this.explainer.key; return (k && this.chartData[k]?.csv_rows)    || []; },

        async openDrill(id) {
            this.drill.open = true; this.drill.alertId = id; this.drill.data = null;
            this.wizard = { open: true, step: 1 };
            const r = await fetch('{{ url('/admin/reports/rpt-response-time/records') }}/' + id);
            if (!r.ok) { this.drill.data = { error: r.status }; return; }
            const j = await r.json(); this.drill.data = j.data;
            this.$nextTick(() => this.renderDrillCharts());
        },

        /* ════════ Smart-wizard narrative (deterministic, R4-flavoured) ════════ */
        wizardSteps() {
            return [
                { id: 1, title: 'Snapshot',         ...this.wizardSnapshot() },
                { id: 2, title: 'Trigger',          ...this.wizardTrigger() },
                { id: 3, title: 'Routing',          ...this.wizardRouting() },
                { id: 4, title: 'Response Speed',   ...this.wizardResponse() },
                { id: 5, title: 'Clinical Picture', ...this.wizardClinical() },
                { id: 6, title: 'Actions Taken',    ...this.wizardActions() },
                { id: 7, title: 'Resolution',       ...this.wizardResolution() },
            ];
        },
        _fmtDate(d) { return d ? new Date(d).toLocaleString() : 'not yet recorded'; },

        wizardSnapshot() {
            const a = this.drill.data?.alert || {};
            const code = a.code || ('#' + (a.alertId ?? this.drill.alertId ?? '?'));
            const risk = a.risk_level || 'UNRATED';
            const status = a.status || 'UNKNOWN';
            const open = a.hours_open ?? 0;
            const sla = a.sla_hours ?? '?';
            return {
                headline: `Alert ${code} — ${risk} risk, currently ${status.toLowerCase()}.`,
                narrative: `Created on ${this._fmtDate(a.created_at)} at POE “${a.poe_code || 'unknown'}”. ${open}h have elapsed against a ${sla}h SLA target — ${a.sla_breached ? 'SLA breached.' : 'still within SLA.'} ${a.reopen_count > 0 ? 'Reopened ' + a.reopen_count + ' time(s).' : ''}`,
                highlights: [
                    { label: 'Risk',   value: risk,   tone: this.riskTone(risk) === 'critical' ? 'critical' : (this.riskTone(risk) === 'danger' ? 'critical' : 'info') },
                    { label: 'Status', value: status, tone: status === 'CLOSED' ? 'success' : 'warning' },
                    { label: 'SLA',    value: open + 'h / ' + sla + 'h', tone: a.sla_breached ? 'critical' : 'success' },
                ],
            };
        },
        wizardTrigger() {
            const t = this.drill.data?.traveller;
            const a = this.drill.data?.alert || {};
            if (!t) {
                return {
                    headline: 'No linked traveller record on file.',
                    narrative: `The alert was raised at POE “${a.poe_code || 'unknown'}” but no secondary screening is attached, so traveller demographics are not available.`,
                    highlights: [],
                };
            }
            return {
                headline: `Triggered by secondary screening of ${t.name || 'an anonymous traveller'}.`,
                narrative: `${(t.age ? t.age + '-year-old ' : '') + (t.gender ? t.gender.toLowerCase() : 'traveller')}; clinician marked the case ${(t.risk_level || 'UNRATED').toLowerCase()} risk and triage ${t.triage || 'unclassified'}.`,
                highlights: [
                    { label: 'Triage',      value: t.triage || '—', tone: t.triage === 'EMERGENCY' ? 'critical' : (t.triage === 'URGENT' ? 'warning' : 'success') },
                    { label: 'Risk (sec.)', value: t.risk_level || '—', tone: (t.risk_level === 'CRITICAL' || t.risk_level === 'HIGH') ? 'critical' : 'info' },
                ],
            };
        },
        wizardRouting() {
            const a = this.drill.data?.alert || {};
            const o = this.drill.data?.owner;
            return {
                headline: `${a.acknowledged_at ? 'Acknowledged' : 'Awaiting acknowledgement'}${o ? ' by ' + (o.full_name || o.username) : ''}.`,
                narrative: `${a.acknowledged_at ? 'Acknowledged on ' + this._fmtDate(a.acknowledged_at) + (a.ack_minutes ? ' (' + this.formatMinutes(a.ack_minutes) + ' after creation)' : '') + '. ' : 'No officer has acknowledged this alert. '}${o ? 'Responsible officer is ' + (o.full_name || o.username) + ' (' + (o.role_key || 'unknown role') + ').' : 'No owner is on file.'}`,
                highlights: [
                    { label: 'Owner',        value: o ? (o.full_name || o.username) : 'Unassigned', tone: o ? 'success' : 'warning' },
                    { label: 'Acked',        value: a.acknowledged_at ? this.formatMinutes(a.ack_minutes) : 'pending', tone: a.acknowledged_at ? (a.ack_minutes > 60 ? 'warning' : 'success') : 'critical' },
                ],
            };
        },
        wizardResponse() {
            const a = this.drill.data?.alert || {};
            const sla = a.sla_hours ?? 24;
            const open = a.hours_open ?? 0;
            const pct = sla > 0 ? Math.round((open / sla) * 100) : 0;
            const verdict = a.sla_breached ? 'SLA has been breached — this needs immediate attention.' : (pct >= 75 ? 'SLA is close to breach — push for closure.' : 'SLA is comfortably within target.');
            return {
                headline: `${pct}% of the SLA window has been consumed.`,
                narrative: `Risk tier ${a.risk_level || 'UNRATED'} carries a ${sla}h SLA. ${open}h have elapsed since this alert opened. ${verdict} Acknowledgement took ${this.formatMinutes(a.ack_minutes)}; resolution ${a.res_minutes ? 'took ' + this.formatMinutes(a.res_minutes) : 'is still pending'}.`,
                highlights: [
                    { label: 'Ack Time', value: this.formatMinutes(a.ack_minutes), tone: a.ack_minutes !== null && a.ack_minutes > 60 ? 'warning' : 'success' },
                    { label: 'Resolution', value: a.res_minutes ? this.formatMinutes(a.res_minutes) : 'Open', tone: a.sla_breached ? 'critical' : (a.res_minutes ? 'success' : 'warning') },
                    { label: 'SLA Used', value: pct + '%', tone: a.sla_breached ? 'critical' : (pct >= 75 ? 'warning' : 'success') },
                ],
            };
        },
        wizardClinical() {
            const t = this.drill.data?.traveller || {};
            return {
                headline: t.triage ? `Clinical priority: ${t.triage.toLowerCase()}.` : 'No clinical detail on file.',
                narrative: `${t.triage ? 'Triage classified the case as ' + t.triage + '. ' : 'Triage was not recorded. '}${t.risk_level ? 'Clinician risk rating: ' + t.risk_level + '. ' : ''}${(t.age || t.gender) ? 'Demographic: ' + (t.age || '?') + 'yo ' + (t.gender || '').toLowerCase() + '. ' : ''}For deeper clinical detail (vitals, suspected diseases, symptoms) open the alert in R3 (Alert Intelligence).`,
                highlights: t.triage ? [
                    { label: 'Triage',      value: t.triage, tone: t.triage === 'EMERGENCY' ? 'critical' : (t.triage === 'URGENT' ? 'warning' : 'success') },
                    { label: 'Risk',        value: t.risk_level || '—', tone: 'info' },
                ] : [],
            };
        },
        wizardActions() {
            const fa = this.drill.data?.followup_agg || {};
            const total = fa.total ?? 0;
            const done = fa.completed ?? 0;
            return {
                headline: `${done} of ${total} follow-up tasks completed.`,
                narrative: `${total === 0 ? 'No follow-up tasks have been recorded for this alert. ' : (done + ' completed, ' + (fa.pending ?? 0) + ' pending, ' + (fa.blocked ?? 0) + ' blocked. ')}${(this.drill.data?.timeline || []).length} timeline event(s) on the audit log. Use the technical sections below to inspect each follow-up and event.`,
                highlights: [
                    { label: 'Done',     value: done + ' / ' + total, tone: total > 0 && done === total ? 'success' : 'warning' },
                    { label: 'Pending',  value: fa.pending ?? 0, tone: (fa.pending ?? 0) > 0 ? 'warning' : 'success' },
                    { label: 'Blocked',  value: fa.blocked ?? 0, tone: (fa.blocked ?? 0) > 0 ? 'critical' : 'success' },
                ],
            };
        },
        wizardResolution() {
            const a = this.drill.data?.alert || {};
            return {
                headline: a.status === 'CLOSED' ? `Closed${a.close_category ? ' as ' + a.close_category : ''}.` : 'Alert is still open.',
                narrative: `${a.status === 'CLOSED' ? 'Closed on ' + this._fmtDate(a.closed_at) + (a.close_category ? ' with reason ' + a.close_category + '.' : '.') : 'No closure timestamp on file.'} ${a.reopen_count > 0 ? 'Reopened ' + a.reopen_count + ' time(s) — review the timeline for the reasons. ' : ''}${a.sla_breached && a.status !== 'CLOSED' ? 'This open alert has breached its SLA. ' : ''}`,
                highlights: [
                    { label: 'Final Status', value: a.status || '—', tone: a.status === 'CLOSED' ? 'success' : 'warning' },
                    { label: 'Closure',      value: a.close_category || (a.status === 'CLOSED' ? '—' : 'Pending'), tone: a.close_category === 'FALSE_POSITIVE' ? 'info' : (a.close_category ? 'success' : 'warning') },
                    { label: 'Reopens',      value: a.reopen_count ?? 0, tone: (a.reopen_count > 0) ? 'warning' : 'info' },
                ],
            };
        },
        renderDrillCharts() {
            const a = this.drill.data?.followup_agg || {};
            const ref = this.$refs.d_followup; if (!ref) return;
            if (this.chartObjs.dFollowup) this.chartObjs.dFollowup.destroy();
            this.chartObjs.dFollowup = new Chart(ref.getContext('2d'), {
                type: 'doughnut',
                data: { labels: ['Completed', 'Pending', 'Blocked', 'Other'], datasets: [{ data: [a.completed || 0, a.pending || 0, a.blocked || 0, Math.max(0, (a.total || 0) - ((a.completed || 0) + (a.pending || 0) + (a.blocked || 0)))], backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#94a3b8'], borderWidth: 0 }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 10 } } } },
            });
        },

        formatMinutes(m) { if (m === null || m === undefined) return '—'; if (m < 60) return Math.round(m) + ' min'; if (m < 1440) return (Math.round((m / 60) * 10) / 10) + ' h'; return (Math.round((m / 1440) * 10) / 10) + ' d'; },
        badgeForRisk(r) { return { LOW: 'badge-low', MEDIUM: 'badge-medium', HIGH: 'badge-high', CRITICAL: 'badge-critical' }[r] || 'badge-secondary'; },
        badgeForStatus(s) { return { OPEN: 'badge-warning', ACKNOWLEDGED: 'badge-info', CLOSED: 'badge-success' }[s] || 'badge-outline'; },
        badgeForFollowup(s) { return { COMPLETED: 'badge-success', PENDING: 'badge-warning', IN_PROGRESS: 'badge-info', BLOCKED: 'badge-critical', NOT_APPLICABLE: 'badge-secondary' }[s] || 'badge-outline'; },
        riskTone(r) { return { LOW: 'success', MEDIUM: 'warning', HIGH: 'danger', CRITICAL: 'critical' }[r] || 'neutral'; },
    };
}
</script>
@endsection
