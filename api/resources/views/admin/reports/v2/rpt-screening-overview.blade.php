@extends('admin.layout')

@section('crumb', 'Reports')
@section('title', 'Screening Overview')

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
    .rpt-heat-cell { @apply h-5 rounded-sm; }
</style>
@endpush

@section('content')
<div x-data="rptScreeningOverview()" x-init="boot()" class="space-y-4">

    {{-- ────────── HEADER ────────── --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-brand-ink/80">Executive Overview · R1</p>
            <h1 class="text-[22px] font-bold tracking-tight">Screening Overview</h1>
            <p class="text-sm text-muted-foreground mt-0.5">How many travellers were screened, where, and how it has trended.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-md border border-border/70 bg-card px-2.5 py-1 text-[11.5px] font-mono text-foreground/80" x-show="window_label">
                <span class="status-dot status-dot-live"></span><span x-text="window_label"></span>
            </span>
            <button class="btn btn-outline btn-xs" @click="filtersOpen = !filtersOpen" x-text="filtersOpen ? 'Hide Filters' : 'Show Filters'"></button>
        </div>
    </section>

    {{-- ────────── FILTERS (collapsed by default) ────────── --}}
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

    {{-- Data-quality banner: surfaces mobile-sync duplicate-secondary issue --}}
    <template x-if="dataQuality?.note">
        <div class="rounded-md border border-amber-300 bg-amber-50 px-4 py-2.5 text-[12.5px] text-amber-900 flex items-start gap-2">
            <svg class="h-4 w-4 mt-0.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/></svg>
            <span><strong>Data-quality notice:</strong> <span x-text="dataQuality.note"></span></span>
        </div>
    </template>

    {{-- ────────── KPI ROW (semantic colour) ────────── --}}
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
            ['target' => 'volume', 'serverKey' => 'volume_over_time', 'title' => 'Volume Over Time', 'subtitle' => 'Daily travellers screened · primary vs secondary tier'],
            ['target' => 'topPoes', 'serverKey' => 'top_poes', 'title' => 'Top 10 Points of Entry', 'subtitle' => 'Highest screening volume in the selected window'],
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

    {{-- ────────── PREMIUM TABLE ────────── --}}
    <section class="card overflow-hidden">
        <div class="flex flex-col gap-3 p-4 border-b border-border/60">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-[14px] font-semibold tracking-tight">POE Activity Summary</h3>
                    <p class="text-[12px] text-muted-foreground">Click any row for the full POE profile.</p>
                </div>
                <span class="text-[11px] font-mono text-muted-foreground whitespace-nowrap" x-text="(pagination.total ?? 0).toLocaleString() + ' POEs'"></span>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                <div class="relative flex-1 max-w-md">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="search" placeholder="Search POE name or code..." class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-sm" x-model.debounce.300ms="search" @input="loadRecords(1)">
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead class="table-head sticky top-0">
                    <tr>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('poe_name')">POE <span x-html="sortIcon('poe_name')"></span></span></th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('primary')">Primary <span x-html="sortIcon('primary')"></span></span></th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('secondary')">Secondary <span x-html="sortIcon('secondary')"></span></span></th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('escalation_pct')">Escalation <span x-html="sortIcon('escalation_pct')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('last_screening')">Last Screening <span x-html="sortIcon('last_screening')"></span></span></th>
                    </tr>
                </thead>
                <tbody class="table-body">
                    <template x-if="loading">
                        <template x-for="i in 10" :key="i">
                            <tr class="border-b border-border/40">
                                <td class="table-cell"><div class="rpt-skel h-4 w-40"></div><div class="rpt-skel h-3 w-24 mt-1.5"></div></td>
                                <td class="table-cell text-right"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell text-right"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell text-right"><div class="rpt-skel h-5 w-14 ml-auto"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-32"></div></td>
                            </tr>
                        </template>
                    </template>
                    <template x-if="!loading">
                        <template x-for="r in rows" :key="r.poe_code">
                            <tr class="table-row cursor-pointer" @click="openDrill(r.poe_code)">
                                <td class="table-cell">
                                    <div class="font-medium" x-text="r.poe_name"></div>
                                    <div class="text-[11px] text-muted-foreground font-mono" x-text="r.poe_code"></div>
                                </td>
                                <td class="table-cell text-right font-mono" x-text="(r.primary ?? 0).toLocaleString()"></td>
                                <td class="table-cell text-right font-mono" x-text="(r.secondary ?? 0).toLocaleString()"></td>
                                <td class="table-cell text-right">
                                    <span class="badge"
                                          :class="r.escalation_pct === null ? 'badge-secondary' : (r.escalation_pct >= 30 ? 'badge-warning' : 'badge-success')"
                                          x-text="r.escalation_pct === null ? '—' : (r.escalation_pct + '%')"></span>
                                </td>
                                <td class="table-cell text-[12px] text-muted-foreground" x-text="r.last_screening ? new Date(r.last_screening).toLocaleString() : '—'"></td>
                            </tr>
                        </template>
                    </template>
                    <tr x-show="!loading && !rows.length">
                        <td colspan="5" class="table-cell py-12 text-center text-muted-foreground">No screening activity matches the current filters.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        {{-- Advanced pagination --}}
        <div class="flex flex-col sm:flex-row items-center justify-between gap-3 p-4 border-t border-border/60">
            <span class="text-[12px] text-muted-foreground">
                Showing <span class="font-mono" x-text="(pagination.from ?? 0).toLocaleString()"></span>–<span class="font-mono" x-text="(pagination.to ?? 0).toLocaleString()"></span>
                of <span class="font-mono" x-text="(pagination.total ?? 0).toLocaleString()"></span>
            </span>
            <div class="flex items-center gap-1">
                <button class="btn btn-outline btn-xs" :disabled="pagination.page <= 1" @click="loadRecords(1)" title="First">«</button>
                <button class="btn btn-outline btn-xs" :disabled="pagination.page <= 1" @click="loadRecords(pagination.page - 1)" title="Prev">‹</button>
                <template x-for="p in pageCluster()" :key="p">
                    <button class="btn btn-xs" :class="p === pagination.page ? 'btn-brand' : 'btn-outline'" @click="loadRecords(p)" x-text="p"></button>
                </template>
                <button class="btn btn-outline btn-xs" :disabled="pagination.page >= pagination.total_pages" @click="loadRecords(pagination.page + 1)" title="Next">›</button>
                <button class="btn btn-outline btn-xs" :disabled="pagination.page >= pagination.total_pages" @click="loadRecords(pagination.total_pages)" title="Last">»</button>
                <span class="ml-2 hidden sm:inline-flex items-center gap-1 text-[12px] text-muted-foreground">
                    Go to
                    <input type="number" min="1" :max="pagination.total_pages" class="h-8 w-14 rounded-md border border-input bg-background px-2 text-sm font-mono"
                           @keydown.enter="loadRecords(parseInt($event.target.value) || 1); $event.target.value = ''">
                </span>
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
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-brand-ink/80">Point of Entry Profile</p>
                        <h2 class="text-[17px] font-bold tracking-tight truncate" x-text="drill.data?.poe?.name || drill.poe || ''"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="drill.open = false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="p-5 space-y-4" x-show="drill.data">
                    {{-- 6 KPI strip --}}
                    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-2.5">
                        <template x-for="t in drillTiles()" :key="t.label">
                            <div class="rpt-tile" :class="'rpt-tile-' + t.tone + ' p-3'">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground" x-text="t.label"></p>
                                <p class="text-xl font-bold mt-0.5" x-text="t.value"></p>
                            </div>
                        </template>
                    </div>

                    {{-- Profile + chart grid --}}
                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                        <details class="rpt-section lg:col-span-1" open>
                            <summary class="rpt-section-head"><span>POE Profile</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                            <div class="rpt-section-body grid grid-cols-2 gap-3 text-[13px]">
                                <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Type</p><p x-text="drill.data?.poe?.type || '—'"></p></div>
                                <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Transport</p><p x-text="drill.data?.poe?.transport || '—'"></p></div>
                                <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Border</p><p x-text="drill.data?.poe?.border_country || '—'"></p></div>
                                <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Region</p><p x-text="drill.data?.poe?.province || '—'"></p></div>
                                <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">District</p><p x-text="drill.data?.poe?.district || '—'"></p></div>
                                <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Coords</p><p class="font-mono text-[11px]" x-text="drillCoords()"></p></div>
                            </div>
                        </details>
                        <details class="rpt-section lg:col-span-1" open>
                            <summary class="rpt-section-head"><span>Gender Mix</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                            <div class="rpt-section-body"><div class="relative h-[160px]"><canvas x-ref="drill_gender"></canvas></div></div>
                        </details>
                        <details class="rpt-section lg:col-span-1" open>
                            <summary class="rpt-section-head"><span>Direction Split</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                            <div class="rpt-section-body"><div class="relative h-[160px]"><canvas x-ref="drill_direction"></canvas></div></div>
                        </details>
                    </div>

                    <details class="rpt-section" open>
                        <summary class="rpt-section-head"><span>Last 14 Days · Volume</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body"><div class="relative h-[140px]"><canvas x-ref="drill_spark"></canvas></div></div>
                    </details>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        <details class="rpt-section">
                            <summary class="rpt-section-head"><span>Alert Risk Mix</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                            <div class="rpt-section-body"><div class="relative h-[180px]"><canvas x-ref="drill_riskmix"></canvas></div></div>
                        </details>
                        <details class="rpt-section">
                            <summary class="rpt-section-head"><span>Activity Heatmap · Hour × Weekday</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                            <div class="rpt-section-body" x-html="drillHeatmapHtml()"></div>
                        </details>
                    </div>

                    <details class="rpt-section">
                        <summary class="rpt-section-head"><span>Top Officers <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.officers?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body">
                            <table class="w-full text-[13px]">
                                <thead class="text-[10px] uppercase tracking-wider text-muted-foreground"><tr><th class="text-left py-1.5">Officer</th><th class="text-left py-1.5">Role</th><th class="text-right py-1.5">Captured</th><th class="text-left py-1.5">Last</th></tr></thead>
                                <tbody>
                                    <template x-for="o in (drill.data?.officers ?? [])" :key="o.id">
                                        <tr class="border-t border-border/40">
                                            <td class="py-1.5 font-medium" x-text="o.full_name || o.username"></td>
                                            <td class="py-1.5 text-muted-foreground text-[11.5px]" x-text="o.role_key || '—'"></td>
                                            <td class="py-1.5 text-right font-mono" x-text="o.captured.toLocaleString()"></td>
                                            <td class="py-1.5 text-muted-foreground" x-text="o.last_at ? new Date(o.last_at).toLocaleString() : '—'"></td>
                                        </tr>
                                    </template>
                                    <tr x-show="!(drill.data?.officers?.length)"><td colspan="4" class="py-3 text-center text-muted-foreground">No officers active in this window.</td></tr>
                                </tbody>
                            </table>
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
                            <p x-show="!(drill.data?.recent_alerts?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No alerts originated at this POE in the window.</p>
                        </div>
                    </details>

                    @include('admin.reports.v2._related_views', ['type' => 'poe'])
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
                    {{-- Narrative pane (2/5) --}}
                    <div class="lg:col-span-2 space-y-4 text-[13.5px] leading-relaxed">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">What it shows</p>
                            <p x-text="explainer.spec?.what"></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">How to read it</p>
                            <p x-text="explainer.spec?.how"></p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Decisions it supports</p>
                            <ul class="list-disc pl-5 space-y-1">
                                <template x-for="d in (explainer.spec?.decisions || [])" :key="d"><li x-text="d"></li></template>
                            </ul>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Data source</p>
                            <p class="font-mono text-[12px] text-muted-foreground" x-text="explainer.spec?.source"></p>
                        </div>
                        <div x-show="explainer.spec?.caveats" class="rounded-md border border-warning/30 bg-warning-soft/40 px-3 py-2">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-warning">Caveats</p>
                            <p x-text="explainer.spec?.caveats"></p>
                        </div>
                    </div>

                    {{-- Data table (3/5) — the exact aggregated rows behind the chart --}}
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
function rptScreeningOverview() {
    return {
        filters: { poe: '', start_date: '', end_date: '' },
        meta: {}, kpis: [], rows: [], dataQuality: null,
        pagination: { page: 1, per_page: 10, total: 0, total_pages: 1, from: 0, to: 0 },
        controls: { sort: 'primary', dir: 'desc', q: '' },
        search: '',
        window_label: '',
        loading: false, filtersOpen: false,
        chartObjs: { volume: null, topPoes: null, drillSpark: null, drillGender: null, drillDirection: null, drillRiskmix: null },
        drill: { open: false, poe: null, data: null },
        explainer: { open: false, key: null, spec: null },
        explainerSpecs: {
            volume: {
                title: 'Volume Over Time',
                what: 'Daily count of travellers screened in the selected window, split into primary-tier (boarder gate) and secondary-tier (clinical review).',
                how: 'X-axis is the date. Each line is a tier — primary in green, secondary in blue. A widening gap means more travellers are escalating to clinical review.',
                decisions: ['Spot demand spikes that need staff reinforcement.', 'Detect sudden drops indicating a screening outage.', 'Compare tiers to gauge clinical workload pressure.'],
                source: 'primary_screenings.captured_at + secondary_screenings.opened_at, GROUP BY DATE().',
                caveats: 'Only COMPLETED primary records counted; voided rows excluded.',
            },
            topPoes: {
                title: 'Top 10 Points of Entry',
                what: 'The ten POEs with the highest primary-screening volume in the window.',
                how: 'Bars are ranked descending. The longer the bar, the busier the POE.',
                decisions: ['Direct supervision and supplies to the busiest crossings.', 'Identify low-volume POEs that may need stimulus or audit.'],
                source: 'primary_screenings GROUP BY poe_code ORDER BY COUNT(*) DESC LIMIT 10.',
                caveats: 'A POE with zero records does not appear at all — see R11 for dark POEs.',
            },
        },

        async boot() {
            await this.loadMeta();
            await this.apply();
            const poe = new URLSearchParams(location.search).get('poe');
            if (poe) this.$nextTick(() => this.openDrill(poe));
        },
        async loadMeta() { const r = await fetch('{{ url('/admin/reports/rpt-screening-overview/meta') }}'); const j = await r.json(); this.meta = j.data || {}; },
        async apply() { this.loading = true; await Promise.all([this.loadKpis(), this.loadChart('volume_over_time', 'volume'), this.loadChart('top_poes', 'topPoes'), this.loadRecords(1)]); this.loading = false; },
        resetFilters() { this.filters = { poe: '', start_date: '', end_date: '' }; this.search = ''; this.apply(); },
        qs() { const p = new URLSearchParams(); for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v); return p.toString(); },
        async loadKpis() { const r = await fetch('{{ url('/admin/reports/rpt-screening-overview/kpis') }}?' + this.qs()); const j = await r.json(); this.kpis = j.data?.kpis || []; this.window_label = j.data?.window?.label || ''; this.dataQuality = j.data?.data_quality || null; },
        async loadChart(key, target) { const r = await fetch('{{ url('/admin/reports/rpt-screening-overview/chart') }}/' + key + '?' + this.qs()); const j = await r.json(); this.renderChart(target, j.data); },

        renderChart(target, payload) {
            if (!payload || !this.$refs['chart_' + target]) return;
            if (this.chartObjs[target]) this.chartObjs[target].destroy();
            const isBar = target === 'topPoes';
            const palette = ['#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444'];
            const datasets = (payload.datasets || []).map((d, i) => ({
                label: d.label, data: d.data,
                borderColor: palette[i], backgroundColor: isBar ? palette[i] + 'cc' : palette[i] + '22',
                borderWidth: 2, tension: 0.35, fill: !isBar, pointRadius: isBar ? 0 : 3, pointHoverRadius: isBar ? 0 : 5,
                hoverBackgroundColor: isBar ? palette[i] : undefined,
                borderRadius: isBar ? 6 : 0,
            }));
            this.chartObjs[target] = new Chart(this.$refs['chart_' + target].getContext('2d'), {
                type: isBar ? 'bar' : 'line',
                data: { labels: payload.labels, datasets },
                options: {
                    responsive: true, maintainAspectRatio: false, indexAxis: isBar ? 'y' : 'x',
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: !isBar, position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                        tooltip: { backgroundColor: 'rgba(15,23,42,.95)', titleFont: { weight: 'bold' }, padding: 10, cornerRadius: 8 },
                    },
                    scales: {
                        x: { grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } },
                        y: { grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } }, beginAtZero: true },
                    },
                },
            });
        },

        async loadRecords(page) {
            this.loading = true;
            const p = new URLSearchParams({ page, q: this.search, sort: this.controls.sort, dir: this.controls.dir });
            for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v);
            const r = await fetch('{{ url('/admin/reports/rpt-screening-overview/records') }}?' + p.toString());
            const j = await r.json();
            this.rows = j.data?.rows || [];
            this.pagination = j.data?.pagination || this.pagination;
            this.controls = j.data?.controls || this.controls;
            this.loading = false;
        },
        toggleSort(col) { if (this.controls.sort === col) { this.controls.dir = this.controls.dir === 'asc' ? 'desc' : 'asc'; } else { this.controls.sort = col; this.controls.dir = 'desc'; } this.loadRecords(1); },
        sortIcon(col) { if (this.controls.sort !== col) return '<svg class="h-3 w-3 text-muted-foreground/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 10l5-5 5 5M7 14l5 5 5-5"/></svg>'; return this.controls.dir === 'asc' ? '<svg class="h-3 w-3 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 14l5-5 5 5"/></svg>' : '<svg class="h-3 w-3 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 10l5 5 5-5"/></svg>'; },
        pageCluster() { const p = this.pagination.page, total = this.pagination.total_pages; const out = []; const start = Math.max(1, p - 2), end = Math.min(total, p + 2); for (let i = start; i <= end; i++) out.push(i); return out; },

        exportChart(target, fmt) {
            if (fmt === 'png') {
                const c = this.chartObjs[target]; if (!c) return;
                const a = document.createElement('a'); a.href = c.toBase64Image('image/png', 1);
                a.download = 'rpt-screening-overview__' + target + '__' + this.stamp() + '.png'; a.click();
            } else {
                const map = { volume: 'volume_over_time', topPoes: 'top_poes' };
                window.location.href = '{{ url('/admin/reports/rpt-screening-overview/chart') }}/' + map[target] + '/csv?' + this.qs();
            }
        },
        stamp() { const d = new Date(); const p = n => String(n).padStart(2, '0'); return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()); },

        openExplainer(key) { this.explainer.open = true; this.explainer.key = key; this.explainer.spec = this.explainerSpecs[key] || null; },
        explainerHeaders() { const k = this.explainer.key; return (k && this.chartData[k]?.csv_headers) || []; },
        explainerRows()    { const k = this.explainer.key; return (k && this.chartData[k]?.csv_rows)    || []; },

        async openDrill(poe) {
            this.drill.open = true; this.drill.poe = poe; this.drill.data = null;
            const r = await fetch('{{ url('/admin/reports/rpt-screening-overview/records') }}/' + encodeURIComponent(poe) + '?' + this.qs());
            const j = await r.json(); this.drill.data = j.data;
            this.$nextTick(() => this.renderDrillCharts());
        },

        drillTiles() {
            const t = this.drill.data?.totals || {};
            return [
                { label: 'Primary',     value: (t.primary || 0).toLocaleString(),     tone: 'brand'    },
                { label: 'Secondary',   value: (t.secondary || 0).toLocaleString(),   tone: 'info'     },
                { label: 'Symptomatic', value: (t.symptomatic || 0).toLocaleString(), tone: 'warning'  },
                { label: 'High-Risk',   value: (t.high_risk || 0).toLocaleString(),   tone: t.high_risk > 0 ? 'critical' : 'neutral' },
                { label: 'Alerts',      value: (t.alerts || 0).toLocaleString(),      tone: t.alerts > 0 ? 'danger' : 'neutral' },
                { label: 'In-Progress', value: (t.in_progress || 0).toLocaleString(), tone: 'neutral'  },
            ];
        },
        drillCoords() { const p = this.drill.data?.poe || {}; return (p.lat && p.lng) ? (p.lat + ', ' + p.lng) : '—'; },
        drillHeatmapHtml() {
            const data = this.drill.data?.heatmap || [];
            const matrix = Array.from({ length: 7 }, () => Array(24).fill(0));
            let max = 0;
            data.forEach(r => { const dow = (r.dow - 1 + 7) % 7; const h = r.h; const c = r.c; matrix[dow][h] = c; if (c > max) max = c; });
            const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
            const cell = (v) => { if (max === 0) return 'background:hsl(var(--muted))'; const pct = v / max; const alpha = 0.08 + pct * 0.92; return `background:rgba(16,185,129,${alpha})`; };
            let html = '<div class="space-y-1">';
            for (let d = 0; d < 7; d++) {
                html += `<div class="flex items-center gap-1"><span class="w-8 text-[10px] uppercase tracking-wider text-muted-foreground">${days[d]}</span><div class="grid grid-cols-24 gap-px flex-1" style="grid-template-columns:repeat(24,minmax(0,1fr))">`;
                for (let h = 0; h < 24; h++) {
                    const v = matrix[d][h];
                    html += `<div class="rpt-heat-cell" style="${cell(v)}" title="${days[d]} ${h}:00 — ${v} screenings"></div>`;
                }
                html += '</div></div>';
            }
            html += '<div class="flex items-center justify-between text-[10px] text-muted-foreground mt-2"><span>00:00</span><span>06:00</span><span>12:00</span><span>18:00</span><span>23:00</span></div>';
            html += `<p class="text-[11px] text-muted-foreground mt-2">Peak: <span class="font-mono">${max}</span> screenings in a single hour-of-week.</p></div>`;
            return html;
        },
        renderDrillCharts() {
            // Sparkline
            const spark = this.drill.data?.sparkline || [];
            this._mountChart('drill_spark', 'drillSpark', {
                type: 'line',
                data: {
                    labels: spark.map(p => p.date.slice(5)),
                    datasets: [{ data: spark.map(p => p.count), borderColor: '#10b981', backgroundColor: '#10b98133', fill: true, tension: 0.35, borderWidth: 2, pointRadius: 2 }],
                },
                opts: { plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 10 } } } } },
            });
            // Gender donut
            const g = this.drill.data?.gender || {};
            this._mountChart('drill_gender', 'drillGender', {
                type: 'doughnut',
                data: { labels: Object.keys(g), datasets: [{ data: Object.values(g), backgroundColor: ['#3b82f6', '#ec4899', '#8b5cf6', '#94a3b8'], borderWidth: 0 }] },
                opts: { cutout: '65%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 10 } } } },
            });
            // Direction bar
            const d = this.drill.data?.direction || {};
            this._mountChart('drill_direction', 'drillDirection', {
                type: 'bar',
                data: { labels: Object.keys(d), datasets: [{ data: Object.values(d), backgroundColor: ['#10b981', '#f59e0b', '#8b5cf6'], borderRadius: 6 }] },
                opts: { plugins: { legend: { display: false } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 10 } } }, y: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 10 } } } } },
            });
            // Alert risk donut
            const r = this.drill.data?.alert_risk_mix || {};
            this._mountChart('drill_riskmix', 'drillRiskmix', {
                type: 'doughnut',
                data: { labels: Object.keys(r), datasets: [{ data: Object.values(r), backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#7f1d1d'], borderWidth: 0 }] },
                opts: { cutout: '65%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 10 } } } },
            });
        },
        _mountChart(refKey, slot, cfg) {
            const ref = this.$refs[refKey]; if (!ref) return;
            if (this.chartObjs[slot]) this.chartObjs[slot].destroy();
            this.chartObjs[slot] = new Chart(ref.getContext('2d'), { type: cfg.type, data: cfg.data, options: { responsive: true, maintainAspectRatio: false, ...(cfg.opts || {}) } });
        },
        badgeForRisk(r) { return { LOW: 'badge-low', MEDIUM: 'badge-medium', HIGH: 'badge-high', CRITICAL: 'badge-critical' }[r] || 'badge-secondary'; },
    };
}
</script>
@endsection
