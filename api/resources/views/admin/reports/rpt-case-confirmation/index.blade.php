@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Case Confirmation')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
{{--
    Case Confirmation — REBUILT 2026-04-26.

    Question: "Of the cases we suspected, how many got confirmed, ruled out,
    or stuck — and where is the lab pipeline lagging?"

    The IHR focal point + disease programme lead live here. The funnel is
    the headliner; the per-disease league answers "is my disease being
    correctly classified"; the Pending tab is the action queue.

    Composition (TWO tabs):
      · Default: KPI strip · Funnel · Per-disease league · Lab pathway one-liner
      · Pending: action queue with overdue hours
--}}
<div x-data="rptConfirm()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('rpt-confirm', wizard.open || ask.open || tour.open || aboutOpen)"
     class="space-y-4">

    {{-- HEADER --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">National Reports · rpt-case-confirmation</p>
            <h1 class="text-[18px] font-semibold flex items-center gap-2">
                Case Confirmation
                <button type="button" class="rpt-explain-btn" @click="aboutOpen = true" aria-label="About this report" title="About this report">i</button>
            </h1>
            <p class="help-text mt-0.5">How many suspected cases got confirmed, ruled out, or are stuck — and where the lab pipeline is lagging.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="windowLabel()"></span>
            </span>
            <span class="topbar-chip topbar-chip-mono" x-show="ready"
                  :class="(kpis.overdue_pending ?? 0) > 0 ? 'text-critical font-semibold' : ''">
                <span x-text="(kpis.overdue_pending ?? 0)"></span> overdue
            </span>
            @include('admin.reports._coach', ['reportKey' => 'rpt-case-confirmation'])
            <button type="button" class="btn btn-soft-brand btn-xs" @click="openTour()">Walk-through</button>
            <button type="button" class="btn btn-outline btn-xs" @click="ask.open = true">Do something</button>
            <button type="button" class="btn btn-default btn-xs" @click="exportOpen = true">Export</button>
        </div>
    </section>

    {{-- FILTERS --}}
    <section class="card">
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-border/60">
            <div class="flex items-center gap-2">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 text-muted-foreground"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
                <span class="text-[13px] font-semibold">Filters</span>
                <button type="button" class="rpt-explain-btn" data-chart-key="funnel" aria-label="What this report shows">?</button>
            </div>
            <div class="flex items-center gap-1.5">
                <button type="button" class="btn btn-ghost btn-xs text-muted-foreground" @click="resetFilters()">Reset</button>
                <button type="button" class="btn btn-brand btn-xs" @click="runReport()">Apply Filters</button>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 p-4">
            <div>
                <label class="label block mb-1">Point of Entry</label>
                <select class="select" x-model="filters.poe">
                    <option value="">All POEs</option>
                    <template x-for="(name, code) in (meta.poes || {})" :key="code">
                        <option :value="code" x-text="name"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1">Year</label>
                <select class="select" x-model="filters.year">
                    <option value="">All Years</option>
                    <template x-for="y in (meta.years || [])" :key="y">
                        <option :value="y" x-text="y"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1">Quarter</label>
                <select class="select" x-model="filters.quarter">
                    <option value="">All Quarters</option>
                    <template x-for="(lbl, q) in (meta.quarters || {})" :key="q">
                        <option :value="q" x-text="lbl"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1">Month</label>
                <select class="select" x-model="filters.month">
                    <option value="">All Months</option>
                    <template x-for="(lbl, m) in (meta.months || {})" :key="m">
                        <option :value="m" x-text="lbl"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1">Start Date</label>
                <input type="date" class="input" x-model="filters.start_date">
            </div>
            <div>
                <label class="label block mb-1">End Date</label>
                <input type="date" class="input" x-model="filters.end_date">
            </div>
        </div>
    </section>

    {{-- COLD STATE --}}
    <template x-if="!ready">
        <section class="card"><div class="card-content py-10 text-center space-y-3">
            <h2 class="text-[15px] font-semibold">Configure the period for this report</h2>
            <p class="help-text max-w-md mx-auto">Pick a year, quarter, or date range, then press Apply Filters. Pending-overdue threshold is 168 hours (7 days).</p>
            <button type="button" class="btn btn-brand btn-sm" @click="runReport()">Run report</button>
        </div></section>
    </template>

    {{-- KPI ROW --}}
    <template x-if="ready">
        <section class="grid grid-cols-2 md:grid-cols-4 gap-2.5">
            <div class="kpi kpi-glow">
                <span class="kpi-label">Suspected</span>
                <div class="kpi-value" x-text="formatNum(kpis.suspected)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    Total alerts <span x-text="formatNum(kpis.total_alerts)"></span>
                </div>
            </div>
            <div class="kpi">
                <span class="kpi-label">Confirmed</span>
                <div class="kpi-value text-success" x-text="formatNum(kpis.confirmed)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    Median to confirm <span class="font-mono" x-text="formatNum(Math.round(kpis.median_confirm_minutes ?? 0))"></span> min
                </div>
            </div>
            <div class="kpi">
                <span class="kpi-label">Ruled out</span>
                <div class="kpi-value" x-text="formatNum(kpis.ruled_out)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    P90 to confirm <span class="font-mono" x-text="formatNum(Math.round(kpis.p90_confirm_minutes ?? 0))"></span> min
                </div>
            </div>
            <div class="kpi" :class="(kpis.overdue_pending ?? 0) > 0 ? '' : 'kpi-glow'">
                <span class="kpi-label">Pending overdue</span>
                <div class="kpi-value" :class="(kpis.overdue_pending ?? 0) > 0 ? 'text-critical' : ''" x-text="formatNum(kpis.overdue_pending)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    of <span x-text="formatNum(kpis.pending)"></span> pending · &gt; 168h
                </div>
            </div>
        </section>
    </template>

    {{-- TABS --}}
    <template x-if="ready">
        <section>
            <div class="tabs-list" role="tablist" aria-label="Case confirmation views">
                <button class="tabs-trigger" role="tab" :data-state="tab === 'overview' ? 'active' : null" @click="tab = 'overview'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><path d="M3 6h18M3 12h12M3 18h6"/></svg>
                    Funnel &amp; By Disease
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'pending' ? 'active' : null" @click="tab = 'pending'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    Pending overdue
                    <span class="ml-1 px-1.5 py-0.5 rounded text-[10px] font-mono"
                          :class="(kpis.overdue_pending ?? 0) > 0 ? 'bg-critical text-critical-foreground' : 'bg-muted text-muted-foreground'"
                          x-text="kpis.overdue_pending ?? 0"></span>
                </button>
            </div>
        </section>
    </template>

    {{-- ===== TAB 1 · FUNNEL & BY DISEASE ===== --}}
    <template x-if="ready && tab === 'overview'">
        <section class="space-y-4">
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Diagnostic flow</div>
                        <h2 class="text-base font-semibold mt-0.5">Classification funnel</h2>
                        <p class="text-[11.5px] text-muted-foreground mt-0.5">Bar width = count. Read top to bottom — Suspected at the top, terminal states below.</p>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('funnelChart','funnel')" aria-label="Download PNG">PNG</button>
                        <button type="button" class="rpt-explain-btn" data-chart-key="funnel">?</button>
                    </div>
                </div>
                <div class="px-3 pb-3">
                    <div class="relative h-[260px]"><canvas x-ref="funnelChart" id="funnelChart"></canvas></div>
                </div>
            </article>

            <article class="card overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/60 flex-wrap">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Per-disease classification league</h2>
                        <span class="badge badge-secondary">Showing <span x-text="visibleDiseaseCount()"></span> of <span x-text="(byDisease || []).length"></span></span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="by_disease">?</button>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="relative">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                            <input class="input pl-7 w-48" type="search" placeholder="Search disease…" x-model="diseaseQuery">
                        </div>
                        <button type="button" class="btn btn-outline btn-xs" @click="exportAs('CSV')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3"><path d="M12 3v12m0 0l-4-4m4 4l4-4"/><path d="M5 21h14"/></svg>
                            CSV
                        </button>
                    </div>
                </div>
                <div class="overflow-auto max-h-[55vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">#</th>
                                <th class="table-head-th table-head-th-sort" @click="sortBy('disease_name')">Disease</th>
                                <th class="table-head-th">Tier</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortBy('suspected')">Suspected</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortBy('confirmed')">Confirmed</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortBy('ruled_out')">Ruled out</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortBy('pending')">Pending</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortBy('confirmation_rate')">Confirm %</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortBy('false_positive_rate')">False-pos %</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-for="(row, idx) in sortedFilteredDisease()" :key="row.disease_code">
                                <tr class="table-row">
                                    <td class="table-cell text-muted-foreground" x-text="idx + 1"></td>
                                    <td class="table-cell font-sans font-semibold" x-text="row.disease_name"></td>
                                    <td class="table-cell">
                                        <span class="badge" :class="tierBadgeClass(row.ihr_tier)" x-text="tierLabel(row.ihr_tier)"></span>
                                    </td>
                                    <td class="table-cell text-right" x-text="formatNum(row.suspected)"></td>
                                    <td class="table-cell text-right text-success" x-text="formatNum(row.confirmed)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(row.ruled_out)"></td>
                                    <td class="table-cell text-right" :class="(row.pending ?? 0) > 0 ? 'text-warning' : 'text-muted-foreground'" x-text="formatNum(row.pending)"></td>
                                    <td class="table-cell text-right">
                                        <span class="badge" :class="confirmBadgeClass(row.confirmation_rate)" x-text="row.confirmation_rate + '%'"></span>
                                    </td>
                                    <td class="table-cell text-right">
                                        <span class="badge" :class="fpBadgeClass(row.false_positive_rate, row.ihr_tier)" x-text="row.false_positive_rate + '%'"></span>
                                    </td>
                                </tr>
                            </template>
                            <template x-if="sortedFilteredDisease().length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="9">No diseases in this filter window.</td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </article>

            {{-- Lab pathway one-liner footer --}}
            <article class="card" x-show="(labSummary && labSummary.methods_total) > 0">
                <div class="px-4 py-3 text-[12.5px] flex items-center gap-3 flex-wrap">
                    <span class="badge badge-info">Lab pathway</span>
                    <span x-show="labSummary.top_method">
                        Most-used test method: <strong x-text="labSummary.top_method"></strong>
                        (<span x-text="formatNum(labSummary.top_method_count)"></span> of <span x-text="formatNum(labSummary.methods_total)"></span>)
                    </span>
                    <span class="text-muted-foreground" x-show="labSummary.insufficient_samples > 0">
                        ·
                        <strong class="text-warning"><span x-text="formatNum(labSummary.insufficient_samples)"></span></strong>
                        INSUFFICIENT_SAMPLE rejections
                    </span>
                </div>
            </article>
        </section>
    </template>

    {{-- ===== TAB 2 · PENDING OVERDUE ===== --}}
    <template x-if="ready && tab === 'pending'">
        <section>
            <article class="card overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/60 flex-wrap">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Pending past 7-day guideline</h2>
                        <span class="badge" :class="(pendingList || []).length > 0 ? 'badge-critical' : 'badge-success'" x-text="(pendingList || []).length + ' rows'"></span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="pending_overdue">?</button>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="relative">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                            <input class="input pl-7 w-48" type="search" placeholder="Search disease / POE…" x-model="pendingQuery">
                        </div>
                        <button type="button" class="btn btn-outline btn-xs" @click="exportPending()">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3"><path d="M12 3v12m0 0l-4-4m4 4l4-4"/><path d="M5 21h14"/></svg>
                            CSV
                        </button>
                    </div>
                </div>
                <div class="overflow-auto max-h-[60vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">#</th>
                                <th class="table-head-th">Disease</th>
                                <th class="table-head-th">Tier</th>
                                <th class="table-head-th">POE</th>
                                <th class="table-head-th">Province</th>
                                <th class="table-head-th">Opened</th>
                                <th class="table-head-th text-right">Overdue</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-for="(row, i) in filteredPending()" :key="row.alert_id">
                                <tr class="table-row" :class="row.ihr_tier === 1 ? 'bg-critical-soft/40' : ''">
                                    <td class="table-cell text-muted-foreground" x-text="i + 1"></td>
                                    <td class="table-cell font-sans font-semibold" x-text="row.disease_name"></td>
                                    <td class="table-cell">
                                        <span class="badge" :class="tierBadgeClass(row.ihr_tier)" x-text="tierLabel(row.ihr_tier)"></span>
                                    </td>
                                    <td class="table-cell font-sans" x-text="row.poe_name"></td>
                                    <td class="table-cell font-sans" x-text="row.province"></td>
                                    <td class="table-cell font-sans" x-text="formatDateTime(row.created_at)"></td>
                                    <td class="table-cell text-right text-critical font-semibold" x-text="formatHours(row.overdue_hours)"></td>
                                </tr>
                            </template>
                            <template x-if="filteredPending().length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="7">No pending cases over the 7-day guideline.</td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5 border-t border-border/60">
                    <span class="text-[11px] text-muted-foreground">Sorted by overdue hours (descending). Capped at 50 rows. Tier-1 (always-notifiable) rows are tinted.</span>
                </div>
            </article>
        </section>
    </template>

    {{-- INSIGHTS + DATA NOTES --}}
    <template x-if="ready">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @include('admin.reports._insights')
            @include('admin.reports._data_notes')
        </div>
    </template>

    @include('admin.reports._filter_wizard')
    @include('admin.reports._chart_explainer', ['reportKey' => 'rpt-case-confirmation'])

    {{-- ABOUT --}}
    <div x-show="aboutOpen" x-cloak class="fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="aboutOpen = false">
        <div class="bg-background w-full sm:max-w-lg sm:rounded-xl border border-border shadow-elevation-5 flex flex-col overflow-hidden max-h-[88vh]" @click.away="aboutOpen = false">
            <header class="px-5 pt-5 pb-3 border-b border-border">
                <span class="badge badge-brand mb-1">About this report</span>
                <h3 class="text-base font-semibold">Case Confirmation</h3>
            </header>
            <div class="overflow-y-auto px-5 py-4 space-y-2.5 text-[13px] leading-relaxed">
                <p><strong>Purpose.</strong> Show every alert in the window placed into a terminal classification — Suspected, Probable, Confirmed, Ruled-out, Pending — so leadership can see how the lab loop is performing per disease.</p>
                <p><strong>Audience.</strong> IHR focal point, lab liaison, disease programme leads.</p>
                <p><strong>Source.</strong> <code class="kbd">alert_case_outcomes.case_classification</code> joined back to <code class="kbd">alerts</code>. Top-suspected disease comes from <code class="kbd">secondary_suspected_diseases</code>; metadata from <code class="kbd">ref_diseases</code>.</p>
                <p><strong>What it cannot tell you.</strong> Why a case was confirmed or ruled out — the case dossier in Cases Registry has that. Acknowledgement SLAs live in Alert Acknowledgement.</p>
            </div>
            <footer class="px-5 py-3 border-t border-border flex justify-end">
                <button type="button" class="btn btn-default btn-xs" @click="aboutOpen = false">Close</button>
            </footer>
        </div>
    </div>

    {{-- WALK-THROUGH --}}
    <div x-show="tour.open" x-cloak class="fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="tour.open = false">
        <div class="bg-background w-full sm:max-w-xl sm:rounded-xl border border-border shadow-elevation-5 flex flex-col overflow-hidden max-h-[85vh]" @click.away="tour.open = false">
            <header class="flex items-center justify-between px-5 pt-4 pb-3 border-b border-border">
                <span class="badge badge-brand">Walk-through · Step <span x-text="tour.step"></span> of <span x-text="tour.steps.length"></span></span>
                <button type="button" class="btn btn-ghost btn-icon-xs" @click="tour.open = false" aria-label="Close walk-through">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M6 6l12 12M6 18L18 6"/></svg>
                </button>
            </header>
            <div class="overflow-y-auto px-5 py-4 grow">
                <h3 class="text-base font-semibold" x-text="tour.steps[tour.step - 1]?.t"></h3>
                <div class="text-[13px] leading-relaxed mt-2" x-html="tour.steps[tour.step - 1]?.b"></div>
            </div>
            <footer class="flex items-center justify-between px-5 py-3 border-t border-border">
                <button type="button" class="btn btn-ghost btn-xs" :disabled="tour.step === 1" @click="tour.step = Math.max(1, tour.step - 1)">Back</button>
                <button type="button" class="btn btn-default btn-xs"
                        @click="tour.step === tour.steps.length ? (tour.open = false) : tour.step++"
                        x-text="tour.step === tour.steps.length ? 'Finish' : 'Next'"></button>
            </footer>
        </div>
    </div>

    {{-- DO SOMETHING --}}
    <div x-show="ask.open" x-cloak class="fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="ask.open = false">
        <div class="bg-background w-full sm:max-w-xl sm:rounded-xl border border-border shadow-elevation-5 flex flex-col overflow-hidden max-h-[85vh]" @click.away="ask.open = false">
            <header class="px-5 pt-5 pb-3 border-b border-border">
                <h3 class="text-base font-semibold">What would you like to do?</h3>
                <p class="text-[12px] text-muted-foreground">Pick the goal that matches what you came for.</p>
            </header>
            <div class="overflow-y-auto px-5 py-4 grow grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                <template x-for="opt in askOptions" :key="opt.code">
                    <button type="button" class="text-left card p-3 hover:shadow-elevation-3"
                            @click="ask.open = false; runAsk(opt.code)">
                        <div class="badge mb-1.5" :class="opt.badge"><span x-text="opt.tag"></span></div>
                        <div class="font-semibold text-[13px]" x-text="opt.label"></div>
                        <p class="text-[11.5px] text-muted-foreground mt-0.5" x-text="opt.help"></p>
                    </button>
                </template>
            </div>
            <footer class="px-5 py-3 border-t border-border flex justify-end">
                <button type="button" class="btn btn-ghost btn-xs" @click="ask.open = false">Cancel</button>
            </footer>
        </div>
    </div>

    {{-- EXPORT --}}
    <div x-show="exportOpen" x-cloak class="fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="exportOpen = false">
        <div class="bg-background w-full sm:max-w-lg sm:rounded-xl border border-border shadow-elevation-5 flex flex-col overflow-hidden" @click.away="exportOpen = false">
            <header class="px-5 pt-5 pb-3 border-b border-border">
                <h3 class="text-base font-semibold">Export</h3>
                <p class="text-[12px] text-muted-foreground">Pick a format. Scope and filters are baked into the file footer.</p>
            </header>
            <div class="px-5 py-4 grid grid-cols-2 gap-2">
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportAs('PDF')">
                    <div class="font-semibold text-[13px]">PDF — by-disease league</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Per-disease classification, ready to print.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportAs('CSV')">
                    <div class="font-semibold text-[13px]">CSV — by-disease league</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Disease · counts · confirm % · false-pos %.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportPending()">
                    <div class="font-semibold text-[13px]">CSV — Pending overdue</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Action queue with overdue hours.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; downloadChartPng('funnelChart','funnel')">
                    <div class="font-semibold text-[13px]">PNG — funnel</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Stamped with scope · period · timestamp.</p>
                </button>
            </div>
            <footer class="px-5 py-3 border-t border-border flex justify-end">
                <button type="button" class="btn btn-ghost btn-xs" @click="exportOpen = false">Cancel</button>
            </footer>
        </div>
    </div>
</div>

@push('scripts')
<script>
if (typeof Chart !== 'undefined') { Chart.defaults.animation = false; Chart.defaults.maintainAspectRatio = false; }

async function rptJson(url, params) {
    const u = new URL(url, window.location.origin);
    if (params) for (const [k,v] of Object.entries(params)) if (v !== '' && v !== null && v !== undefined) u.searchParams.set(k, v);
    const r = await fetch(u.toString(), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
    if (!r.ok) throw new Error('HTTP ' + r.status);
    return await r.json();
}

function tokenColor(token) {
    try {
        const v = getComputedStyle(document.documentElement).getPropertyValue(token).trim();
        return v ? `hsl(${v})` : '#0EA5E9';
    } catch (e) { return '#0EA5E9'; }
}

function rptConfirm() {
    return {
        ready: false,
        tab: 'overview',
        wizard: { open:false, step:1 },
        ask:    { open:false },
        tour:   { open:false, step:1, steps: [
            { t: 'The question this view answers',
              b: '<p>Of the cases we suspected, how many got confirmed, ruled out, or stuck — and where is the lab pipeline lagging?</p>' },
            { t: 'Step 1 — The funnel',
              b: '<p>Five stages — Suspected, Probable, Confirmed, Ruled-out, Pending. Bar width is the count. A wide Pending band means the lab loop is stalled.</p>' },
            { t: 'Step 2 — Per-disease league',
              b: '<p>Sortable league with confirmation %, false-positive %, IHR tier badge. Click any column to re-sort.</p>' },
            { t: 'Step 3 — Pending tab',
              b: '<p>The action queue. Every alert sitting in pending past 168 hours, sorted by overdue. Tier-1 rows are tinted.</p>' },
        ]},

        aboutOpen: false,
        exportOpen: false,
        notesOpen: true,

        filters: { poe:'', sex:'', year:'', quarter:'', month:'', start_date:'', end_date:'' },
        meta:    { poes:{}, districts:{}, provinces:{}, years:[], quarters:{}, months:{}, genders:{} },

        kpis: {},
        pathway: {},
        byDisease: [],
        pendingList: [],
        labSummary: { top_method: null, top_method_count: 0, methods_total: 0, insufficient_samples: 0 },
        insights: [],
        dataNotes: {},
        window: { from:'', to:'' },

        diseaseQuery: '',
        pendingQuery: '',
        sortKey: 'confirmed',
        sortDir: 'desc',

        charts: {},

        askOptions: [
            { code:'PENDING',   label:'Triage the pending overdue', help:'Open the Pending tab.', tag:'Common',     badge:'badge-warning' },
            { code:'WORST_FP',  label:'Find diseases with high false-positive', help:'Sort the league by False-pos %.', tag:'Investigate', badge:'badge-secondary' },
            { code:'TIER1',     label:'Show only Tier-1 diseases',  help:'Filter the league to always-notifiable diseases.', tag:'Filter',     badge:'badge-info' },
            { code:'EXPORT',    label:'Export the league',          help:'CSV or PDF for the IHR briefing.', tag:'Share',     badge:'badge-success' },
        ],

        async boot() {
            this.restoreFiltersFromUrl();
            this.$watch('tab', () => this.$nextTick(() => this.renderCharts()));
            await this.loadMeta();
            if (this.urlHasRun() || this.anyFilter()) { await this.runReport(); }
        },

        anyFilter() { return Object.values(this.filters).some(v => v !== '' && v !== null); },
        urlHasRun() { return new URLSearchParams(window.location.search).get('run') === '1'; },
        restoreFiltersFromUrl() { const u = new URLSearchParams(window.location.search); for (const k of Object.keys(this.filters)) { const v = u.get(k); if (v !== null) this.filters[k] = v; } },
        writeFiltersToUrl() {
            const u = new URLSearchParams();
            for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) u.set(k, v);
            u.set('run','1');
            window.history.replaceState(null, '', window.location.pathname + '?' + u.toString());
        },

        async loadMeta() { try { const r = await rptJson(@json(url('/admin/reports/meta'))); this.meta = Object.assign(this.meta, r?.data || {}); } catch (e) {} },
        openWizard() { this.wizard.open = true; this.wizard.step = 1; },
        openTour()   { this.tour.open = true; this.tour.step = 1; },
        resetFilters() { this.filters = { poe:'', sex:'', year:'', quarter:'', month:'', start_date:'', end_date:'' }; window.history.replaceState(null, '', window.location.pathname); },

        async runReport() {
            this.writeFiltersToUrl();
            try {
                const r = await rptJson(@json(url('/admin/reports/rpt-case-confirmation/data')), this.buildParams());
                const d = r?.data || {};
                this.window      = d.window || {};
                this.kpis        = d.kpis || {};
                this.pathway     = d.pathway || {};
                this.byDisease   = d.by_disease || [];
                this.pendingList = d.pending_list || [];
                this.labSummary  = d.lab_summary || { top_method: null, top_method_count: 0, methods_total: 0, insufficient_samples: 0 };
                this.insights    = d.insights || [];
                this.dataNotes   = d.data_notes || {};
                this.ready       = true;
                this.$nextTick(() => this.renderCharts());
                Alpine.store('pageMeta', { rows: (this.kpis.total_alerts ?? 0), version: null, kind: 'rpt-case-confirmation' });
            } catch (e) { console.error(e); this.ready = false; }
        },

        buildParams() { const p = {}; for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) p[k] = v; return p; },

        exportAs(fmt) {
            const u = new URL(@json(url('/admin/reports/rpt-case-confirmation/export')), window.location.origin);
            for (const [k,v] of Object.entries(this.buildParams())) u.searchParams.set(k, v);
            u.searchParams.set('format', fmt);
            if (fmt === 'PDF') window.open(u.toString(), '_blank', 'noopener');
            else window.location.href = u.toString();
        },

        exportPending() {
            const rows = this.filteredPending();
            if (!rows.length) return;
            const headers = ['Alert ID', 'Disease', 'Disease code', 'Tier', 'POE Name', 'POE Code', 'Province', 'Opened', 'Overdue (h)'];
            const lines = [headers.join(',')].concat(rows.map(r => [
                r.alert_id, this.csv(r.disease_name), this.csv(r.disease),
                r.ihr_tier, this.csv(r.poe_name), this.csv(r.poe_code), this.csv(r.province),
                this.csv(r.created_at), r.overdue_hours,
            ].join(',')));
            this.downloadBlob(new Blob(["﻿" + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }), 'pending-overdue-' + this.stamp() + '.csv');
        },

        csv(v) { const s = v == null ? '' : String(v); return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; },
        stamp() { const d = new Date(); return d.toISOString().slice(0,10) + '-' + d.toTimeString().slice(0,8).replace(/:/g,''); },

        downloadBlob(blob, filename) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = filename; document.body.appendChild(a); a.click();
            setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 250);
        },

        downloadChartPng(canvasId, slug) {
            const c = document.getElementById(canvasId);
            if (!c) return;
            const stamp = this.stamp();
            const filename = `rpt-case-confirmation-${slug}-${stamp}.png`;
            const footerH = 32;
            const out = document.createElement('canvas');
            out.width  = c.width;
            out.height = c.height + footerH * (c.height / Math.max(1, c.clientHeight));
            const g = out.getContext('2d');
            g.fillStyle = '#fff';
            g.fillRect(0, 0, out.width, out.height);
            g.drawImage(c, 0, 0);
            g.fillStyle = '#475569';
            g.font = '11px Inter, system-ui, sans-serif';
            const win = (this.window?.from && this.window?.to) ? `${this.window.from} → ${this.window.to}` : '—';
            const lbl = (window.__SCOPE_LABEL__ || 'Scope: —');
            g.fillText(`Case Confirmation · ${slug} · ${lbl} · ${win} · generated ${stamp}`, 8, c.height + 18);
            out.toBlob(blob => this.downloadBlob(blob, filename), 'image/png');
        },

        formatNum(v)  { return (v == null || v === undefined) ? '—' : Number(v).toLocaleString(); },
        formatHours(h) {
            const x = Number(h || 0);
            if (x < 24) return x + ' h';
            const d = Math.floor(x / 24);
            const rh = x % 24;
            return d + ' d ' + rh + ' h';
        },
        formatDateTime(d) {
            if (!d) return '—';
            try {
                const dt = new Date(String(d).replace(' ', 'T'));
                const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][dt.getMonth()];
                const hh = String(dt.getHours()).padStart(2,'0');
                const mm = String(dt.getMinutes()).padStart(2,'0');
                return `${dt.getDate()} ${m} ${dt.getFullYear()} ${hh}:${mm}`;
            } catch (e) { return d; }
        },
        formatDate(d) {
            if (!d) return '—';
            try {
                const dt = new Date(d + 'T00:00:00');
                const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][dt.getMonth()];
                return `${dt.getDate()} ${m} ${dt.getFullYear()}`;
            } catch (e) { return d; }
        },
        windowLabel() { return this.window.from ? (this.formatDate(this.window.from) + ' → ' + this.formatDate(this.window.to)) : 'No window'; },

        tierBadgeClass(tier) {
            const t = Number(tier || 0);
            return t === 1 ? 'badge-critical' : t === 2 ? 'badge-warning' : t === 3 ? 'badge-info' : 'badge-secondary';
        },
        tierLabel(tier) {
            const t = Number(tier || 0);
            return t === 1 ? 'Tier 1' : t === 2 ? 'Tier 2' : t === 3 ? 'Tier 3' : '—';
        },
        confirmBadgeClass(rate) {
            const v = Number(rate || 0);
            if (v >= 70) return 'badge-success';
            if (v >= 40) return 'badge-warning';
            return 'badge-secondary';
        },
        fpBadgeClass(rate, tier) {
            const v = Number(rate || 0);
            const t = Number(tier || 0);
            if (t === 1 && v >= 50) return 'badge-critical';
            if (v >= 70) return 'badge-warning';
            return 'badge-secondary';
        },

        sortBy(key) {
            if (this.sortKey === key) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            else { this.sortKey = key; this.sortDir = 'desc'; }
        },
        sortedFilteredDisease() {
            const q = (this.diseaseQuery || '').toLowerCase().trim();
            const numeric = ['suspected','probable','confirmed','ruled_out','pending','total','confirmation_rate','false_positive_rate','ihr_tier'];
            const key = this.sortKey;
            const dir = this.sortDir === 'asc' ? 1 : -1;
            return [...(this.byDisease || [])]
                .filter(r => !q || (r.disease_name && r.disease_name.toLowerCase().includes(q)) || (r.disease_code && r.disease_code.toLowerCase().includes(q)))
                .sort((a, b) => {
                    let av = a[key], bv = b[key];
                    if (numeric.includes(key)) { av = Number(av); bv = Number(bv); }
                    if (av < bv) return -1 * dir;
                    if (av > bv) return  1 * dir;
                    return 0;
                });
        },
        visibleDiseaseCount() { return this.sortedFilteredDisease().length; },

        filteredPending() {
            const q = (this.pendingQuery || '').toLowerCase().trim();
            return (this.pendingList || []).filter(r => {
                if (!q) return true;
                return (r.disease_name && r.disease_name.toLowerCase().includes(q))
                    || (r.poe_name && r.poe_name.toLowerCase().includes(q));
            });
        },

        runAsk(code) {
            switch (code) {
                case 'PENDING':  this.tab = 'pending'; break;
                case 'WORST_FP': this.tab = 'overview'; this.sortKey = 'false_positive_rate'; this.sortDir = 'desc'; break;
                case 'TIER1':    this.tab = 'overview'; this.sortKey = 'ihr_tier'; this.sortDir = 'asc'; break;
                case 'EXPORT':   this.exportOpen = true; break;
            }
        },

        // ────────────────────────────────────────────────
        // Chart rendering — single chart on this view (the funnel).
        // ────────────────────────────────────────────────
        destroyCharts() { Object.values(this.charts).forEach(c => { try { c.destroy(); } catch (e) {} }); this.charts = {}; },
        renderCharts() {
            if (typeof Chart === 'undefined') return;
            this.destroyCharts();
            requestAnimationFrame(() => this.renderFunnel());
        },

        renderFunnel() {
            const ref = this.$refs.funnelChart;
            if (!ref) return;
            const labels = ['Suspected', 'Probable', 'Confirmed', 'Ruled-out', 'Pending'];
            const data   = [
                Number(this.kpis.suspected ?? 0),
                Number(this.kpis.probable ?? 0),
                Number(this.kpis.confirmed ?? 0),
                Number(this.kpis.ruled_out ?? 0),
                Number(this.kpis.pending ?? 0),
            ];
            const colors = [
                tokenColor('--info'),
                tokenColor('--medium'),
                tokenColor('--success'),
                tokenColor('--muted-foreground'),
                tokenColor('--warning'),
            ];
            this.charts.funnel = new Chart(ref, {
                type: 'bar',
                data: { labels, datasets: [{ label: 'Cases', data, backgroundColor: colors, borderRadius: 4 }] },
                options: {
                    indexAxis: 'y',
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: c => `${c.parsed.x.toLocaleString()} cases` } },
                    },
                    scales: {
                        x: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground'), precision: 0 }, grid: { color: tokenColor('--border'), drawBorder: false } },
                        y: { ticks: { color: tokenColor('--foreground'), font: { weight: '600' } }, grid: { display: false } },
                    },
                },
            });
        },
    };
}
</script>
@endpush
@endsection
