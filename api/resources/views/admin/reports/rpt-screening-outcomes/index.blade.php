@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Screening Outcomes')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
{{--
    Screening Outcomes after Alert Cycles — REBUILT 2026-04-26 to follow the
    rpt-volume design system. Question: "Did the alerts we raised resolve into
    a defensible outcome, and where they didn't?"

    Composition:
      · Compact title bar (eyebrow · h1 · walk-through · do-something · export)
      · Filters card (Apply / Reset)
      · 6 KPI tiles (closure rate is the glow tile)
      · Tabs: Overview · By POE · Cycles · Disposition & Follow-up · Quality
      · Charts use Chart.js with theme tokens via CSS-variable resolution.
      · Each chart card has a "?" explainer button + a "PNG" download button.

    Theme primitives only. No dark mode. No animations beyond animate-spin.
--}}
<div x-data="rptOutcomes()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('rpt-outcomes', wizard.open || ask.open || tour.open || aboutOpen)"
     class="space-y-4">

    {{-- ======================================================================
         HEADER
       ====================================================================== --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">National Reports · rpt-screening-outcomes</p>
            <h1 class="text-[18px] font-semibold flex items-center gap-2">
                Screening Outcomes
                <button type="button" class="rpt-explain-btn" @click="aboutOpen = true" aria-label="About this report" title="About this report">i</button>
            </h1>
            <p class="help-text mt-0.5">Whether alerts raised at the front line resolved into a defensible outcome — and where they didn't.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="windowLabel()"></span>
            </span>
            <span class="topbar-chip topbar-chip-mono" x-show="ready">
                <span x-text="(kpis.closed_cycles ?? 0)"></span>/<span x-text="(kpis.total_cycles ?? 0)"></span> closed
            </span>
            @include('admin.reports._coach', ['reportKey' => 'rpt-screening-outcomes'])
            <button type="button" class="btn btn-soft-brand btn-xs" @click="openTour()">Walk-through</button>
            <button type="button" class="btn btn-outline btn-xs" @click="ask.open = true">Do something</button>
            <button type="button" class="btn btn-default btn-xs" @click="exportOpen = true">Export</button>
        </div>
    </section>

    {{-- ======================================================================
         FILTERS CARD
       ====================================================================== --}}
    <section class="card">
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-border/60">
            <div class="flex items-center gap-2">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 text-muted-foreground"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
                <span class="text-[13px] font-semibold">Filters</span>
                <button type="button" class="rpt-explain-btn" data-chart-key="closure_donut" aria-label="What this report shows">?</button>
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
                <label class="label block mb-1">Sex</label>
                <select class="select" x-model="filters.sex">
                    <option value="">All</option>
                    <template x-for="(lbl, g) in (meta.genders || {})" :key="g">
                        <option :value="g" x-text="lbl"></option>
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
                <label class="label block mb-1">Province</label>
                <select class="select" x-model="filters.province">
                    <option value="">All Provinces</option>
                    <template x-for="(name, code) in (meta.provinces || {})" :key="code">
                        <option :value="code" x-text="name"></option>
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
            <p class="help-text max-w-md mx-auto">Pick a year, quarter, or date range, then press Apply Filters. Closure rules respect your scope.</p>
            <button type="button" class="btn btn-brand btn-sm" @click="runReport()">Run report</button>
        </div></section>
    </template>

    {{-- ======================================================================
         KPI ROW
       ====================================================================== --}}
    <template x-if="ready">
        <section class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2.5">
            <div class="kpi kpi-glow">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Closure rate</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('closure_rate')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value">
                    <span x-text="kpis.closure_rate ?? 0"></span><span class="text-base text-muted-foreground">%</span>
                </div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="formatNum(kpis.closed_cycles)"></span> of <span x-text="formatNum((kpis.total_cycles ?? 0) - (kpis.duplicates ?? 0))"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Total cycles</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('total_cycles')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.total_cycles)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="formatNum(kpis.duplicates)"></span> duplicate-merged excluded
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Pending</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('pending')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.pending_cycles)"></div>
                <div class="text-[10.5px]">
                    <span class="badge" :class="overdueBadgeClass()" x-text="formatNum(kpis.overdue_open) + ' overdue'"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Median (min)</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('median')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(Math.round(kpis.median_close_minutes ?? 0))"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    P90 <span class="font-mono" x-text="formatNum(Math.round(kpis.p90_close_minutes ?? 0))"></span> min
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Acknowledged</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('ack')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value">
                    <span x-text="kpis.ack_rate ?? 0"></span><span class="text-base text-muted-foreground">%</span>
                </div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="formatNum(kpis.acknowledged)"></span> of <span x-text="formatNum(kpis.total_cycles)"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Follow-up done</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('followup')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value">
                    <span x-text="kpis.followup_complete_pct ?? 0"></span><span class="text-base text-muted-foreground">%</span>
                </div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="formatNum(kpis.followup_completed)"></span> of <span x-text="formatNum(kpis.followup_required)"></span>
                </div>
            </div>
        </section>
    </template>

    {{-- ======================================================================
         TABS
       ====================================================================== --}}
    <template x-if="ready">
        <section>
            <div class="tabs-list" role="tablist" aria-label="Screening outcomes views">
                <button class="tabs-trigger" role="tab" :data-state="tab === 'overview' ? 'active' : null" @click="tab = 'overview'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    Overview
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'by-poe' ? 'active' : null" @click="tab = 'by-poe'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><circle cx="12" cy="10" r="3"/><path d="M12 22s-7-7.5-7-12a7 7 0 0114 0c0 4.5-7 12-7 12z"/></svg>
                    By POE
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'cycles' ? 'active' : null" @click="tab = 'cycles'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    Cycles
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'disposition' ? 'active' : null" @click="tab = 'disposition'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><path d="M5 13l4 4L19 7"/></svg>
                    Disposition &amp; Follow-up
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'quality' ? 'active' : null" @click="tab = 'quality'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><path d="M3 3v18h18"/><path d="M7 14l4-4 4 4 5-5"/></svg>
                    Quality
                </button>
            </div>
        </section>
    </template>

    {{-- ======================================================================
         OVERVIEW TAB
       ====================================================================== --}}
    <template x-if="ready && tab === 'overview'">
        <section class="space-y-4">
            <div class="grid grid-cols-12 gap-4">
                <article class="card col-span-12 md:col-span-4">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Cycle closure</div>
                            <h2 class="text-base font-semibold mt-0.5">Closed vs pending</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('closureDonutChart','closure-donut')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="closure_donut">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[220px]"><canvas x-ref="closureDonutChart" id="closureDonutChart"></canvas></div>
                        <div class="space-y-1.5 mt-3">
                            <div class="flex items-center justify-between gap-3 text-[12.5px]">
                                <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm" style="background:hsl(var(--success))"></span><span>Closed</span></div>
                                <div class="font-mono tabular-nums font-semibold" x-text="formatNum(kpis.closed_cycles)"></div>
                            </div>
                            <div class="flex items-center justify-between gap-3 text-[12.5px]">
                                <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm" style="background:hsl(var(--warning))"></span><span>Pending</span></div>
                                <div class="font-mono tabular-nums font-semibold" x-text="formatNum(kpis.pending_cycles)"></div>
                            </div>
                            <div class="flex items-center justify-between gap-3 text-[12.5px]">
                                <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm" style="background:hsl(var(--critical))"></span><span>Overdue (&gt; 72h)</span></div>
                                <div class="font-mono tabular-nums font-semibold" x-text="formatNum(kpis.overdue_open)"></div>
                            </div>
                            <div class="flex items-center justify-between gap-3 text-[12.5px]">
                                <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm bg-muted border border-border"></span><span>Duplicates excluded</span></div>
                                <div class="font-mono tabular-nums text-muted-foreground" x-text="formatNum(kpis.duplicates)"></div>
                            </div>
                        </div>
                    </div>
                </article>

                <article class="card col-span-12 md:col-span-8">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Trend</div>
                            <h2 class="text-base font-semibold mt-0.5">Weekly closure trend</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <span class="badge badge-info"><span class="h-1.5 w-1.5 rounded-full bg-info"></span>Opened</span>
                            <span class="badge badge-success"><span class="h-1.5 w-1.5 rounded-full bg-success"></span>Closed</span>
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('weeklyClosureChart','weekly-closure')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="weekly_closure">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[240px]"><canvas x-ref="weeklyClosureChart" id="weeklyClosureChart"></canvas></div>
                    </div>
                </article>
            </div>

            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Time-to-close</div>
                        <h2 class="text-base font-semibold mt-0.5">Distribution by hour band</h2>
                        <p class="text-[11.5px] text-muted-foreground mt-0.5">
                            Median <span class="font-mono tabular-nums font-semibold" x-text="formatNum(Math.round(kpis.median_close_minutes ?? 0))"></span> min ·
                            P90 <span class="font-mono tabular-nums font-semibold" x-text="formatNum(Math.round(kpis.p90_close_minutes ?? 0))"></span> min
                        </p>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('timeBucketsChart','time-to-close')" aria-label="Download PNG">PNG</button>
                        <button type="button" class="rpt-explain-btn" data-chart-key="time_buckets">?</button>
                    </div>
                </div>
                <div class="px-3 pb-3">
                    <div class="relative h-[220px]"><canvas x-ref="timeBucketsChart" id="timeBucketsChart"></canvas></div>
                </div>
            </article>
        </section>
    </template>

    {{-- ======================================================================
         BY POE TAB
       ====================================================================== --}}
    <template x-if="ready && tab === 'by-poe'">
        <section>
            <article class="card overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/60 flex-wrap">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Closure league by Point of Entry</h2>
                        <span class="badge badge-secondary">Showing <span x-text="visiblePoeCount()"></span> of <span x-text="(byPoeTable || []).length"></span></span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="relative">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                            <input class="input pl-7 w-48" type="search" placeholder="Search POE…" x-model="poeQuery">
                        </div>
                        <button type="button" class="btn btn-outline btn-xs" @click="exportAs('CSV')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3"><path d="M12 3v12m0 0l-4-4m4 4l4-4"/><path d="M5 21h14"/></svg>
                            CSV
                        </button>
                        <button type="button" class="btn btn-outline btn-xs" @click="exportAs('PDF')">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><path d="M14 2v6h6"/></svg>
                            PDF
                        </button>
                    </div>
                </div>

                <div class="grid grid-cols-3 divide-x divide-border border-b border-border/60">
                    <div class="px-4 py-3">
                        <div class="eyebrow">Total POEs</div>
                        <div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="(byPoeTable || []).length"></div>
                    </div>
                    <div class="px-4 py-3">
                        <div class="eyebrow">Total cycles</div>
                        <div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="formatNum(kpis.total_cycles)"></div>
                    </div>
                    <div class="px-4 py-3">
                        <div class="eyebrow">Average closure</div>
                        <div class="text-xl font-bold tabular-nums font-mono mt-0.5">
                            <span x-text="(kpis.closure_rate ?? 0)"></span><span class="text-sm text-muted-foreground">%</span>
                        </div>
                    </div>
                </div>

                <div class="overflow-auto max-h-[60vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">#</th>
                                <th class="table-head-th table-head-th-sort" @click="sortBy('poe_name')">POE Name</th>
                                <th class="table-head-th">Type</th>
                                <th class="table-head-th">Province</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortBy('total')">Cycles</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortBy('closed')">Closed</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortBy('closure_rate')">Closure %</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortBy('overdue')">Overdue</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortBy('ack_rate')">Ack %</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortBy('median_minutes')">Median (min)</th>
                                <th class="table-head-th">Trend</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-for="(row, idx) in sortedFilteredPoeRows()" :key="row.poe_code">
                                <tr class="table-row">
                                    <td class="table-cell text-muted-foreground" x-text="idx + 1"></td>
                                    <td class="table-cell font-sans font-semibold" x-text="row.poe_name"></td>
                                    <td class="table-cell font-sans">
                                        <span class="badge" :class="poeTypeBadgeClass(row.poe_type)" x-text="poeTypeLabel(row.poe_type)"></span>
                                    </td>
                                    <td class="table-cell font-sans" x-text="row.province"></td>
                                    <td class="table-cell text-right" x-text="formatNum(row.total)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(row.closed)"></td>
                                    <td class="table-cell text-right">
                                        <span class="badge" :class="closureBadgeClass(row.closure_rate)" x-text="row.closure_rate + '%'"></span>
                                    </td>
                                    <td class="table-cell text-right" :class="(row.overdue ?? 0) > 0 ? 'text-critical font-semibold' : 'text-muted-foreground'" x-text="formatNum(row.overdue)"></td>
                                    <td class="table-cell text-right" x-text="row.ack_rate + '%'"></td>
                                    <td class="table-cell text-right" x-text="formatNum(Math.round(row.median_minutes))"></td>
                                    <td class="table-cell">
                                        <svg viewBox="0 0 100 24" class="h-6 w-24">
                                            <polyline fill="none" stroke="hsl(var(--success))" stroke-width="1.5" :points="sparkPoints(row.sparkline)"/>
                                        </svg>
                                    </td>
                                </tr>
                            </template>
                            <template x-if="sortedFilteredPoeRows().length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="11">No POEs in this filter window.</td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between px-4 py-2.5 border-t border-border/60">
                    <span class="text-[11px] text-muted-foreground">Sorted by Cycles (descending) · Click any column header to re-sort</span>
                </div>
            </article>
        </section>
    </template>

    {{-- ======================================================================
         CYCLES TAB
       ====================================================================== --}}
    <template x-if="ready && tab === 'cycles'">
        <section class="space-y-4">
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Why cycles remain open</div>
                        <h2 class="text-base font-semibold mt-0.5">Reasons not closed</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('reasonsBarChart','reasons-not-closed')" aria-label="Download PNG">PNG</button>
                        <button type="button" class="rpt-explain-btn" data-chart-key="reasons_bar">?</button>
                    </div>
                </div>
                <div class="px-3 pb-3">
                    <div class="relative h-[220px]"><canvas x-ref="reasonsBarChart" id="reasonsBarChart"></canvas></div>
                </div>
            </article>

            <article class="card overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/60 flex-wrap">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Oldest open cycles</h2>
                        <span class="badge badge-warning" x-show="(oldestOpen || []).length"><span x-text="(oldestOpen || []).length"></span> rows</span>
                        <span class="badge badge-success" x-show="!(oldestOpen || []).length">No open cycles</span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="oldest_open">?</button>
                    </div>
                    <button type="button" class="btn btn-outline btn-xs" @click="exportOldestOpen()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3"><path d="M12 3v12m0 0l-4-4m4 4l4-4"/><path d="M5 21h14"/></svg>
                        CSV
                    </button>
                </div>
                <div class="overflow-auto max-h-[55vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">#</th>
                                <th class="table-head-th">POE</th>
                                <th class="table-head-th">Title</th>
                                <th class="table-head-th">Risk</th>
                                <th class="table-head-th">Status</th>
                                <th class="table-head-th">Stall reason</th>
                                <th class="table-head-th">Opened</th>
                                <th class="table-head-th text-right">Age (h)</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-for="(row, i) in (oldestOpen || [])" :key="row.alert_id">
                                <tr class="table-row">
                                    <td class="table-cell text-muted-foreground" x-text="i + 1"></td>
                                    <td class="table-cell font-sans font-semibold" x-text="row.poe_name"></td>
                                    <td class="table-cell font-sans truncate max-w-[260px]" :title="row.alert_title" x-text="row.alert_title"></td>
                                    <td class="table-cell">
                                        <span class="badge" :class="riskBadgeClass(row.risk_level)" x-text="riskLabel(row.risk_level)"></span>
                                    </td>
                                    <td class="table-cell">
                                        <span class="badge badge-secondary" x-text="row.status"></span>
                                    </td>
                                    <td class="table-cell font-sans">
                                        <span class="badge badge-warning" x-text="reasonLabel(row.reason)"></span>
                                    </td>
                                    <td class="table-cell font-sans" x-text="formatDateTime(row.created_at)"></td>
                                    <td class="table-cell text-right" :class="row.age_hours > 72 ? 'text-critical font-semibold' : ''" x-text="row.age_hours"></td>
                                </tr>
                            </template>
                            <template x-if="(oldestOpen || []).length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="8">All cycles closed in this window.</td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    </template>

    {{-- ======================================================================
         DISPOSITION & FOLLOW-UP TAB
       ====================================================================== --}}
    <template x-if="ready && tab === 'disposition'">
        <section class="space-y-4">
            <div class="grid grid-cols-12 gap-4">
                <article class="card col-span-12 md:col-span-7">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Closed cycles</div>
                            <h2 class="text-base font-semibold mt-0.5">Terminal disposition</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('dispositionDonutChart','disposition')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="disposition_donut">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[280px]"><canvas x-ref="dispositionDonutChart" id="dispositionDonutChart"></canvas></div>
                    </div>
                </article>

                <article class="card col-span-12 md:col-span-5">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Required follow-ups</div>
                            <h2 class="text-base font-semibold mt-0.5">Follow-up completion</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('followupDonutChart','followup-completion')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="followup_donut">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[220px]"><canvas x-ref="followupDonutChart" id="followupDonutChart"></canvas></div>
                        <div class="mt-3 text-[12px]">
                            <div class="flex items-center justify-between">
                                <span>Required</span>
                                <span class="font-mono tabular-nums font-semibold" x-text="formatNum(kpis.followup_required)"></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span>Completed</span>
                                <span class="font-mono tabular-nums font-semibold text-success" x-text="formatNum(kpis.followup_completed)"></span>
                            </div>
                            <div class="flex items-center justify-between mt-1 pt-1 border-t border-border">
                                <span class="text-muted-foreground">Completion rate</span>
                                <span class="font-mono tabular-nums font-semibold"><span x-text="kpis.followup_complete_pct ?? 0"></span>%</span>
                            </div>
                        </div>
                    </div>
                </article>
            </div>

            <div class="grid grid-cols-12 gap-4">
                <article class="card col-span-12 md:col-span-6 overflow-hidden">
                    <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between">
                        <h2 class="text-base font-semibold">Disposition mix</h2>
                    </div>
                    <div class="overflow-auto max-h-[40vh]">
                        <table class="table">
                            <thead class="table-head">
                                <tr>
                                    <th class="table-head-th">Disposition</th>
                                    <th class="table-head-th text-right">Count</th>
                                    <th class="table-head-th text-right">% of closed</th>
                                </tr>
                            </thead>
                            <tbody class="table-body font-mono tabular-nums">
                                <template x-for="row in dispositionRows()" :key="row.key">
                                    <tr class="table-row">
                                        <td class="table-cell font-sans">
                                            <span class="inline-block h-2 w-2 rounded-sm mr-2 align-middle" :style="`background:${row.color}`"></span>
                                            <span x-text="row.label"></span>
                                        </td>
                                        <td class="table-cell text-right" x-text="formatNum(row.value)"></td>
                                        <td class="table-cell text-right" x-text="row.pct + '%'"></td>
                                    </tr>
                                </template>
                                <template x-if="dispositionRows().length === 0">
                                    <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="3">No closed cycles in window.</td></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </article>

                <article class="card col-span-12 md:col-span-6 overflow-hidden">
                    <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between">
                        <h2 class="text-base font-semibold">Facility arrival</h2>
                        <span class="text-[11.5px] text-muted-foreground">Among REFERRED dispositions</span>
                    </div>
                    <div class="p-4 space-y-3">
                        <div class="flex items-center justify-between text-[13px]">
                            <div class="flex items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-sm" style="background:hsl(var(--success))"></span>
                                <span>Arrived</span>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="font-mono tabular-nums font-semibold" x-text="formatNum(facilityArrival.ARRIVED)"></span>
                                <span class="text-muted-foreground text-[11.5px]" x-text="(kpis.arrived_rate ?? 0) + '%'"></span>
                            </div>
                        </div>
                        <div class="flex items-center justify-between text-[13px]">
                            <div class="flex items-center gap-2">
                                <span class="h-2.5 w-2.5 rounded-sm" style="background:hsl(var(--critical))"></span>
                                <span>No-show</span>
                            </div>
                            <div class="flex items-baseline gap-2">
                                <span class="font-mono tabular-nums font-semibold" x-text="formatNum(facilityArrival.NO_SHOW)"></span>
                                <span class="text-muted-foreground text-[11.5px]" x-text="noShowPct() + '%'"></span>
                            </div>
                        </div>
                        <div class="progress" role="progressbar" aria-label="Facility arrival rate"
                             :aria-valuenow="kpis.arrived_rate ?? 0" aria-valuemin="0" aria-valuemax="100">
                            <div class="progress-bar" :style="`width: ${(kpis.arrived_rate ?? 0)}%`"></div>
                        </div>
                        <p class="text-[11.5px] text-muted-foreground">A high no-show share usually means the receiving facility never confirmed arrival.</p>
                    </div>
                </article>
            </div>
        </section>
    </template>

    {{-- ======================================================================
         QUALITY TAB
       ====================================================================== --}}
    <template x-if="ready && tab === 'quality'">
        <section class="space-y-4">
            <div class="grid grid-cols-12 gap-4">
                <article class="card col-span-12 md:col-span-6">
                    <div class="px-4 py-3 border-b border-border/60">
                        <h2 class="text-base font-semibold">Closure rule</h2>
                    </div>
                    <div class="p-4 space-y-2 text-[13px]">
                        <p>An alert cycle counts as <strong>closed</strong> when:</p>
                        <ul class="list-disc list-inside space-y-1 text-muted-foreground">
                            <li><code class="kbd">alerts.status = CLOSED</code></li>
                            <li><code class="kbd">alerts.closed_at IS NOT NULL</code></li>
                            <li><code class="kbd">close_category != DUPLICATE</code></li>
                        </ul>
                        <p>Cycles are flagged <strong class="text-critical">overdue</strong> when their age exceeds the closure window
                            (<span class="font-mono" x-text="quality.closure_window_hours"></span> hours) without a CLOSED state.</p>
                    </div>
                </article>

                <article class="card col-span-12 md:col-span-6">
                    <div class="px-4 py-3 border-b border-border/60">
                        <h2 class="text-base font-semibold">Quality counters</h2>
                    </div>
                    <div class="p-4 grid grid-cols-2 gap-3 text-[13px]">
                        <div>
                            <div class="eyebrow">Cycles without disposition</div>
                            <div class="text-xl font-bold tabular-nums font-mono mt-0.5"
                                 :class="(quality.cycles_without_disposition ?? 0) > 0 ? 'text-warning' : ''"
                                 x-text="formatNum(quality.cycles_without_disposition)"></div>
                            <p class="text-[11px] text-muted-foreground">Closed cycles whose linked secondary screening lacks a final_disposition.</p>
                        </div>
                        <div>
                            <div class="eyebrow">Duplicate-merged</div>
                            <div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="formatNum(quality.duplicates_excluded)"></div>
                            <p class="text-[11px] text-muted-foreground">Excluded from the closure rate (rule §4.1).</p>
                        </div>
                        <div>
                            <div class="eyebrow">Closure window</div>
                            <div class="text-xl font-bold tabular-nums font-mono mt-0.5"><span x-text="quality.closure_window_hours"></span> h</div>
                            <p class="text-[11px] text-muted-foreground">Hours after which an open cycle becomes overdue.</p>
                        </div>
                        <div>
                            <div class="eyebrow">Unassigned POE</div>
                            <div class="text-xl font-bold tabular-nums font-mono mt-0.5"
                                 :class="(quality.unassigned_poe_count ?? 0) > 0 ? 'text-warning' : ''"
                                 x-text="formatNum(quality.unassigned_poe_count)"></div>
                            <p class="text-[11px] text-muted-foreground">Cycles with no poe_code on the alert row.</p>
                        </div>
                    </div>
                </article>
            </div>

            <article class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-border/60">
                    <h2 class="text-base font-semibold">Reasons not closed (counts)</h2>
                </div>
                <div class="overflow-auto max-h-[40vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">Reason</th>
                                <th class="table-head-th text-right">Count</th>
                                <th class="table-head-th text-right">% of pending</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-for="(v, k) in (reasonsNoClose || {})" :key="k">
                                <tr class="table-row">
                                    <td class="table-cell font-sans" x-text="reasonLabel(k)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(v)"></td>
                                    <td class="table-cell text-right" x-text="reasonPct(v) + '%'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
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
    @include('admin.reports._chart_explainer', ['reportKey' => 'rpt-screening-outcomes'])

    {{-- ABOUT modal --}}
    <div x-show="aboutOpen" x-cloak class="fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="aboutOpen = false">
        <div class="bg-background w-full sm:max-w-lg sm:rounded-xl border border-border shadow-elevation-5 flex flex-col overflow-hidden max-h-[88vh]" @click.away="aboutOpen = false">
            <header class="px-5 pt-5 pb-3 border-b border-border">
                <span class="badge badge-brand mb-1">About this report</span>
                <h3 class="text-base font-semibold">Screening Outcomes after Alert Cycles</h3>
            </header>
            <div class="overflow-y-auto px-5 py-4 space-y-2.5 text-[13px] leading-relaxed">
                <p><strong>Purpose.</strong> Show whether each alert cycle that opened in the period closed cleanly, in how long, and with what outcome — so leadership can see where the workflow is stuck.</p>
                <p><strong>Audience.</strong> PHEOC operations leads, district focal persons, IHR focal point.</p>
                <p><strong>Source.</strong> <code class="kbd">alerts</code> joined to <code class="kbd">secondary_screenings</code> for disposition and follow-up. <code class="kbd">ref_poes</code> supplies POE metadata. Rows with <code class="kbd">sync_status = FAILED</code> are excluded.</p>
                <p><strong>What it cannot tell you.</strong> Which conditions drove the alerts (use Suspected Cases) or whether acknowledgement SLAs were met (use Alert Acknowledgement).</p>
            </div>
            <footer class="px-5 py-3 border-t border-border flex justify-end">
                <button type="button" class="btn btn-default btn-xs" @click="aboutOpen = false">Close</button>
            </footer>
        </div>
    </div>

    {{-- DEFINITIONS modal --}}
    <div x-show="defOpen" x-cloak class="fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="defOpen = false">
        <div class="bg-background w-full sm:max-w-md sm:rounded-xl border border-border shadow-elevation-5 flex flex-col overflow-hidden" @click.away="defOpen = false">
            <header class="px-5 pt-5 pb-3 border-b border-border">
                <span class="badge badge-secondary mb-1">Definition</span>
                <h3 class="text-base font-semibold" x-text="defRow?.title || '—'"></h3>
            </header>
            <div class="px-5 py-4 space-y-2 text-[13px]" x-html="defRow?.body || '—'"></div>
            <p class="px-5 pb-3 text-[11.5px] text-muted-foreground"><strong>Source.</strong> <span x-text="defRow?.src || '—'"></span></p>
            <footer class="px-5 py-3 border-t border-border flex justify-end">
                <button type="button" class="btn btn-default btn-xs" @click="defOpen = false">Close</button>
            </footer>
        </div>
    </div>

    {{-- WALK-THROUGH wizard --}}
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

    {{-- WHAT WOULD YOU LIKE TO DO? --}}
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

    {{-- EXPORT modal --}}
    <div x-show="exportOpen" x-cloak class="fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="exportOpen = false">
        <div class="bg-background w-full sm:max-w-lg sm:rounded-xl border border-border shadow-elevation-5 flex flex-col overflow-hidden" @click.away="exportOpen = false">
            <header class="px-5 pt-5 pb-3 border-b border-border">
                <h3 class="text-base font-semibold">Export</h3>
                <p class="text-[12px] text-muted-foreground">Pick a format. Scope and filters are baked into the file footer.</p>
            </header>
            <div class="px-5 py-4 grid grid-cols-2 gap-2">
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportAs('PDF')">
                    <div class="font-semibold text-[13px]">PDF report</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Per-POE table, ready to print.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportAs('CSV')">
                    <div class="font-semibold text-[13px]">CSV — per-POE</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Raw per-POE league.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportAs('XLSX')">
                    <div class="font-semibold text-[13px]">Excel summary</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Per-POE table for spreadsheet tools.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; downloadAllPng()">
                    <div class="font-semibold text-[13px]">PNG — all charts</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">High-DPI for slide decks.</p>
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

