@extends('admin.layout')

@section('crumb', 'Reports')
@section('title', 'Gender Analytics')

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
    .rpt-th-sort { @apply inline-flex items-center gap-1 cursor-pointer select-none; }
    .rpt-th-sort:hover { @apply text-foreground; }
    .rpt-skel { @apply animate-pulse bg-muted/60 rounded; }
</style>
@endpush

@section('content')
<div x-data="rptGender()" x-init="boot()" class="space-y-4">

    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-info/80">Screening Analytics · R2</p>
            <h1 class="text-[22px] font-bold tracking-tight">Gender Analytics</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Who is being screened — gender mix, trend, and POE-level disparity.</p>
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

    <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @foreach ([
            ['target' => 'overTime', 'serverKey' => 'gender_over_time', 'title' => 'Gender Mix Over Time', 'subtitle' => 'Daily count by gender — primary tier'],
            ['target' => 'byPoe',    'serverKey' => 'gender_by_poe',    'title' => 'Gender Mix by POE',     'subtitle' => 'Top 10 POEs · stacked breakdown'],
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

    <section class="card overflow-hidden">
        <div class="flex flex-col gap-3 p-4 border-b border-border/60">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-[14px] font-semibold tracking-tight">POE Gender Breakdown</h3>
                    <p class="text-[12px] text-muted-foreground">Click any row for the full POE gender profile.</p>
                </div>
                <span class="text-[11px] font-mono text-muted-foreground" x-text="(pagination.total ?? 0).toLocaleString() + ' POEs'"></span>
            </div>
            <div class="relative max-w-md">
                <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="search" placeholder="Search POE..." class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-sm" x-model.debounce.300ms="search" @input="loadRecords(1)">
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead class="table-head sticky top-0">
                    <tr>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('poe_name')">POE <span x-html="sortIcon('poe_name')"></span></span></th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('total')">Total <span x-html="sortIcon('total')"></span></span></th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('male')">Male <span x-html="sortIcon('male')"></span></span></th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('female')">Female <span x-html="sortIcon('female')"></span></span></th>
                        <th class="table-head-th text-right">Other</th>
                        <th class="table-head-th text-right">Unknown</th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('female_pct')">Female % <span x-html="sortIcon('female_pct')"></span></span></th>
                    </tr>
                </thead>
                <tbody class="table-body">
                    <template x-if="loading">
                        <template x-for="i in 10" :key="i">
                            <tr class="border-b border-border/40">
                                <td class="table-cell"><div class="rpt-skel h-4 w-40"></div><div class="rpt-skel h-3 w-24 mt-1.5"></div></td>
                                <td class="table-cell text-right"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell text-right"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell text-right"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell text-right"><div class="rpt-skel h-4 w-10 ml-auto"></div></td>
                                <td class="table-cell text-right"><div class="rpt-skel h-4 w-10 ml-auto"></div></td>
                                <td class="table-cell text-right"><div class="rpt-skel h-5 w-14 ml-auto"></div></td>
                            </tr>
                        </template>
                    </template>
                    <template x-if="!loading">
                        <template x-for="r in rows" :key="r.poe_code">
                            <tr class="table-row cursor-pointer" @click="openDrill(r.poe_code)">
                                <td class="table-cell"><div class="font-medium" x-text="r.poe_name"></div><div class="text-[11px] text-muted-foreground font-mono" x-text="r.poe_code"></div></td>
                                <td class="table-cell text-right font-mono" x-text="r.total.toLocaleString()"></td>
                                <td class="table-cell text-right font-mono" x-text="r.male.toLocaleString()"></td>
                                <td class="table-cell text-right font-mono" x-text="r.female.toLocaleString()"></td>
                                <td class="table-cell text-right font-mono text-muted-foreground" x-text="r.other.toLocaleString()"></td>
                                <td class="table-cell text-right font-mono text-muted-foreground" x-text="r.unknown.toLocaleString()"></td>
                                <td class="table-cell text-right"><span class="badge" :class="r.female_pct === null ? 'badge-secondary' : 'badge-info'" x-text="r.female_pct === null ? '—' : (r.female_pct + '%')"></span></td>
                            </tr>
                        </template>
                    </template>
                    <tr x-show="!loading && !rows.length"><td colspan="7" class="table-cell py-12 text-center text-muted-foreground">No screening activity matches the current filters.</td></tr>
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
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-info/80">POE Gender Profile</p>
                        <h2 class="text-[17px] font-bold tracking-tight truncate" x-text="drill.data?.poe?.name || drill.poe || ''"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="drill.open = false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </div>
                <div class="p-5 space-y-4" x-show="drill.data">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                        <div class="rpt-tile rpt-tile-brand p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Primary Total</p><p class="text-xl font-bold mt-0.5" x-text="(drill.data?.primary?.total ?? 0).toLocaleString()"></p></div>
                        <div class="rpt-tile rpt-tile-info p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Secondary Total</p><p class="text-xl font-bold mt-0.5" x-text="(drill.data?.secondary?.total ?? 0).toLocaleString()"></p></div>
                        <div class="rpt-tile rpt-tile-success p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Female %</p><p class="text-xl font-bold mt-0.5" x-text="femalePct(drill.data?.primary)"></p></div>
                        <div class="rpt-tile rpt-tile-neutral p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Border</p><p class="text-[14px] font-semibold mt-1" x-text="drill.data?.poe?.border_country || '—'"></p></div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-3">
                        <details class="rpt-section" open>
                            <summary class="rpt-section-head"><span>Primary Gender Mix</span></summary>
                            <div class="rpt-section-body"><div class="relative h-[180px]"><canvas x-ref="d_primary"></canvas></div></div>
                        </details>
                        <details class="rpt-section" open>
                            <summary class="rpt-section-head"><span>Secondary Gender Mix</span></summary>
                            <div class="rpt-section-body"><div class="relative h-[180px]"><canvas x-ref="d_secondary"></canvas></div></div>
                        </details>
                        <details class="rpt-section" open>
                            <summary class="rpt-section-head"><span>14-day Trend (M vs F)</span></summary>
                            <div class="rpt-section-body"><div class="relative h-[180px]"><canvas x-ref="d_spark"></canvas></div></div>
                        </details>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        <details class="rpt-section" open>
                            <summary class="rpt-section-head"><span>Gender × Direction</span></summary>
                            <div class="rpt-section-body">
                                <table class="w-full text-[13px]">
                                    <thead class="text-[10px] uppercase tracking-wider text-muted-foreground"><tr><th class="text-left py-1.5">Direction</th><th class="text-right py-1.5">Male</th><th class="text-right py-1.5">Female</th></tr></thead>
                                    <tbody>
                                        <template x-for="g in (drill.data?.direction_grid ?? [])" :key="g.direction">
                                            <tr class="border-t border-border/40"><td class="py-1.5" x-text="g.direction"></td><td class="py-1.5 text-right font-mono" x-text="g.male.toLocaleString()"></td><td class="py-1.5 text-right font-mono" x-text="g.female.toLocaleString()"></td></tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                        <details class="rpt-section" open>
                            <summary class="rpt-section-head"><span>Gender × Risk (secondary)</span></summary>
                            <div class="rpt-section-body">
                                <table class="w-full text-[13px]">
                                    <thead class="text-[10px] uppercase tracking-wider text-muted-foreground"><tr><th class="text-left py-1.5">Bucket</th><th class="text-right py-1.5">Male</th><th class="text-right py-1.5">Female</th></tr></thead>
                                    <tbody>
                                        <template x-for="g in (drill.data?.risk_grid ?? [])" :key="g.bucket">
                                            <tr class="border-t border-border/40"><td class="py-1.5" x-text="g.bucket"></td><td class="py-1.5 text-right font-mono" x-text="g.male.toLocaleString()"></td><td class="py-1.5 text-right font-mono" x-text="g.female.toLocaleString()"></td></tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    </div>

                    <details class="rpt-section">
                        <summary class="rpt-section-head"><span>Recent Travellers <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.recent?.length ?? 0) + ')'"></span></span></summary>
                        <div class="rpt-section-body">
                            <table class="w-full text-[13px]">
                                <thead class="text-[10px] uppercase tracking-wider text-muted-foreground"><tr><th class="text-left py-1.5">Traveller</th><th class="text-left py-1.5">Gender</th><th class="text-left py-1.5">Direction</th><th class="text-left py-1.5">Symptoms</th><th class="text-left py-1.5">Captured</th></tr></thead>
                                <tbody>
                                    <template x-for="(r, i) in (drill.data?.recent ?? [])" :key="i">
                                        <tr class="border-t border-border/40"><td class="py-1.5 font-medium" x-text="r.traveler_full_name || '—'"></td><td class="py-1.5" x-text="r.gender"></td><td class="py-1.5" x-text="r.traveler_direction"></td><td class="py-1.5" x-text="r.symptoms_present ? 'Yes' : 'No'"></td><td class="py-1.5 text-muted-foreground" x-text="new Date(r.captured_at).toLocaleString()"></td></tr>
                                    </template>
                                    <tr x-show="!(drill.data?.recent?.length)"><td colspan="5" class="py-3 text-center text-muted-foreground">No recent screenings.</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </details>

                    @include('admin.reports.v2._related_views', ['type' => 'poe'])
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
function rptGender() {
    return {
        filters: { poe: '', start_date: '', end_date: '' },
        meta: {}, kpis: [], rows: [],
        pagination: { page: 1, per_page: 10, total: 0, total_pages: 1, from: 0, to: 0 },
        controls: { sort: 'total', dir: 'desc', q: '' },
        search: '', window_label: '', loading: false, filtersOpen: false,
        chartObjs: { overTime: null, byPoe: null, dPrimary: null, dSecondary: null, dSpark: null },
        chartData: { overTime: null, byPoe: null },
        drill: { open: false, poe: null, data: null },
        explainer: { open: false, key: null, spec: null },
        explainerSpecs: {
            overTime: {
                title: 'Gender Mix Over Time',
                what: 'Daily count of travellers screened in the window, split by gender (Male / Female / Other / Unknown). Stacked area.',
                how: 'X-axis is date; the height of each colour band is the count for that gender on that day. A widening Other/Unknown band suggests deteriorating data quality.',
                decisions: ['Detect demographic shifts (e.g. female travellers spiking).', 'Spot data-quality issues if Unknown/Other grow.', 'Compare current period against expected baseline.'],
                source: 'primary_screenings GROUP BY DATE(captured_at), gender (record_status=COMPLETED).',
                caveats: 'Only primary tier; secondary-tier gender lives in secondary_screenings.traveler_gender.',
            },
            byPoe: {
                title: 'Gender Mix by POE',
                what: 'Top 10 POEs by total screening volume, with each bar broken down by gender. Stacked bars.',
                how: 'POEs are ranked by total height. Within each bar, the relative size of each colour is the gender share at that POE. Compare the bars to spot disparity.',
                decisions: ['Identify POEs with skewed gender access.', 'Direct gender-sensitive resourcing (privacy spaces, female officers).', 'Spot outlier POEs warranting audit.'],
                source: 'primary_screenings GROUP BY poe_code, gender ORDER BY COUNT(*) DESC LIMIT 10.',
                caveats: 'Only the top 10 POEs by total volume are charted — smaller POEs roll up in the records table.',
            },
        },

        async boot() { await this.loadMeta(); await this.apply(); },
        async loadMeta() { const r = await fetch('{{ url('/admin/reports/rpt-gender/meta') }}'); const j = await r.json(); this.meta = j.data || {}; },
        async apply() { this.loading = true; await Promise.all([this.loadKpis(), this.loadChart('gender_over_time', 'overTime'), this.loadChart('gender_by_poe', 'byPoe'), this.loadRecords(1)]); this.loading = false; },
        resetFilters() { this.filters = { poe: '', start_date: '', end_date: '' }; this.search = ''; this.apply(); },
        qs() { const p = new URLSearchParams(); for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v); return p.toString(); },
        async loadKpis() { const r = await fetch('{{ url('/admin/reports/rpt-gender/kpis') }}?' + this.qs()); const j = await r.json(); this.kpis = j.data?.kpis || []; this.window_label = j.data?.window?.label || ''; },
        async loadChart(key, target) { const r = await fetch('{{ url('/admin/reports/rpt-gender/chart') }}/' + key + '?' + this.qs()); const j = await r.json(); this.chartData[target] = j.data; this.renderChart(target, j.data); },

        renderChart(target, payload) {
            if (!payload || !this.$refs['chart_' + target]) return;
            if (this.chartObjs[target]) this.chartObjs[target].destroy();
            const isStackedArea = target === 'overTime';
            const isStackedBar  = target === 'byPoe';
            const palette = ['#3b82f6', '#ec4899', '#8b5cf6', '#94a3b8'];
            const datasets = (payload.datasets || []).map((d, i) => ({
                label: d.label, data: d.data,
                borderColor: palette[i], backgroundColor: palette[i] + (isStackedArea ? '99' : 'cc'),
                borderWidth: isStackedArea ? 1.5 : 0, fill: isStackedArea, tension: 0.35, pointRadius: 0,
                stack: 'gender', borderRadius: isStackedBar ? 4 : 0,
            }));
            this.chartObjs[target] = new Chart(this.$refs['chart_' + target].getContext('2d'), {
                type: isStackedArea ? 'line' : 'bar',
                data: { labels: payload.labels, datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: isStackedBar ? 'y' : 'x',
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } }, tooltip: { backgroundColor: 'rgba(15,23,42,.95)', padding: 10, cornerRadius: 8 } },
                    scales: {
                        x: { stacked: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } },
                        y: { stacked: true, beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } },
                    },
                },
            });
        },

        async loadRecords(page) {
            this.loading = true;
            const p = new URLSearchParams({ page, q: this.search, sort: this.controls.sort, dir: this.controls.dir });
            for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v);
            const r = await fetch('{{ url('/admin/reports/rpt-gender/records') }}?' + p.toString());
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
            if (fmt === 'png') { const c = this.chartObjs[target]; if (!c) return; const a = document.createElement('a'); a.href = c.toBase64Image('image/png', 1); a.download = 'rpt-gender__' + target + '__' + this.stamp() + '.png'; a.click(); }
            else { const map = { overTime: 'gender_over_time', byPoe: 'gender_by_poe' }; window.location.href = '{{ url('/admin/reports/rpt-gender/chart') }}/' + map[target] + '/csv?' + this.qs(); }
        },
        stamp() { const d = new Date(); const p = n => String(n).padStart(2, '0'); return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()); },

        openExplainer(key) { this.explainer.open = true; this.explainer.key = key; this.explainer.spec = this.explainerSpecs[key] || null; },
        explainerHeaders() { const k = this.explainer.key; return (k && this.chartData[k]?.csv_headers) || []; },
        explainerRows()    { const k = this.explainer.key; return (k && this.chartData[k]?.csv_rows)    || []; },

        async openDrill(poe) {
            this.drill.open = true; this.drill.poe = poe; this.drill.data = null;
            const r = await fetch('{{ url('/admin/reports/rpt-gender/records') }}/' + encodeURIComponent(poe) + '?' + this.qs());
            const j = await r.json(); this.drill.data = j.data;
            this.$nextTick(() => this.renderDrillCharts());
        },
        renderDrillCharts() {
            const palette = ['#3b82f6', '#ec4899', '#8b5cf6', '#94a3b8'];
            const p = this.drill.data?.primary || {};
            this._mountChart('d_primary', 'dPrimary', { type: 'doughnut', data: { labels: ['Male', 'Female', 'Other', 'Unknown'], datasets: [{ data: [p.MALE || 0, p.FEMALE || 0, p.OTHER || 0, p.UNKNOWN || 0], backgroundColor: palette, borderWidth: 0 }] }, opts: { cutout: '60%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 10 } } } } });
            const s = this.drill.data?.secondary || {};
            this._mountChart('d_secondary', 'dSecondary', { type: 'doughnut', data: { labels: ['Male', 'Female', 'Other', 'Unknown'], datasets: [{ data: [s.MALE || 0, s.FEMALE || 0, s.OTHER || 0, s.UNKNOWN || 0], backgroundColor: palette, borderWidth: 0 }] }, opts: { cutout: '60%', plugins: { legend: { position: 'bottom', labels: { font: { size: 10 }, boxWidth: 10 } } } } });
            const sp = this.drill.data?.sparkline || [];
            this._mountChart('d_spark', 'dSpark', { type: 'line', data: { labels: sp.map(p => p.date.slice(5)), datasets: [{ label: 'Male', data: sp.map(p => p.male), borderColor: '#3b82f6', backgroundColor: '#3b82f622', fill: true, tension: 0.35, borderWidth: 2, pointRadius: 1 }, { label: 'Female', data: sp.map(p => p.female), borderColor: '#ec4899', backgroundColor: '#ec489922', fill: true, tension: 0.35, borderWidth: 2, pointRadius: 1 }] }, opts: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }, scales: { x: { grid: { display: false }, ticks: { font: { size: 9 } } }, y: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 10 } } } } } });
        },
        _mountChart(refKey, slot, cfg) {
            const ref = this.$refs[refKey]; if (!ref) return;
            if (this.chartObjs[slot]) this.chartObjs[slot].destroy();
            this.chartObjs[slot] = new Chart(ref.getContext('2d'), { type: cfg.type, data: cfg.data, options: { responsive: true, maintainAspectRatio: false, ...(cfg.opts || {}) } });
        },
        femalePct(p) { if (!p) return '—'; const m = p.MALE || 0, f = p.FEMALE || 0; return (m + f) > 0 ? Math.round((f / (m + f)) * 1000) / 10 + '%' : '—'; },
    };
}
</script>
@endsection
