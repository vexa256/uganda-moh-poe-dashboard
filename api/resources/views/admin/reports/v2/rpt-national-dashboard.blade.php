@extends('admin.layout')

@section('crumb', 'Dashboard')
@section('title', 'National Dashboard')

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
    .rpt-th-sort { @apply inline-flex items-center gap-1 cursor-pointer select-none; }
    .rpt-section { @apply rounded-lg border bg-card; }
    .rpt-section-head { @apply flex items-center justify-between px-4 py-3 cursor-pointer text-[13px] font-semibold; }
    .rpt-section-body { @apply px-4 pb-4; }
    .rpt-skel { @apply animate-pulse bg-muted/60 rounded; }
    .wiz-step { @apply inline-flex items-center justify-center h-7 w-7 rounded-full text-[11px] font-bold border cursor-pointer transition-colors; }
    .wiz-step-active { @apply bg-brand text-white border-brand; }
    .wiz-step-idle { @apply bg-card text-muted-foreground border-border/60 hover:bg-muted/60; }
</style>
@endpush

@section('content')
<div x-data="rptNationalDashboard()" x-init="boot()" class="space-y-4">

    {{-- HEADER --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0 flex-1">
            <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-brand">Executive Overview · Landing</p>
            <h1 class="text-[22px] font-bold tracking-tight">National Dashboard</h1>
            <p class="text-sm text-muted-foreground mt-0.5">Right now: is everything healthy, where is risk concentrating, and where should you drill?</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button class="btn btn-outline btn-xs" @click="filtersOpen = !filtersOpen" x-text="filtersOpen ? 'Hide Filters' : 'Show Filters'"></button>
        </div>
    </section>

    {{-- FILTERS (collapsed) --}}
    <section class="card" x-show="filtersOpen" x-cloak>
        <div class="card-content py-4">
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">POE</label>
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

    {{-- KPI ROW · 6 tiles · each links to its R{n} report --}}
    <section class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
        <template x-for="k in kpis" :key="k.key">
            <a :href="kpiHref(k)" class="rpt-tile block hover:shadow-elevation-3" :class="'rpt-tile-' + (k.tone || 'neutral')">
                <p class="kpi-label" x-text="k.label"></p>
                <p class="kpi-value mt-1" x-text="k.value"></p>
                <p class="text-[11px] text-muted-foreground mt-1 leading-snug" x-text="k.hint"></p>
                <p class="text-[10px] mt-1.5 text-info-foreground/80 hover:underline" x-text="'View in ' + (k.href_report || '')"></p>
            </a>
        </template>
        <template x-if="!kpis.length">
            <template x-for="i in 6" :key="i">
                <div class="rpt-tile rpt-tile-neutral"><div class="rpt-skel h-4 w-24"></div><div class="rpt-skel h-8 w-16 mt-2"></div><div class="rpt-skel h-3 w-32 mt-2"></div></div>
            </template>
        </template>
    </section>

    {{-- CHARTS · 8 chart cards · 2-per-row (col-6) --}}
    <section class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ([
            ['target' => 'screenings_30d',   'r' => 'rpt-screening-overview', 'title' => 'Screenings · 30d',           'subtitle' => 'Daily primary + secondary screenings over the last 30 days.'],
            ['target' => 'alerts_by_risk',   'r' => 'rpt-alert-intel',        'title' => 'Alerts by Risk · 30d',        'subtitle' => 'Daily alert counts stacked by risk level — watch for CRITICAL growth.'],
            ['target' => 'top_poes',         'r' => 'rpt-poe-performance',    'title' => 'Top 10 POEs by Volume',       'subtitle' => 'Busiest POEs by primary screening count. Longer bar = higher throughput.'],
            ['target' => 'top_origins',      'r' => 'rpt-country-travel',     'title' => 'Top 10 Origin Countries',     'subtitle' => 'Secondary-screened travellers by origin — spot high-risk corridors.'],
            ['target' => 'outcome_mix',      'r' => 'rpt-resolution-db',      'title' => 'Outcome Classification Mix',  'subtitle' => 'Confirmed / Probable / Suspected / Non-case breakdown.'],
            ['target' => 'gender_mix',       'r' => 'rpt-gender',             'title' => 'Traveller Gender Mix',        'subtitle' => 'Gender distribution across primary screenings. Large Unknown = data gap.'],
            ['target' => 'sla_status',       'r' => 'rpt-response-time',      'title' => 'SLA Compliance Status',       'subtitle' => 'Alerts bucketed as Within / At-risk / Breached by risk-level thresholds.'],
            ['target' => 'officer_activity', 'r' => 'rpt-user-activity',      'title' => 'Officer Activity Status',     'subtitle' => 'Active (≤14 days) / Dormant / Locked officers in scope.'],
        ] as $c)
        <div class="card overflow-hidden">
            <div class="flex items-start justify-between gap-3 px-5 py-4 border-b border-border/60">
                <div class="min-w-0">
                    <h3 class="text-[13.5px] font-semibold tracking-tight">{{ $c['title'] }}</h3>
                    <p class="text-[12px] text-muted-foreground mt-0.5 leading-snug">{{ $c['subtitle'] }}</p>
                </div>
                <a class="text-[11px] text-info-foreground hover:underline shrink-0 mt-0.5" :href="reportHref('{{ $c['r'] }}')">View report →</a>
            </div>
            <div class="px-5 pt-4 pb-5">
                <div class="relative h-[260px]"><canvas x-ref="chart_{{ $c['target'] }}"></canvas></div>
                <div class="flex items-center gap-2 mt-3 pt-3 border-t border-border/40">
                    <button class="btn btn-soft-info btn-xs gap-1.5" @click="openExplainer('{{ $c['target'] }}')">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                        Explain this chart
                    </button>
                    <span class="flex-1"></span>
                    <button class="btn btn-outline btn-xs" @click="exportChart('{{ $c['target'] }}', 'png')">PNG</button>
                    <button class="btn btn-outline btn-xs" @click="exportChart('{{ $c['target'] }}', 'csv')">CSV</button>
                </div>
            </div>
        </div>
        @endforeach
    </section>

    {{-- CASES NEEDING ATTENTION · 10-row table · case-bearing → Smart Wizard drill --}}
    <section class="card overflow-hidden">
        <div class="flex flex-col gap-3 p-4 border-b border-border/60">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="text-[14px] font-semibold tracking-tight">Cases Needing Attention</h3>
                    <p class="text-[12px] text-muted-foreground">Open alerts ranked by priority (CRITICAL > HIGH > age). Click any row for the case walkthrough.</p>
                </div>
                <span class="text-[11px] font-mono text-muted-foreground whitespace-nowrap" x-text="(pagination.total ?? 0).toLocaleString() + ' open'"></span>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="table">
                <thead class="table-head sticky top-0">
                    <tr>
                        <th class="table-head-th">Alert</th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('risk')">Risk</span></th>
                        <th class="table-head-th">Status</th>
                        <th class="table-head-th">POE</th>
                        <th class="table-head-th text-right"><span class="rpt-th-sort" @click="toggleSort('age')">Age (h)</span></th>
                        <th class="table-head-th"><span class="rpt-th-sort" @click="toggleSort('sla')">SLA</span></th>
                        <th class="table-head-th">Origin</th>
                    </tr>
                </thead>
                <tbody class="table-body">
                    <template x-if="loading">
                        <template x-for="i in 10" :key="i">
                            <tr class="border-b border-border/40">
                                <td class="table-cell"><div class="rpt-skel h-4 w-40"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-5 w-16"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-5 w-20"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-20"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-12 ml-auto"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-5 w-16"></div></td>
                                <td class="table-cell"><div class="rpt-skel h-4 w-24"></div></td>
                            </tr>
                        </template>
                    </template>
                    <template x-if="!loading">
                        <template x-for="r in rows" :key="r.id">
                            <tr class="table-row cursor-pointer" @click="openDrill(r)">
                                <td class="table-cell"><div class="font-medium truncate max-w-xs" x-text="r.alert_title"></div><div class="text-[11px] font-mono text-muted-foreground" x-text="r.alert_code"></div></td>
                                <td class="table-cell"><span class="badge" :class="badgeForRisk(r.risk_level)" x-text="r.risk_level"></span></td>
                                <td class="table-cell"><span class="badge badge-outline" x-text="r.status"></span></td>
                                <td class="table-cell text-[12px] font-mono" x-text="r.poe_code || '—'"></td>
                                <td class="table-cell text-right font-mono" x-text="r.age_hours"></td>
                                <td class="table-cell"><span class="badge" :class="r.sla_breached ? 'badge-danger' : 'badge-success'" x-text="r.sla_breached ? 'BREACHED' : 'WITHIN'"></span></td>
                                <td class="table-cell text-[12px]" x-text="r.origin || '—'"></td>
                            </tr>
                        </template>
                    </template>
                    <tr x-show="!loading && !rows.length"><td colspan="7" class="table-cell py-12 text-center text-muted-foreground">No open alerts in scope. The system is calm.</td></tr>
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
            </div>
        </div>
    </section>

    {{-- DRILL-DOWN MODAL · Smart Wizard (case-bearing) --}}
    <template x-teleport="body">
        <div x-show="drill.open" x-cloak class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" @keydown.escape.window="drill.open = false">
            <div class="fixed inset-0 z-40 bg-slate-950/70 backdrop-blur-sm" @click="drill.open = false"></div>
            <div class="relative z-50 w-full sm:w-[96vw] sm:max-w-[1400px] h-[94vh] sm:max-h-[94vh] overflow-y-auto rounded-t-2xl sm:rounded-2xl border border-border bg-white shadow-2xl">
                <div class="sticky top-0 z-10 flex items-center justify-between border-b border-border/60 bg-card/95 backdrop-blur px-5 py-3.5">
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-brand">Case Walkthrough</p>
                        <h2 class="text-[17px] font-bold tracking-tight truncate" x-text="drill.row?.alert_title || ''"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="drill.open = false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </div>

                <div class="p-5 space-y-4" x-show="drill.data">
                    {{-- Wizard step bar --}}
                    <div class="flex items-center gap-2 flex-wrap" x-show="!wiz.skip">
                        <template x-for="(label, i) in ['Snapshot','Trigger','Routing','Response Speed','Clinical Picture','Actions Taken','Resolution']" :key="i">
                            <div class="flex items-center gap-2">
                                <span class="wiz-step" :class="wiz.step === i+1 ? 'wiz-step-active' : 'wiz-step-idle'" @click="wiz.step = i+1" x-text="i+1"></span>
                                <span class="text-[11.5px]" :class="wiz.step === i+1 ? 'font-semibold' : 'text-muted-foreground'" x-text="label"></span>
                                <span class="text-muted-foreground/40" x-show="i < 6">▸</span>
                            </div>
                        </template>
                        <button class="ml-auto text-[11px] text-info-foreground hover:underline" @click="wiz.skip = true">Skip walkthrough</button>
                    </div>

                    <div x-show="!wiz.skip" class="rpt-section">
                        <div class="rpt-section-body py-4 space-y-2">
                            <p class="text-[16px] font-bold" x-text="wizHeadline()"></p>
                            <p class="text-[13.5px] text-foreground/85 leading-relaxed" x-text="wizNarrative()"></p>
                            <div class="flex flex-wrap gap-1.5 mt-1">
                                <template x-for="(chip, ci) in wizChips()" :key="ci">
                                    <span class="badge" :class="chip.tone || 'badge-outline'" x-text="chip.text"></span>
                                </template>
                            </div>
                            <div class="flex items-center gap-2 pt-2">
                                <button class="btn btn-outline btn-xs" :disabled="wiz.step <= 1" @click="wiz.step--">Prev</button>
                                <button class="btn btn-brand btn-xs" :disabled="wiz.step >= 7" @click="wiz.step++">Next</button>
                            </div>
                        </div>
                    </div>

                    {{-- Raw data sections (always visible below the wizard) --}}
                    <details class="rpt-section" open>
                        <summary class="rpt-section-head"><span>Alert Facts</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body grid grid-cols-2 sm:grid-cols-4 gap-3 text-[13px]">
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Code</p><p class="font-mono text-[11.5px]" x-text="drill.data?.alert?.code || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Risk</p><p><span class="badge" :class="badgeForRisk(drill.data?.alert?.risk_level)" x-text="drill.data?.alert?.risk_level"></span></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Status</p><p x-text="drill.data?.alert?.status"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">POE</p><p class="font-mono text-[11.5px]" x-text="drill.data?.alert?.poe_code || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Created</p><p x-text="drill.data?.alert?.created_at ? new Date(drill.data.alert.created_at).toLocaleString() : '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Acknowledged</p><p x-text="drill.data?.alert?.acknowledged_at ? new Date(drill.data.alert.acknowledged_at).toLocaleString() : '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Hours Open</p><p class="font-mono" x-text="drill.data?.alert?.hours_open"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">SLA</p><p><span class="badge" :class="drill.data?.alert?.sla_breached ? 'badge-danger' : 'badge-success'" x-text="(drill.data?.alert?.sla_hours || '—') + 'h'"></span></p></div>
                        </div>
                    </details>

                    <details class="rpt-section" x-show="drill.data?.traveller">
                        <summary class="rpt-section-head"><span>Traveller</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body grid grid-cols-2 sm:grid-cols-4 gap-3 text-[13px]">
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Name</p><p x-text="drill.data?.traveller?.name || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Sex/Age</p><p x-text="(drill.data?.traveller?.gender || '—') + ' · ' + (drill.data?.traveller?.age || '—')"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Origin</p><p x-text="drill.data?.traveller?.origin || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Document</p><p class="font-mono text-[11.5px]" x-text="drill.data?.traveller?.document || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Phone</p><p class="font-mono text-[11.5px]" x-text="drill.data?.traveller?.phone || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Risk</p><p><span class="badge" :class="badgeForRisk(drill.data?.traveller?.risk_level)" x-text="drill.data?.traveller?.risk_level || '—'"></span></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Disposition</p><p x-text="drill.data?.traveller?.disposition || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Arrival</p><p x-text="drill.data?.traveller?.arrival ? new Date(drill.data.traveller.arrival).toLocaleString() : '—'"></p></div>
                        </div>
                    </details>

                    <details class="rpt-section" x-show="drill.data?.outcome">
                        <summary class="rpt-section-head"><span>Outcome &amp; Lab</span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body grid grid-cols-2 sm:grid-cols-4 gap-3 text-[13px]">
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Classification</p><p x-text="drill.data?.outcome?.classification || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Lab Status</p><p x-text="drill.data?.outcome?.lab_status || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Lab Disease</p><p x-text="drill.data?.outcome?.lab_disease_code || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">PH Action</p><p x-text="drill.data?.outcome?.ph_action || '—'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">IHR Notified</p><p x-text="drill.data?.outcome?.ihr_notified ? 'Yes' : 'No'"></p></div>
                            <div><p class="text-[10px] uppercase tracking-wider text-muted-foreground">IHR Reference</p><p class="font-mono text-[11.5px]" x-text="drill.data?.outcome?.ihr_reference || '—'"></p></div>
                        </div>
                    </details>

                    <details class="rpt-section">
                        <summary class="rpt-section-head"><span>Followups <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.followups?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body space-y-1.5">
                            <template x-for="(f, i) in (drill.data?.followups ?? [])" :key="i">
                                <div class="flex items-center justify-between border-t border-border/40 pt-2 first:border-t-0 first:pt-0">
                                    <div class="min-w-0"><p class="text-[13px] font-medium" x-text="f.action_label"></p><p class="text-[11px] text-muted-foreground" x-text="(f.due_at ? new Date(f.due_at).toLocaleDateString() : '—') + (f.completed_at ? ' · done ' + new Date(f.completed_at).toLocaleDateString() : '')"></p></div>
                                    <span class="badge" :class="{ 'badge-success': f.status==='COMPLETED', 'badge-warning': f.status==='PENDING' || f.status==='IN_PROGRESS', 'badge-danger': f.status==='BLOCKED' }" x-text="f.status"></span>
                                </div>
                            </template>
                            <p x-show="!(drill.data?.followups?.length)" class="text-center text-muted-foreground py-3 text-[13px]">No followups recorded.</p>
                        </div>
                    </details>

                    <details class="rpt-section">
                        <summary class="rpt-section-head"><span>Timeline <span class="text-muted-foreground font-normal" x-text="'(' + (drill.data?.timeline?.length ?? 0) + ')'"></span></span><svg class="h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg></summary>
                        <div class="rpt-section-body space-y-1.5">
                            <template x-for="(t, i) in (drill.data?.timeline ?? [])" :key="i">
                                <div class="flex items-start justify-between border-t border-border/40 pt-2 first:border-t-0 first:pt-0">
                                    <div class="min-w-0"><p class="text-[13px] font-medium"><span class="badge badge-outline" x-text="t.event_code"></span> <span class="text-[12px]" x-text="t.summary || ''"></span></p><p class="text-[11px] text-muted-foreground" x-text="(t.actor_name || 'System') + ' · ' + (t.created_at ? new Date(t.created_at).toLocaleString() : '—')"></p></div>
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
                    <div><p class="text-[11px] font-semibold uppercase tracking-[.14em] text-info">About this chart</p><h2 class="text-[17px] font-bold tracking-tight truncate" x-text="explainer.spec?.title || ''"></h2></div>
                    <button class="btn btn-ghost btn-icon-xs" @click="explainer.open = false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
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
                        <div class="rounded-lg border border-border bg-white overflow-hidden"><div class="max-h-[72vh] overflow-y-auto"><table class="w-full text-[13px]">
                            <thead class="bg-muted/50 sticky top-0"><tr><template x-for="(h, i) in explainerHeaders()" :key="i"><th class="text-left px-3 py-2 text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground border-b border-border" x-text="h"></th></template></tr></thead>
                            <tbody>
                                <template x-for="(row, ri) in explainerRows()" :key="ri"><tr class="border-b border-border/40"><template x-for="(cell, ci) in row" :key="ci"><td class="px-3 py-2" :class="ci === 0 ? 'font-medium' : 'text-right font-mono'" x-text="cell === null || cell === undefined ? '—' : (typeof cell === 'number' ? cell.toLocaleString() : cell)"></td></template></tr></template>
                                <tr x-show="!explainerRows().length"><td :colspan="explainerHeaders().length || 1" class="px-3 py-12 text-center text-muted-foreground">No rows.</td></tr>
                            </tbody>
                        </table></div></div>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

<script>
function rptNationalDashboard() {
    return {
        filters: { poe: '', start_date: '', end_date: '' },
        meta: {}, kpis: [], rows: [],
        pagination: { page: 1, per_page: 10, total: 0, total_pages: 1, from: 0, to: 0 },
        controls: { sort: 'risk', dir: 'desc' },
        loading: false, filtersOpen: false,
        chartObjs: {}, chartData: {},
        drill: { open: false, row: null, data: null },
        wiz: { step: 1, skip: false },
        explainer: { open: false, key: null, spec: null },
        chartKeys: ['screenings_30d','alerts_by_risk','top_poes','top_origins','outcome_mix','gender_mix','sla_status','officer_activity'],
        explainerSpecs: {
            screenings_30d:   { title:'Screenings · 30 days',     what:'Daily count of primary + secondary screenings across all in-scope POEs.',                                            how:'Two-line trend; deviation between the lines indicates secondary-tier capture rate.',          decisions:['Spot operational dips.','Compare current to prior weeks.'],                              source:'primary_screenings GROUP BY DATE(captured_at); secondary_screenings GROUP BY DATE(opened_at).' },
            alerts_by_risk:   { title:'Alerts by Risk · 30 days', what:'Daily alert counts split by risk_level (LOW/MEDIUM/HIGH/CRITICAL).',                                                     how:'Stack height = total alerts; the critical band is the top — watch for sustained red.',          decisions:['Detect risk-shifts before they breach SLA.','Spot CRITICAL spikes.'],                     source:'alerts GROUP BY DATE(created_at), risk_level.' },
            top_poes:         { title:'Top 10 POEs',              what:'POEs by primary-screening count in the active window.',                                                                  how:'Longest bars are the busiest POEs — focus operational decisions there first.',                  decisions:['Allocate staff to throughput hotspots.','Compare to alert volume in R7.'],                source:'primary_screenings GROUP BY poe_code ORDER BY count DESC LIMIT 10. Names via two-query whereIn ref_poes.' },
            top_origins:      { title:'Top 10 Origins',           what:'Origin countries by secondary-screened traveller count.',                                                                 how:'Bars are travellers from each country; compare against alert volume in R10 to spot signal.',    decisions:['Identify watch-list corridors.','Pre-empt outbreak imports.'],                            source:'secondary_screenings GROUP BY journey_start_country_code; names via two-query whereIn ref_countries.', caveats:'Based on secondary-screened travellers (primary tier has no origin country).' },
            outcome_mix:      { title:'Outcome Mix',              what:'Distribution of alert classifications recorded in alert_case_outcomes.',                                                  how:'Each slice is a classification bucket; Unclassified means no outcome row yet.',                 decisions:['Push for outcomes on Unclassified alerts.','Track Confirmed share over time.'],          source:'alerts LEFT JOIN alert_case_outcomes GROUP BY case_classification.' },
            gender_mix:       { title:'Gender Mix',               what:'Distribution of traveller_gender across primary screenings.',                                                             how:'Pie segments; large Unknown share suggests data-quality gap.',                                  decisions:['Drive screener training where Unknown is high.'],                                          source:'primary_screenings GROUP BY traveler_gender.' },
            sla_status:       { title:'SLA Status',               what:'Alerts in window bucketed by SLA position (Within / At-risk / Breached) using risk-level thresholds (CRITICAL=4h, HIGH=24h, ELSE=48h).', how:'Within is healthy; At-risk is your action queue; Breached is the failure tally.',                decisions:['Action At-risk before they breach.','Drive Breached down via R4 routing review.'],        source:'alerts with TIMESTAMPDIFF(HOUR, created_at, COALESCE(closed_at, NOW())) bucketed.' },
            officer_activity: { title:'Officer Activity',         what:'In-scope officers bucketed as Active (last_activity ≤ 14d), Dormant, or Locked.',                                          how:'Active is your live workforce; Dormant indicates onboarding or offboarding gaps; Locked is security.', decisions:['Reactivate Dormant officers.','Investigate Locked accounts.'],                            source:'users (scoped via user_assignments) bucketed by last_activity_at and locked_until.' },
        },

        async boot() {
            await this.loadMeta();
            await this.apply();
        },
        async loadMeta() {
            const r = await fetch('{{ url('/admin/reports/rpt-national-dashboard/meta') }}');
            if (!r.ok) return;
            const j = await r.json();
            this.meta = j.data || {};
        },
        async apply() {
            this.loading = true;
            const tasks = [this.loadKpis(), this.loadRecords(1)];
            for (const k of this.chartKeys) tasks.push(this.loadChart(k));
            await Promise.all(tasks);
            this.loading = false;
        },
        resetFilters() { this.filters = { poe: '', start_date: '', end_date: '' }; this.apply(); },
        qs() { const p = new URLSearchParams(); for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v); return p.toString(); },

        async loadKpis() {
            const r = await fetch('{{ url('/admin/reports/rpt-national-dashboard/kpis') }}?' + this.qs());
            if (!r.ok) return;
            const j = await r.json();
            this.kpis = j.data?.kpis || [];
        },
        async loadChart(key) {
            const r = await fetch('{{ url('/admin/reports/rpt-national-dashboard/chart') }}/' + key + '?' + this.qs());
            if (!r.ok) return;
            const j = await r.json();
            this.chartData[key] = j.data;
            this.renderChart(key, j.data);
        },
        renderChart(key, payload) {
            if (!payload || !this.$refs['chart_' + key]) return;
            if (this.chartObjs[key]) this.chartObjs[key].destroy();
            const palettes = {
                screenings_30d:   ['#10b981','#3b82f6'],
                alerts_by_risk:   ['#10b981','#3b82f6','#f59e0b','#ef4444'],
                top_poes:         ['#10b981'],
                top_origins:      ['#3b82f6'],
                outcome_mix:      ['#7f1d1d','#ef4444','#f59e0b','#10b981','#64748b'],
                gender_mix:       ['#3b82f6','#ec4899','#8b5cf6','#64748b'],
                sla_status:       ['#10b981','#f59e0b','#ef4444'],
                officer_activity: ['#10b981','#f59e0b','#ef4444'],
            };
            const isLine     = key === 'screenings_30d';
            const isStacked  = key === 'alerts_by_risk';
            const isHoriz    = key === 'top_poes' || key === 'top_origins';
            const isDoughnut = key === 'outcome_mix' || key === 'gender_mix';
            const palette    = palettes[key] || ['#10b981'];
            const datasets = isDoughnut
                ? [{ label: payload.datasets[0]?.label || '', data: payload.datasets[0]?.data || [], backgroundColor: palette }]
                : (payload.datasets || []).map((d, i) => ({
                    label: d.label,
                    data: d.data,
                    backgroundColor: (palette[i % palette.length] || '#10b981') + (isLine ? '22' : 'cc'),
                    borderColor:     palette[i % palette.length] || '#10b981',
                    borderRadius: 4, borderSkipped: false,
                    tension: isLine ? 0.35 : 0,
                    fill: isLine,
                    stack: isStacked ? 's' : undefined,
                }));
            this.chartObjs[key] = new Chart(this.$refs['chart_' + key].getContext('2d'), {
                type: isDoughnut ? 'doughnut' : (isLine ? 'line' : 'bar'),
                data: { labels: payload.labels, datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    indexAxis: isHoriz ? 'y' : 'x',
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: !isHoriz, position: 'bottom', labels: { boxWidth: 10, padding: 14, font: { size: 11 } } },
                        tooltip: { backgroundColor: 'rgba(15,23,42,.95)', padding: 10, cornerRadius: 6, titleFont: { size: 12 }, bodyFont: { size: 11 } },
                    },
                    scales: isDoughnut ? {} : (isHoriz
                        ? { x: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } }, y: { grid: { display: false }, ticks: { font: { size: 11 } } } }
                        : (isStacked
                            ? { x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10 }, maxTicksLimit: 12 } }, y: { stacked: true, beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } } }
                            : { x: { grid: { display: false }, ticks: { font: { size: 10 }, maxTicksLimit: 12 } }, y: { beginAtZero: true, grid: { color: 'rgba(15,23,42,0.05)' }, ticks: { font: { size: 11 } } } })),
                },
            });
        },

        async loadRecords(page) {
            const p = new URLSearchParams({ page, sort: this.controls.sort, dir: this.controls.dir });
            for (const [k, v] of Object.entries(this.filters)) if (v) p.append(k, v);
            const r = await fetch('{{ url('/admin/reports/rpt-national-dashboard/records') }}?' + p.toString());
            if (!r.ok) return;
            const j = await r.json();
            this.rows = j.data?.rows || [];
            this.pagination = j.data?.pagination || this.pagination;
            this.controls = j.data?.controls || this.controls;
        },
        toggleSort(col) {
            if (this.controls.sort === col) this.controls.dir = this.controls.dir === 'asc' ? 'desc' : 'asc';
            else { this.controls.sort = col; this.controls.dir = 'desc'; }
            this.loadRecords(1);
        },
        pageCluster() {
            const p = this.pagination.page, total = this.pagination.total_pages;
            const out = []; const start = Math.max(1, p - 2), end = Math.min(total, p + 2);
            for (let i = start; i <= end; i++) out.push(i);
            return out;
        },

        exportChart(target, fmt) {
            if (fmt === 'png') {
                const c = this.chartObjs[target]; if (!c) return;
                const a = document.createElement('a');
                a.href = c.toBase64Image('image/png', 1);
                a.download = 'rpt-national-dashboard__' + target + '__' + this.stamp() + '.png';
                a.click();
            } else {
                window.location.href = '{{ url('/admin/reports/rpt-national-dashboard/chart') }}/' + target + '/csv?' + this.qs();
            }
        },
        stamp() { const d = new Date(); const p = n => String(n).padStart(2, '0'); return d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '-' + p(d.getHours()) + p(d.getMinutes()); },

        openExplainer(key) { this.explainer.open = true; this.explainer.key = key; this.explainer.spec = this.explainerSpecs[key] || null; },
        explainerHeaders() { const k = this.explainer.key; return (k && this.chartData[k]?.csv_headers) || []; },
        explainerRows()    { const k = this.explainer.key; return (k && this.chartData[k]?.csv_rows)    || []; },

        kpiHref(k) {
            return '{{ url('/admin/reports') }}/' + (k.href_report || 'rpt-screening-overview') + '?' + this.qs();
        },
        reportHref(slug) { return '{{ url('/admin/reports') }}/' + slug + '?' + this.qs(); },

        async openDrill(row) {
            this.drill.open = true; this.drill.row = row; this.drill.data = null;
            this.wiz = { step: 1, skip: false };
            const r = await fetch('{{ url('/admin/reports/rpt-national-dashboard/records') }}/' + encodeURIComponent(row.id));
            if (!r.ok) return;
            const j = await r.json();
            this.drill.data = j.data;
        },

        // ─── Smart Wizard narrative templates (deterministic, no LLM, no network) ───
        wizHeadline() {
            const a = this.drill.data?.alert; if (!a) return '';
            switch (this.wiz.step) {
                case 1: return `${a.risk_level} alert "${a.title || a.code}" at ${a.poe_code || 'unknown POE'} — ${a.status}.`;
                case 2: return `Triggered ${a.created_at ? new Date(a.created_at).toLocaleString() : 'recently'}.`;
                case 3: return `Routed to ${a.routed_to_level || '—'}; ${a.acknowledged_at ? 'acknowledged ' + new Date(a.acknowledged_at).toLocaleString() : 'not yet acknowledged'}.`;
                case 4: return a.sla_breached ? `SLA breached — ${a.hours_open}h open against ${a.sla_hours}h target.` : `Within SLA — ${a.hours_open}h of ${a.sla_hours}h.`;
                case 5: return `${(this.drill.data?.diseases?.length || 0)} suspected disease(s); traveller risk ${this.drill.data?.traveller?.risk_level || '—'}.`;
                case 6: const fa = this.drill.data?.followup_agg || {}; return `${fa.completed || 0} of ${fa.total || 0} followups completed.`;
                case 7: return a.status === 'CLOSED' ? `Closed: ${a.close_category || '—'}.` : `Not yet resolved — ${a.status}.`;
            }
            return '';
        },
        wizNarrative() {
            const a = this.drill.data?.alert; const t = this.drill.data?.traveller; const o = this.drill.data?.outcome; if (!a) return '';
            switch (this.wiz.step) {
                case 1: return `This alert opened ${a.created_at ? new Date(a.created_at).toLocaleString() : '—'} at ${a.poe_code || 'an unrecorded POE'}. Current status is ${a.status}; routed to ${a.routed_to_level || '—'}.`;
                case 2: return t ? `The traveller is ${t.gender || '—'}, age ${t.age || '—'}, originating from ${t.origin || 'an unrecorded country'}. Triage was ${t.triage || '—'}.` : 'No secondary screening linked — alert was raised against primary tier or system event.';
                case 3: return a.acknowledged_at ? `Acknowledged ${new Date(a.acknowledged_at).toLocaleString()}.` : 'Alert has not yet been acknowledged. The longer it sits, the more likely the SLA breach.';
                case 4: const sla = a.sla_hours; return `Risk level ${a.risk_level} carries a ${sla}h SLA. Hours open: ${a.hours_open}. ${a.sla_breached ? 'Breach is recorded — escalate.' : 'Still inside the window.'}`;
                case 5: const ds = (this.drill.data?.diseases || []).slice(0, 3).map(d => d.display_name || d.disease_code).join(', '); return ds ? `Top suspected: ${ds}.` : 'No suspected diseases recorded yet.';
                case 6: const fa2 = this.drill.data?.followup_agg || {}; return `Pending: ${fa2.pending || 0}, Blocked: ${fa2.blocked || 0}. ${(fa2.blocking || 0) > 0 ? 'There are blockers preventing closure.' : 'No closure-blockers recorded.'}`;
                case 7: return o ? `Classification: ${o.classification || '—'}. Lab status: ${o.lab_status || '—'}. IHR notified: ${o.ihr_notified ? 'yes' : 'no'}.` : 'No outcome row recorded yet — case is still open.';
            }
            return '';
        },
        wizChips() {
            const a = this.drill.data?.alert; const t = this.drill.data?.traveller; const o = this.drill.data?.outcome; if (!a) return [];
            const out = [];
            if (this.wiz.step === 1) { out.push({ text: a.risk_level, tone: this.badgeForRisk(a.risk_level) }); out.push({ text: a.status, tone: 'badge-outline' }); if (a.poe_code) out.push({ text: a.poe_code, tone: 'badge-info' }); }
            if (this.wiz.step === 4) out.push({ text: a.sla_breached ? 'BREACHED' : 'WITHIN', tone: a.sla_breached ? 'badge-danger' : 'badge-success' });
            if (this.wiz.step === 7 && o) out.push({ text: o.classification || 'Unclassified', tone: 'badge-outline' });
            return out;
        },

        badgeForRisk(r) { return { LOW: 'badge-low', MEDIUM: 'badge-medium', HIGH: 'badge-high', CRITICAL: 'badge-critical' }[r] || 'badge-secondary'; },
    };
}
</script>
@endsection
