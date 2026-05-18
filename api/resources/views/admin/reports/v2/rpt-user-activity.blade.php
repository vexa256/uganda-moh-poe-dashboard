@extends('admin.layout')

@section('crumb', 'Reports')
@section('title', 'User Activity')

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
</style>
@endpush

@section('content')
<div x-data="rptUserActivity()" x-init="boot()" class="space-y-4">

    {{-- ─────── HEADER ─────── --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-brand">Executive Overview · R8</p>
            <h1 class="text-[22px] font-bold tracking-tight">User Activity</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Who is actually doing the work, who is silently inactive, and is the workforce healthy.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span x-show="!meta?.can_name" x-cloak class="rpt-chip rpt-chip-idle" title="Officer names are masked at your scope.">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></svg>
                PII masked
            </span>
            <button class="btn btn-outline btn-xs" @click="filtersOpen = !filtersOpen" x-text="filtersOpen ? 'Hide Filters' : 'Show Filters'"></button>
        </div>
    </section>

    {{-- ─────── FILTERS (collapsed by default) ─────── --}}
    <section class="card" x-show="filtersOpen" x-cloak>
        <div class="card-content py-4">
            <div class="grid grid-cols-1 sm:grid-cols-5 gap-3">
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Point of Entry</label>
                    <select class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm" x-model="filters.poe">
                        <option value="">All POEs</option>
                        <template x-for="(name, code) in (meta.poes || {})" :key="code"><option :value="code" x-text="name"></option></template>
                    </select>
                </div>
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Role</label>
                    <select class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm" x-model="filters.role_key">
                        <option value="">All roles</option>
                        <template x-for="r in (meta.roles || [])" :key="r"><option :value="r" x-text="r"></option></template>
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
        <button type="button" class="px-4 py-2 text-[13px] font-semibold border-b-2 transition-colors" :class="tab === 'records' ? 'border-brand text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground'" @click="tab = 'records'">Officer Index <span class="ml-1 font-mono opacity-60 text-[11px]" x-text="pagination.total ? `(${pagination.total})` : ''"></span></button>
    </div>

    {{-- ─────── CHARTS · 2 col-6 ─────── --}}
    <section x-show="tab === 'charts'" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @foreach ([
            ['target' => 'role',    'serverKey' => 'output_by_role',   'title' => 'Output by Role',           'subtitle' => 'Top 10 roles by total screenings + alerts handled + followups in window.'],
            ['target' => 'heatmap', 'serverKey' => 'activity_heatmap', 'title' => 'Login Activity · Day × Hour', 'subtitle' => 'Successful logins by day-of-week and hour-of-day across officers in scope.'],
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

    {{-- ─────── PREMIUM TABLE · Officer Activity Index ─────── --}}
    <section x-show="tab === 'records'" class="card overflow-hidden">
        <div class="flex flex-col gap-3 p-4 border-b border-border/60">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-[14px] font-semibold tracking-tight">Officer Activity Index</h3>
                    <p class="text-[12px] text-muted-foreground">Click any row for the officer drill-down. Names are masked unless you have national-level access.</p>
                </div>
                <span class="text-[11px] font-mono text-muted-foreground whitespace-nowrap" x-text="(pagination.total ?? 0).toLocaleString() + ' officers'"></span>
            </div>
            <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                <div class="relative flex-1 max-w-md">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                    <input type="search" placeholder="Search name, username, or role..." class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-sm" x-model.debounce.300ms="search" @input="loadRecords(1)">
                </div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="rpt-chip" :class="status === 'all' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setStatus('all')">All</span>
                    <span class="rpt-chip" :class="status === 'active' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setStatus('active')">Active</span>
                    <span class="rpt-chip" :class="status === 'dormant' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setStatus('dormant')">Dormant</span>
                    <span class="rpt-chip" :class="status === 'locked' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setStatus('locked')">Locked</span>
                    <span class="rpt-chip" :class="status === 'flagged' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setStatus('flagged')">Flagged</span>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead class="table-head sticky top-0">
                    <tr>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('name')">Officer <span x-html="sortIcon('name')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('role_key')">Role <span x-html="sortIcon('role_key')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('account_status')">Status <span x-html="sortIcon('account_status')"></span></span></th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('screenings')">Screenings <span x-html="sortIcon('screenings')"></span></span></th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('alerts_')">Alerts <span x-html="sortIcon('alerts_')"></span></span></th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('followups')">Followups <span x-html="sortIcon('followups')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('training')">Training <span x-html="sortIcon('training')"></span></span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('last_act')">Last Activity <span x-html="sortIcon('last_act')"></span></span></th>
                    </tr>
                </thead>
                <tbody class="table-body">
                    <template x-if="loading">
                        <template x-for="i in 10" :key="i">
                            <tr class="border-b border-border/40">
                                <td class="table-cell"><div class="rpt-skel h-4 w-40"></div><div class="rpt-skel h-3 w-24 mt-1.5"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-20"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-5 w-16"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-5 w-16"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-24"></div></td>
                            </tr>
                        </template>
                    </template>
                    <template x-if="!loading">
                        <template x-for="r in rows" :key="r.id">
                            <tr class="table-row cursor-pointer" @click="openDrill(r)">
                                <td class="table-cell">
                                    <div class="font-medium" x-text="r.name"></div>
                                    <div class="text-[11px] font-mono text-muted-foreground" x-text="'#' + r.id + (r.risk_score ? ' · risk ' + r.risk_score : '')"></div>
                                </td>
                                <td class="table-cell text-[12px]" x-text="r.role_key || '—'"></td>
                                <td class="table-cell"><span class="badge" :class="badgeForStatus(r.account_status)" x-text="r.account_status"></span></td>
                                <td class="table-cell text-right font-mono" x-text="(r.screenings ?? 0).toLocaleString()"></td>
                                <td class="table-cell text-right font-mono" x-text="(r.alerts_ ?? 0).toLocaleString()"></td>
                                <td class="table-cell text-right font-mono" x-text="(r.followups ?? 0).toLocaleString()"></td>
                                <td class="table-cell"><span class="badge" :class="badgeForTraining(r.training)" x-text="r.training"></span></td>
                                <td class="table-cell text-[12px] text-muted-foreground" x-text="r.last_act ? new Date(r.last_act).toLocaleDateString() : 'Never'"></td>
                            </tr>
                        </template>
                    </template>
                    <tr x-show="!loading && !rows.length"><td colspan="8" class="table-cell py-12 text-center text-muted-foreground">No officers match the current filter.</td></tr>
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
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-brand">Officer Detail</p>
                        <h2 class="text-[17px] font-bold tracking-tight truncate" x-text="drill.row?.name || ''"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="drill.open = false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </div>

                <div class="p-5 space-y-4" x-show="drill.data">
                    {{-- KPI strip 4-up --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                        <div class="rpt-tile rpt-tile-brand p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Screenings 30d</p><p class="text-xl font-bold mt-0.5 font-mono" x-text="(drill.data?.kpi_strip?.screenings_30d ?? 0).toLocaleString()"></p></div>
                        <div class="rpt-tile rpt-tile-info p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Alerts 30d</p><p class="text-xl font-bold mt-0.5 font-mono" x-text="(drill.data?.kpi_strip?.alerts_30d ?? 0).toLocaleString()"></p></div>
                        <div class="rpt-tile rpt-tile-success p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Followups 30d</p><p class="text-xl font-bold mt-0.5 font-mono" x-text="(drill.data?.kpi_strip?.followups_30d ?? 0).toLocaleString()"></p></div>
                        <div class="rpt-tile p-3" :class="'rpt-tile-' + riskTone(drill.data?.kpi_strip?.risk_score)"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Risk Score</p><p class="text-xl font-bold mt-0.5 font-mono" x-text="(drill.data?.kpi_strip?.risk_score ?? 0)"></p></div>
                    </div>

                    {{-- Officer Profile --}}
                    <details class="rpt-section" open>
                        <summary class="rpt-section-head"><span>Officer Profile</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 gap-3 text-[13px]">
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Name</p><p x-text="drill.data?.user?.name || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Username</p><p class="font-mono text-[11.5px]" x-text="drill.data?.user?.username || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Role</p><p x-text="drill.data?.user?.role_key || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Account Type</p><p x-text="drill.data?.user?.account_type || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Status</p><p><span class="badge" :class="badgeForStatus(drill.data?.user?.account_status)" x-text="drill.data?.user?.account_status"></span></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Country</p><p x-text="drill.data?.user?.country_code || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Last Login</p><p x-text="drill.data?.user?.last_login_at ? new Date(drill.data.user.last_login_at).toLocaleString() : 'Never'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Last Activity</p><p x-text="drill.data?.user?.last_activity_at ? new Date(drill.data.user.last_activity_at).toLocaleString() : 'Never'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Failed Logins</p><p class="font-mono" x-text="(drill.data?.user?.failed_login_count ?? 0)"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Locked Until</p><p x-text="drill.data?.user?.locked_until ? new Date(drill.data.user.locked_until).toLocaleString() : '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Phone</p><p class="font-mono text-[11.5px]" x-text="drill.data?.user?.phone || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Email</p><p class="font-mono text-[11.5px]" x-text="drill.data?.user?.email || '—'"></p></div>
                        </div>
                    </details>

                    {{-- Output sparkline + Anomaly flags side-by-side --}}
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        <details class="rpt-section" open>
                            <summary class="rpt-section-head"><span>30-day Output</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                            <div class="rpt-section-body"><div class="relative h-[180px]"><canvas x-ref="drill_spark"></canvas></div></div>
                        </details>

                        <details class="rpt-section" open>
                            <summary class="rpt-section-head"><span>Anomaly Flags <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.flags?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                            <div class="rpt-section-body space-y-1.5">
                                <template x-for="(f, i) in (drill.data?.flags ?? [])" :key="i">
                                    <div class="flex items-center justify-between border-t border-border/40 pt-2 first:border-t-0 first:pt-0">
                                        <div class="min-w-0">
                                            <p class="text-[13px] font-medium"><span class="badge" :class="badgeForSeverity(f.severity)" x-text="f.severity"></span> <span x-text="f.flag_code"></span></p>
                                            <p class="text-[11px] text-muted-foreground" x-text="(f.first_seen_at ? new Date(f.first_seen_at).toLocaleDateString() : '—') + ' → ' + (f.last_seen_at ? new Date(f.last_seen_at).toLocaleDateString() : '—')"></p>
                                        </div>
                                    </div>
                                </template>
                                <p x-show="!(drill.data?.flags?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No open anomaly flags.</p>
                            </div>
                        </details>
                    </div>

                    {{-- Assignments --}}
                    <details class="rpt-section">
                        <summary class="rpt-section-head"><span>Assignments <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.assignments?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body">
                            <table class="w-full text-[12.5px]">
                                <thead class="bg-muted/40"><tr><th class="text-left px-2 py-1">Country</th><th class="text-left px-2 py-1">Province</th><th class="text-left px-2 py-1">District</th><th class="text-left px-2 py-1">POE</th><th class="text-left px-2 py-1">Active</th><th class="text-left px-2 py-1">From</th><th class="text-left px-2 py-1">To</th></tr></thead>
                                <tbody>
                                    <template x-for="a in (drill.data?.assignments ?? [])" :key="a.id">
                                        <tr class="border-t border-border/40">
                                            <td class="px-2 py-1.5" x-text="a.country_code || '—'"></td>
                                            <td class="px-2 py-1.5" x-text="a.province_code || '—'"></td>
                                            <td class="px-2 py-1.5" x-text="a.district_code || '—'"></td>
                                            <td class="px-2 py-1.5 font-mono text-[11.5px]" x-text="a.poe_code || '—'"></td>
                                            <td class="px-2 py-1.5"><span class="badge" :class="a.is_active ? 'badge-success' : 'badge-secondary'" x-text="a.is_active ? 'YES' : 'NO'"></span> <span x-show="a.is_primary" class="badge badge-info">PRIMARY</span></td>
                                            <td class="px-2 py-1.5 text-[11px] text-muted-foreground" x-text="a.starts_at ? new Date(a.starts_at).toLocaleDateString() : '—'"></td>
                                            <td class="px-2 py-1.5 text-[11px] text-muted-foreground" x-text="a.ends_at ? new Date(a.ends_at).toLocaleDateString() : '—'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <p x-show="!(drill.data?.assignments?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No assignments on file.</p>
                        </div>
                    </details>

                    {{-- Training --}}
                    <details class="rpt-section">
                        <summary class="rpt-section-head"><span>Training Ledger <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.training?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body">
                            <table class="w-full text-[12.5px]">
                                <thead class="bg-muted/40"><tr><th class="text-left px-2 py-1">Title</th><th class="text-left px-2 py-1">Domain</th><th class="text-left px-2 py-1">Provider</th><th class="text-left px-2 py-1">Completed</th><th class="text-left px-2 py-1">Expires</th><th class="text-left px-2 py-1">Status</th></tr></thead>
                                <tbody>
                                    <template x-for="t in (drill.data?.training ?? [])" :key="t.training_code + (t.completed_on || '')">
                                        <tr class="border-t border-border/40">
                                            <td class="px-2 py-1.5"><div x-text="t.training_title"></div><div class="text-[10px] font-mono text-muted-foreground" x-text="t.training_code"></div></td>
                                            <td class="px-2 py-1.5" x-text="t.competency_domain"></td>
                                            <td class="px-2 py-1.5 text-[11px]" x-text="t.provider || '—'"></td>
                                            <td class="px-2 py-1.5 text-[11px] text-muted-foreground" x-text="t.completed_on ? new Date(t.completed_on).toLocaleDateString() : '—'"></td>
                                            <td class="px-2 py-1.5 text-[11px] text-muted-foreground" x-text="t.expires_on ? new Date(t.expires_on).toLocaleDateString() : '—'"></td>
                                            <td class="px-2 py-1.5"><span class="badge" :class="badgeForTraining(t.status)" x-text="t.status"></span></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                            <p x-show="!(drill.data?.training?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No training records on file.</p>
                        </div>
                    </details>

                    {{-- Auth events / summary --}}
                    <details class="rpt-section">
                        <summary class="rpt-section-head"><span>Authentication</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body">
                            <template x-if="drill.data?.auth?.mode === 'events'">
                                <div class="space-y-1.5">
                                    <template x-for="(e, i) in (drill.data?.auth?.events ?? [])" :key="i">
                                        <div class="flex items-center justify-between border-t border-border/40 pt-2 first:border-t-0 first:pt-0">
                                            <div class="min-w-0">
                                                <p class="text-[13px] font-medium"><span class="badge" :class="e.event_type === 'LOGIN_OK' ? 'badge-success' : 'badge-warning'" x-text="e.event_type"></span> <span class="font-mono text-[11.5px]" x-text="e.ip || '—'"></span></p>
                                                <p class="text-[10px] text-muted-foreground truncate max-w-[640px]" x-text="e.user_agent || '—'"></p>
                                            </div>
                                            <p class="text-[11px] text-muted-foreground" x-text="e.created_at ? new Date(e.created_at).toLocaleString() : '—'"></p>
                                        </div>
                                    </template>
                                    <p x-show="!(drill.data?.auth?.events?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No authentication events recorded.</p>
                                </div>
                            </template>
                            <template x-if="drill.data?.auth?.mode === 'summary'">
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                                    <div class="rpt-tile rpt-tile-success p-3"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Logins (30d)</p><p class="text-lg font-bold mt-0.5 font-mono" x-text="(drill.data?.auth?.summary?.login_ok_30d ?? 0).toLocaleString()"></p></div>
                                    <div class="rpt-tile rpt-tile-warning p-3"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Failed (30d)</p><p class="text-lg font-bold mt-0.5 font-mono" x-text="(drill.data?.auth?.summary?.login_fail_30d ?? 0).toLocaleString()"></p></div>
                                    <div class="rpt-tile rpt-tile-info p-3"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Distinct IPs</p><p class="text-lg font-bold mt-0.5 font-mono" x-text="(drill.data?.auth?.summary?.distinct_ips_30d ?? 0).toLocaleString()"></p></div>
                                    <div class="rpt-tile rpt-tile-info p-3"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Distinct UAs</p><p class="text-lg font-bold mt-0.5 font-mono" x-text="(drill.data?.auth?.summary?.distinct_uas_30d ?? 0).toLocaleString()"></p></div>
                                </div>
                            </template>
                            <template x-if="drill.data?.auth?.mode === 'unavailable'">
                                <p class="text-center text-muted-foreground py-3 text-[13px]">Authentication event log unavailable in this environment.</p>
                            </template>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </template>

    {{-- ─────── EXPLAINER MODAL · large screen + data table ─────── --}}
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
function rptUserActivity() {
    return {
        tab: 'charts', // 'charts' | 'records'
        filters: { poe: '', role_key: '', start_date: '', end_date: '' },
        meta: {}, kpis: [], rows: [],
        pagination: { page: 1, per_page: 10, total: 0, total_pages: 1, from: 0, to: 0 },
        controls: { sort: 'screenings', dir: 'desc', q: '', status: 'all', role_key: '' },
        search: '', status: 'all',
        loading: false, filtersOpen: false,
        chartObjs: { role: null, heatmap: null, drillSpark: null },
        chartData: { role: null, heatmap: null },
        drill: { open: false, row: null, data: null },
        explainer: { open: false, key: null, spec: null },
        explainerSpecs: {
            role: {
                title: 'Output by Role',
                what: 'Total throughput by role_key — screenings captured + alerts acknowledged + followups completed — over the active window. Top 10 roles by total.',
                how: 'Each bar is one role. Three colours stack to show the mix — heavy screenings + low alerts means front-line workers; low screenings + heavy alerts means responder roles. A role bar of mostly grey (followups) means clerical/operational closeout.',
                decisions: ['Identify which role cohorts deliver the most measurable output.', 'Spot a workforce role whose output collapsed compared to last quarter.', 'Validate that staffing decisions match throughput needs.'],
                source: 'users JOINed virtually via user_id (no SQL JOIN against ref_*); per-user counts from primary_screenings.captured_by_user_id, alerts.acknowledged_by_user_id, alert_followups.completed_by_user_id, then aggregated in PHP by role_key.',
                caveats: 'Followups depend on the alert_followups table; absent in some environments. Roles with under 5 officers are still shown — interpret thinly-staffed roles with caution.',
            },
            heatmap: {
                title: 'Login Activity · Day × Hour',
                what: 'Distribution of successful logins across day-of-week and hour-of-day, restricted to officers in your scope.',
                how: 'Each grouped column is an hour of day; each colour band is a day of week. Look for unusual hot zones outside 05:00–22:00 — those are unusual-hours logins worth a security check.',
                decisions: ['Verify operational coverage matches actual login patterns.', 'Detect off-hours activity that may indicate compromised credentials.', 'Compare weekday vs weekend operational tempo.'],
                source: 'auth_events GROUP BY DAYOFWEEK(created_at), HOUR(created_at) WHERE event_type=LOGIN_OK AND user_id IN (in-scope users).',
                caveats: 'Times are UTC. The auth_events table is provisioned by the production app.sql — in environments without it the chart renders empty.',
            },
        },

        async boot() {
            await this.loadMeta();
            await this.apply();
        },

        async loadMeta() {
            const r = await fetch('{{ url('/admin/reports/rpt-user-activity/meta') }}');
            if (!r.ok) return;
            const j = await r.json();
            this.meta = j.data || {};
        },

        async apply() {
            this.loading = true;
            await Promise.all([
                this.loadKpis(),
                this.loadChart('output_by_role',   'role'),
                this.loadChart('activity_heatmap', 'heatmap'),
                this.loadRecords(1),
            ]);
            this.loading = false;
        },
        resetFilters() { this.filters = { poe: '', role_key: '', start_date: '', end_date: '' }; this.search = ''; this.status = 'all'; this.apply(); },
        qs() {
            const p = new URLSearchParams();
            for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v);
            return p.toString();
        },

        async loadKpis() {
            const r = await fetch('{{ url('/admin/reports/rpt-user-activity/kpis') }}?' + this.qs());
            if (!r.ok) return;
            const j = await r.json();
            this.kpis = j.data?.kpis || [];
        },

        async loadChart(key, target) {
            const r = await fetch('{{ url('/admin/reports/rpt-user-activity/chart') }}/' + key + '?' + this.qs());
            if (!r.ok) return;
            const j = await r.json();
            this.chartData[target] = j.data;
            this.renderChart(target, j.data);
        },

        renderChart(target, payload) {
            if (!payload || !this.$refs['chart_' + target]) return;
            if (this.chartObjs[target]) this.chartObjs[target].destroy();
            const isRole = target === 'role';
            const palette = isRole
                ? ['#10b981', '#ef4444', '#3b82f6']
                : ['#10b981', '#3b82f6', '#8b5cf6', '#f59e0b', '#ef4444', '#7f1d1d', '#64748b'];
            const datasets = (payload.datasets || []).map((d, i) => ({
                label: d.label,
                data: d.data,
                backgroundColor: (palette[i % palette.length] || '#10b981') + 'cc',
                hoverBackgroundColor: palette[i % palette.length] || '#10b981',
                borderRadius: 4,
                borderSkipped: false,
                stack: isRole ? 'role' : undefined,
            }));
            this.chartObjs[target] = new Chart(this.$refs['chart_' + target].getContext('2d'), {
                type: 'bar',
                data: { labels: payload.labels, datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    indexAxis: isRole ? 'y' : 'x',
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: true, position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                        tooltip: { backgroundColor: 'rgba(15,23,42,.95)', padding: 10, cornerRadius: 8 },
                    },
                    scales: isRole
                        ? { x: { stacked: true, beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } }, y: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } } }
                        : { x: { grid: { display: false }, ticks: { font: { size: 9 }, maxTicksLimit: 12 } }, y: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } } },
                },
            });
        },

        async loadRecords(page) {
            this.loading = true;
            const p = new URLSearchParams({ page, q: this.search, sort: this.controls.sort, dir: this.controls.dir, status: this.status, role_key: this.filters.role_key || '' });
            for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v);
            const r = await fetch('{{ url('/admin/reports/rpt-user-activity/records') }}?' + p.toString());
            if (!r.ok) { this.loading = false; return; }
            const j = await r.json();
            this.rows = j.data?.rows || [];
            this.pagination = j.data?.pagination || this.pagination;
            this.controls = j.data?.controls || this.controls;
            this.loading = false;
        },
        setStatus(s) { this.status = s; this.loadRecords(1); },
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
                a.download = 'rpt-user-activity__' + target + '__' + this.stamp() + '.png';
                a.click();
            } else {
                const map = { role: 'output_by_role', heatmap: 'activity_heatmap' };
                window.location.href = '{{ url('/admin/reports/rpt-user-activity/chart') }}/' + map[target] + '/csv?' + this.qs();
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
            const r = await fetch('{{ url('/admin/reports/rpt-user-activity/records') }}/' + encodeURIComponent(row.id));
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
                    datasets: [
                        { label: 'Screenings', data: spark.map(p => p.screenings), borderColor: '#10b981', backgroundColor: '#10b98122', fill: true, tension: 0.35, borderWidth: 2, pointRadius: 1 },
                        { label: 'Alerts',     data: spark.map(p => p.alerts),     borderColor: '#ef4444', backgroundColor: '#ef444422', fill: true, tension: 0.35, borderWidth: 2, pointRadius: 1 },
                    ],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: true, position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } },
                    scales: {
                        x: { grid: { display: false }, ticks: { font: { size: 9 }, maxTicksLimit: 8 } },
                        y: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 10 } } },
                    },
                },
            });
        },

        badgeForStatus(s) {
            return { active: 'badge-success', dormant: 'badge-warning', locked: 'badge-critical', flagged: 'badge-danger', inactive: 'badge-secondary' }[s] || 'badge-outline';
        },
        badgeForTraining(t) {
            return { VALID: 'badge-success', EXPIRING: 'badge-warning', EXPIRED: 'badge-danger', REVOKED: 'badge-critical', NONE: 'badge-secondary' }[t] || 'badge-outline';
        },
        badgeForSeverity(s) {
            return { LOW: 'badge-low', MEDIUM: 'badge-medium', HIGH: 'badge-high', CRITICAL: 'badge-critical' }[s] || 'badge-secondary';
        },
        riskTone(n) { n = n || 0; if (n >= 60) return 'critical'; if (n >= 30) return 'danger'; if (n > 0) return 'warning'; return 'success'; },
    };
}
</script>
@endsection