function rptOutcomes() {
    return {
        ready: false,
        tab: 'overview',
        wizard: { open:false, step:1 },
        ask:    { open:false },
        tour:   { open:false, step:1, steps: [
            { t: 'The question this view answers',
              b: '<p>Did the alerts we raised resolve into a defensible outcome — and where they didn\'t? Everything on this page exists to answer that.</p>' },
            { t: 'Step 1 — The KPI row',
              b: '<p>Closure rate is the headline (highlighted). Total cycles, pending, median time-to-close, acknowledged share, and follow-up completion follow.</p>' },
            { t: 'Step 2 — The Overview tab',
              b: '<p>Closure donut for the current snapshot, weekly closure trend for movement, time-to-close distribution for SLA pressure.</p>' },
            { t: 'Step 3 — By POE & Cycles',
              b: '<p>By POE is a sortable league with sparklines and ack rate. Cycles shows the reasons-not-closed bar plus the 25 oldest open cycles to triage.</p>' },
            { t: 'Step 4 — Disposition & Quality',
              b: '<p>Disposition and follow-up donuts answer "how cycles ended." Quality flags rule definitions and any data integrity gaps.</p>' },
        ]},

        aboutOpen: false,
        defOpen: false,
        defRow: null,
        exportOpen: false,
        notesOpen: true,

        filters: { poe:'', sex:'', year:'', quarter:'', month:'', province:'', start_date:'', end_date:'' },
        meta:    { poes:{}, districts:{}, provinces:{}, years:[], quarters:{}, months:{}, genders:{} },

        kpis: {},
        weeklyTrend: [],
        byPoe: [],
        byPoeTable: [],
        oldestOpen: [],
        disposition: {},
        reasonsNoClose: {},
        facilityArrival: { ARRIVED: 0, NO_SHOW: 0 },
        followup: { REQUIRED: 0, COMPLETED: 0 },
        timeBuckets: {},
        quality: {},
        insights: [],
        dataNotes: {},
        window: { from:'', to:'' },

        // Sort / search state for the By POE table
        poeQuery: '',
        sortKey: 'total',
        sortDir: 'desc',

        charts: {},

        askOptions: [
            { code:'TRIAGE',     label:'Triage the oldest open cycles',     help:'Jump to the Cycles tab and read the oldest-open list.', tag:'Common',     badge:'badge-info' },
            { code:'STUCK_POE',  label:'Find the POE with stalled cycles',  help:'Open By POE and sort by Overdue.', tag:'Investigate', badge:'badge-secondary' },
            { code:'NO_SHOW',    label:'Where are referred travellers not arriving?', help:'Open Disposition & Follow-up.', tag:'Drill',       badge:'badge-warning' },
            { code:'SLA',        label:'Is the closure SLA being met?',     help:'Read the time-to-close chart on Overview.', tag:'Inspect',     badge:'badge-info' },
            { code:'EXPORT',     label:'Export this view',                  help:'PDF or CSV with scope, period and timestamp.', tag:'Share',       badge:'badge-success' },
            { code:'COMPARE',    label:'Compare two POEs',                  help:'Use the By POE league sorted by Cycles.', tag:'Compare',     badge:'badge-secondary' },
        ],

        async boot() {
            this.restoreFiltersFromUrl();
            // Re-render Chart.js whenever the active tab changes — canvases are
            // mounted on demand by <template x-if>, so refs only resolve once
            // the new tab is in the DOM.
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
        resetFilters() {
            this.filters = { poe:'', sex:'', year:'', quarter:'', month:'', province:'', start_date:'', end_date:'' };
            window.history.replaceState(null, '', window.location.pathname);
        },

        async runReport() {
            this.writeFiltersToUrl();
            try {
                const r = await rptJson(@json(url('/admin/reports/rpt-screening-outcomes/data')), this.buildParams());
                const d = r?.data || {};
                this.window         = d.window || {};
                this.kpis           = d.kpis || {};
                this.weeklyTrend    = d.weekly_trend || [];
                this.byPoe          = d.by_poe || [];
                this.byPoeTable     = d.by_poe_table || [];
                this.oldestOpen     = d.oldest_open || [];
                this.disposition    = d.disposition || {};
                this.reasonsNoClose = d.reasons_no_close || {};
                this.facilityArrival= d.facility_arrival || { ARRIVED: 0, NO_SHOW: 0 };
                this.followup       = d.followup || { REQUIRED: 0, COMPLETED: 0 };
                this.timeBuckets    = (d.time_to_close && d.time_to_close.hour_buckets) || {};
                this.quality        = d.quality || {};
                this.insights       = d.insights || [];
                this.dataNotes      = d.data_notes || {};
                this.ready          = true;
                this.$nextTick(() => this.renderCharts());
                Alpine.store('pageMeta', { rows: (this.kpis.total_cycles ?? 0), version: null, kind: 'rpt-screening-outcomes' });
            } catch (e) {
                console.error(e);
                this.ready = false;
            }
        },

        buildParams() { const p = {}; for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) p[k] = v; return p; },

        exportAs(fmt) {
            const u = new URL(@json(url('/admin/reports/rpt-screening-outcomes/export')), window.location.origin);
            for (const [k,v] of Object.entries(this.buildParams())) u.searchParams.set(k, v);
            u.searchParams.set('format', fmt);
            if (fmt === 'PDF') window.open(u.toString(), '_blank', 'noopener');
            else window.location.href = u.toString();
        },

        exportOldestOpen() {
            const rows = (this.oldestOpen || []);
            if (!rows.length) return;
            const headers = ['Alert ID', 'POE Name', 'POE Code', 'Title', 'Risk', 'Status', 'Stall reason', 'Opened', 'Age (h)'];
            const lines = [headers.join(',')].concat(rows.map(r => [
                r.alert_id, this.csv(r.poe_name), this.csv(r.poe_code), this.csv(r.alert_title),
                this.csv(r.risk_level), this.csv(r.status), this.csv(r.reason),
                this.csv(r.created_at), r.age_hours,
            ].join(',')));
            this.downloadBlob(new Blob(["﻿" + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }), 'oldest-open-' + this.stamp() + '.csv');
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
            const filename = `rpt-screening-outcomes-${slug}-${stamp}.png`;
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
            g.fillText(`Screening Outcomes · ${slug} · ${lbl} · ${win} · generated ${stamp}`, 8, c.height + 18);
            out.toBlob(blob => this.downloadBlob(blob, filename), 'image/png');
        },

        downloadAllPng() {
            ['closureDonutChart','weeklyClosureChart','timeBucketsChart','reasonsBarChart','dispositionDonutChart','followupDonutChart'].forEach(id => {
                const slug = id.replace(/Chart$/, '').replace(/[A-Z]/g, m => '-' + m.toLowerCase()).replace(/^-/, '');
                this.downloadChartPng(id, slug);
            });
        },

        formatNum(v)  { return (v == null || v === undefined) ? '—' : Number(v).toLocaleString(); },
        formatDate(d) {
            if (!d) return '—';
            try {
                const dt = new Date(d + (d.length <= 10 ? 'T00:00:00' : ''));
                const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][dt.getMonth()];
                return `${dt.getDate()} ${m} ${dt.getFullYear()}`;
            } catch (e) { return d; }
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
        formatDateShort(d) {
            if (!d) return '—';
            try {
                const dt = new Date(d + 'T00:00:00');
                const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][dt.getMonth()];
                return `${dt.getDate()} ${m}`;
            } catch (e) { return d; }
        },
        windowLabel() { return this.window.from ? (this.formatDate(this.window.from) + ' → ' + this.formatDate(this.window.to)) : 'No window'; },

        overdueBadgeClass() {
            const v = Number(this.kpis.overdue_open ?? 0);
            if (v === 0) return 'badge-success';
            if (v < 5)   return 'badge-warning';
            return 'badge-critical';
        },
        closureBadgeClass(rate) {
            const v = Number(rate || 0);
            if (v >= 80) return 'badge-success';
            if (v >= 50) return 'badge-warning';
            return 'badge-critical';
        },
        riskBadgeClass(level) {
            return level === 'CRITICAL' ? 'badge-critical'
                 : level === 'HIGH'     ? 'badge-high'
                 : level === 'MEDIUM'   ? 'badge-medium'
                 : level === 'LOW'      ? 'badge-low'
                 : 'badge-secondary';
        },
        riskLabel(level) {
            return ({ CRITICAL: 'Critical', HIGH: 'High', MEDIUM: 'Medium', LOW: 'Low' })[level] || 'Unclassified';
        },
        reasonLabel(code) {
            return ({
                LOST_TO_FOLLOWUP:  'Lost to follow-up',
                AWAITING_LAB:      'Awaiting lab',
                AWAITING_FACILITY: 'Awaiting facility',
                AWAITING_DECISION: 'Awaiting decision',
                OTHER:             'Other',
            })[code] || code;
        },
        reasonPct(value) {
            const total = Object.values(this.reasonsNoClose || {}).reduce((a,b) => a + Number(b || 0), 0);
            if (!total) return '0.0';
            return ((Number(value || 0) / total) * 100).toFixed(1);
        },
        poeTypeBadgeClass(t) { return t === 'airport' || t === 'airstrip' ? 'badge-info' : 'badge-secondary'; },
        poeTypeLabel(t) {
            return ({ airport:'Airport', airstrip:'Airstrip', port:'Port',
                island_entry:'Island', land_border:'Land border',
                rail:'Rail', other:'Other' })[t] || 'Land border';
        },

        noShowPct() {
            const a = Number(this.facilityArrival.ARRIVED || 0);
            const n = Number(this.facilityArrival.NO_SHOW || 0);
            const tot = a + n;
            if (!tot) return '0.0';
            return ((n / tot) * 100).toFixed(1);
        },

        sortBy(key) {
            if (this.sortKey === key) this.sortDir = this.sortDir === 'asc' ? 'desc' : 'asc';
            else { this.sortKey = key; this.sortDir = 'desc'; }
        },
        sortedFilteredPoeRows() {
            const q = (this.poeQuery || '').toLowerCase().trim();
            const numeric = ['total','closed','closure_rate','overdue','ack_rate','median_minutes','p90_minutes'];
            const key = this.sortKey;
            const dir = this.sortDir === 'asc' ? 1 : -1;
            return [...(this.byPoeTable || [])]
                .filter(r => !q || (r.poe_name && r.poe_name.toLowerCase().includes(q)) || (r.poe_code && r.poe_code.toLowerCase().includes(q)))
                .sort((a, b) => {
                    let av = a[key], bv = b[key];
                    if (numeric.includes(key)) { av = Number(av); bv = Number(bv); }
                    if (av < bv) return -1 * dir;
                    if (av > bv) return  1 * dir;
                    return 0;
                });
        },
        visiblePoeCount() { return this.sortedFilteredPoeRows().length; },

        sparkPoints(arr) {
            if (!arr || !arr.length) return '';
            const max = Math.max(1, ...arr);
            const w = 100, h = 24, pad = 2;
            return arr.map((v, i) => {
                const x = (i / Math.max(1, arr.length - 1)) * (w - 2 * pad) + pad;
                const y = h - pad - ((v / max) * (h - 2 * pad));
                return `${x.toFixed(1)},${y.toFixed(1)}`;
            }).join(' ');
        },

        dispositionRows() {
            const labels = {
                REFERRED_ARRIVED: 'Referred (arrived)',
                REFERRED_NO_SHOW: 'Referred (no-show)',
                ISOLATED:         'Isolated / Quarantined',
                RELEASED:         'Released',
                TRANSFERRED:      'Transferred / Denied',
                OTHER:            'Other',
            };
            const colors = {
                REFERRED_ARRIVED: tokenColor('--success'),
                REFERRED_NO_SHOW: tokenColor('--critical'),
                ISOLATED:         tokenColor('--high'),
                RELEASED:         tokenColor('--info'),
                TRANSFERRED:      tokenColor('--viz-4'),
                OTHER:            tokenColor('--muted-foreground'),
            };
            const total = Object.values(this.disposition || {}).reduce((a,b) => a + Number(b || 0), 0);
            return Object.entries(this.disposition || {}).map(([k, v]) => ({
                key: k,
                label: labels[k] || k,
                color: colors[k] || tokenColor('--viz-1'),
                value: Number(v || 0),
                pct: total > 0 ? ((Number(v || 0) / total) * 100).toFixed(1) : '0.0',
            }));
        },

        runAsk(code) {
            switch (code) {
                case 'TRIAGE':    this.tab = 'cycles'; break;
                case 'STUCK_POE': this.tab = 'by-poe'; this.sortKey = 'overdue'; this.sortDir = 'desc'; break;
                case 'NO_SHOW':   this.tab = 'disposition'; break;
                case 'SLA':       this.tab = 'overview'; break;
                case 'EXPORT':    this.exportOpen = true; break;
                case 'COMPARE':   this.tab = 'by-poe'; this.sortKey = 'total'; this.sortDir = 'desc'; break;
            }
        },

        openDef(key) {
            const defs = {
                closure_rate: {
                    title: 'Closure rate',
                    body:  '<p>Of all cycles opened in the period (excluding duplicate-merged), the share that have a CLOSED status with closed_at set.</p>',
                    src:   'closed / (total − duplicates) × 100; suppressed if denominator < 5.',
                },
                total_cycles: {
                    title: 'Total cycles',
                    body:  '<p>Every alert cycle whose <code>created_at</code> falls in the window, after filtering rows with <code>sync_status = FAILED</code>.</p>',
                    src:   'COUNT(*) on alerts WHERE deleted_at IS NULL AND sync_status != FAILED.',
                },
                pending: {
                    title: 'Pending cycles',
                    body:  '<p>Cycles that have not yet closed. Rows older than the closure window are flagged overdue.</p>',
                    src:   'total − closed − duplicates; overdue = open AND age > 72h.',
                },
                median: {
                    title: 'Median time-to-close',
                    body:  '<p>Median minutes from <code>created_at</code> to <code>closed_at</code> for closed, non-duplicate cycles.</p>',
                    src:   'median(closed_at − created_at) over countable closed set.',
                },
                ack: {
                    title: 'Acknowledgement rate',
                    body:  '<p>Share of cycles whose <code>acknowledged_at</code> is set — confirms the alert was picked up.</p>',
                    src:   'COUNT(acknowledged_at IS NOT NULL) ÷ total.',
                },
                followup: {
                    title: 'Follow-up completion',
                    body:  '<p>Of cycles flagging <code>followup_required = 1</code>, the share where the secondary screening also closed.</p>',
                    src:   'completed ÷ required × 100.',
                },
            };
            this.defRow = defs[key] || null;
            this.defOpen = !!this.defRow;
        },

        // ────────────────────────────────────────────────
        // Chart rendering
        // ────────────────────────────────────────────────
        destroyCharts() {
            Object.values(this.charts).forEach(c => { try { c.destroy(); } catch (e) {} });
            this.charts = {};
        },
        renderCharts() {
            if (typeof Chart === 'undefined') return;
            this.destroyCharts();
            requestAnimationFrame(() => {
                this.renderClosureDonut();
                this.renderWeeklyClosure();
                this.renderTimeBuckets();
                this.renderReasonsBar();
                this.renderDispositionDonut();
                this.renderFollowupDonut();
            });
        },

        renderClosureDonut() {
            const ref = this.$refs.closureDonutChart;
            if (!ref) return;
            const closed   = Number(this.kpis.closed_cycles ?? 0);
            const overdue  = Number(this.kpis.overdue_open ?? 0);
            const pendingT = Number(this.kpis.pending_cycles ?? 0);
            const pending  = Math.max(0, pendingT - overdue);
            const dups     = Number(this.kpis.duplicates ?? 0);
            this.charts.closureDonut = new Chart(ref, {
                type: 'doughnut',
                data: {
                    labels: ['Closed', 'Pending', 'Overdue (>72h)', 'Duplicates'],
                    datasets: [{
                        data: [closed, pending, overdue, dups],
                        backgroundColor: [
                            tokenColor('--success'),
                            tokenColor('--warning'),
                            tokenColor('--critical'),
                            tokenColor('--muted'),
                        ],
                        borderWidth: 2, borderColor: '#fff',
                    }],
                },
                options: {
                    cutout: '64%',
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: { label: c => `${c.label}: ${c.parsed.toLocaleString()}` } },
                    },
                },
            });
        },

        renderWeeklyClosure() {
            const ref = this.$refs.weeklyClosureChart;
            if (!ref) return;
            const labels = (this.weeklyTrend || []).map(w => this.formatDateShort(w.week_ending));
            const opened = (this.weeklyTrend || []).map(w => w.total);
            const closed = (this.weeklyTrend || []).map(w => w.closed);
            this.charts.weeklyClosure = new Chart(ref, {
                data: {
                    labels,
                    datasets: [
                        { type: 'bar',  label: 'Opened', data: opened, backgroundColor: tokenColor('--info') + 'AA', borderRadius: 3, order: 2 },
                        { type: 'line', label: 'Closed', data: closed,
                          borderColor: tokenColor('--success'),
                          backgroundColor: tokenColor('--success') + '22',
                          tension: 0.3, pointRadius: 4,
                          pointBackgroundColor: tokenColor('--success'),
                          pointBorderColor: '#fff', pointBorderWidth: 1.5,
                          fill: false, order: 1 },
                    ],
                },
                options: {
                    plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
                    interaction: { mode: 'nearest', axis: 'x', intersect: false },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tokenColor('--muted-foreground') } },
                        y: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground') }, grid: { color: tokenColor('--border'), drawBorder: false } },
                    },
                },
            });
        },

        renderTimeBuckets() {
            const ref = this.$refs.timeBucketsChart;
            if (!ref) return;
            const labels = Object.keys(this.timeBuckets || {});
            const data   = labels.map(k => this.timeBuckets[k] || 0);
            const colors = labels.map((_, i) => i < 3 ? tokenColor('--success')
                                            : i < 5 ? tokenColor('--warning')
                                                    : tokenColor('--critical'));
            this.charts.timeBuckets = new Chart(ref, {
                type: 'bar',
                data: { labels, datasets: [{ label: 'Cycles', data, backgroundColor: colors, borderRadius: 3 }] },
                options: {
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tokenColor('--muted-foreground') } },
                        y: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground'), precision: 0 }, grid: { color: tokenColor('--border'), drawBorder: false } },
                    },
                },
            });
        },

        renderReasonsBar() {
            const ref = this.$refs.reasonsBarChart;
            if (!ref) return;
            const order = ['AWAITING_DECISION','AWAITING_FACILITY','AWAITING_LAB','LOST_TO_FOLLOWUP','OTHER'];
            const sorted = order.filter(k => k in (this.reasonsNoClose || {}));
            const labels = sorted.map(k => this.reasonLabel(k));
            const data   = sorted.map(k => Number(this.reasonsNoClose[k] || 0));
            const palette = [tokenColor('--info'), tokenColor('--warning'), tokenColor('--high'), tokenColor('--critical'), tokenColor('--muted-foreground')];
            this.charts.reasonsBar = new Chart(ref, {
                type: 'bar',
                data: { labels, datasets: [{ label: 'Open cycles', data, backgroundColor: palette.slice(0, sorted.length), borderRadius: 3 }] },
                options: {
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground'), precision: 0 }, grid: { color: tokenColor('--border'), drawBorder: false } },
                        y: { ticks: { color: tokenColor('--foreground'), font: { weight: '600' } }, grid: { display: false } },
                    },
                },
            });
        },

        renderDispositionDonut() {
            const ref = this.$refs.dispositionDonutChart;
            if (!ref) return;
            const rows = this.dispositionRows();
            this.charts.dispositionDonut = new Chart(ref, {
                type: 'doughnut',
                data: {
                    labels: rows.map(r => r.label),
                    datasets: [{
                        data: rows.map(r => r.value),
                        backgroundColor: rows.map(r => r.color),
                        borderWidth: 2, borderColor: '#fff',
                    }],
                },
                options: {
                    cutout: '60%',
                    plugins: {
                        legend: { position: 'right', labels: { color: tokenColor('--foreground'), boxWidth: 10 } },
                        tooltip: { callbacks: { label: c => `${c.label}: ${c.parsed.toLocaleString()}` } },
                    },
                },
            });
        },

        renderFollowupDonut() {
            const ref = this.$refs.followupDonutChart;
            if (!ref) return;
            const required  = Number(this.followup.REQUIRED || 0);
            const completed = Number(this.followup.COMPLETED || 0);
            const open      = Math.max(0, required - completed);
            this.charts.followupDonut = new Chart(ref, {
                type: 'doughnut',
                data: {
                    labels: ['Completed', 'Open'],
                    datasets: [{
                        data: [completed, open],
                        backgroundColor: [tokenColor('--success'), tokenColor('--warning')],
                        borderWidth: 2, borderColor: '#fff',
                    }],
                },
                options: {
                    cutout: '64%',
                    plugins: {
                        legend: { position: 'bottom', labels: { color: tokenColor('--foreground'), boxWidth: 10 } },
                        tooltip: { callbacks: { label: c => `${c.label}: ${c.parsed.toLocaleString()}` } },
                    },
                },
            });
        },
    };
}
</script>
@endpush
@endsection
