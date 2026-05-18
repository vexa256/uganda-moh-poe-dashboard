@extends('admin.layout')

@section('crumb', 'Reports')
@section('title', 'Operational Risk')

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
    .rpt-icon-btn { @apply inline-flex items-center justify-center h-7 w-7 rounded-md text-muted-foreground hover:bg-accent hover:text-accent-foreground transition-colors; }
    .rpt-chip { @apply inline-flex items-center gap-1.5 h-7 rounded-full border px-2.5 text-[11.5px] font-medium transition-colors cursor-pointer; }
    .rpt-chip-active { @apply bg-brand-soft border-brand/40 text-brand-ink; }
    .rpt-chip-idle   { @apply bg-card border-border/60 text-muted-foreground hover:bg-muted/60; }
    .rpt-th-sort { @apply inline-flex items-center gap-1 cursor-pointer select-none; }
    .rpt-th-sort:hover { @apply text-foreground; }
    .rpt-section { @apply rounded-lg border bg-card; }
    .rpt-section-head { @apply flex items-center justify-between px-4 py-3 cursor-pointer text-[13px] font-semibold; }
    .rpt-section-body { @apply px-4 pb-4; }
    .rpt-skel { @apply animate-pulse bg-muted/60 rounded; }
    .rpt-sla-bar { @apply relative h-2 overflow-hidden rounded-full bg-muted; }
    .rpt-sla-fill { @apply absolute inset-y-0 left-0 rounded-full transition-[width]; }
</style>
@endpush

