@extends('admin.layout')

@section('crumb', 'Reports')
@section('title', 'Alert Resolution Database')

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
</style>
@endpush

@section('content')
<div x-data="rptResolutionDb()" x-init="boot()" class="space-y-4">

    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-success/80">Case &amp; Resolution Registry · R5</p>
            <h1 class="text-[22px] font-bold tracking-tight">Alert Resolution Database</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Every handled alert: who did what, when, and how it ended.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-md border border-border/70 bg-card px-2.5 py-1 text-[11.5px] font-mono text-foreground/80" x-show="window_label"><span class="status-dot status-dot-live"></span><span x-text="window_label"></span></span>
            <button class="btn btn-outline btn-xs" @click="filtersOpen = !filtersOpen" x-text="filtersOpen ? 'Hide Filters' : 'Show Filters'"></button>
        </div>
    </section>

    <section class="card" x-show="filtersOpen" x-cloak>
        <div class="card-content py-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div><label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">POE</label><select class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm" x-model="filters.poe"><option value="">All POEs</option><template x-for="(name, code) in (meta.poes || {})" :key="code"><option :value="code" x-text="name"></option></template></select></div>
                <div><label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">From</label><input type="date" class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm" x-model="filters.start_date"></div>
                <div><label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">To</label><input type="date" class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm" x-model="filters.end_date"></div>
                <div class="flex items-end gap-2"><button class="btn btn-brand btn-md flex-1" @click="apply()">Apply</button><button class="btn btn-outline btn-md" @click="resetFilters()">Reset</button></div>
            </div>
        </div>
    </section>

    <section class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
        <template x-for="k in kpis" :key="k.key"><div class="rpt-tile" :class="'rpt-tile-' + (k.tone || 'neutral')"><p class="kpi-label" x-text="k.label"></p><p class="kpi-value mt-1" x-text="k.value"></p><p class="text-[11px] text-muted-foreground mt-1 leading-snug" x-text="k.hint"></p></div></template>
        <template x-if="!kpis.length"><template x-for="i in 5" :key="i"><div class="rpt-tile rpt-tile-neutral"><div class="rpt-skel h-4 w-24"></div><div class="rpt-skel h-8 w-16 mt-2"></div><div class="rpt-skel h-3 w-32 mt-2"></div></div></template></template>
    </section>

    <section class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        @foreach ([
            ['target' => 'reason',    'serverKey' => 'resolutions_by_reason', 'title' => 'Resolutions Over Time', 'subtitle' => 'Stacked by closure reason — Resolved · False Positive · Unspecified'],
            ['target' => 'resolvers', 'serverKey' => 'top_resolvers',         'title' => 'Top Resolving Officers', 'subtitle' => 'Top 10 by alerts resolved in window'],
        ] as $c)
        <div class="card overflow-hidden">
            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3 p-4 border-b border-border/60">
                <div class="min-w-0"><h3 class="text-[14px] font-semibold tracking-tight">{{ $c['title'] }}</h3><p class="text-[12px] text-muted-foreground">{{ $c['subtitle'] }}</p></div>
                <div class="flex items-center gap-1.5 flex-wrap">
                    <button class="btn btn-soft-info btn-xs gap-1.5" @click="openExplainer('{{ $c['target'] }}')"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg> Explain</button>
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
            <div class="flex items-start justify-between gap-3"><div><h3 class="text-[14px] font-semibold tracking-tight">Resolved Alerts</h3><p class="text-[12px] text-muted-foreground">Click any row for the full lifecycle dossier with case walkthrough.</p></div><span class="text-[11px] font-mono text-muted-foreground" x-text="(pagination.total ?? 0).toLocaleString() + ' alerts'"></span></div>
            <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                <div class="relative flex-1 max-w-md"><svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg><input type="search" placeholder="Search code, title, POE, owner..." class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-sm" x-model.debounce.300ms="search" @input="loadRecords(1)"></div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="rpt-chip" :class="cat === 'all' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('all')">All <span class="font-mono opacity-70" x-text="categoryCounts.all || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'real' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('real')">Real Cases <span class="font-mono opacity-70" x-text="categoryCounts.real || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'resolved' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('resolved')">Resolved <span class="font-mono opacity-70" x-text="categoryCounts.resolved || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'fp' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('fp')">False Positive <span class="font-mono opacity-70" x-text="categoryCounts.fp || 0"></span></span>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto"><table class="table"><thead class="table-head sticky top-0"><tr>
            <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('alert_code')">Alert <span x-html="sortIcon('alert_code')"></span></span></th>
            <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('poe_code')">POE <span x-html="sortIcon('poe_code')"></span></span></th>
            <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('risk_level')">Risk <span x-html="sortIcon('risk_level')"></span></span></th>
            <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('close_category')">Closure <span x-html="sortIcon('close_category')"></span></span></th>
            <th class="table-head-th">Owner</th>
            <th class="table-head-th text-right">Follow-ups</th>
            <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('closed_at')">Closed <span x-html="sortIcon('closed_at')"></span></span></th>
        </tr></thead><tbody class="table-body">
            <template x-if="loading"><template x-for="i in 10" :key="i"><tr class="border-b border-border/40">
                <td class="table-cell"><div class="rpt-skel h-4 w-48"></div><div class="rpt-skel h-3 w-32 mt-1.5"></div></td>
                <td class="table-cell"><div class="rpt-skel h-4 w-20"></div></td>
                <td class="table-cell"><div class="rpt-skel h-5 w-16"></div></td>
                <td class="table-cell"><div class="rpt-skel h-5 w-24"></div></td>
                <td class="table-cell"><div class="rpt-skel h-4 w-28"></div></td>
                <td class="table-cell text-right"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                <td class="table-cell"><div class="rpt-skel h-4 w-32"></div></td>
            </tr></template></template>
            <template x-if="!loading"><template x-for="r in rows" :key="r.id"><tr class="table-row cursor-pointer" @click="openDrill(r.id)">
                <td class="table-cell"><div class="font-medium" x-text="r.alert_title"></div><div class="text-[11px] text-muted-foreground font-mono" x-text="r.alert_code || ('Alert #' + r.id)"></div></td>
                <td class="table-cell text-[12px]" x-text="r.poe_code || '—'"></td>
                <td class="table-cell"><span class="badge" :class="badgeForRisk(r.risk_level)" x-text="r.risk_level"></span></td>
                <td class="table-cell"><span class="badge" :class="badgeForClosure(r.close_category)" x-text="r.close_category || 'Unspecified'"></span><span x-show="r.classification" class="badge ml-1" :class="badgeForClassification(r.classification)" x-text="r.classification"></span></td>
                <td class="table-cell text-[12.5px]" x-text="r.owner_name || '—'"></td>
                <td class="table-cell text-right font-mono"><span x-text="r.fu_completed + ' / ' + r.fu_total"></span></td>
                <td class="table-cell text-[12px] text-muted-foreground" x-text="r.closed_at ? new Date(r.closed_at).toLocaleString() : '—'"></td>
            </tr></template></template>
            <tr x-show="!loading && !rows.length"><td colspan="7" class="table-cell py-12 text-center text-muted-foreground">No resolved alerts match the current filters.</td></tr>
        </tbody></table></div>
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

    {{-- DRILL MODAL with Case Walkthrough --}}
    <template x-teleport="body">
        <div x-show="drill.open" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" @keydown.escape.window="drill.open = false">
            <div class="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm" @click="drill.open = false"></div>
            <div class="relative z-50 w-full sm:w-[96vw] sm:max-w-[1400px] h-[94vh] sm:max-h-[94vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl border border-border bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-border/60 bg-white/95 backdrop-blur px-5 py-3.5">
                    <div class="min-w-0"><p class="text-[11px] font-semibold uppercase tracking-[.14em] text-success/80">Resolution Lifecycle</p><h2 class="text-[17px] font-bold tracking-tight truncate" x-text="drill.data?.alert?.title || ('Alert #' + (drill.alertId || ''))"></h2></div>
                    <button class="btn btn-ghost btn-icon-xs" @click="drill.open = false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </div>
                <div class="p-5 space-y-4" x-show="drill.data">
                    {{-- Smart wizard --}}
                    <div class="rounded-xl border-2 border-success/30 bg-gradient-to-br from-success-soft/40 to-info-soft/30 p-4 sm:p-5" x-show="wizard.open">
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="min-w-0"><p class="text-[11px] font-semibold uppercase tracking-[.14em] text-success">Resolution Walkthrough · Deterministic AI</p><h3 class="text-[16px] font-bold tracking-tight">A guided tour of how this alert was resolved</h3></div>
                            <button class="btn btn-ghost btn-xs text-muted-foreground" @click="wizard.open = false">Skip</button>
                        </div>
                        <div class="flex items-center gap-1 sm:gap-1.5 overflow-x-auto pb-2 mb-3"><template x-for="s in wizardSteps()" :key="s.id"><button class="flex-shrink-0 flex items-center gap-1.5 rounded-full border px-2.5 h-7 text-[11px] font-medium transition-colors" :class="s.id === wizard.step ? 'bg-brand text-white border-brand' : (s.id < wizard.step ? 'bg-success-soft border-success/30 text-success' : 'bg-card border-border/60 text-muted-foreground hover:bg-muted/60')" @click="wizard.step = s.id"><span class="font-mono" x-text="s.id"></span><span x-text="s.title"></span></button></template></div>
                        <template x-for="s in wizardSteps()" :key="s.id"><div x-show="wizard.step === s.id" class="space-y-3">
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground" x-text="'Step ' + s.id + ' of 7'"></p>
                            <p class="text-[15px] sm:text-[16px] font-semibold leading-snug" x-text="s.headline"></p>
                            <p class="text-[13.5px] leading-relaxed text-foreground/90" x-text="s.narrative"></p>
                            <div class="flex flex-wrap gap-1.5" x-show="s.highlights && s.highlights.length"><template x-for="(h, hi) in (s.highlights || [])" :key="hi"><span class="inline-flex items-center gap-1.5 rounded-md border bg-card px-2 py-1 text-[11.5px]" :class="h.tone === 'critical' ? 'border-critical/40 text-critical' : (h.tone === 'warning' ? 'border-warning/40 text-warning' : (h.tone === 'success' ? 'border-success/40 text-success' : (h.tone === 'info' ? 'border-info/40 text-info' : 'border-border/60 text-foreground/80')))"><span class="text-[10px] uppercase tracking-wider opacity-70" x-text="h.label"></span><span class="font-semibold" x-text="h.value"></span></span></template></div>
                        </div></template>
                        <div class="flex items-center justify-between mt-4 pt-3 border-t border-border/60"><button class="btn btn-outline btn-sm" :disabled="wizard.step <= 1" @click="wizard.step = Math.max(1, wizard.step - 1)">‹ Prev</button><span class="text-[11.5px] font-mono text-muted-foreground" x-text="wizard.step + ' / 7'"></span><button class="btn btn-brand btn-sm" :disabled="wizard.step >= 7" @click="wizard.step = Math.min(7, wizard.step + 1)">Next ›</button></div>
                    </div>
                    <button x-show="!wizard.open" class="btn btn-soft-brand btn-xs w-full" @click="wizard.open = true; wizard.step = 1">Re-open the resolution walkthrough</button>

                    {{-- KPI strip --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                        <div class="rpt-tile p-3" :class="'rpt-tile-' + riskTone(drill.data?.alert?.risk_level)"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Risk</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.alert?.risk_level"></p></div>
                        <div class="rpt-tile p-3" :class="drill.data?.alert?.close_category === 'FALSE_POSITIVE' ? 'rpt-tile-info' : 'rpt-tile-success'"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Closure</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.alert?.close_category || '—'"></p></div>
                        <div class="rpt-tile p-3" :class="drill.data?.alert?.sla_breached ? 'rpt-tile-critical' : 'rpt-tile-success'"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">SLA</p><p class="text-xl font-bold mt-0.5" x-text="formatDuration(drill.data?.alert?.minutes_open ?? (drill.data?.alert?.hours_open * 60)) + ' / ' + (drill.data?.alert?.sla_hours ?? '?') + 'h'"></p></div>
                        <div class="rpt-tile rpt-tile-neutral p-3"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Reopens</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.alert?.reopen_count ?? 0"></p></div>
                    </div>

                    <details class="rpt-section" open><summary class="rpt-section-head"><span>Alert Facts</span></summary><div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 gap-3 text-[13px]">
                        <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Code</p><p class="font-mono text-[11.5px]" x-text="drill.data?.alert?.code || '—'"></p></div>
                        <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Routed To</p><p x-text="drill.data?.alert?.routed_to_level || '—'"></p></div>
                        <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">POE</p><p x-text="drill.data?.alert?.poe_code || '—'"></p></div>
                        <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Created</p><p x-text="drill.data?.alert?.created_at ? new Date(drill.data.alert.created_at).toLocaleString() : '—'"></p></div>
                        <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Acknowledged</p><p x-text="drill.data?.alert?.acknowledged_at ? new Date(drill.data.alert.acknowledged_at).toLocaleString() : '—'"></p></div>
                        <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Closed</p><p x-text="drill.data?.alert?.closed_at ? new Date(drill.data.alert.closed_at).toLocaleString() : '—'"></p></div>
                        <div class="col-span-2 sm:col-span-3" x-show="drill.data?.alert?.close_note"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Closure Note</p><p class="text-[12.5px] leading-snug" x-text="drill.data?.alert?.close_note || '—'"></p></div>
                    </div></details>

                    <details class="rpt-section" x-show="drill.data?.outcome"><summary class="rpt-section-head"><span>Case Outcome &amp; Lab</span></summary><div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 gap-3 text-[13px]">
                        <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Classification</p><span class="badge mt-1" :class="badgeForClassification(drill.data?.outcome?.classification)" x-text="drill.data?.outcome?.classification || '—'"></span></div>
                        <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Lab Status</p><p x-text="drill.data?.outcome?.lab_status || '—'"></p></div>
                        <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Lab Disease</p><p class="font-mono text-[11.5px]" x-text="drill.data?.outcome?.lab_disease_code || '—'"></p></div>
                        <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Method</p><p x-text="drill.data?.outcome?.lab_test_method || '—'"></p></div>
                        <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Public-Health Action</p><p x-text="drill.data?.outcome?.ph_action || '—'"></p></div>
                        <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">IHR Notified</p><p x-text="drill.data?.outcome?.ihr_notified ? 'Yes — ' + (drill.data.outcome.ihr_reference || '—') : 'No'"></p></div>
                    </div></details>

                    <details class="rpt-section"><summary class="rpt-section-head"><span>Handoffs <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.handoffs?.length ?? 0) + ')'"></span></span></summary><div class="rpt-section-body">
                        <template x-for="(h, i) in (drill.data?.handoffs ?? [])" :key="i"><div class="border-l-2 border-info/40 pl-3 mt-2 first:mt-0">
                            <p class="text-[12.5px] font-medium"><span x-text="h.from_level || '—'"></span> → <span x-text="h.to_level || '—'"></span> <span class="badge ml-1" :class="badgeForHandoff(h.status)" x-text="h.status"></span></p>
                            <p class="text-[11.5px] text-muted-foreground" x-text="(h.from_name || 'system') + ' → ' + (h.to_name || 'system') + ' · ' + new Date(h.created_at).toLocaleString()"></p>
                            <p class="text-[11.5px] text-muted-foreground" x-show="h.reason" x-text="h.reason"></p>
                        </div></template>
                        <p x-show="!(drill.data?.handoffs?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No handoffs recorded.</p>
                    </div></details>

                    <details class="rpt-section"><summary class="rpt-section-head"><span>Follow-ups <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.followups?.length ?? 0) + ')'"></span></span></summary><div class="rpt-section-body">
                        <template x-for="(f, i) in (drill.data?.followups ?? [])" :key="i"><div class="flex items-start justify-between border-t border-border/40 pt-2 first:border-t-0 first:pt-0">
                            <div class="min-w-0"><p class="text-[13px] font-medium truncate"><span x-show="f.blocks_closure" class="badge badge-warning text-[9px] mr-1">BLOCKS</span><span x-text="f.action_label"></span></p><p class="text-[11px] text-muted-foreground" x-text="(f.completed_at ? 'Completed ' + new Date(f.completed_at).toLocaleString() + (f.completed_by_name ? ' by ' + f.completed_by_name : '') : (f.due_at ? 'Due ' + new Date(f.due_at).toLocaleString() : 'No due date'))"></p><p class="text-[11.5px] text-muted-foreground italic" x-show="f.notes" x-text="'“' + f.notes + '”'"></p></div>
                            <span class="badge" :class="badgeForFollowup(f.status)" x-text="f.status"></span>
                        </div></template>
                        <p x-show="!(drill.data?.followups?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No follow-ups recorded.</p>
                    </div></details>

                    <details class="rpt-section"><summary class="rpt-section-head"><span>Samples <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.samples?.length ?? 0) + ')'"></span></span></summary><div class="rpt-section-body">
                        <template x-for="(s, i) in (drill.data?.samples ?? [])" :key="i"><div class="border-t border-border/40 pt-2 first:border-t-0 first:pt-0 grid grid-cols-2 sm:grid-cols-4 gap-2 text-[13px]">
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Type</p><p x-text="s.sample_type || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Identifier</p><p class="font-mono text-[11.5px]" x-text="s.sample_identifier || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Lab</p><p x-text="s.lab_destination || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Collected</p><p x-text="s.collected_at ? new Date(s.collected_at).toLocaleString() : (s.sample_collected ? 'Yes' : 'No')"></p></div>
                        </div></template>
                        <p x-show="!(drill.data?.samples?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No lab samples recorded for this case.</p>
                    </div></details>

                    <details class="rpt-section" x-show="(drill.data?.diseases || []).length"><summary class="rpt-section-head"><span>Suspected Diseases</span></summary><div class="rpt-section-body">
                        <table class="w-full text-[13px]"><thead class="text-[10px] uppercase tracking-wider text-muted-foreground"><tr><th class="text-left py-1.5">Rank</th><th class="text-left py-1.5">Disease</th><th class="text-right py-1.5">Confidence</th><th class="text-left py-1.5">Reasoning</th></tr></thead><tbody>
                            <template x-for="(d, i) in (drill.data?.diseases ?? [])" :key="i"><tr class="border-t border-border/40"><td class="py-1.5 font-mono" x-text="d.rank_order"></td><td class="py-1.5"><span class="font-medium" x-text="d.display_name || d.disease_code"></span><span class="ml-1 font-mono text-[11px] text-muted-foreground" x-text="d.disease_code"></span></td><td class="py-1.5 text-right font-mono" x-text="d.confidence !== null ? Number(d.confidence).toFixed(2) : '—'"></td><td class="py-1.5 text-[12px] text-muted-foreground" x-text="d.reasoning || '—'"></td></tr></template>
                        </tbody></table>
                    </div></details>

                    <details class="rpt-section"><summary class="rpt-section-head"><span>Audit Timeline <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.timeline?.length ?? 0) + ')'"></span></span></summary><div class="rpt-section-body space-y-2">
                        <template x-for="(t, i) in (drill.data?.timeline ?? [])" :key="i"><div class="border-l-2 pl-3" :class="t.severity === 'CRITICAL' ? 'border-critical' : (t.severity === 'WARN' ? 'border-warning' : 'border-brand/40')"><p class="text-[12px] font-semibold" x-text="t.event_code"></p><p class="text-[11.5px] text-muted-foreground" x-text="t.summary || '—'"></p><p class="text-[10.5px] text-muted-foreground/80" x-text="(t.actor_name || 'system') + ' · ' + new Date(t.created_at).toLocaleString()"></p></div></template>
                        <p x-show="!(drill.data?.timeline?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No timeline events.</p>
                    </div></details>

                    @include('admin.reports.v2._related_views', ['type' => 'alert'])
                </div>
            </div>
        </div>
    </template>

    {{-- EXPLAINER MODAL --}}
    <template x-teleport="body">
        <div x-show="explainer.open" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" @keydown.escape.window="explainer.open = false">
            <div class="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm" @click="explainer.open = false"></div>
            <div class="relative z-50 w-full sm:w-[96vw] sm:max-w-[1400px] h-[94vh] sm:max-h-[94vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl border border-border bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-border/60 bg-white/95 backdrop-blur px-5 py-3.5"><div class="min-w-0"><p class="text-[11px] font-semibold uppercase tracking-[.14em] text-info">About this chart</p><h2 class="text-[17px] font-bold tracking-tight truncate" x-text="explainer.spec?.title || ''"></h2></div><div class="flex items-center gap-1.5"><button class="btn btn-outline btn-xs gap-1.5" @click="exportChart(explainer.key, 'csv')" x-show="explainer.key"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 17H5a2 2 0 01-2-2V5a2 2 0 012-2h10l4 4v3M14 3v4h4M9 13h6m-6 4h4"/></svg> Export CSV</button><button class="btn btn-ghost btn-icon-xs" @click="explainer.open = false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg></button></div></div>
                <div class="p-5 grid grid-cols-1 lg:grid-cols-5 gap-5">
                    <div class="lg:col-span-2 space-y-4 text-[13.5px] leading-relaxed">
                        <div><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">What it shows</p><p x-text="explainer.spec?.what"></p></div>
                        <div><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">How to read it</p><p x-text="explainer.spec?.how"></p></div>
                        <div><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Decisions it supports</p><ul class="list-disc pl-5 space-y-1"><template x-for="d in (explainer.spec?.decisions || [])" :key="d"><li x-text="d"></li></template></ul></div>
                        <div><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Data source</p><p class="font-mono text-[12px] text-muted-foreground" x-text="explainer.spec?.source"></p></div>
                        <div x-show="explainer.spec?.caveats" class="rounded-md border border-warning/30 bg-warning-soft/40 px-3 py-2"><p class="text-[10px] font-semibold uppercase tracking-wider text-warning">Caveats</p><p x-text="explainer.spec?.caveats"></p></div>
                    </div>
                    <div class="lg:col-span-3"><div class="flex items-center justify-between mb-2"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Underlying data</p><span class="text-[11px] font-mono text-muted-foreground" x-text="(explainerRows().length || 0).toLocaleString() + ' rows'"></span></div><div class="rounded-lg border border-border bg-white overflow-hidden"><div class="max-h-[72vh] overflow-y-auto"><table class="w-full text-[13px]"><thead class="bg-muted/50 sticky top-0"><tr><template x-for="(h, i) in explainerHeaders()" :key="i"><th class="text-left px-3 py-2 text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground border-b border-border" x-text="h"></th></template></tr></thead><tbody><template x-for="(row, ri) in explainerRows()" :key="ri"><tr class="border-b border-border/40 hover:bg-muted/30"><template x-for="(cell, ci) in row" :key="ci"><td class="px-3 py-2" :class="ci === 0 ? 'font-medium' : 'text-right font-mono'" x-text="cell === null || cell === undefined ? '—' : (typeof cell === 'number' ? cell.toLocaleString() : cell)"></td></template></tr></template><tr x-show="!explainerRows().length"><td :colspan="explainerHeaders().length || 1" class="px-3 py-12 text-center text-muted-foreground">No rows in the current window.</td></tr></tbody></table></div></div></div>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function rptResolutionDb() {
    return {
        filters: { poe: '', start_date: '', end_date: '' },
        meta: {}, kpis: [], rows: [],
        pagination: { page: 1, per_page: 10, total: 0, total_pages: 1, from: 0, to: 0 },
        controls: { sort: 'closed_at', dir: 'desc', q: '', cat: 'all' },
        categoryCounts: {},
        search: '', cat: 'all', window_label: '',
        loading: false, filtersOpen: false,
        chartObjs: { reason: null, resolvers: null },
        chartData: { reason: null, resolvers: null },
        drill: { open: false, alertId: null, data: null },
        wizard: { open: true, step: 1 },
        explainer: { open: false, key: null, spec: null },
        explainerSpecs: {
            reason: {
                title: 'Resolutions Over Time by Reason',
                what: 'Daily count of alerts that were closed in the window, stacked by closure reason — Resolved, False Positive, or Unspecified.',
                how: 'Each column is a single day. Tall green bars = lots of legitimate resolutions; tall blue bars = noise (false positives).',
                decisions: ['Track operational closure pace.', 'Spot weeks dominated by false positives — refine alert rules.', 'Identify gaps where reasons are not being recorded.'],
                source: 'alerts WHERE status=CLOSED GROUP BY DATE(closed_at), close_category.',
                caveats: 'Reopened alerts are not separately counted.',
            },
            resolvers: {
                title: 'Top Resolving Officers',
                what: 'Top 10 officers ranked by alerts they acknowledged that have since closed.',
                how: 'Bars are descending. Long bars = workhorse responders. Compare to ensure load distribution.',
                decisions: ['Recognise top performers.', 'Detect single-officer dependencies (single tall bar = burnout risk).', 'Inform staffing plans.'],
                source: 'alerts WHERE status=CLOSED GROUP BY acknowledged_by_user_id ORDER BY COUNT(*) DESC LIMIT 10.',
                caveats: 'Counts the acknowledger, not necessarily the closer — alerts can change hands.',
            },
        },

        async boot() { await this.loadMeta(); await this.apply(); },
        async loadMeta() { const r = await fetch('{{ url('/admin/reports/rpt-resolution-db/meta') }}'); const j = await r.json(); this.meta = j.data || {}; },
        async apply() { this.loading = true; await Promise.all([this.loadKpis(), this.loadChart('resolutions_by_reason', 'reason'), this.loadChart('top_resolvers', 'resolvers'), this.loadRecords(1)]); this.loading = false; },
        resetFilters() { this.filters = { poe: '', start_date: '', end_date: '' }; this.search = ''; this.cat = 'all'; this.apply(); },
        qs() { const p = new URLSearchParams(); for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v); return p.toString(); },
        async loadKpis() { const r = await fetch('{{ url('/admin/reports/rpt-resolution-db/kpis') }}?' + this.qs()); const j = await r.json(); this.kpis = j.data?.kpis || []; this.window_label = j.data?.window?.label || ''; },
        async loadChart(key, target) { const r = await fetch('{{ url('/admin/reports/rpt-resolution-db/chart') }}/' + key + '?' + this.qs()); const j = await r.json(); this.chartData[target] = j.data; this.renderChart(target, j.data); },

        renderChart(target, payload) {
            if (!payload || !this.$refs['chart_' + target]) return;
            if (this.chartObjs[target]) this.chartObjs[target].destroy();
            const isStacked = target === 'reason';
            const isHBar = target === 'resolvers';
            const palette = isStacked ? ['#10b981', '#3b82f6', '#94a3b8'] : ['#10b981'];
            const datasets = (payload.datasets || []).map((d, i) => ({
                label: d.label, data: d.data,
                backgroundColor: palette[i % palette.length] + 'cc',
                borderColor: palette[i % palette.length],
                borderWidth: 0, borderRadius: 6, borderSkipped: false,
                stack: isStacked ? 'r' : undefined,
            }));
            this.chartObjs[target] = new Chart(this.$refs['chart_' + target].getContext('2d'), {
                type: 'bar',
                data: { labels: payload.labels, datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: isHBar ? 'y' : 'x',
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { display: isStacked, position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } }, tooltip: { backgroundColor: 'rgba(15,23,42,.95)', padding: 10, cornerRadius: 8 } },
                    scales: {
                        x: { stacked: isStacked, beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } },
                        y: { stacked: isStacked, beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } },
                    },
                },
            });
        },

        async loadRecords(page) {
            this.loading = true;
            const p = new URLSearchParams({ page, q: this.search, sort: this.controls.sort, dir: this.controls.dir, cat: this.cat });
            for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v);
            const r = await fetch('{{ url('/admin/reports/rpt-resolution-db/records') }}?' + p.toString());
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
            if (fmt === 'png') { const c = this.chartObjs[target]; if (!c) return; const a = document.createElement('a'); a.href = c.toBase64Image('image/png', 1); a.download = 'rpt-resolution-db__' + target + '__' + this.stamp() + '.png'; a.click(); }
            else { const map = { reason: 'resolutions_by_reason', resolvers: 'top_resolvers' }; window.location.href = '{{ url('/admin/reports/rpt-resolution-db/chart') }}/' + map[target] + '/csv?' + this.qs(); }
        },
        stamp() { const d = new Date(); const p = n => String(n).padStart(2, '0'); return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()); },

        openExplainer(key) { this.explainer.open = true; this.explainer.key = key; this.explainer.spec = this.explainerSpecs[key] || null; },
        explainerHeaders() { const k = this.explainer.key; return (k && this.chartData[k]?.csv_headers) || []; },
        explainerRows()    { const k = this.explainer.key; return (k && this.chartData[k]?.csv_rows)    || []; },

        async openDrill(id) {
            this.drill.open = true; this.drill.alertId = id; this.drill.data = null;
            this.wizard = { open: true, step: 1 };
            const r = await fetch('{{ url('/admin/reports/rpt-resolution-db/records') }}/' + id);
            if (!r.ok) { this.drill.data = { error: r.status }; return; }
            const j = await r.json(); this.drill.data = j.data;
        },

        /* ── 7-step deterministic walkthrough (resolution-flavoured) ── */
        wizardSteps() {
            return [
                { id: 1, title: 'Snapshot',         ...this.wSnapshot() },
                { id: 2, title: 'Trigger',          ...this.wTrigger() },
                { id: 3, title: 'Routing',          ...this.wRouting() },
                { id: 4, title: 'Response Speed',   ...this.wResponse() },
                { id: 5, title: 'Clinical Picture', ...this.wClinical() },
                { id: 6, title: 'Actions Taken',    ...this.wActions() },
                { id: 7, title: 'Final Resolution', ...this.wResolution() },
            ];
        },
        _fmtDate(d) { return d ? new Date(d).toLocaleString() : 'not yet recorded'; },

        /** Minutes → "8 min" / "4h 12m" / "2d 5h". Accepts null/undef → "—". */
        formatDuration(value) {
            if (value === null || value === undefined || isNaN(value)) return '—';
            const minutes = Math.round(value);
            if (minutes < 60)    return minutes + ' min';
            if (minutes < 1440)  { const h = Math.floor(minutes / 60), m = minutes % 60; return m ? `${h}h ${m}m` : `${h}h`; }
            const d = Math.floor(minutes / 1440), h = Math.floor((minutes % 1440) / 60);
            return h ? `${d}d ${h}h` : `${d}d`;
        },
        _fmtMin(m)  { if (m === null || m === undefined) return '—'; if (m < 60) return Math.round(m) + ' min'; if (m < 1440) return Math.round((m / 60) * 10) / 10 + ' h'; return Math.round((m / 1440) * 10) / 10 + ' d'; },

        wSnapshot() {
            const a = this.drill.data?.alert || {};
            return {
                headline: `Alert ${a.code || ('#' + a.id)} closed${a.close_category ? ' as ' + a.close_category : ''} after ${this.formatDuration(a.minutes_open ?? a.hours_open * 60)}.`,
                narrative: `Opened on ${this._fmtDate(a.created_at)} at POE “${a.poe_code || 'unknown'}” at ${a.risk_level || 'UNRATED'} risk; closed on ${this._fmtDate(a.closed_at)}. ${a.sla_breached ? 'It breached its ' + a.sla_hours + 'h SLA.' : 'It closed within its ' + a.sla_hours + 'h SLA.'} ${a.reopen_count > 0 ? 'It was re-opened ' + a.reopen_count + ' time(s).' : ''}`,
                highlights: [
                    { label: 'Risk',    value: a.risk_level || '—', tone: this.riskTone(a.risk_level) === 'critical' ? 'critical' : 'info' },
                    { label: 'Closure', value: a.close_category || 'Unspecified', tone: a.close_category === 'FALSE_POSITIVE' ? 'info' : 'success' },
                    { label: 'SLA',     value: this.formatDuration(a.minutes_open ?? a.hours_open * 60) + ' / ' + a.sla_hours + 'h', tone: a.sla_breached ? 'critical' : 'success' },
                ],
            };
        },
        wTrigger() {
            const t = this.drill.data?.traveller;
            const a = this.drill.data?.alert || {};
            if (!t) return { headline: 'No linked traveller record.', narrative: `The alert was raised at POE “${a.poe_code || 'unknown'}” without an attached secondary screening.`, highlights: [] };
            return {
                headline: `Triggered by ${t.name || 'an anonymous traveller'}, ${t.age || '?'}-year-old ${(t.gender || '').toLowerCase()}.`,
                narrative: `Nationality ${t.nationality || 'unknown'}; arrival from ${t.origin || 'unspecified origin'}. Triage classified ${t.triage || 'unclassified'}; clinician marked ${t.risk_level || 'UNRATED'} risk. Vitals on file: ${t.temperature ? t.temperature + '°C' : '—'}, SpO₂ ${t.oxygen_sat ? t.oxygen_sat + '%' : '—'}.`,
                highlights: [
                    { label: 'Triage',      value: t.triage || '—', tone: t.triage === 'EMERGENCY' ? 'critical' : (t.triage === 'URGENT' ? 'warning' : 'success') },
                    { label: 'Risk (sec.)', value: t.risk_level || '—', tone: 'info' },
                ],
            };
        },
        wRouting() {
            const a = this.drill.data?.alert || {};
            const o = this.drill.data?.owner;
            const ackTime = a.acknowledged_at ? Math.round((new Date(a.acknowledged_at) - new Date(a.created_at)) / 60000) : null;
            const handoffs = (this.drill.data?.handoffs || []).length;
            return {
                headline: `Routed to ${a.routed_to_level || 'unspecified level'}; ${a.acknowledged_at ? 'acknowledged in ' + this._fmtMin(ackTime) : 'never acknowledged'}.`,
                narrative: `${o ? 'Acknowledged by ' + (o.full_name || o.username) + ' (' + (o.role_key || 'unknown role') + ') on ' + this._fmtDate(a.acknowledged_at) + '. ' : 'No owner is on file. '}${handoffs > 0 ? handoffs + ' handoff(s) recorded — see the Handoffs section below.' : 'No handoffs were recorded for this alert.'}`,
                highlights: [
                    { label: 'Owner',    value: o ? (o.full_name || o.username) : 'Unassigned', tone: o ? 'success' : 'warning' },
                    { label: 'Ack Time', value: a.acknowledged_at ? this._fmtMin(ackTime) : '—', tone: ackTime > 60 ? 'warning' : 'success' },
                    { label: 'Handoffs', value: handoffs, tone: 'info' },
                ],
            };
        },
        wResponse() {
            const a = this.drill.data?.alert || {};
            const pct = a.sla_hours ? Math.round((a.hours_open / a.sla_hours) * 100) : 0;
            return {
                headline: `${pct}% of the SLA window was used.`,
                narrative: `Risk ${a.risk_level || 'UNRATED'} carries a ${a.sla_hours}h SLA. Total time open: ${this.formatDuration(a.minutes_open ?? a.hours_open * 60)}. ${a.sla_breached ? 'The SLA was breached — review the timeline to understand the delay.' : 'SLA was met.'}`,
                highlights: [{ label: 'SLA Used', value: pct + '%', tone: a.sla_breached ? 'critical' : 'success' }],
            };
        },
        wClinical() {
            const t = this.drill.data?.traveller || {};
            const dis = (this.drill.data?.diseases || []).slice(0, 3);
            return {
                headline: dis.length ? `Top suspected: ${dis[0].display_name || dis[0].disease_code}.` : 'No diseases ranked.',
                narrative: `${dis.length ? 'Engine ranked ' + dis.map(d => d.display_name || d.disease_code).join(', ') + '. ' : ''}${(t.temperature || t.oxygen_sat) ? 'Vitals — temperature ' + (t.temperature || '—') + '°C, SpO₂ ' + (t.oxygen_sat || '—') + '%.' : 'Vitals not captured.'}`,
                highlights: dis.map(d => ({ label: 'Rank ' + d.rank_order, value: d.display_name || d.disease_code, tone: 'info' })),
            };
        },
        wActions() {
            const fa = this.drill.data?.followup_agg || {};
            const samples = (this.drill.data?.samples || []).filter(s => s.sample_collected).length;
            return {
                headline: `${fa.completed ?? 0} of ${fa.total ?? 0} follow-ups done; ${samples} sample(s) collected.`,
                narrative: `${fa.total === 0 ? 'No follow-up tasks were recorded. ' : 'Follow-up tally: ' + (fa.completed ?? 0) + ' done, ' + (fa.pending ?? 0) + ' pending, ' + (fa.blocked ?? 0) + ' blocked. ' + (fa.blocking > 0 ? fa.blocking + ' task(s) blocked closure. ' : '')}${samples > 0 ? samples + ' lab sample(s) collected — see Samples section.' : 'No lab samples were collected.'}`,
                highlights: [
                    { label: 'Done',    value: (fa.completed ?? 0) + ' / ' + (fa.total ?? 0), tone: fa.total > 0 && fa.completed === fa.total ? 'success' : 'warning' },
                    { label: 'Samples', value: samples, tone: samples > 0 ? 'success' : 'info' },
                ],
            };
        },
        wResolution() {
            const a = this.drill.data?.alert || {};
            const o = this.drill.data?.outcome;
            return {
                headline: `Closed${a.close_category ? ' as ' + a.close_category : ''}${o?.classification ? '; classification: ' + o.classification : ''}.`,
                narrative: `${o?.classification ? 'Final case classification on file is ' + o.classification + (o.lab_status ? ' (lab: ' + o.lab_status + ')' : '') + '. ' : 'No formal case classification recorded.'} ${o?.ihr_notified ? 'IHR has been formally notified (ref ' + (o.ihr_reference || '—') + ').' : 'IHR was not notified.'} ${a.close_note ? 'Closure note: “' + a.close_note + '”.' : ''}`,
                highlights: [
                    { label: 'Closure', value: a.close_category || '—', tone: a.close_category === 'FALSE_POSITIVE' ? 'info' : 'success' },
                    { label: 'Class.',  value: o?.classification || 'Unrecorded', tone: o?.classification === 'CONFIRMED' ? 'critical' : (o?.classification ? 'info' : 'warning') },
                    { label: 'IHR',     value: o?.ihr_notified ? 'Notified' : 'Not notified', tone: o?.ihr_notified ? 'success' : 'warning' },
                ],
            };
        },

        badgeForRisk(r) { return { LOW: 'badge-low', MEDIUM: 'badge-medium', HIGH: 'badge-high', CRITICAL: 'badge-critical' }[r] || 'badge-secondary'; },
        badgeForClosure(c) { return { RESOLVED: 'badge-success', FALSE_POSITIVE: 'badge-info' }[c] || 'badge-secondary'; },
        badgeForFollowup(s) { return { COMPLETED: 'badge-success', PENDING: 'badge-warning', IN_PROGRESS: 'badge-info', BLOCKED: 'badge-critical', NOT_APPLICABLE: 'badge-secondary' }[s] || 'badge-outline'; },
        badgeForHandoff(s) { return { ACCEPTED: 'badge-success', SENT: 'badge-info', ACKNOWLEDGED: 'badge-info', REJECTED: 'badge-critical', RECALLED: 'badge-warning' }[s] || 'badge-outline'; },
        badgeForClassification(c) { return { CONFIRMED: 'badge-critical', PROBABLE: 'badge-danger', SUSPECTED: 'badge-warning', NON_CASE: 'badge-info' }[c] || 'badge-secondary'; },
        riskTone(r) { return { LOW: 'success', MEDIUM: 'warning', HIGH: 'danger', CRITICAL: 'critical' }[r] || 'neutral'; },
    };
}
</script>
@endsection
