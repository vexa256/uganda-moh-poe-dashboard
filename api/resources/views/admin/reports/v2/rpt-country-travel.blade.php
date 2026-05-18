@extends('admin.layout')

@section('crumb', 'Reports')
@section('title', 'Country & Travel')

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
    .rpt-chip { @apply inline-flex items-center gap-1.5 h-7 rounded-full border px-2.5 text-[11.5px] font-medium transition-colors cursor-pointer; }
    .rpt-chip-active { @apply bg-brand-soft border-brand/40 text-brand-ink; }
    .rpt-chip-idle   { @apply bg-card border-border/60 text-muted-foreground hover:bg-muted/60; }
    .rpt-th-sort { @apply inline-flex items-center gap-1 cursor-pointer select-none; }
    .rpt-th-sort:hover { @apply text-foreground; }
    .rpt-section { @apply rounded-lg border bg-card; }
    .rpt-section-head { @apply flex items-center justify-between px-4 py-3 cursor-pointer text-[13px] font-semibold; }
    .rpt-section-body { @apply px-4 pb-4; }
    .rpt-skel { @apply animate-pulse bg-muted/60 rounded; }
    .rpt-footnote { @apply text-[10.5px] text-muted-foreground italic mt-1; }
</style>
@endpush

@section('content')
<div x-data="rptCountryTravel()" x-init="boot()" class="space-y-4">

    {{-- ─────── HEADER ─────── --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-brand">Executive Overview · R10</p>
            <h1 class="text-[22px] font-bold tracking-tight">Country &amp; Travel</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Where travellers are coming from, who is high-risk, and whether endemic-country flow is producing alerts.</p>
            <p class="text-[11px] text-muted-foreground mt-1 italic">All KPIs and charts on this page are based on secondary-screened travellers — the primary tier does not capture origin country.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button class="btn btn-outline btn-xs" @click="filtersOpen = !filtersOpen" x-text="filtersOpen ? 'Hide Filters' : 'Show Filters'"></button>
        </div>
    </section>

    {{-- ─────── FILTERS (collapsed by default) ─────── --}}
    <section class="card" x-show="filtersOpen" x-cloak>
        <div class="card-content py-4">
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
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

    {{-- ─────── KPI ROW ─────── --}}
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
        <button type="button" class="px-4 py-2 text-[13px] font-semibold border-b-2 transition-colors" :class="tab === 'records' ? 'border-brand text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'" @click="tab = 'records'">Country Index <span class="ml-1 font-mono opacity-60 text-[11px]" x-text="pagination.total ? `(${pagination.total})` : ''"></span></button>
    </div>

    {{-- ─────── CHARTS · 2 col-6 ─────── --}}
    <section x-show="tab === 'charts'" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @foreach ([
            ['target' => 'origins', 'serverKey' => 'top_origins',      'title' => 'Top Origin Countries',           'subtitle' => 'Top 10 origin countries by traveller count, with alerts overlay.'],
            ['target' => 'flow',    'serverKey' => 'endemic_flow_30d', 'title' => 'Endemic vs Non-endemic · 30 days', 'subtitle' => 'Daily traveller count bucketed by endemic flag of origin country.'],
        ] as $c)
        <div class="card overflow-hidden">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-4 border-b border-border/60">
                <div class="min-w-0">
                    <h3 class="text-[14px] font-semibold tracking-tight">{{ $c['title'] }}</h3>
                    <p class="text-[12px] text-muted-foreground">{{ $c['subtitle'] }}</p>
                    <p class="rpt-footnote">Based on secondary-screened travellers (primary tier has no origin country).</p>
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

    {{-- ─────── PREMIUM TABLE · Country Index ─────── --}}
    <section x-show="tab === 'records'" class="card overflow-hidden">
        <div class="flex flex-col gap-3 p-4 border-b border-border/60">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-[14px] font-semibold tracking-tight">Country Index</h3>
                    <p class="text-[12px] text-muted-foreground">Click any row for the country drill-down. Travellers PII inside the drill is masked unless you have NATIONAL/PHEOC scope.</p>
                </div>
                <span class="text-[11px] font-mono text-muted-foreground whitespace-nowrap" x-text="(pagination.total ?? 0).toLocaleString() + ' countries'"></span>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                <div class="relative flex-1 max-w-md">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="search" placeholder="Search country, ISO code..." class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-sm" x-model.debounce.300ms="search" @input="loadRecords(1)">
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="rpt-chip" :class="cat === 'all' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('all')">All</span>
                    <span class="rpt-chip" :class="cat === 'endemic' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('endemic')">Endemic</span>
                    <span class="rpt-chip" :class="cat === 'non_endemic' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('non_endemic')">Non-endemic</span>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead class="table-head sticky top-0">
                    <tr>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('country')">Country <span x-html="sortIcon('country')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('iso2')">ISO <span x-html="sortIcon('iso2')"></span></span></th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('travellers')">Travellers <span x-html="sortIcon('travellers')"></span></span></th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('alerts_')">Alerts <span x-html="sortIcon('alerts_')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('top_disease')">Top Disease <span x-html="sortIcon('top_disease')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('endemic')">Endemic <span x-html="sortIcon('endemic')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('top_poe')">Top POE <span x-html="sortIcon('top_poe')"></span></span></th>
                    </tr>
                </thead>
                <tbody class="table-body">
                    <template x-if="loading">
                        <template x-for="i in 10" :key="i">
                            <tr class="border-b border-border/40">
                                <td class="table-cell"><div class="rpt-skel h-4 w-40"></div><div class="rpt-skel h-3 w-24 mt-1.5"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-10"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-24"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-5 w-16"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-24"></div></td>
                            </tr>
                        </template>
                    </template>
                    <template x-if="!loading">
                        <template x-for="r in rows" :key="r.country_code">
                            <tr class="table-row cursor-pointer" @click="openDrill(r)">
                                <td class="table-cell">
                                    <div class="font-medium" x-text="r.country"></div>
                                    <div class="text-[11px] font-mono text-muted-foreground" x-text="r.country_code"></div>
                                </td>
                                <td class="table-cell text-[12px] font-mono" x-text="r.iso2 || '—'"></td>
                                <td class="table-cell text-right font-mono" x-text="(r.travellers ?? 0).toLocaleString()"></td>
                                <td class="table-cell text-right font-mono" x-text="(r.alerts_ ?? 0).toLocaleString()"></td>
                                <td class="table-cell text-[12px]" x-text="r.top_disease || '—'"></td>
                                <td class="table-cell"><span class="badge" :class="r.endemic ? 'badge-warning' : 'badge-secondary'" x-text="r.endemic ? 'YES' : 'NO'"></span></td>
                                <td class="table-cell text-[12px] font-mono" x-text="r.top_poe || '—'"></td>
                            </tr>
                        </template>
                    </template>
                    <tr x-show="!loading && !rows.length"><td colspan="7" class="table-cell py-12 text-center text-muted-foreground">No countries match the current filter.</td></tr>
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

    {{-- ─────── DRILL-DOWN MODAL · MASSIVE ─────── --}}
    <template x-teleport="body">
        <div x-show="drill.open" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" @keydown.escape.window="drill.open = false">
            <div class="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm" @click="drill.open = false"></div>
            <div class="relative z-50 w-full sm:w-[96vw] sm:max-w-[1400px] h-[94vh] sm:max-h-[94vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl border border-border bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-border/60 bg-card/95 backdrop-blur px-5 py-3.5">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-brand">Country Detail</p>
                        <h2 class="text-[17px] font-bold tracking-tight truncate" x-text="drill.row?.country || ''"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="drill.open = false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </div>

                <div class="p-5 space-y-4" x-show="drill.data">
                    {{-- KPI strip 4-up --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                        <div class="rpt-tile rpt-tile-brand p-3"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Travellers</p><p class="text-xl font-bold mt-0.5 font-mono" x-text="(drill.data?.kpi_strip?.travellers ?? 0).toLocaleString()"></p></div>
                        <div class="rpt-tile p-3" :class="'rpt-tile-' + alertTone(drill.data?.kpi_strip?.alerts)"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Alerts</p><p class="text-xl font-bold mt-0.5 font-mono" x-text="(drill.data?.kpi_strip?.alerts ?? 0).toLocaleString()"></p></div>
                        <div class="rpt-tile rpt-tile-info p-3"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Alert Rate</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.kpi_strip?.alert_rate === null || drill.data?.kpi_strip?.alert_rate === undefined ? '—' : (drill.data.kpi_strip.alert_rate + '%')"></p></div>
                        <div class="rpt-tile p-3" :class="drill.data?.kpi_strip?.endemic ? 'rpt-tile-warning' : 'rpt-tile-neutral'"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Endemic</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.kpi_strip?.endemic ? 'YES' : 'NO'"></p></div>
                    </div>

                    {{-- Country profile --}}
                    <details class="rpt-section" open>
                        <summary class="rpt-section-head"><span>Country Profile</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body grid grid-cols-2 sm:grid-cols-4 gap-3 text-[13px]">
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Code</p><p class="font-mono text-[11.5px]" x-text="drill.data?.country?.country_code || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Name</p><p x-text="drill.data?.country?.name || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">ISO α2</p><p class="font-mono" x-text="drill.data?.country?.iso_alpha2 || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">ISO α3</p><p class="font-mono" x-text="drill.data?.country?.iso_alpha3 || '—'"></p></div>
                        </div>
                    </details>

                    {{-- Spark + Endemicity --}}
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        <details class="rpt-section" open>
                            <summary class="rpt-section-head"><span>30-day Travellers</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                            <div class="rpt-section-body"><div class="relative h-[180px]"><canvas x-ref="drill_spark"></canvas></div></div>
                        </details>

                        <details class="rpt-section" open>
                            <summary class="rpt-section-head"><span>Endemicity Map <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.endemicity?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                            <div class="rpt-section-body space-y-1.5">
                                <template x-for="(e, i) in (drill.data?.endemicity ?? [])" :key="i">
                                    <div class="flex items-center justify-between border-t border-border/40 pt-2 first:border-t-0 first:pt-0">
                                        <div class="min-w-0">
                                            <p class="text-[13px] font-medium" x-text="e.display_name || e.disease_code"></p>
                                            <p class="text-[10px] font-mono text-muted-foreground" x-text="e.disease_code"></p>
                                        </div>
                                        <div class="flex flex-col items-end gap-1">
                                            <span class="badge badge-warning" x-text="e.endemicity_level"></span>
                                            <span class="text-[10px] text-muted-foreground" x-text="e.since_year ? ('since ' + e.since_year) : ''"></span>
                                        </div>
                                    </div>
                                </template>
                                <p x-show="!(drill.data?.endemicity?.length)" class="text-center text-muted-foreground py-3 text-[13px]">Not flagged endemic for any active disease.</p>
                            </div>
                        </details>
                    </div>

                    {{-- Travellers (latest 20) --}}
                    <details class="rpt-section">
                        <summary class="rpt-section-head"><span>Recent Travellers <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.travellers?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body">
                            <table class="w-full text-[12.5px]">
                                <thead class="bg-muted/40"><tr><th class="text-left px-2 py-1">Name</th><th class="text-left px-2 py-1">Sex/Age</th><th class="text-left px-2 py-1">Arrival</th><th class="text-left px-2 py-1">Mode</th><th class="text-left px-2 py-1">POE</th><th class="text-left px-2 py-1">Risk</th><th class="text-left px-2 py-1">Disposition</th></tr></thead>
                                <tbody>
                                    <template x-for="t in (drill.data?.travellers ?? [])" :key="t.id">
                                        <tr class="border-t border-border/40">
                                            <td class="px-2 py-1.5"><div x-text="t.name || '—'"></div><div class="text-[10px] font-mono text-muted-foreground" x-text="t.travel_document_number || ''"></div></td>
                                            <td class="px-2 py-1.5" x-text="(t.gender || '—') + ' · ' + (t.age || '—')"></td>
                                            <td class="px-2 py-1.5 text-[11px]" x-text="t.arrival_datetime ? new Date(t.arrival_datetime).toLocaleString() : '—'"></td>
                                            <td class="px-2 py-1.5" x-text="t.transport_mode || '—'"></td>
                                            <td class="px-2 py-1.5 font-mono text-[11.5px]" x-text="t.poe_code || '—'"></td>
                                            <td class="px-2 py-1.5"><span class="badge" :class="badgeForRisk(t.risk_level)" x-text="t.risk_level || '—'"></span></td>
                                            <td class="px-2 py-1.5 text-[11px]" x-text="t.final_disposition || '—'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <p x-show="!(drill.data?.travellers?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No travellers recorded from this country in window.</p>
                        </div>
                    </details>

                    {{-- Top diseases --}}
                    <details class="rpt-section">
                        <summary class="rpt-section-head"><span>Top Suspected Diseases <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.top_diseases?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body">
                            <table class="w-full text-[12.5px]">
                                <thead class="bg-muted/40"><tr><th class="text-left px-2 py-1">Disease</th><th class="text-right px-2 py-1">Count</th></tr></thead>
                                <tbody>
                                    <template x-for="d in (drill.data?.top_diseases ?? [])" :key="d.disease_code">
                                        <tr class="border-t border-border/40">
                                            <td class="px-2 py-1.5"><div x-text="d.display_name"></div><div class="text-[10px] font-mono text-muted-foreground" x-text="d.disease_code"></div></td>
                                            <td class="px-2 py-1.5 text-right font-mono font-bold" x-text="(d.count ?? 0).toLocaleString()"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <p x-show="!(drill.data?.top_diseases?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No suspected diseases recorded.</p>
                        </div>
                    </details>

                    {{-- Top POEs --}}
                    <details class="rpt-section">
                        <summary class="rpt-section-head"><span>Top Arrival POEs</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body">
                            <table class="w-full text-[12.5px]">
                                <thead class="bg-muted/40"><tr><th class="text-left px-2 py-1">POE</th><th class="text-right px-2 py-1">Travellers</th></tr></thead>
                                <tbody>
                                    <template x-for="p in (drill.data?.top_poes ?? [])" :key="p.poe_code">
                                        <tr class="border-t border-border/40">
                                            <td class="px-2 py-1.5 font-mono text-[11.5px]" x-text="p.poe_code"></td>
                                            <td class="px-2 py-1.5 text-right font-mono font-bold" x-text="(p.c ?? 0).toLocaleString()"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <p x-show="!(drill.data?.top_poes?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No POE arrivals recorded.</p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </template>

    {{-- ─────── EXPLAINER MODAL ─────── --}}
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
function rptCountryTravel() {
    return {
        tab: 'charts', // 'charts' | 'records'
        filters: { poe: '', start_date: '', end_date: '' },
        meta: {}, kpis: [], rows: [],
        pagination: { page: 1, per_page: 10, total: 0, total_pages: 1, from: 0, to: 0 },
        controls: { sort: 'travellers', dir: 'desc', q: '', cat: 'all' },
        search: '', cat: 'all',
        loading: false, filtersOpen: false,
        chartObjs: { origins: null, flow: null, drillSpark: null },
        chartData: { origins: null, flow: null },
        drill: { open: false, row: null, data: null },
        explainer: { open: false, key: null, spec: null },
        explainerSpecs: {
            origins: {
                title: 'Top Origin Countries',
                what: 'The top 10 origin countries (journey_start_country_code) by traveller count in the active window, with alerts overlaid.',
                how: 'Each row is a country. Bar length is travellers; the secondary bar is alerts originating from those screenings. A long traveller bar with a long alert bar means the country is producing meaningful signal — investigate.',
                decisions: ['Identify which transit corridors deliver the highest screening load.', 'Spot countries that produce alerts disproportionately to their volume.', 'Validate where to focus liaison and surveillance investment.'],
                source: 'secondary_screenings GROUP BY journey_start_country_code (scope-applied). Country names resolved via two-query whereIn against ref_countries to avoid cross-collation join. Alerts overlay JOINs alerts → secondary_screenings (same collation family — safe).',
                caveats: 'Based on secondary-screened travellers only — primary tier has no origin country. Origin codes that do not match ref_countries.country_code are shown by code rather than name.',
            },
            flow: {
                title: 'Endemic vs Non-endemic Flow · 30 days',
                what: 'Daily traveller count split by whether the origin country is flagged endemic for any active disease (ref_endemic_countries).',
                how: 'Each day is a stacked bar: warning band = endemic origins, success band = non-endemic. A rising endemic share is the early signal of imported-case risk.',
                decisions: ['Detect uptrends in endemic-country arrivals before alert volumes catch up.', 'Verify that targeted screening protocols are being applied to the endemic corridor.', 'Compare endemic flow over time to refine resource allocation.'],
                source: 'secondary_screenings GROUP BY DATE(opened_at), journey_start_country_code; endemic flag resolved via ref_endemic_countries with iso_alpha3/iso_alpha2 fallback against ref_countries.',
                caveats: 'Based on secondary-screened travellers only. Endemic resolution is per-country across ANY active disease — a country can be endemic for one and not another. Drill into a country for the disease-level breakdown.',
            },
        },

        async boot() {
            await this.loadMeta();
            await this.apply();
        },

        async loadMeta() {
            const r = await fetch('{{ url('/admin/reports/rpt-country-travel/meta') }}');
            if (!r.ok) return;
            const j = await r.json();
            this.meta = j.data || {};
        },

        async apply() {
            this.loading = true;
            await Promise.all([
                this.loadKpis(),
                this.loadChart('top_origins',      'origins'),
                this.loadChart('endemic_flow_30d', 'flow'),
                this.loadRecords(1),
            ]);
            this.loading = false;
        },
        resetFilters() { this.filters = { poe: '', start_date: '', end_date: '' }; this.search = ''; this.cat = 'all'; this.apply(); },
        qs() {
            const p = new URLSearchParams();
            for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v);
            return p.toString();
        },

        async loadKpis() {
            const r = await fetch('{{ url('/admin/reports/rpt-country-travel/kpis') }}?' + this.qs());
            if (!r.ok) return;
            const j = await r.json();
            this.kpis = j.data?.kpis || [];
        },

        async loadChart(key, target) {
            const r = await fetch('{{ url('/admin/reports/rpt-country-travel/chart') }}/' + key + '?' + this.qs());
            if (!r.ok) return;
            const j = await r.json();
            this.chartData[target] = j.data;
            this.renderChart(target, j.data);
        },

        renderChart(target, payload) {
            if (!payload || !this.$refs['chart_' + target]) return;
            if (this.chartObjs[target]) this.chartObjs[target].destroy();
            const isOrigins = target === 'origins';
            const palette = isOrigins ? ['#10b981', '#ef4444'] : ['#f59e0b', '#10b981'];
            const datasets = (payload.datasets || []).map((d, i) => ({
                label: d.label,
                data: d.data,
                backgroundColor: palette[i % palette.length] + 'cc',
                hoverBackgroundColor: palette[i % palette.length],
                borderRadius: 4,
                borderSkipped: false,
                stack: isOrigins ? undefined : 'flow',
            }));
            this.chartObjs[target] = new Chart(this.$refs['chart_' + target].getContext('2d'), {
                type: 'bar',
                data: { labels: payload.labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: isOrigins ? 'y' : 'x',
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: true, position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                        tooltip: { backgroundColor: 'rgba(15,23,42,.95)', padding: 10, cornerRadius: 8 },
                    },
                    scales: isOrigins
                        ? { x: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } }, y: { grid: { display: false }, ticks: { font: { size: 11 } } } }
                        : { x: { stacked: true, grid: { display: false }, ticks: { font: { size: 9 }, maxTicksLimit: 12 } }, y: { stacked: true, beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } } },
                },
            });
        },

        async loadRecords(page) {
            this.loading = true;
            const p = new URLSearchParams({ page, q: this.search, sort: this.controls.sort, dir: this.controls.dir, cat: this.cat });
            for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v);
            const r = await fetch('{{ url('/admin/reports/rpt-country-travel/records') }}?' + p.toString());
            if (!r.ok) { this.loading = false; return; }
            const j = await r.json();
            this.rows = j.data?.rows || [];
            this.pagination = j.data?.pagination || this.pagination;
            this.controls = j.data?.controls || this.controls;
            this.loading = false;
        },
        setCat(c) { this.cat = c; this.loadRecords(1); },
        toggleSort(col) {
            if (this.controls.sort === col) {
                this.controls.dir = this.controls.dir === 'asc' ? 'desc' : 'asc';
            } else {
                this.controls.sort = col; this.controls.dir = 'desc';
            }
            this.loadRecords(1);
        },
        sortIcon(col) {
            if (this.controls.sort !== col) return '<svg class="h-3 w-3 text-muted-foreground/50" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 10l5-5 5 5M7 14l5 5 5-5"/></svg>';
            return this.controls.dir === 'asc'
                ? '<svg class="h-3 w-3 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 14l5-5 5 5"/></svg>'
                : '<svg class="h-3 w-3 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M7 10l5 5 5-5"/></svg>';
        },
        pageCluster() {
            const p = this.pagination.page, total = this.pagination.total_pages;
            const out = []; const start = Math.max(1, p - 2), end = Math.min(total, p + 2);
            for (let i = start; i <= end; i++) out.push(i);
            return out;
        },

        exportChart(target, fmt) {
            if (fmt === 'png') {
                const c = this.chartObjs[target];
                if (!c) return;
                const a = document.createElement('a');
                a.href = c.toBase64Image('image/png', 1);
                a.download = 'rpt-country-travel__' + target + '__' + this.stamp() + '.png';
                a.click();
            } else {
                const map = { origins: 'top_origins', flow: 'endemic_flow_30d' };
                window.location.href = '{{ url('/admin/reports/rpt-country-travel/chart') }}/' + map[target] + '/csv?' + this.qs();
            }
        },
        stamp() { const d = new Date(); const p = n => String(n).padStart(2, '0'); return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()); },

        openExplainer(key) {
            this.explainer.open = true;
            this.explainer.key = key;
            this.explainer.spec = this.explainerSpecs[key] || null;
        },
        explainerHeaders() { const k = this.explainer.key; return (k && this.chartData[k]?.csv_headers) || []; },
        explainerRows()    { const k = this.explainer.key; return (k && this.chartData[k]?.csv_rows)    || []; },

        async openDrill(row) {
            this.drill.open = true; this.drill.row = row; this.drill.data = null;
            const r = await fetch('{{ url('/admin/reports/rpt-country-travel/records') }}/' + encodeURIComponent(row.country_code) + '?' + this.qs());
            if (!r.ok) return;
            const j = await r.json();
            this.drill.data = j.data;
            this.$nextTick(() => this.renderDrillSpark());
        },

        renderDrillSpark() {
            const ref = this.$refs['drill_spark']; if (!ref) return;
            if (this.chartObjs.drillSpark) this.chartObjs.drillSpark.destroy();
            const spark = this.drill.data?.sparkline || [];
            this.chartObjs.drillSpark = new Chart(ref.getContext('2d'), {
                type: 'line',
                data: {
                    labels: spark.map(p => p.date.slice(5)),
                    datasets: [{ data: spark.map(p => p.count), borderColor: '#10b981', backgroundColor: '#10b98122', fill: true, tension: 0.35, borderWidth: 2, pointRadius: 1 }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 9 }, maxTicksLimit: 8 } },
                        y: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 10 } } },
                    },
                },
            });
        },

        badgeForRisk(r) { return { LOW: 'badge-low', MEDIUM: 'badge-medium', HIGH: 'badge-high', CRITICAL: 'badge-critical' }[r] || 'badge-secondary'; },
        alertTone(n) { n = n || 0; if (n > 50) return 'critical'; if (n > 10) return 'danger'; if (n > 0) return 'warning'; return 'neutral'; },
    };
}
</script>
@endsection
