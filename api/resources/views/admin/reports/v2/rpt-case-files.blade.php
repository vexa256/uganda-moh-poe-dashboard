@extends('admin.layout')

@section('crumb', 'Reports')
@section('title', 'Case File Registry')

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
    .rpt-field { @apply text-[13px]; }
    .rpt-field-label { @apply text-[10px] uppercase tracking-wider text-muted-foreground; }
</style>
@endpush

@section('content')
<div x-data="rptCaseFiles()" x-init="boot()" class="space-y-4">

    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-warning/80">Case &amp; Resolution Registry · R6</p>
            <h1 class="text-[22px] font-bold tracking-tight">Case File Registry</h1>
            <p class="text-sm text-muted-foreground mt-0.5">The complete case file for every traveller of concern — identity, travel, clinical, outcome.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-md border border-border/70 bg-card px-2.5 py-1 text-[11.5px] font-mono text-foreground/80" x-show="window_label"><span class="status-dot status-dot-live"></span><span x-text="window_label"></span></span>
            <button class="btn btn-outline btn-xs" @click="filtersOpen = !filtersOpen" x-text="filtersOpen ? 'Hide Filters' : 'Show Filters'"></button>
        </div>
    </section>

    <section class="card" x-show="filtersOpen" x-cloak>
        <div class="card-content py-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
                <div><label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">POE</label><select class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm" x-model="filters.poe"><option value="">All POEs</option><template x-for="(name, code) in (meta.poes || {})" :key="code"><option :value="code" x-text="name"></option></template></select></div>
                <div><label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Gender</label><select class="mt-1 h-10 w-full rounded-md border border-input bg-background px-3 text-sm" x-model="filters.gender"><option value="">All</option><option value="MALE">Male</option><option value="FEMALE">Female</option><option value="OTHER">Other</option><option value="UNKNOWN">Unknown</option></select></div>
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
            ['target' => 'disposition', 'serverKey' => 'disposition_mix',        'title' => 'Case Disposition Mix', 'subtitle' => 'How cases were closed at the border'],
            ['target' => 'diseases',    'serverKey' => 'top_suspected_diseases', 'title' => 'Top Suspected Diseases', 'subtitle' => 'Top 10 diseases ranked by clinical engine'],
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
            <div class="flex items-start justify-between gap-3"><div><h3 class="text-[14px] font-semibold tracking-tight">Travellers</h3><p class="text-[12px] text-muted-foreground">Click any row for the complete case file with walkthrough.</p></div><span class="text-[11px] font-mono text-muted-foreground" x-text="(pagination.total ?? 0).toLocaleString() + ' cases'"></span></div>
            <div class="flex flex-col sm:flex-row gap-2 sm:items-center">
                <div class="relative flex-1 max-w-md"><svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg><input type="search" placeholder="Search name, nationality, document, POE..." class="h-9 w-full rounded-md border border-input bg-background pl-8 pr-3 text-sm" x-model.debounce.300ms="search" @input="loadRecords(1)"></div>
                <div class="flex flex-wrap items-center gap-1.5">
                    <span class="rpt-chip" :class="cat === 'all' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('all')">All <span class="font-mono opacity-70" x-text="categoryCounts.all || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'released' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('released')">Released <span class="font-mono opacity-70" x-text="categoryCounts.released || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'referred' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('referred')">Referred <span class="font-mono opacity-70" x-text="categoryCounts.referred || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'isolated' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('isolated')">Isolated <span class="font-mono opacity-70" x-text="categoryCounts.isolated || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'delayed' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('delayed')">Delayed <span class="font-mono opacity-70" x-text="categoryCounts.delayed || 0"></span></span>
                    <span class="rpt-chip" :class="cat === 'high_risk' ? 'rpt-chip-active' : 'rpt-chip-idle'" @click="setCat('high_risk')">High-Risk <span class="font-mono opacity-70" x-text="categoryCounts.high_risk || 0"></span></span>
                </div>
            </div>
        </div>
        <div class="overflow-x-auto"><table class="table"><thead class="table-head sticky top-0"><tr>
            <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('name')">Traveller <span x-html="sortIcon('name')"></span></span></th>
            <th class="table-head-th">Nationality</th>
            <th class="table-head-th">Sex</th>
            <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('age')">Age <span x-html="sortIcon('age')"></span></span></th>
            <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('poe_code')">POE <span x-html="sortIcon('poe_code')"></span></span></th>
            <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('opened_at')">Opened <span x-html="sortIcon('opened_at')"></span></span></th>
            <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('disposition')">Disposition <span x-html="sortIcon('disposition')"></span></span></th>
            <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('risk_level')">Risk <span x-html="sortIcon('risk_level')"></span></span></th>
        </tr></thead><tbody class="table-body">
            <template x-if="loading"><template x-for="i in 10" :key="i"><tr class="border-b border-border/40">
                <td class="table-cell"><div class="rpt-skel h-4 w-40"></div></td>
                <td class="table-cell"><div class="rpt-skel h-4 w-12"></div></td>
                <td class="table-cell"><div class="rpt-skel h-4 w-12"></div></td>
                <td class="table-cell text-right"><div class="rpt-skel h-4 w-8 ml-auto"></div></td>
                <td class="table-cell"><div class="rpt-skel h-4 w-20"></div></td>
                <td class="table-cell"><div class="rpt-skel h-4 w-32"></div></td>
                <td class="table-cell"><div class="rpt-skel h-5 w-20"></div></td>
                <td class="table-cell"><div class="rpt-skel h-5 w-16"></div></td>
            </tr></template></template>
            <template x-if="!loading"><template x-for="r in rows" :key="r.id"><tr class="table-row cursor-pointer" @click="openDrill(r.id)">
                <td class="table-cell"><div class="font-medium" x-text="r.name"></div><div class="text-[11px] text-muted-foreground" x-text="r.triage || ''"></div></td>
                <td class="table-cell text-[12.5px]" x-text="r.nationality || '—'"></td>
                <td class="table-cell text-[12.5px]" x-text="r.gender || '—'"></td>
                <td class="table-cell text-right font-mono" x-text="r.age ?? '—'"></td>
                <td class="table-cell text-[12.5px]" x-text="r.poe_code || '—'"></td>
                <td class="table-cell text-[12px] text-muted-foreground" x-text="r.opened_at ? new Date(r.opened_at).toLocaleString() : '—'"></td>
                <td class="table-cell"><span class="badge" :class="badgeForDisposition(r.disposition)" x-text="r.disposition || '—'"></span></td>
                <td class="table-cell"><span class="badge" :class="badgeForRisk(r.risk_level)" x-text="r.risk_level || '—'"></span></td>
            </tr></template></template>
            <tr x-show="!loading && !rows.length"><td colspan="8" class="table-cell py-12 text-center text-muted-foreground">No cases match the current filters.</td></tr>
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

    {{-- DRILL MODAL — full case file with walkthrough --}}
    <template x-teleport="body">
        <div x-show="drill.open" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" @keydown.escape.window="drill.open = false">
            <div class="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm" @click="drill.open = false"></div>
            <div class="relative z-50 w-full sm:w-[96vw] sm:max-w-[1400px] h-[94vh] sm:max-h-[94vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl border border-border bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-border/60 bg-white/95 backdrop-blur px-5 py-3.5">
                    <div class="min-w-0"><p class="text-[11px] font-semibold uppercase tracking-[.14em] text-warning/80">Case File · 100% of available data</p><h2 class="text-[17px] font-bold tracking-tight truncate" x-text="drill.data?.identity?.name || ('Case #' + (drill.caseId || ''))"></h2></div>
                    <button class="btn btn-ghost btn-icon-xs" @click="drill.open = false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </div>
                <div class="p-5 space-y-4" x-show="drill.data">
                    {{-- Smart wizard --}}
                    <div class="rounded-xl border-2 border-warning/30 bg-gradient-to-br from-warning-soft/40 to-info-soft/30 p-4 sm:p-5" x-show="wizard.open">
                        <div class="flex items-start justify-between gap-3 mb-3">
                            <div class="min-w-0"><p class="text-[11px] font-semibold uppercase tracking-[.14em] text-warning">Case File Walkthrough · Deterministic AI</p><h3 class="text-[16px] font-bold tracking-tight">A guided tour of this traveller's case file</h3></div>
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
                    <button x-show="!wizard.open" class="btn btn-soft-brand btn-xs w-full" @click="wizard.open = true; wizard.step = 1">Re-open the case-file walkthrough</button>

                    {{-- KPI strip --}}
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2.5">
                        <div class="rpt-tile p-3" :class="'rpt-tile-' + dispositionTone(drill.data?.case?.final_disposition)"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Disposition</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.case?.final_disposition || '—'"></p></div>
                        <div class="rpt-tile p-3" :class="'rpt-tile-' + riskTone(drill.data?.clinical?.risk_level)"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Risk</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.clinical?.risk_level || '—'"></p></div>
                        <div class="rpt-tile p-3" :class="drill.data?.clinical?.triage_category === 'EMERGENCY' ? 'rpt-tile-critical' : (drill.data?.clinical?.triage_category === 'URGENT' ? 'rpt-tile-warning' : 'rpt-tile-success')"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Triage</p><p class="text-xl font-bold mt-0.5" x-text="drill.data?.clinical?.triage_category || '—'"></p></div>
                        <div class="rpt-tile p-3" :class="drill.data?.alert ? 'rpt-tile-danger' : 'rpt-tile-neutral'"><p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Linked Alert</p><p class="text-[14px] font-semibold mt-1" x-text="drill.data?.alert ? (drill.data.alert.code || ('#' + drill.data.alert.id)) : 'None'"></p></div>
                    </div>

                    {{-- Identity --}}
                    <details class="rpt-section" open><summary class="rpt-section-head"><span>Identity &amp; Demographics</span></summary><div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        <div class="rpt-field"><p class="rpt-field-label">Name</p><p class="font-medium" x-text="drill.data?.identity?.name || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Initials</p><p x-text="drill.data?.identity?.initials || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Anonymous Code</p><p class="font-mono text-[11.5px]" x-text="drill.data?.identity?.anonymous_code || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Document</p><p class="font-mono text-[11.5px]" x-text="(drill.data?.identity?.document_type || '—') + ' · ' + (drill.data?.identity?.document_number || '—')"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Gender</p><p x-text="drill.data?.identity?.gender || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Age</p><p x-text="drill.data?.identity?.age ?? '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Date of Birth</p><p x-text="drill.data?.identity?.dob || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Nationality</p><p x-text="drill.data?.identity?.nationality || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Occupation</p><p x-text="drill.data?.identity?.occupation || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Residence Country</p><p x-text="drill.data?.identity?.residence_country || '—'"></p></div>
                        <div class="rpt-field col-span-2"><p class="rpt-field-label">Residence Address</p><p x-text="drill.data?.identity?.residence_address || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Phone</p><p class="font-mono text-[11.5px]" x-text="drill.data?.identity?.phone || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Phone (alt)</p><p class="font-mono text-[11.5px]" x-text="drill.data?.identity?.phone_alt || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Email</p><p class="text-[11.5px]" x-text="drill.data?.identity?.email || '—'"></p></div>
                        <div class="rpt-field col-span-2"><p class="rpt-field-label">Destination Address</p><p x-text="drill.data?.identity?.destination_address || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Destination District</p><p x-text="drill.data?.identity?.destination_district || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Emergency Contact</p><p class="font-medium" x-text="drill.data?.identity?.emergency_contact_name || '—'"></p><p class="font-mono text-[11px] text-muted-foreground" x-text="drill.data?.identity?.emergency_contact_phone || ''"></p></div>
                    </div></details>

                    {{-- Travel --}}
                    <details class="rpt-section" open><summary class="rpt-section-head"><span>Travel</span></summary><div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <div class="rpt-field"><p class="rpt-field-label">Country of Origin</p><p x-text="drill.data?.travel?.origin_country || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Embarkation Port / City</p><p x-text="drill.data?.travel?.embarkation_port_city || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Conveyance</p><p x-text="(drill.data?.travel?.conveyance_type || '—') + ' · ' + (drill.data?.travel?.conveyance_identifier || '')"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Seat</p><p class="font-mono" x-text="drill.data?.travel?.seat_number || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Arrival</p><p x-text="drill.data?.travel?.arrival_datetime ? new Date(drill.data.travel.arrival_datetime).toLocaleString() : '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Departure</p><p x-text="drill.data?.travel?.departure_datetime ? new Date(drill.data.travel.departure_datetime).toLocaleString() : '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Purpose</p><p x-text="drill.data?.travel?.purpose_of_travel || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Stay (planned)</p><p x-text="drill.data?.travel?.planned_length_of_stay !== null ? drill.data.travel.planned_length_of_stay + ' days' : '—'"></p></div>
                        <div class="rpt-field col-span-3" x-show="(drill.data?.travel?.countries || []).length">
                            <p class="rpt-field-label mb-1.5">Countries on Itinerary</p>
                            <table class="w-full text-[12.5px]"><thead class="text-[10px] uppercase tracking-wider text-muted-foreground"><tr><th class="text-left py-1">Country</th><th class="text-left py-1">Role</th><th class="text-left py-1">Arrival</th><th class="text-left py-1">Departure</th></tr></thead><tbody>
                                <template x-for="(c, i) in (drill.data?.travel?.countries || [])" :key="i"><tr class="border-t border-border/40"><td class="py-1 font-mono" x-text="c.country_code"></td><td class="py-1" x-text="c.travel_role"></td><td class="py-1" x-text="c.arrival_date || '—'"></td><td class="py-1" x-text="c.departure_date || '—'"></td></tr></template>
                            </tbody></table>
                        </div>
                    </div></details>

                    {{-- Clinical --}}
                    <details class="rpt-section" open><summary class="rpt-section-head"><span>Clinical Picture</span></summary><div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
                        <div class="rpt-field"><p class="rpt-field-label">General Appearance</p><p x-text="drill.data?.clinical?.general_appearance || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Triage</p><p x-text="drill.data?.clinical?.triage_category || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Risk Level</p><p x-text="drill.data?.clinical?.risk_level || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Emergency Signs</p><p x-text="drill.data?.clinical?.emergency_signs_present ? 'Yes' : 'No'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Temperature</p><p x-text="drill.data?.clinical?.temperature_value !== null ? (drill.data.clinical.temperature_value + (drill.data.clinical.temperature_unit === 'F' ? '°F' : '°C')) : '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Pulse</p><p x-text="drill.data?.clinical?.pulse_rate !== null ? (drill.data.clinical.pulse_rate + ' bpm') : '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Respiratory Rate</p><p x-text="drill.data?.clinical?.respiratory_rate !== null ? drill.data.clinical.respiratory_rate : '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">SpO₂</p><p x-text="drill.data?.clinical?.oxygen_saturation !== null ? (drill.data.clinical.oxygen_saturation + '%') : '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Blood Pressure</p><p x-text="(drill.data?.clinical?.bp_systolic ?? '—') + ' / ' + (drill.data?.clinical?.bp_diastolic ?? '—')"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Syndrome</p><p x-text="drill.data?.clinical?.syndrome_classification || '—'"></p></div>
                        <div class="rpt-field col-span-2 sm:col-span-3 lg:col-span-4" x-show="drill.data?.clinical?.officer_notes"><p class="rpt-field-label">Officer Notes</p><p class="text-[12.5px] leading-snug" x-text="drill.data?.clinical?.officer_notes || '—'"></p></div>
                    </div></details>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        {{-- Symptoms --}}
                        <details class="rpt-section"><summary class="rpt-section-head"><span>Symptoms <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.symptoms?.length ?? 0) + ')'"></span></span></summary><div class="rpt-section-body">
                            <table class="w-full text-[13px]"><thead class="text-[10px] uppercase tracking-wider text-muted-foreground"><tr><th class="text-left py-1.5">Symptom</th><th class="text-left py-1.5">Status</th><th class="text-left py-1.5">Onset</th></tr></thead><tbody>
                                <template x-for="(s, i) in (drill.data?.symptoms || [])" :key="i"><tr class="border-t border-border/40">
                                    <td class="py-1.5"><span class="font-medium" x-text="s.name"></span><span x-show="s.is_red_flag" class="badge badge-critical ml-1 text-[9px]">RED</span></td>
                                    <td class="py-1.5" x-text="s.is_present ? 'Present' : (s.explicit_absent ? 'Explicitly Absent' : 'Not noted')"></td>
                                    <td class="py-1.5 text-[11.5px] text-muted-foreground" x-text="s.onset_date || '—'"></td>
                                </tr></template>
                                <tr x-show="!(drill.data?.symptoms?.length)"><td colspan="3" class="py-3 text-center text-muted-foreground">No symptoms recorded.</td></tr>
                            </tbody></table>
                        </div></details>

                        {{-- Exposures --}}
                        <details class="rpt-section"><summary class="rpt-section-head"><span>Exposures <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.exposures?.length ?? 0) + ')'"></span></span></summary><div class="rpt-section-body">
                            <table class="w-full text-[13px]"><thead class="text-[10px] uppercase tracking-wider text-muted-foreground"><tr><th class="text-left py-1.5">Exposure</th><th class="text-left py-1.5">Response</th></tr></thead><tbody>
                                <template x-for="(e, i) in (drill.data?.exposures || [])" :key="i"><tr class="border-t border-border/40">
                                    <td class="py-1.5"><span class="font-medium" x-text="e.name"></span><span x-show="e.is_high_risk" class="badge badge-critical ml-1 text-[9px]">HIGH</span></td>
                                    <td class="py-1.5"><span class="badge" :class="e.response === 'YES' ? 'badge-warning' : (e.response === 'NO' ? 'badge-success' : 'badge-secondary')" x-text="e.response"></span></td>
                                </tr></template>
                                <tr x-show="!(drill.data?.exposures?.length)"><td colspan="2" class="py-3 text-center text-muted-foreground">No exposures recorded.</td></tr>
                            </tbody></table>
                        </div></details>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                        {{-- Suspected diseases --}}
                        <details class="rpt-section"><summary class="rpt-section-head"><span>Suspected Diseases <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.diseases?.length ?? 0) + ')'"></span></span></summary><div class="rpt-section-body">
                            <table class="w-full text-[13px]"><thead class="text-[10px] uppercase tracking-wider text-muted-foreground"><tr><th class="text-left py-1.5">Rank</th><th class="text-left py-1.5">Disease</th><th class="text-right py-1.5">Confidence</th></tr></thead><tbody>
                                <template x-for="(d, i) in (drill.data?.diseases || [])" :key="i"><tr class="border-t border-border/40"><td class="py-1.5 font-mono" x-text="d.rank"></td><td class="py-1.5"><span class="font-medium" x-text="d.name"></span><span class="ml-1 font-mono text-[11px] text-muted-foreground" x-text="d.code"></span></td><td class="py-1.5 text-right font-mono" x-text="d.confidence !== null ? Number(d.confidence).toFixed(2) : '—'"></td></tr></template>
                                <tr x-show="!(drill.data?.diseases?.length)"><td colspan="3" class="py-3 text-center text-muted-foreground">No suspected diseases ranked.</td></tr>
                            </tbody></table>
                        </div></details>

                        {{-- Actions --}}
                        <details class="rpt-section"><summary class="rpt-section-head"><span>Actions Taken <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.actions?.length ?? 0) + ')'"></span></span></summary><div class="rpt-section-body">
                            <template x-for="(a, i) in (drill.data?.actions || [])" :key="i"><div class="flex items-center justify-between border-t border-border/40 pt-2 first:border-t-0 first:pt-0">
                                <div><p class="text-[12.5px] font-medium" x-text="a.action_code"></p><p class="text-[11.5px] text-muted-foreground" x-text="a.details || ''"></p></div>
                                <span class="badge" :class="a.is_done ? 'badge-success' : 'badge-warning'" x-text="a.is_done ? 'Done' : 'Pending'"></span>
                            </div></template>
                            <p x-show="!(drill.data?.actions?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No actions recorded.</p>
                        </div></details>
                    </div>

                    {{-- Samples --}}
                    <details class="rpt-section"><summary class="rpt-section-head"><span>Lab Samples <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.samples?.length ?? 0) + ')'"></span></span></summary><div class="rpt-section-body">
                        <template x-for="(s, i) in (drill.data?.samples || [])" :key="i"><div class="border-t border-border/40 pt-2 first:border-t-0 first:pt-0 grid grid-cols-2 sm:grid-cols-4 gap-2 text-[13px]">
                            <div><p class="rpt-field-label">Type</p><p x-text="s.sample_type || '—'"></p></div>
                            <div><p class="rpt-field-label">Identifier</p><p class="font-mono text-[11.5px]" x-text="s.sample_identifier || '—'"></p></div>
                            <div><p class="rpt-field-label">Lab</p><p x-text="s.lab_destination || '—'"></p></div>
                            <div><p class="rpt-field-label">Collected</p><p x-text="s.collected_at ? new Date(s.collected_at).toLocaleString() : (s.sample_collected ? 'Yes' : 'No')"></p></div>
                        </div></template>
                        <p x-show="!(drill.data?.samples?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No lab samples recorded.</p>
                    </div></details>

                    {{-- Disposition --}}
                    <details class="rpt-section" open><summary class="rpt-section-head"><span>Disposition</span></summary><div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <div class="rpt-field"><p class="rpt-field-label">Final Disposition</p><p class="font-medium" x-text="drill.data?.case?.final_disposition || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Details</p><p x-text="drill.data?.case?.disposition_details || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Status</p><p x-text="drill.data?.case?.case_status || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Outcome</p><p x-text="drill.data?.case?.screening_outcome || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Dispositioned At</p><p x-text="drill.data?.case?.dispositioned_at ? new Date(drill.data.case.dispositioned_at).toLocaleString() : '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Closed At</p><p x-text="drill.data?.case?.closed_at ? new Date(drill.data.case.closed_at).toLocaleString() : '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Follow-up Required</p><p x-text="drill.data?.case?.followup_required ? 'Yes — ' + (drill.data.case.followup_assigned_level || 'unspecified level') : 'No'"></p></div>
                    </div></details>

                    {{-- Linked alert + outcome --}}
                    <details class="rpt-section" x-show="drill.data?.alert"><summary class="rpt-section-head"><span>Linked Alert &amp; Outcome</span></summary><div class="rpt-section-body grid grid-cols-2 sm:grid-cols-3 gap-3">
                        <div class="rpt-field"><p class="rpt-field-label">Alert Code</p><p class="font-mono text-[11.5px]" x-text="drill.data?.alert?.code || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Alert Title</p><p class="font-medium" x-text="drill.data?.alert?.title || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Status</p><p x-text="drill.data?.alert?.status || '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Risk</p><span class="badge" :class="badgeForRisk(drill.data?.alert?.risk_level)" x-text="drill.data?.alert?.risk_level || '—'"></span></div>
                        <div class="rpt-field"><p class="rpt-field-label">Created</p><p x-text="drill.data?.alert?.created_at ? new Date(drill.data.alert.created_at).toLocaleString() : '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Closed</p><p x-text="drill.data?.alert?.closed_at ? new Date(drill.data.alert.closed_at).toLocaleString() : '—'"></p></div>
                        <div class="rpt-field"><p class="rpt-field-label">Closure Reason</p><p x-text="drill.data?.alert?.close_category || '—'"></p></div>
                        <div class="rpt-field" x-show="drill.data?.outcome"><p class="rpt-field-label">Classification</p><span class="badge" :class="badgeForClassification(drill.data?.outcome?.classification)" x-text="drill.data?.outcome?.classification || '—'"></span></div>
                        <div class="rpt-field" x-show="drill.data?.outcome"><p class="rpt-field-label">Lab Status</p><p x-text="drill.data?.outcome?.lab_status || '—'"></p></div>
                        <div class="rpt-field" x-show="drill.data?.outcome"><p class="rpt-field-label">IHR</p><p x-text="drill.data?.outcome?.ihr_notified ? 'Notified — ' + (drill.data.outcome.ihr_reference || '—') : 'Not notified'"></p></div>
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
function rptCaseFiles() {
    return {
        filters: { poe: '', gender: '', start_date: '', end_date: '' },
        meta: {}, kpis: [], rows: [],
        pagination: { page: 1, per_page: 10, total: 0, total_pages: 1, from: 0, to: 0 },
        controls: { sort: 'opened_at', dir: 'desc', q: '', cat: 'all' },
        categoryCounts: {},
        search: '', cat: 'all', window_label: '',
        loading: false, filtersOpen: false,
        chartObjs: { disposition: null, diseases: null },
        chartData: { disposition: null, diseases: null },
        drill: { open: false, caseId: null, data: null },
        wizard: { open: true, step: 1 },
        explainer: { open: false, key: null, spec: null },
        explainerSpecs: {
            disposition: {
                title: 'Case Disposition Mix',
                what: 'How cases were closed at the border — RELEASED, REFERRED, TRANSFERRED, ISOLATED, QUARANTINED, DELAYED, DENIED_BOARDING, OTHER, or NOT_SET (still open).',
                how: 'Larger bars = more cases ending in that state. A growing ISOLATED/QUARANTINED bar signals containment activity; a large NOT_SET bar means cases are still in progress.',
                decisions: ['Spot containment trends.', 'Identify processing bottlenecks (large NOT_SET).', 'Inform facility-referral capacity needs.'],
                source: 'secondary_screenings GROUP BY COALESCE(final_disposition, "NOT_SET") ORDER BY COUNT(*) DESC.',
                caveats: 'NOT_SET means the case is still open; not "no decision".',
            },
            diseases: {
                title: 'Top Suspected Diseases',
                what: 'Top 10 diseases ranked by the clinical scoring engine across cases in the window.',
                how: 'Each row in secondary_suspected_diseases counts as one suspicion. Tall bars = consistently flagged across multiple cases.',
                decisions: ['Detect emerging clinical signals before lab confirmation.', 'Brief leadership on suspected disease load.', 'Plan lab capacity by suspected pathogen.'],
                source: 'secondary_suspected_diseases (joined by secondary_screening_id) GROUP BY disease_code ORDER BY COUNT(*) DESC LIMIT 10.',
                caveats: 'Suspicions ≠ diagnoses. Lab confirmation lives in alert_case_outcomes.lab_status.',
            },
        },

        async boot() { await this.loadMeta(); await this.apply(); },
        async loadMeta() { const r = await fetch('{{ url('/admin/reports/rpt-case-files/meta') }}'); const j = await r.json(); this.meta = j.data || {}; },
        async apply() { this.loading = true; await Promise.all([this.loadKpis(), this.loadChart('disposition_mix', 'disposition'), this.loadChart('top_suspected_diseases', 'diseases'), this.loadRecords(1)]); this.loading = false; },
        resetFilters() { this.filters = { poe: '', gender: '', start_date: '', end_date: '' }; this.search = ''; this.cat = 'all'; this.apply(); },
        qs() { const p = new URLSearchParams(); for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v); return p.toString(); },
        async loadKpis() { const r = await fetch('{{ url('/admin/reports/rpt-case-files/kpis') }}?' + this.qs()); const j = await r.json(); this.kpis = j.data?.kpis || []; this.window_label = j.data?.window?.label || ''; },
        async loadChart(key, target) { const r = await fetch('{{ url('/admin/reports/rpt-case-files/chart') }}/' + key + '?' + this.qs()); const j = await r.json(); this.chartData[target] = j.data; this.renderChart(target, j.data); },

        renderChart(target, payload) {
            if (!payload || !this.$refs['chart_' + target]) return;
            if (this.chartObjs[target]) this.chartObjs[target].destroy();
            const isDonut = target === 'disposition';
            const ds = (payload.datasets || [])[0];
            const data = ds?.data || [];
            const palette = ['#10b981', '#3b82f6', '#f59e0b', '#ef4444', '#8b5cf6', '#7f1d1d', '#06b6d4', '#94a3b8', '#ec4899', '#facc15'];
            const colors = data.map((_, i) => palette[i % palette.length]);
            this.chartObjs[target] = new Chart(this.$refs['chart_' + target].getContext('2d'), {
                type: isDonut ? 'doughnut' : 'bar',
                data: { labels: payload.labels, datasets: [{ label: ds?.label || 'Cases', data, backgroundColor: isDonut ? colors : colors.map(c => c + 'cc'), borderColor: colors, borderWidth: isDonut ? 0 : 1, borderRadius: isDonut ? 0 : 4 }] },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: isDonut ? 'x' : 'y',
                    cutout: isDonut ? '55%' : undefined,
                    plugins: { legend: { display: isDonut, position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }, tooltip: { backgroundColor: 'rgba(15,23,42,.95)', padding: 10, cornerRadius: 8 } },
                    scales: isDonut ? {} : {
                        x: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } },
                        y: { grid: { display: false }, ticks: { font: { size: 11 }, autoSkip: false } },
                    },
                },
            });
        },

        async loadRecords(page) {
            this.loading = true;
            const p = new URLSearchParams({ page, q: this.search, sort: this.controls.sort, dir: this.controls.dir, cat: this.cat });
            for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v);
            const r = await fetch('{{ url('/admin/reports/rpt-case-files/records') }}?' + p.toString());
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
            if (fmt === 'png') { const c = this.chartObjs[target]; if (!c) return; const a = document.createElement('a'); a.href = c.toBase64Image('image/png', 1); a.download = 'rpt-case-files__' + target + '__' + this.stamp() + '.png'; a.click(); }
            else { const map = { disposition: 'disposition_mix', diseases: 'top_suspected_diseases' }; window.location.href = '{{ url('/admin/reports/rpt-case-files/chart') }}/' + map[target] + '/csv?' + this.qs(); }
        },
        stamp() { const d = new Date(); const p = n => String(n).padStart(2, '0'); return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()); },

        openExplainer(key) { this.explainer.open = true; this.explainer.key = key; this.explainer.spec = this.explainerSpecs[key] || null; },
        explainerHeaders() { const k = this.explainer.key; return (k && this.chartData[k]?.csv_headers) || []; },
        explainerRows()    { const k = this.explainer.key; return (k && this.chartData[k]?.csv_rows)    || []; },

        async openDrill(id) {
            this.drill.open = true; this.drill.caseId = id; this.drill.data = null;
            this.wizard = { open: true, step: 1 };
            const r = await fetch('{{ url('/admin/reports/rpt-case-files/records') }}/' + id);
            if (!r.ok) { this.drill.data = { error: r.status }; return; }
            const j = await r.json(); this.drill.data = j.data;
        },

        /* ── 7-step Case File walkthrough (deterministic) ── */
        wizardSteps() {
            return [
                { id: 1, title: 'Snapshot',          ...this.wSnapshot() },
                { id: 2, title: 'Identity',          ...this.wIdentity() },
                { id: 3, title: 'Travel',            ...this.wTravel() },
                { id: 4, title: 'Clinical',          ...this.wClinical() },
                { id: 5, title: 'Symptoms & Exposures', ...this.wSymptoms() },
                { id: 6, title: 'Suspicions & Actions', ...this.wSuspicions() },
                { id: 7, title: 'Outcome',           ...this.wOutcome() },
            ];
        },

        wSnapshot() {
            const c = this.drill.data?.case || {};
            const cl = this.drill.data?.clinical || {};
            const id = this.drill.data?.identity || {};
            return {
                headline: `${id.name || 'Anonymous traveller'} — ${c.final_disposition || 'still in progress'} at POE “${c.poe_code || 'unknown'}”.`,
                narrative: `Secondary screening opened on ${c.opened_at ? new Date(c.opened_at).toLocaleString() : 'unknown date'}. Clinician marked the case ${cl.risk_level || 'UNRATED'} risk and triaged it as ${cl.triage_category || 'unclassified'}. ${c.followup_required ? 'Follow-up was flagged at ' + (c.followup_assigned_level || 'unspecified') + ' level.' : 'No follow-up was flagged.'} ${this.drill.data?.alert ? 'A formal alert was raised on this case (code ' + (this.drill.data.alert.code || '#' + this.drill.data.alert.id) + ').' : 'No formal alert was raised.'}`,
                highlights: [
                    { label: 'Disposition', value: c.final_disposition || '—', tone: this.dispositionTone(c.final_disposition) === 'warning' ? 'warning' : (this.dispositionTone(c.final_disposition) === 'critical' ? 'critical' : 'success') },
                    { label: 'Risk',        value: cl.risk_level || '—', tone: this.riskTone(cl.risk_level) === 'critical' ? 'critical' : 'info' },
                    { label: 'Triage',      value: cl.triage_category || '—', tone: cl.triage_category === 'EMERGENCY' ? 'critical' : (cl.triage_category === 'URGENT' ? 'warning' : 'success') },
                    { label: 'Alert',       value: this.drill.data?.alert ? 'Linked' : 'None', tone: this.drill.data?.alert ? 'warning' : 'success' },
                ],
            };
        },
        wIdentity() {
            const id = this.drill.data?.identity || {};
            return {
                headline: `${id.name || 'Anonymous'}, ${id.age ?? '?'}-year-old ${(id.gender || 'traveller').toLowerCase()} from ${id.nationality || 'unknown nationality'}.`,
                narrative: `Identifying document on file: ${id.document_type || '—'} ${id.document_number || ''}. Occupation: ${id.occupation || 'unrecorded'}. Residence in ${id.residence_country || 'unknown country'}. ${id.email || id.phone ? 'Contact details were captured. ' : 'No contact details were captured. '}${id.emergency_contact_name ? 'Emergency contact: ' + id.emergency_contact_name + '.' : 'No emergency contact recorded.'}`,
                highlights: [
                    { label: 'Nationality', value: id.nationality || '—', tone: 'info' },
                    { label: 'Age',         value: id.age ?? '—', tone: 'info' },
                    { label: 'Gender',      value: id.gender || '—', tone: 'info' },
                ],
            };
        },
        wTravel() {
            const t = this.drill.data?.travel || {};
            const countries = (t.countries || []).length;
            return {
                headline: `Arrived from ${t.origin_country || 'an unknown origin'} via ${(t.conveyance_type || 'unspecified').toLowerCase()}.`,
                narrative: `Embarkation from ${t.embarkation_port_city || 'unspecified'}; conveyance ${t.conveyance_identifier || '—'}${t.seat_number ? ', seat ' + t.seat_number : ''}. Arrival ${t.arrival_datetime ? new Date(t.arrival_datetime).toLocaleString() : 'unrecorded'}. Purpose: ${t.purpose_of_travel || 'unspecified'}. Planned stay: ${t.planned_length_of_stay ?? '—'} day(s). ${countries > 0 ? countries + ' country/countries on the itinerary — see Travel section for the full list.' : 'No transit/visited countries on file.'}`,
                highlights: [
                    { label: 'Origin',       value: t.origin_country || '—', tone: 'info' },
                    { label: 'Mode',         value: t.conveyance_type || '—', tone: 'info' },
                    { label: 'Itinerary',    value: countries + ' countries', tone: countries > 0 ? 'warning' : 'info' },
                ],
            };
        },
        wClinical() {
            const cl = this.drill.data?.clinical || {};
            const vitals = [];
            if (cl.temperature_value !== null) vitals.push(cl.temperature_value + (cl.temperature_unit === 'F' ? '°F' : '°C'));
            if (cl.oxygen_saturation !== null) vitals.push('SpO₂ ' + cl.oxygen_saturation + '%');
            if (cl.pulse_rate !== null) vitals.push('HR ' + cl.pulse_rate);
            return {
                headline: `${cl.general_appearance ? cl.general_appearance.replace('_', ' ') : 'Clinical state unrecorded'}; ${cl.emergency_signs_present ? 'emergency signs present.' : 'no emergency signs.'}`,
                narrative: `Vitals: ${vitals.length ? vitals.join(', ') + '.' : 'not captured.'} ${cl.syndrome_classification ? 'Syndromic classification: ' + cl.syndrome_classification + '. ' : ''}${cl.officer_notes ? 'Officer notes: “' + cl.officer_notes.slice(0, 200) + (cl.officer_notes.length > 200 ? '…' : '') + '”' : 'No officer notes were recorded.'}`,
                highlights: [
                    { label: 'Triage',     value: cl.triage_category || '—', tone: cl.triage_category === 'EMERGENCY' ? 'critical' : (cl.triage_category === 'URGENT' ? 'warning' : 'success') },
                    { label: 'Risk',       value: cl.risk_level || '—', tone: this.riskTone(cl.risk_level) === 'critical' ? 'critical' : 'info' },
                    { label: 'Emergency',  value: cl.emergency_signs_present ? 'Yes' : 'No', tone: cl.emergency_signs_present ? 'critical' : 'success' },
                ],
            };
        },
        wSymptoms() {
            const sx = this.drill.data?.symptoms || [];
            const ex = this.drill.data?.exposures || [];
            const present = sx.filter(s => s.is_present);
            const redFlags = present.filter(s => s.is_red_flag);
            const yesEx   = ex.filter(e => e.response === 'YES');
            const highEx  = yesEx.filter(e => e.is_high_risk);
            return {
                headline: `${present.length} symptom(s) reported${redFlags.length ? ` — ${redFlags.length} red-flag` : ''}; ${yesEx.length} exposure(s) confirmed${highEx.length ? `, ${highEx.length} high-risk` : ''}.`,
                narrative: `${present.length ? 'Symptoms present: ' + present.slice(0, 5).map(s => s.name).join(', ') + (present.length > 5 ? ', and ' + (present.length - 5) + ' more.' : '.') : 'No symptoms were reported as present. '} ${yesEx.length ? 'Exposures confirmed (YES): ' + yesEx.slice(0, 5).map(e => e.name).join(', ') + (yesEx.length > 5 ? ', and ' + (yesEx.length - 5) + ' more.' : '.') : 'No exposures confirmed.'}`,
                highlights: [
                    { label: 'Symptoms',   value: present.length, tone: present.length > 0 ? 'warning' : 'success' },
                    { label: 'Red-Flag',   value: redFlags.length, tone: redFlags.length > 0 ? 'critical' : 'success' },
                    { label: 'Exposures',  value: yesEx.length, tone: yesEx.length > 0 ? 'warning' : 'success' },
                    { label: 'High-Risk',  value: highEx.length, tone: highEx.length > 0 ? 'critical' : 'success' },
                ],
            };
        },
        wSuspicions() {
            const dis = (this.drill.data?.diseases || []).slice(0, 3);
            const actions = this.drill.data?.actions || [];
            const samples = (this.drill.data?.samples || []).filter(s => s.sample_collected);
            return {
                headline: dis.length ? `Top suspected: ${dis[0].name}.` : 'No diseases ranked.',
                narrative: `${dis.length ? 'Engine ranked ' + dis.map(d => d.name).join(', ') + '. ' : ''}${actions.length ? actions.filter(a => a.is_done).length + ' of ' + actions.length + ' clinical actions completed. ' : 'No clinical actions on file. '}${samples.length ? samples.length + ' lab sample(s) collected — see Lab Samples below.' : 'No lab samples collected.'}`,
                highlights: [
                    { label: 'Suspected', value: (this.drill.data?.diseases || []).length, tone: 'info' },
                    { label: 'Actions',   value: actions.filter(a => a.is_done).length + ' / ' + actions.length, tone: 'info' },
                    { label: 'Samples',   value: samples.length, tone: samples.length > 0 ? 'success' : 'info' },
                ],
            };
        },
        wOutcome() {
            const c = this.drill.data?.case || {};
            const a = this.drill.data?.alert;
            const o = this.drill.data?.outcome;
            return {
                headline: `${c.final_disposition || 'No disposition'}${o?.classification ? ' · classification: ' + o.classification : ''}.`,
                narrative: `${c.dispositioned_at ? 'Dispositioned on ' + new Date(c.dispositioned_at).toLocaleString() + '. ' : 'Not yet dispositioned. '}${c.disposition_details ? 'Details: ' + c.disposition_details + '. ' : ''}${a ? 'Linked alert ' + (a.code || '#' + a.id) + ' is currently ' + a.status + '. ' : ''}${o?.ihr_notified ? 'IHR notified (ref ' + (o.ihr_reference || '—') + ').' : 'IHR not notified.'}`,
                highlights: [
                    { label: 'Disposition', value: c.final_disposition || '—', tone: this.dispositionTone(c.final_disposition) },
                    { label: 'Classification', value: o?.classification || 'Unrecorded', tone: o?.classification === 'CONFIRMED' ? 'critical' : (o?.classification ? 'info' : 'warning') },
                    { label: 'Linked Alert', value: a ? a.status : 'None', tone: a ? 'warning' : 'success' },
                ],
            };
        },

        badgeForRisk(r) { return { LOW: 'badge-low', MEDIUM: 'badge-medium', HIGH: 'badge-high', CRITICAL: 'badge-critical' }[r] || 'badge-secondary'; },
        badgeForDisposition(d) { return { RELEASED: 'badge-success', DELAYED: 'badge-warning', QUARANTINED: 'badge-warning', ISOLATED: 'badge-warning', REFERRED: 'badge-info', TRANSFERRED: 'badge-info', DENIED_BOARDING: 'badge-critical', OTHER: 'badge-secondary' }[d] || 'badge-secondary'; },
        badgeForClassification(c) { return { CONFIRMED: 'badge-critical', PROBABLE: 'badge-danger', SUSPECTED: 'badge-warning', NON_CASE: 'badge-info' }[c] || 'badge-secondary'; },
        riskTone(r) { return { LOW: 'success', MEDIUM: 'warning', HIGH: 'danger', CRITICAL: 'critical' }[r] || 'neutral'; },
        dispositionTone(d) { return { RELEASED: 'success', REFERRED: 'info', TRANSFERRED: 'info', QUARANTINED: 'warning', ISOLATED: 'warning', DELAYED: 'warning', DENIED_BOARDING: 'critical' }[d] || 'neutral'; },
    };
}
</script>
@endsection