@section('content')
<div x-data="rptOpsRisk()" x-init="boot()" class="space-y-4">

    {{-- ────────── HEADER ────────── --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-danger/80">Executive Overview · R11</p>
            <h1 class="text-[22px] font-bold tracking-tight">Operational Risk</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Where leadership should look this week — overdue alerts, dark POEs, inactive officers.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button class="btn btn-outline btn-xs" @click="filtersOpen = !filtersOpen" x-text="filtersOpen ? 'Hide Filters' : 'Show Filters'"></button>
        </div>
    </section>

    {{-- ────────── FILTERS (collapsed) ────────── --}}
    <section class="card" x-show="filtersOpen" x-cloak>
        <div class="card-content py-4">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Point of Entry</label>
                    <select class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm" x-model="filters.poe">
                        <option value="">All POEs</option>
                        <template x-for="(name, code) in (meta.poes || {})" :key="code"><option :value="code" x-text="name"></option></template>
                    </select>
                </div>
                <div class="sm:col-span-2 flex items-end gap-2">
                    <button class="btn btn-brand btn-md flex-1" @click="apply()">Apply</button>
                    <button class="btn btn-outline btn-md" @click="resetFilters()">Reset</button>
                </div>
            </div>
        </div>
    </section>

    {{-- ────────── KPI ROW ────────── --}}
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

    {{-- ────────── CHARTS · 2 col-6 ────────── --}}
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @foreach ([
            ['target' => 'aging', 'serverKey' => 'open_alert_aging', 'title' => 'Open Alert Aging', 'subtitle' => 'How long open and acknowledged alerts have been waiting.'],
            ['target' => 'risk',  'serverKey' => 'alerts_by_risk_30d', 'title' => 'Alert Volume by Risk · 30 days', 'subtitle' => 'Daily alerts by risk level over the last 30 days.'],
        ] as $c)
        <div class="card overflow-hidden">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-4 border-b border-border/60">
                <div class="min-w-0">
                    <h3 class="text-[14px] font-semibold tracking-tight">{{ $c['title'] }}</h3>
                    <p class="text-[12px] text-muted-foreground">{{ $c['subtitle'] }}</p>
                </div>
                <div class="flex items-center gap-1.5 flex-wrap">
                    <button class="btn btn-soft-info btn-xs gap-1.5" @click="openExplainer('{{ $c['target'] }}')" title="What this chart shows + underlying data table">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                        Explain
                    </button>
                    <button class="btn btn-outline btn-xs gap-1.5" @click="exportChart('{{ $c['target'] }}', 'png')">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
                        PNG
                    </button>
                    <button class="btn btn-outline btn-xs gap-1.5" @click="exportChart('{{ $c['target'] }}', 'csv')">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 17H5a2 2 0 01-2-2V5a2 2 0 012-2h10l4 4v3M14 3v4h4M9 13h6m-6 4h4"/></svg>
                        CSV
                    </button>
                </div>
            </div>
            <div class="card-content py-4"><div class="relative h-[280px]"><canvas x-ref="chart_{{ $c['target'] }}"></canvas></div></div>
        </div>
        @endforeach
    </section>

    {{-- ────────── PREMIUM TABLE · Risk Feed ────────── --}}
    <section class="card overflow-hidden">
        <div class="flex flex-col gap-3 p-4 border-b border-border/60">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-[14px] font-semibold tracking-tight">Risk Feed</h3>
                    <p class="text-[12px] text-muted-foreground">Sorted by severity. Click any row for full context.</p>
                </div>
                <span class="text-[11px] font-mono text-muted-foreground whitespace-nowrap" x-text="(pagination.total ?? 0).toLocaleString() + ' items'"></span>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                <div class="relative flex-1 max-w-md">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="search" placeholder="Search title, POE, or detail..." class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-sm" x-model.debounce.300ms="search" @input="loadRecords(1)">
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="rpt-chip" :class="cat === 'all' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('all')">All <span class="font-mono opacity-70" x-text="categoryCounts.all || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'open_alert' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('open_alert')">Overdue Alerts <span class="font-mono opacity-70" x-text="categoryCounts.open_alert || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'dark_poe' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('dark_poe')">Dark POEs <span class="font-mono opacity-70" x-text="categoryCounts.dark_poe || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'inactive_user' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('inactive_user')">Inactive Officers <span class="font-mono opacity-70" x-text="categoryCounts.inactive_user || 0"></span></span>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead class="table-head sticky top-0">
                    <tr>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('title')">Issue <span x-html="sortIcon('title')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('type_label')">Type <span x-html="sortIcon('type_label')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('severity')">Severity <span x-html="sortIcon('severity')"></span></span></th>
                        <th class="table-head-th">Where</th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('detected_at')">Detected <span x-html="sortIcon('detected_at')"></span></span></th>
                    </tr>
                </thead>
                <tbody class="table-body">
                    <template x-if="loading">
                        <template x-for="i in 10" :key="i">
                            <tr class="border-b border-border/40">
                                <td class="table-cell"><div class="rpt-skel h-4 w-56"></div><div class="rpt-skel h-3 w-40 mt-1.5"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-5 w-24"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-5 w-16"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-32"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-32"></div></td>
                            </tr>
                        </template>
                    </template>
                    <template x-if="!loading">
                        <template x-for="r in rows" :key="r.type + ':' + r.key">
                            <tr class="table-row cursor-pointer" @click="openDrill(r)">
                                <td class="table-cell"><div class="font-medium" x-text="r.title"></div><div class="text-[11px] text-muted-foreground" x-text="r.detail"></div></td>
                                <td class="table-cell"><span class="badge badge-outline" x-text="r.type_label"></span></td>
                                <td class="table-cell"><span class="badge" :class="badgeForRisk(r.severity)" x-text="r.severity"></span></td>
                                <td class="table-cell text-[12px]" x-text="r.where || '—'"></td>
                                <td class="table-cell text-[12px] text-muted-foreground" x-text="r.detected_at ? new Date(r.detected_at).toLocaleString() : '—'"></td>
                            </tr>
                        </template>
                    </template>
                    <tr x-show="!loading && !rows.length"><td colspan="5" class="table-cell py-12 text-center text-muted-foreground">Nothing matches the current filter — operations look clean.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 p-4 border-t border-border/60">
            <span class="text-[12px] text-muted-foreground">
                Showing <span class="font-mono" x-text="(pagination.from ?? 0).toLocaleString()"></span>–<span class="font-mono" x-text="(pagination.to ?? 0).toLocaleString()"></span>
                of <span class="font-mono" x-text="(pagination.total ?? 0).toLocaleString()"></span>
            </span>
            <div class="flex items-center gap-1">
                <button class="btn btn-outline btn-xs" :disabled="pagination.page <= 1" @click="loadRecords(1)">«</button>
                <button class="btn btn-outline btn-xs" :disabled="pagination.page <= 1" @click="loadRecords(pagination.page - 1)">‹</button>
                <template x-for="p in pageCluster()" :key="p"><button class="btn btn-xs" :class="p === pagination.page ? 'btn-brand' : 'btn-outline'" @click="loadRecords(p)" x-text="p"></button></template>
                <button class="btn btn-outline btn-xs" :disabled="pagination.page >= pagination.total_pages" @click="loadRecords(pagination.page + 1)">›</button>
                <button class="btn btn-outline btn-xs" :disabled="pagination.page >= pagination.total_pages" @click="loadRecords(pagination.total_pages)">»</button>
                <span class="ml-2 hidden sm:inline-flex items-center gap-1 text-[12px] text-muted-foreground">Go to <input type="number" min="1" :max="pagination.total_pages" class="h-8 w-14 rounded-md border border-input bg-background px-2 text-sm font-mono" @keydown.enter="loadRecords(parseInt($event.target.value) || 1); $event.target.value = ''"></span>
            </div>
        </div>
    </section>

    {{-- ────────── DRILL-DOWN MODAL · MASSIVE ────────── --}}
    <template x-teleport="body">
        <div x-show="drill.open" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" @keydown.escape.window="drill.open = false">
            <div class="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm" @click="drill.open = false"></div>
            <div class="relative z-50 w-full sm:w-[96vw] sm:max-w-[1400px] h-[94vh] sm:max-h-[94vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl border border-border bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-border/60 bg-card/95 backdrop-blur px-5 py-3.5">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em]" :class="drillEyebrowTone()" x-text="drill.row?.type_label || 'Risk Item'"></p>
                        <h2 class="text-[17px] font-bold tracking-tight truncate" x-text="drill.row?.title || ''"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="drill.open = false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </div>

                <div class="p-5 space-y-4" x-show="drill.data">
                    {{-- Open alert detail --}}
                    <template x-if="drill.row?.type === 'open_alert'">
                        <div class="space-y-4">
                            {{-- KPI strip --}}
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                                <div class="rpt-tile p-3" :class="'rpt-tile-' + (drill.data?.alert?.sla_breached ? 'critical' : 'warning')">
                                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Open For</p>
                                    <p class="text-xl font-bold mt-0.5" x-text="formatDuration(drill.data?.alert?.minutes_open ?? (drill.data?.alert?.hours_open * 60))"></p>
                                    <p class="text-[10.5px] mt-1" :class="drill.data?.alert?.sla_breached ? 'text-critical font-semibold' : 'text-muted-foreground'" x-text="'SLA target ' + (drill.data?.alert?.sla_hours ?? '?') + 'h' + (drill.data?.alert?.sla_breached ? ' · breached' : '')"></p>
                                </div>
                                <div class="rpt-tile rpt-tile-info p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Status</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.alert?.status"></p></div>
                                <div class="rpt-tile p-3" :class="'rpt-tile-' + riskTone(drill.data?.alert?.risk_level)"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Risk</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.alert?.risk_level"></p></div>
                                <div class="rpt-tile rpt-tile-neutral p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Reopens</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.alert?.reopen_count ?? 0"></p></div>
                            </div>

                            {{-- SLA progress + Followup donut --}}
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                                <details class="rpt-section" open>
                                    <summary class="rpt-section-head"><span>SLA Progress</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                                    <div class="rpt-section-body space-y-3">
                                        <div class="rpt-sla-bar"><div class="rpt-sla-fill" :class="drill.data?.alert?.sla_breached ? 'bg-critical' : 'bg-warning'" :style="'width:' + Math.min(100, ((drill.data?.alert?.hours_open ?? 0) / Math.max(1, drill.data?.alert?.sla_hours ?? 24)) * 100) + '%'"></div></div>
                                        <p class="text-[12px] text-muted-foreground"><span class="font-mono" x-text="formatDuration(drill.data?.alert?.minutes_open ?? (drill.data?.alert?.hours_open * 60))"></span> of the <span class="font-mono" x-text="(drill.data?.alert?.sla_hours ?? 24) + 'h'"></span> SLA window used.</p>
                                        <p class="text-[11px] text-muted-foreground">SLA thresholds: CRITICAL 4h · HIGH 24h · MEDIUM/LOW 48h.</p>
                                    </div>
                                </details>
                                <details class="rpt-section" open>
                                    <summary class="rpt-section-head"><span>Follow-up Status</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                                    <div class="rpt-section-body"><div class="relative h-[160px]"><canvas x-ref="drill_followup"></canvas></div></div>
                                </details>
                            </div>

                            <details class="rpt-section">
                                <summary class="rpt-section-head"><span>Alert Facts</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                                <div class="rpt-section-body grid grid-cols-2 gap-3 text-[13px]">
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Code</p><p class="font-mono text-[11.5px]" x-text="drill.data?.alert?.code || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Routed To</p><p x-text="drill.data?.alert?.routed_to_level || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">POE</p><p x-text="drill.data?.alert?.poe_code || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Created</p><p x-text="drill.data?.alert?.created_at ? new Date(drill.data.alert.created_at).toLocaleString() : '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Acknowledged</p><p x-text="drill.data?.alert?.acknowledged_at ? new Date(drill.data.alert.acknowledged_at).toLocaleString() : 'Not yet'"></p></div>
                                    <div class="col-span-2"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Details</p><p class="text-[12.5px] leading-snug" x-text="drill.data?.alert?.details || '—'"></p></div>
                                </div>
                            </details>

                            <details class="rpt-section" x-show="drill.data?.traveller">
                                <summary class="rpt-section-head"><span>Traveller</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                                <div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 gap-3 text-[13px]">
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Name</p><p x-text="drill.data?.traveller?.name || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Gender</p><p x-text="drill.data?.traveller?.gender || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Age</p><p x-text="drill.data?.traveller?.age ?? '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Nationality</p><p x-text="drill.data?.traveller?.nationality || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Origin</p><p x-text="drill.data?.traveller?.origin || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Disposition</p><p x-text="drill.data?.traveller?.disposition || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Triage</p><p x-text="drill.data?.traveller?.triage || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Temperature</p><p x-text="drill.data?.traveller?.temperature ? (drill.data.traveller.temperature + '°C') : '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">SpO₂</p><p x-text="drill.data?.traveller?.oxygen_sat ? (drill.data.traveller.oxygen_sat + '%') : '—'"></p></div>
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
                                <summary class="rpt-section-head"><span>Timeline <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.timeline?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                                <div class="rpt-section-body space-y-2">
                                    <template x-for="(t, i) in (drill.data?.timeline ?? [])" :key="i">
                                        <div class="border-l-2 border-brand/40 pl-3">
                                            <p class="text-[12px] font-semibold" x-text="t.event_code"></p>
                                            <p class="text-[11.5px] text-muted-foreground" x-text="t.summary || '—'"></p>
                                            <p class="text-[10.5px] text-muted-foreground/80" x-text="(t.actor_name || 'system') + ' · ' + new Date(t.created_at).toLocaleString()"></p>
                                        </div>
                                    </template>
                                    <p x-show="!(drill.data?.timeline?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No timeline events recorded.</p>
                                </div>
                            </details>
                        </div>
                    </template>

                    {{-- Dark POE detail --}}
                    <template x-if="drill.row?.type === 'dark_poe'">
                        <div class="space-y-4">
                            <details class="rpt-section" open>
                                <summary class="rpt-section-head"><span>POE Profile</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                                <div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 gap-3 text-[13px]">
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Type</p><p x-text="drill.data?.poe?.poe_type || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Transport</p><p x-text="drill.data?.poe?.transport_mode || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Border</p><p x-text="drill.data?.poe?.border_country || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Province</p><p x-text="drill.data?.poe?.admin_level_1 || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">District</p><p x-text="drill.data?.poe?.district || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Last Screening</p><p x-text="drill.data?.last_screen ? new Date(drill.data.last_screen).toLocaleString() : 'Never'"></p></div>
                                </div>
                            </details>

                            <details class="rpt-section" open>
                                <summary class="rpt-section-head"><span>30-day Volume</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                                <div class="rpt-section-body"><div class="relative h-[140px]"><canvas x-ref="drill_dark_spark"></canvas></div></div>
                            </details>

                            <details class="rpt-section">
                                <summary class="rpt-section-head"><span>Assigned Officers <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.assigned?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                                <div class="rpt-section-body">
                                    <template x-for="u in (drill.data?.assigned ?? [])" :key="u.id">
                                        <div class="flex items-center justify-between border-t border-border/40 pt-2 first:border-t-0 first:pt-0">
                                            <div><p class="text-[13px] font-medium" x-text="u.full_name || u.username"></p><p class="text-[11px] text-muted-foreground" x-text="u.role_key"></p></div>
                                            <p class="text-[11px] text-muted-foreground" x-text="u.last_activity_at ? new Date(u.last_activity_at).toLocaleString() : 'Never'"></p>
                                        </div>
                                    </template>
                                    <p x-show="!(drill.data?.assigned?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No officers currently assigned.</p>
                                </div>
                            </details>

                            <details class="rpt-section">
                                <summary class="rpt-section-head"><span>Recent Alerts <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.recent_alerts?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                                <div class="rpt-section-body space-y-2">
                                    <template x-for="a in (drill.data?.recent_alerts ?? [])" :key="a.id">
                                        <div class="flex items-center justify-between border-t border-border/40 pt-2 first:border-t-0 first:pt-0">
                                            <div class="min-w-0"><p class="text-[13px] font-medium truncate" x-text="a.alert_title || a.alert_code || ('Alert #' + a.id)"></p><p class="text-[11px] text-muted-foreground" x-text="new Date(a.created_at).toLocaleString()"></p></div>
                                            <div class="flex gap-1.5"><span class="badge" :class="badgeForRisk(a.risk_level)" x-text="a.risk_level"></span><span class="badge badge-outline" x-text="a.status"></span></div>
                                        </div>
                                    </template>
                                    <p x-show="!(drill.data?.recent_alerts?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No alerts originated at this POE.</p>
                                </div>
                            </details>
                        </div>
                    </template>

                    {{-- Inactive user detail --}}
                    <template x-if="drill.row?.type === 'inactive_user'">
                        <div class="space-y-4">
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                                <div class="rpt-tile rpt-tile-warning p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Last Login</p><p class="text-[14px] font-bold mt-0.5" x-text="drill.data?.user?.last_login_at ? new Date(drill.data.user.last_login_at).toLocaleDateString() : 'Never'"></p></div>
                                <div class="rpt-tile rpt-tile-warning p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Last Activity</p><p class="text-[14px] font-bold mt-0.5" x-text="drill.data?.user?.last_activity_at ? new Date(drill.data.user.last_activity_at).toLocaleDateString() : 'Never'"></p></div>
                                <div class="rpt-tile rpt-tile-neutral p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Failed Logins</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.user?.failed_login_count ?? 0"></p></div>
                                <div class="rpt-tile rpt-tile-info p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Risk Score</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.user?.risk_score ?? 0"></p></div>
                            </div>

                            <details class="rpt-section" open>
                                <summary class="rpt-section-head"><span>Recent Output</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                                <div class="rpt-section-body"><div class="relative h-[200px]"><canvas x-ref="drill_user_output"></canvas></div></div>
                            </details>

                            <details class="rpt-section">
                                <summary class="rpt-section-head"><span>Account</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                                <div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 gap-3 text-[13px]">
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Username</p><p class="font-mono text-[11.5px]" x-text="drill.data?.user?.username || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Role</p><p x-text="drill.data?.user?.role_key || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Account Type</p><p x-text="drill.data?.user?.account_type || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Email</p><p class="text-[11.5px]" x-text="drill.data?.user?.email || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Phone</p><p class="text-[11.5px]" x-text="drill.data?.user?.phone || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Joined</p><p x-text="drill.data?.user?.created_at ? new Date(drill.data.user.created_at).toLocaleDateString() : '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Last Login IP</p><p class="font-mono text-[11px]" x-text="drill.data?.user?.last_login_ip || '—'"></p></div>
                                    <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Locked Until</p><p x-text="drill.data?.user?.locked_until ? new Date(drill.data.user.locked_until).toLocaleString() : '—'"></p></div>
                                </div>
                            </details>

                            <details class="rpt-section">
                                <summary class="rpt-section-head"><span>Assignments <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.assignments?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                                <div class="rpt-section-body">
                                    <template x-for="(a, i) in (drill.data?.assignments ?? [])" :key="i">
                                        <div class="border-t border-border/40 pt-2 first:border-t-0 first:pt-0">
                                            <p class="text-[13px]"><span class="font-medium" x-text="a.poe_code || a.district_code || a.province_code || a.country_code"></span> <span x-show="a.is_primary" class="badge badge-brand ml-1">Primary</span><span x-show="!a.is_active" class="badge badge-secondary ml-1">Inactive</span></p>
                                            <p class="text-[11px] text-muted-foreground" x-text="(a.starts_at ? new Date(a.starts_at).toLocaleDateString() : '—') + ' → ' + (a.ends_at ? new Date(a.ends_at).toLocaleDateString() : 'open')"></p>
                                        </div>
                                    </template>
                                    <p x-show="!(drill.data?.assignments?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No assignments on file.</p>
                                </div>
                            </details>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- ────────── EXPLAINER MODAL · large screen + data table ────────── --}}
    <template x-teleport="body">
        <div x-show="explainer.open" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" @keydown.escape.window="explainer.open = false">
            <div class="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm" @click="explainer.open = false"></div>
            <div class="relative z-50 w-full sm:w-[96vw] sm:max-w-[1400px] h-[94vh] sm:max-h-[94vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl border border-border bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-border/60 bg-white/95 backdrop-blur px-5 py-3.5">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-info">About this chart</p>
                        <h2 class="text-[17px] font-bold tracking-tight truncate" x-text="explainer.spec?.title || ''"></h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button class="btn btn-outline btn-xs gap-1.5" @click="exportChart(explainer.key, 'csv')" x-show="explainer.key">
                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 17H5a2 2 0 01-2-2V5a2 2 0 012-2h10l4 4v3M14 3v4h4M9 13h6m-6 4h4"/></svg>
                            Export CSV
                        </button>
                        <button class="btn btn-ghost btn-icon-xs" @click="explainer.open = false">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
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
                                    <thead class="bg-muted/50 sticky top-0">
                                        <tr>
                                            <template x-for="(h, i) in explainerHeaders()" :key="i">
                                                <th class="text-left px-3 py-2 text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground border-b border-border" x-text="h"></th>
                                            </template>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(row, ri) in explainerRows()" :key="ri">
                                            <tr class="border-b border-border/40 hover:bg-muted/30">
                                                <template x-for="(cell, ci) in row" :key="ci">
                                                    <td class="px-3 py-2" :class="ci === 0 ? 'font-medium' : 'text-right font-mono'" x-text="cell === null || cell === undefined ? '—' : (typeof cell === 'number' ? cell.toLocaleString() : cell)"></td>
                                                </template>
                                            </tr>
                                        </template>
                                        <tr x-show="!explainerRows().length">
                                            <td :colspan="explainerHeaders().length || 1" class="px-3 py-12 text-center text-muted-foreground">No rows in the current window.</td>
                                        </tr>
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
function rptOpsRisk() {
    return {
        filters: { poe: '' },
        meta: {}, kpis: [], rows: [],
        pagination: { page: 1, per_page: 10, total: 0, total_pages: 1, from: 0, to: 0 },
        controls: { sort: 'severity', dir: 'desc', q: '', cat: 'all' },
        categoryCounts: {},
        search: '', cat: 'all',
        loading: false, filtersOpen: false,
        chartObjs: { aging: null, risk: null, drillFollowup: null, drillDarkSpark: null, drillUserOutput: null },
        chartData: { aging: null, risk: null },
        drill: { open: false, row: null, data: null },
        explainer: { open: false, key: null, spec: null },
        explainerSpecs: {
            aging: {
                title: 'Open Alert Aging',
                what: 'How long alerts that are still OPEN or ACKNOWLEDGED have been waiting. Buckets are <6h, 6–24h, 1–3d, >3d.',
                how: 'Each bar is the count of unresolved alerts in that age bucket. Anything in the >3d bucket is a clear escalation candidate.',
                decisions: ['Identify alerts already breaching SLA.', 'Decide where to push handoffs or escalations.', 'Spot operational backlog forming.'],
                source: 'alerts.created_at where status IN (OPEN, ACKNOWLEDGED), bucketed via TIMESTAMPDIFF(HOUR, created_at, NOW()).',
                caveats: 'CLOSED alerts are excluded — see R5 (Resolution Database) for those.',
            },
            risk: {
                title: 'Alert Volume by Risk · 30 days',
                what: 'Daily count of alerts created in the last 30 days, stacked by risk level: Low / Medium / High / Critical.',
                how: 'Each stacked column is a day. The taller the column, the more alerts that day. Red/dark-red on top indicates Critical/High pressure.',
                decisions: ['Detect emerging incidents.', 'Compare current activity to recent baseline.', 'Schedule escalation capacity ahead of trend.'],
                source: 'alerts GROUP BY DATE(created_at), risk_level over the trailing 30 days.',
                caveats: 'The first day in the window may be partial if it spans midnight. Reopens are not separately counted here.',
            },
        },

        async boot() { await this.loadMeta(); await this.apply(); },
        async loadMeta() { const r = await fetch('{{ url('/admin/reports/rpt-ops-risk/meta') }}'); const j = await r.json(); this.meta = j.data || {}; },
        async apply() { this.loading = true; await Promise.all([this.loadKpis(), this.loadChart('open_alert_aging', 'aging'), this.loadChart('alerts_by_risk_30d', 'risk'), this.loadRecords(1)]); this.loading = false; },
        resetFilters() { this.filters = { poe: '' }; this.search = ''; this.cat = 'all'; this.apply(); },
        qs() { const p = new URLSearchParams(); for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v); return p.toString(); },
        async loadKpis() { const r = await fetch('{{ url('/admin/reports/rpt-ops-risk/kpis') }}?' + this.qs()); const j = await r.json(); this.kpis = j.data?.kpis || []; },
        async loadChart(key, target) { const r = await fetch('{{ url('/admin/reports/rpt-ops-risk/chart') }}/' + key + '?' + this.qs()); const j = await r.json(); this.chartData[target] = j.data; this.renderChart(target, j.data); },

        renderChart(target, payload) {
            if (!payload || !this.$refs['chart_' + target]) return;
            if (this.chartObjs[target]) this.chartObjs[target].destroy();
            const isStacked = target === 'risk';
            const palette = { aging: ['#10b981', '#f59e0b', '#ef4444', '#7f1d1d'], risk: ['#10b981', '#f59e0b', '#ef4444', '#7f1d1d'] };
            const datasets = (payload.datasets || []).map((d, i) => ({
                label: d.label, data: d.data,
                backgroundColor: palette[target][i % 4] + (isStacked ? 'cc' : 'dd'),
                hoverBackgroundColor: palette[target][i % 4],
                borderRadius: 6, borderSkipped: false,
                stack: isStacked ? 'risk' : undefined,
            }));
            this.chartObjs[target] = new Chart(this.$refs['chart_' + target].getContext('2d'), {
                type: 'bar',
                data: { labels: payload.labels, datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: isStacked, position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                        tooltip: { backgroundColor: 'rgba(15,23,42,.95)', padding: 10, cornerRadius: 8 },
                    },
                    scales: {
                        x: { stacked: isStacked, grid: { display: false }, ticks: { font: { size: 11 } } },
                        y: { stacked: isStacked, beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } },
                    },
                },
            });
        },

        async loadRecords(page) {
            this.loading = true;
            const p = new URLSearchParams({ page, q: this.search, sort: this.controls.sort, dir: this.controls.dir, cat: this.cat });
            for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v);
            const r = await fetch('{{ url('/admin/reports/rpt-ops-risk/records') }}?' + p.toString());
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
            if (fmt === 'png') { const c = this.chartObjs[target]; if (!c) return; const a = document.createElement('a'); a.href = c.toBase64Image('image/png', 1); a.download = 'rpt-ops-risk__' + target + '__' + this.stamp() + '.png'; a.click(); }
            else { const map = { aging: 'open_alert_aging', risk: 'alerts_by_risk_30d' }; window.location.href = '{{ url('/admin/reports/rpt-ops-risk/chart') }}/' + map[target] + '/csv?' + this.qs(); }
        },
        stamp() { const d = new Date(); const p = n => String(n).padStart(2, '0'); return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()); },

        openExplainer(key) { this.explainer.open = true; this.explainer.key = key; this.explainer.spec = this.explainerSpecs[key] || null; },
        explainerHeaders() { const k = this.explainer.key; return (k && this.chartData[k]?.csv_headers) || []; },
        explainerRows()    { const k = this.explainer.key; return (k && this.chartData[k]?.csv_rows)    || []; },

        async openDrill(row) {
            this.drill.open = true; this.drill.row = row; this.drill.data = null;
            const r = await fetch('{{ url('/admin/reports/rpt-ops-risk/records') }}/' + encodeURIComponent(row.type) + '/' + encodeURIComponent(row.key));
            const j = await r.json(); this.drill.data = j.data;
            this.$nextTick(() => this.renderDrillCharts());
        },

        renderDrillCharts() {
            const t = this.drill.row?.type;
            if (t === 'open_alert') {
                const a = this.drill.data?.followup_agg || {};
                this._mountChart('drill_followup', 'drillFollowup', {
                    type: 'doughnut',
                    data: { labels: ['Completed', 'Pending', 'Blocked', 'Other'], datasets: [{ data: [a.completed || 0, a.pending || 0, a.blocked || 0, Math.max(0, (a.total || 0) - ((a.completed || 0) + (a.pending || 0) + (a.blocked || 0)))], backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#94a3b8'], borderWidth: 0 }] },
                    opts: { cutout: '65%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 10 } } } },
                });
            } else if (t === 'dark_poe') {
                const spark = this.drill.data?.sparkline || [];
                this._mountChart('drill_dark_spark', 'drillDarkSpark', {
                    type: 'line',
                    data: { labels: spark.map(p => p.date.slice(5)), datasets: [{ data: spark.map(p => p.count), borderColor: '#ef4444', backgroundColor: '#ef444422', fill: true, tension: 0.35, borderWidth: 2, pointRadius: 1 }] },
                    opts: { plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 9 }, maxTicksLimit: 8 } }, y: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 10 } } } } },
                });
            } else if (t === 'inactive_user') {
                const o = this.drill.data?.output || {};
                this._mountChart('drill_user_output', 'drillUserOutput', {
                    type: 'bar',
                    data: { labels: ['Screenings 30d', 'Screenings 90d', 'Secondary 30d', 'Alerts 30d', 'Followups 30d'], datasets: [{ data: [o.screenings_30d || 0, o.screenings_90d || 0, o.secondary_screenings_30d || 0, o.alerts_30d || 0, o.followups_30d || 0], backgroundColor: ['#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444'], borderRadius: 6 }] },
                    opts: { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } }, y: { grid: { display: false }, ticks: { font: { size: 11 } } } } },
                });
            }
        },
        _mountChart(refKey, slot, cfg) {
            const ref = this.$refs[refKey]; if (!ref) return;
            if (this.chartObjs[slot]) this.chartObjs[slot].destroy();
            this.chartObjs[slot] = new Chart(ref.getContext('2d'), { type: cfg.type, data: cfg.data, options: { responsive: true, maintainAspectRatio: false, ...(cfg.opts || {}) } });
        },

        badgeForRisk(r) { return { LOW: 'badge-low', MEDIUM: 'badge-medium', HIGH: 'badge-high', CRITICAL: 'badge-critical' }[r] || 'badge-secondary'; },
        badgeForFollowup(s) { return { COMPLETED: 'badge-success', PENDING: 'badge-warning', IN_PROGRESS: 'badge-info', BLOCKED: 'badge-critical', NOT_APPLICABLE: 'badge-secondary' }[s] || 'badge-outline'; },
        riskTone(r) { return { LOW: 'success', MEDIUM: 'warning', HIGH: 'danger', CRITICAL: 'critical' }[r] || 'neutral'; },
        drillEyebrowTone() { const t = this.drill.row?.type; return { open_alert: 'text-danger', dark_poe: 'text-warning', inactive_user: 'text-info' }[t] || 'text-muted-foreground'; },
        formatDuration(value) {
            if (value === null || value === undefined || isNaN(value)) return '—';
            const m = Math.round(value);
            if (m < 60)   return m + ' min';
            if (m < 1440) { const h = Math.floor(m / 60), r = m % 60; return r ? `${h}h ${r}m` : `${h}h`; }
            const d = Math.floor(m / 1440), h = Math.floor((m % 1440) / 60);
            return h ? `${d}d ${h}h` : `${d}d`;
        },
    };
}
</script>
@endsection
