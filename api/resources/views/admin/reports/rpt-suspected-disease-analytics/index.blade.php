@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Suspected Disease Analytics')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
{{--
    R9 · Suspected Disease Analytics — REBUILT 2026-04-26 as a lean 3-tab
    chassis. Audience: IHR Focal Point, disease programme leads, WHO Country
    Office. The view answers four questions in order:

      1. Did anything fire?              → Tripwire callout strip
      2. Which diseases are hot?         → Top-15 leaderboard with VHF ring
      3. Where are they concentrated?    → POE × disease heatmap
      4. Is the lab keeping up?          → Lab-loop band

    Tabs (3, deliberate):
      · Overview         — answers all four at a glance
      · Per-Disease      — drill into one disease (trend, POE breakdown,
                           age cohort, co-suspected pairs)
      · Tripwires        — fired-rule cards + methodology footnote

    What was deliberately removed: the redundant "By District" tab (POE is
    enough for IHR-level decisions), the "Quality" tab (footer note in data
    notes), the standalone "Co-suspicion" tab (folded into Per-Disease),
    the standalone "Trend" tab (folded into Per-Disease).

    Theme primitives only. No dark mode. No animations beyond animate-spin.
--}}
<div x-data="rptSuspectedDiseases()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('rpt-suspected-disease-analytics', wizard.open || ask.open || tour.open || aboutOpen)"
     class="space-y-4">

    {{-- ======================================================================
         HEADER
       ====================================================================== --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">National Reports · rpt-suspected-disease-analytics</p>
            <h1 class="text-[18px] font-semibold flex items-center gap-2">
                Suspected Disease Analytics
                <button type="button" class="rpt-explain-btn" @click="aboutOpen = true" aria-label="About this report" title="About this report">i</button>
            </h1>
            <p class="help-text mt-0.5">What clinicians at the border are seeing — before laboratory confirmation. IHR Focal Point lens.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="windowLabel()"></span>
            </span>
            <span class="topbar-chip topbar-chip-mono" x-show="ready && (kpis.tripwire_count ?? 0) > 0">
                <span class="status-dot status-dot-danger"></span>
                <span x-text="(kpis.tripwire_count ?? 0) + ' tripwire' + ((kpis.tripwire_count ?? 0) === 1 ? '' : 's') + ' fired'"></span>
            </span>
            @include('admin.reports._coach', ['reportKey' => 'rpt-suspected-disease-analytics'])
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
                <button type="button" class="rpt-explain-btn" data-chart-key="disease_leaderboard" aria-label="What this report shows">?</button>
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
            <p class="help-text max-w-md mx-auto">Pick a year, quarter, or date range, then press Apply Filters. Filters always reflect into the URL — share the URL to share the picture.</p>
            <button type="button" class="btn btn-brand btn-sm" @click="runReport()">Run report</button>
        </div></section>
    </template>

    {{-- ======================================================================
         KPI ROW · 6 numbers, urgency-ordered
       ====================================================================== --}}
    <template x-if="ready">
        <section class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2.5">
            <div class="kpi" :class="(kpis.tripwire_count ?? 0) > 0 ? 'kpi-glow' : ''">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Tripwires</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('tripwires')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" :class="(kpis.tripwire_count ?? 0) > 0 ? 'text-critical' : ''" x-text="formatNum(kpis.tripwire_count)"></div>
                <div class="text-[10.5px] text-muted-foreground" x-text="(kpis.tripwire_count ?? 0) > 0 ? 'VHF + cholera rules' : 'No rules fired'"></div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">VHF suspicions</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('vhf')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" :class="(kpis.vhf_total ?? 0) > 0 ? 'text-critical' : ''" x-text="formatNum(kpis.vhf_total)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="(kpis.vhf_diseases ?? 0) + ' distinct VHF diseases'"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Total suspected</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('total')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.total_suspected)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="(kpis.reporting_poes ?? 0) + ' POE' + ((kpis.reporting_poes ?? 0) === 1 ? '' : 's') + ' · ' + windowLabel()"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Unique diseases</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('unique')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.unique_diseases)"></div>
                <div class="text-[10.5px] text-muted-foreground">Across the cohort</div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Lead disease</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('lead')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value text-base font-bold flex items-center gap-1.5 truncate">
                    <span class="truncate" :title="kpis.lead_disease || ''" x-text="kpis.lead_disease || '—'"></span>
                    <span x-show="kpis.lead_is_vhf" class="badge badge-critical text-[9px]">VHF</span>
                </div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="(kpis.lead_count ?? 0) + ' suspected'"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Confirmation</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('confirmation')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value">
                    <span x-text="(kpis.confirmation_pct ?? 0)"></span><span class="text-base text-muted-foreground">%</span>
                </div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="formatNum(kpis.confirmed) + ' / ' + formatNum(kpis.total_suspected)"></span>
                </div>
            </div>
        </section>
    </template>

    {{-- ======================================================================
         TABS · 3
       ====================================================================== --}}
    <template x-if="ready">
        <section>
            <div class="tabs-list" role="tablist" aria-label="Suspected disease analytics views">
                <button class="tabs-trigger" role="tab" :data-state="tab === 'overview' ? 'active' : null" @click="tab = 'overview'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    Overview
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'disease' ? 'active' : null" @click="tab = 'disease'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><circle cx="12" cy="12" r="9"/><path d="M9 12h6"/><path d="M12 9v6"/></svg>
                    Per-Disease
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'tripwires' ? 'active' : null" @click="tab = 'tripwires'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><path d="M12 9v4M12 17h.01M10.3 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    Tripwires
                </button>
            </div>
        </section>
    </template>

    {{-- ====================================================================
         OVERVIEW TAB
       ==================================================================== --}}
    <template x-if="ready && tab === 'overview'">
        <section class="space-y-4">

            {{-- Tripwire callout strip --}}
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">First — did anything fire?</div>
                        <h2 class="text-base font-semibold mt-0.5">Tripwire Status</h2>
                    </div>
                    <button type="button" class="rpt-explain-btn" data-chart-key="tripwire_panel">?</button>
                </div>
                <div class="p-4 pt-2">
                    <template x-if="(kpis.tripwire_count ?? 0) === 0">
                        <div class="rounded-md border border-success/40 bg-success-soft/40 px-3 py-3 text-[12.5px] flex items-start gap-2.5">
                            <span class="mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full shrink-0 text-[10px] font-bold uppercase bg-success text-success-foreground">✓</span>
                            <div>
                                <p class="font-semibold">No tripwires fired</p>
                                <p class="text-muted-foreground text-[11.5px] mt-0.5">No VHF cluster (≥5/14d/POE) or cholera doubling (W/W/district) in this scope.</p>
                            </div>
                        </div>
                    </template>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-2" x-show="(kpis.tripwire_count ?? 0) > 0">
                        <template x-for="(t, i) in (tripwires.vhf_cluster || [])" :key="'vhf-' + i">
                            <div class="rounded-md border border-critical/60 bg-critical-soft/40 px-3 py-2.5 text-[12.5px]">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="font-semibold flex items-center gap-1.5">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-critical"><path d="M12 9v4M12 17h.01M10.3 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                                        VHF cluster · <span x-text="poeName(t.poe_code)"></span>
                                    </p>
                                    <span class="badge badge-critical"><span x-text="t.count + ' / 14d'"></span></span>
                                </div>
                                <p class="text-muted-foreground text-[11.5px] mt-1">
                                    <span x-text="t.count"></span> suspected <span x-text="t.disease_code"></span> at <span x-text="poeName(t.poe_code)"></span> within 14 days. IHR Tier-1 review.
                                </p>
                            </div>
                        </template>
                        <template x-for="(t, i) in (tripwires.cholera_doubling || [])" :key="'ch-' + i">
                            <div class="rounded-md border border-warning/60 bg-warning-soft/40 px-3 py-2.5 text-[12.5px]">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="font-semibold flex items-center gap-1.5">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-warning"><path d="M12 9v4M12 17h.01M10.3 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                                        Cholera doubling · <span x-text="t.district_code"></span>
                                    </p>
                                    <span class="badge badge-warning"><span x-text="t.curr + ' (was ' + t.prev + ')'"></span></span>
                                </div>
                                <p class="text-muted-foreground text-[11.5px] mt-1">
                                    Cases doubled week-over-week in <span x-text="t.district_code"></span>. Inspect water/sanitation triggers within 24 hours.
                                </p>
                            </div>
                        </template>
                    </div>
                </div>
            </article>

            {{-- Lab loop strip --}}
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Lab loop</div>
                        <h2 class="text-base font-semibold mt-0.5">Confirmation Status</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="badge badge-success"><span class="h-1.5 w-1.5 rounded-full bg-success"></span>Confirmed</span>
                        <span class="badge badge-warning"><span class="h-1.5 w-1.5 rounded-full bg-warning"></span>Pending</span>
                        <span class="badge badge-secondary">Unresolved</span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="lab_loop">?</button>
                    </div>
                </div>
                <div class="p-4 pt-2">
                    <template x-if="(labLoop.suspected ?? 0) === 0">
                        <p class="text-[12px] text-muted-foreground">No suspected cases in window.</p>
                    </template>
                    <template x-if="(labLoop.suspected ?? 0) > 0">
                        <div class="space-y-2">
                            <div class="flex h-9 rounded-lg overflow-hidden border border-border/60">
                                <div class="bg-success text-white text-[11.5px] flex items-center justify-center font-semibold" :style="`flex: ${Math.max(1, labLoop.confirmed)}`">
                                    <span x-show="labLoop.confirmed > 0" x-text="labLoop.confirmed + ' Confirmed'"></span>
                                </div>
                                <div class="text-[11.5px] flex items-center justify-center font-semibold" :style="`flex: ${Math.max(1, labLoop.pending)}; background: hsl(var(--warning)); color: hsl(var(--warning-foreground));`">
                                    <span x-show="labLoop.pending > 0" x-text="labLoop.pending + ' Pending'"></span>
                                </div>
                                <div class="bg-muted text-muted-foreground text-[11.5px] flex items-center justify-center font-semibold" :style="`flex: ${Math.max(1, labLoop.unresolved)}`">
                                    <span x-show="labLoop.unresolved > 0" x-text="labLoop.unresolved + ' Unresolved'"></span>
                                </div>
                            </div>
                            <div class="grid grid-cols-3 gap-3 text-[11px] pt-1">
                                <div><span class="text-muted-foreground">Confirmation rate</span><div class="font-mono tabular-nums font-semibold text-success" x-text="(labLoop.pct ?? 0) + '%'"></div></div>
                                <div><span class="text-muted-foreground">Pending share</span><div class="font-mono tabular-nums font-semibold text-warning" x-text="pendingPct() + '%'"></div></div>
                                <div><span class="text-muted-foreground">Total suspected</span><div class="font-mono tabular-nums font-semibold" x-text="formatNum(labLoop.suspected)"></div></div>
                            </div>
                        </div>
                    </template>
                </div>
            </article>

            {{-- Disease leaderboard --}}
            <article class="card overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/60 flex-wrap">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Top Suspected Diseases</h2>
                        <span class="badge badge-secondary"><span x-text="topDiseases.length"></span> rows</span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="disease_leaderboard">?</button>
                    </div>
                    <div class="flex items-center gap-1.5">
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
                                <th class="table-head-th">Disease</th>
                                <th class="table-head-th">Syndrome</th>
                                <th class="table-head-th text-right">Suspected</th>
                                <th class="table-head-th text-right">Confirmed</th>
                                <th class="table-head-th text-right">Pending</th>
                                <th class="table-head-th text-right">POEs</th>
                                <th class="table-head-th">Top POE</th>
                                <th class="table-head-th">12-week trend</th>
                                <th class="table-head-th">Confirmation</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-if="topDiseases.length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="9">No suspected diseases in window.</td></tr>
                            </template>
                            <template x-for="r in topDiseases" :key="r.disease_code">
                                <tr class="table-row cursor-pointer" @click="selectDisease(r.disease_code)">
                                    <td class="table-cell font-sans font-semibold">
                                        <div class="flex items-center gap-1.5">
                                            <span x-text="r.disease_code"></span>
                                            <span x-show="isVhf(r.who_syndrome)" class="badge badge-critical text-[9px]" title="VHF · IHR Tier-1">VHF</span>
                                        </div>
                                    </td>
                                    <td class="table-cell font-sans text-[11px] text-muted-foreground" x-text="r.who_syndrome || '—'"></td>
                                    <td class="table-cell text-right font-semibold" x-text="formatNum(r.count)"></td>
                                    <td class="table-cell text-right text-success" x-text="formatNum(r.confirmed)"></td>
                                    <td class="table-cell text-right text-warning" x-text="formatNum(r.pending)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(r.poes)"></td>
                                    <td class="table-cell font-sans" x-text="r.top_poe ? (r.top_poe + ' (' + r.top_poe_count + ')') : '—'"></td>
                                    <td class="table-cell">
                                        <svg viewBox="0 0 100 24" class="h-6 w-24">
                                            <polyline fill="none"
                                                      :stroke="isVhf(r.who_syndrome) ? 'hsl(var(--critical))' : 'hsl(var(--viz-1))'"
                                                      stroke-width="1.5"
                                                      :points="sparkPoints(r.sparkline)"/>
                                        </svg>
                                    </td>
                                    <td class="table-cell">
                                        <template x-if="r.confirmation_pct === null">
                                            <span class="badge badge-secondary text-[9px]" title="n < 5">—</span>
                                        </template>
                                        <template x-if="r.confirmation_pct !== null">
                                            <span class="badge" :class="confirmRateBadgeClass(r.confirmation_pct)" x-text="r.confirmation_pct + '%'"></span>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </article>

            {{-- POE × Disease heatmap --}}
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Concentration</div>
                        <h2 class="text-base font-semibold mt-0.5">POE × Disease</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="badge badge-secondary">Top <span x-text="(heatmap.poes || []).length"></span> POEs × Top <span x-text="(heatmap.diseases || []).length"></span> diseases</span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="poe_heatmap">?</button>
                    </div>
                </div>
                <div class="p-4 pt-2 overflow-auto">
                    <template x-if="!(heatmap.poes && heatmap.poes.length && heatmap.diseases && heatmap.diseases.length)">
                        <p class="text-[12px] text-muted-foreground py-2">Not enough data to build the concentration matrix.</p>
                    </template>
                    <template x-if="heatmap.poes && heatmap.poes.length && heatmap.diseases && heatmap.diseases.length">
                        <div class="inline-grid gap-1 text-[11px] min-w-[640px]"
                             :style="`grid-template-columns: 180px repeat(${heatmap.diseases.length}, minmax(64px, 1fr)) 60px;`">
                            <div></div>
                            <template x-for="d in heatmap.diseases" :key="'h-' + d">
                                <div class="text-center font-semibold uppercase tracking-wider text-muted-foreground text-[9.5px] pb-1 truncate" :title="d" x-text="d"></div>
                            </template>
                            <div class="text-center font-semibold uppercase tracking-wider text-muted-foreground text-[9.5px] pb-1">Total</div>
                            <template x-for="poe in heatmap.poes" :key="'r-' + poe.code">
                                <template x-for="cell in heatmapCells(poe)" :key="'c-' + poe.code + '-' + cell.col">
                                    <template x-if="cell.col === '__label'">
                                        <div class="text-[12px] font-semibold flex items-center pr-2 truncate" :title="poe.name" x-text="poe.name"></div>
                                    </template>
                                    <template x-if="cell.col === '__total'">
                                        <div class="hm-cell bg-card border font-bold" x-text="cell.value"></div>
                                    </template>
                                    <template x-if="cell.col !== '__label' && cell.col !== '__total'">
                                        <div class="hm-cell"
                                             :class="cell.value === 0 ? 'bg-muted/40 text-muted-foreground' : ''"
                                             :style="cell.value === 0 ? '' : `background: hsl(var(${cell.color}) / ${cell.intensity}); color: ${cell.intensity > 0.55 ? 'white' : 'hsl(var(--foreground))'};`"
                                             :title="poe.name + ' × ' + cell.col + ' = ' + cell.value"
                                             @click="selectDisease(cell.col)"
                                             x-text="cell.value === 0 ? '—' : cell.value"></div>
                                    </template>
                                </template>
                            </template>
                        </div>
                    </template>
                </div>
            </article>
        </section>
    </template>

    {{-- ====================================================================
         PER-DISEASE TAB
       ==================================================================== --}}
    <template x-if="ready && tab === 'disease'">
        <section class="space-y-4">

            {{-- Disease selector --}}
            <article class="card">
                <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-[12.5px] font-semibold">Disease</span>
                        <select class="select w-auto min-w-[200px]" x-model="selectedDisease">
                            <template x-for="d in topDiseases" :key="d.disease_code">
                                <option :value="d.disease_code" x-text="d.disease_code + ' (' + d.count + ')'"></option>
                            </template>
                        </select>
                        <span x-show="selectedRow && isVhf(selectedRow.who_syndrome)" class="badge badge-critical">VHF · IHR Tier-1</span>
                        <span x-show="selectedRow && selectedRow.who_syndrome" class="text-[11px] text-muted-foreground">
                            <span x-text="selectedRow?.who_syndrome"></span>
                        </span>
                    </div>
                    <div class="text-[11px] text-muted-foreground">
                        Latest detected: <span class="font-mono" x-text="formatDate(selectedRow?.latest)"></span>
                    </div>
                </div>

                {{-- Per-disease KPI strip --}}
                <div class="grid grid-cols-2 md:grid-cols-5 divide-x divide-border border-b border-border/60">
                    <div class="px-4 py-3"><div class="eyebrow">Suspected</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="formatNum(selectedRow?.count)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">Confirmed</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5 text-success" x-text="formatNum(selectedRow?.confirmed)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">Pending</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5 text-warning" x-text="formatNum(selectedRow?.pending)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">POEs</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="formatNum(selectedRow?.poes)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">Top POE</div><div class="text-base font-bold mt-0.5 truncate" :title="selectedRow?.top_poe || ''" x-text="selectedRow?.top_poe || '—'"></div></div>
                </div>
            </article>

            <div class="grid grid-cols-12 gap-4">
                {{-- 12-week trend --}}
                <article class="card col-span-12 lg:col-span-7">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Trajectory</div>
                            <h2 class="text-base font-semibold mt-0.5">12-week Trend</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('diseaseTrendChart','disease-trend')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="disease_trend">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[240px]"><canvas x-ref="diseaseTrendChart" id="diseaseTrendChart"></canvas></div>
                        <p class="text-[11px] text-muted-foreground mt-2 italic" x-text="trendCaption()"></p>
                    </div>
                </article>

                {{-- Top age band --}}
                <article class="card col-span-12 lg:col-span-5">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Cohort</div>
                            <h2 class="text-base font-semibold mt-0.5">Age Band Distribution</h2>
                        </div>
                    </div>
                    <div class="p-4 pt-2 space-y-2">
                        <template x-if="!ageDistribution.length">
                            <p class="text-[12px] text-muted-foreground">No age data for this disease.</p>
                        </template>
                        <template x-for="band in ageDistribution" :key="band.label">
                            <div>
                                <div class="flex items-center justify-between text-[11.5px] mb-1">
                                    <span class="font-semibold" x-text="band.label"></span>
                                    <span class="font-mono tabular-nums">
                                        <span x-text="band.count"></span>
                                        <span class="text-muted-foreground text-[10px] ml-1" x-text="band.pct + '%'"></span>
                                    </span>
                                </div>
                                <div class="h-2 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full" :style="`width: ${band.pct}%; background: hsl(var(${band.label === selectedRow?.top_age_band ? '--brand' : '--viz-2'}))`"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </article>
            </div>

            {{-- Co-suspected diseases --}}
            <article class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Often Co-suspected With</h2>
                        <span class="badge badge-secondary"><span x-text="selectedCosuspect.length"></span> pairs ≥ 3</span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="cosuspect_pairs">?</button>
                    </div>
                </div>
                <div class="overflow-auto max-h-[40vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">Co-suspected disease</th>
                                <th class="table-head-th text-right">Cases (both)</th>
                                <th class="table-head-th">Visual</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-if="selectedCosuspect.length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="3">No co-suspicion pairs at the ≥ 3 threshold.</td></tr>
                            </template>
                            <template x-for="p in selectedCosuspect" :key="p.partner">
                                <tr class="table-row cursor-pointer" @click="selectDisease(p.partner)">
                                    <td class="table-cell font-sans font-semibold" x-text="p.partner"></td>
                                    <td class="table-cell text-right" x-text="p.count"></td>
                                    <td class="table-cell">
                                        <div class="h-2 rounded-full bg-muted overflow-hidden w-40">
                                            <div class="h-full bg-info" :style="`width: ${cosuspectWidth(p.count)}%`"></div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    </template>

    {{-- ====================================================================
         TRIPWIRES TAB · methodology & history
       ==================================================================== --}}
    <template x-if="ready && tab === 'tripwires'">
        <section class="space-y-4">

            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Deterministic rules · v1 — domain sign-off pending</div>
                        <h2 class="text-base font-semibold mt-0.5">Fired Tripwires</h2>
                    </div>
                    <button type="button" class="rpt-explain-btn" data-chart-key="tripwire_panel">?</button>
                </div>
                <div class="p-4 pt-2">
                    <template x-if="(kpis.tripwire_count ?? 0) === 0">
                        <div class="rounded-md border border-success/40 bg-success-soft/40 px-3 py-3 text-[12.5px] flex items-start gap-2.5">
                            <span class="mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full shrink-0 text-[10px] font-bold uppercase bg-success text-success-foreground">✓</span>
                            <div>
                                <p class="font-semibold">Nothing fired in this scope</p>
                                <p class="text-muted-foreground text-[11.5px] mt-0.5">Both rules ran clean across the cohort.</p>
                            </div>
                        </div>
                    </template>

                    <div class="space-y-3" x-show="(kpis.tripwire_count ?? 0) > 0">
                        <div x-show="(tripwires.vhf_cluster || []).length > 0">
                            <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">VHF cluster · ≥5 suspicions / 14 days / POE</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                <template x-for="(t, i) in (tripwires.vhf_cluster || [])" :key="'tv-' + i">
                                    <div class="rounded-md border border-critical/60 bg-critical-soft/40 px-3 py-2.5 text-[12.5px]">
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="font-semibold flex items-center gap-1.5">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-critical"><path d="M12 9v4M12 17h.01M10.3 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                                                <span x-text="poeName(t.poe_code)"></span>
                                            </p>
                                            <span class="badge badge-critical"><span x-text="t.count + ' / 14d'"></span></span>
                                        </div>
                                        <p class="text-muted-foreground text-[11.5px] mt-1">
                                            <span x-text="t.disease_code"></span> · IHR Tier-1 review mandated.
                                        </p>
                                        <p class="text-[10.5px] font-mono text-muted-foreground/70 mt-1">rule: TRIPWIRE_VHF_CLUSTER_v1</p>
                                    </div>
                                </template>
                            </div>
                        </div>

                        <div x-show="(tripwires.cholera_doubling || []).length > 0">
                            <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Cholera doubling · W/W / district</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-2">
                                <template x-for="(t, i) in (tripwires.cholera_doubling || [])" :key="'tc-' + i">
                                    <div class="rounded-md border border-warning/60 bg-warning-soft/40 px-3 py-2.5 text-[12.5px]">
                                        <div class="flex items-start justify-between gap-2">
                                            <p class="font-semibold flex items-center gap-1.5">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-warning"><path d="M12 9v4M12 17h.01M10.3 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                                                <span x-text="t.district_code"></span>
                                            </p>
                                            <span class="badge badge-warning"><span x-text="t.curr + ' (was ' + t.prev + ')'"></span></span>
                                        </div>
                                        <p class="text-muted-foreground text-[11.5px] mt-1">
                                            Doubled W/W. Inspect water/sanitation triggers within 24 hours.
                                        </p>
                                        <p class="text-[10.5px] font-mono text-muted-foreground/70 mt-1">rule: TRIPWIRE_CHOLERA_DOUBLING_v1</p>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </article>

            {{-- Methodology footnote --}}
            <article class="card">
                <div class="px-4 py-3 border-b border-border/60">
                    <h2 class="text-base font-semibold">Methodology</h2>
                </div>
                <div class="p-4 space-y-3 text-[12.5px] leading-relaxed">
                    <div>
                        <p class="font-semibold">VHF cluster · TRIPWIRE_VHF_CLUSTER_v1</p>
                        <p class="text-muted-foreground mt-0.5">Fires when ≥ 5 suspicions of any single VHF-tagged disease occur at the same POE within a rolling 14-day window. VHF tagging derived from <code class="kbd">ref_diseases.who_syndrome</code> (any value containing "VHF").</p>
                    </div>
                    <div>
                        <p class="font-semibold">Cholera doubling · TRIPWIRE_CHOLERA_DOUBLING_v1</p>
                        <p class="text-muted-foreground mt-0.5">Fires when suspected cholera in a single district at least doubles week-over-week and the prior week had ≥ 2 cases (to suppress 0→1 noise). Disease match is case-insensitive on the substring "cholera".</p>
                    </div>
                    <div class="pt-2 border-t border-border/60">
                        <p class="text-[11.5px] text-muted-foreground">Rules are pinned at <strong>v1</strong>. Any threshold change requires a new version. The engine never auto-fires downstream actions — fires surface as critical-level insights only.</p>
                    </div>
                </div>
            </article>
        </section>
    </template>

    {{-- ====================================================================
         INSIGHTS + DATA NOTES
       ==================================================================== --}}
    <template x-if="ready">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @include('admin.reports._insights')
            @include('admin.reports._data_notes')
        </div>
    </template>

    @include('admin.reports._filter_wizard')
    @include('admin.reports._chart_explainer', ['reportKey' => 'rpt-suspected-disease-analytics'])

    {{-- =================================================================
         FOOTER
       ================================================================= --}}
    <footer class="text-[11px] text-muted-foreground border-t border-border/60 pt-3 mt-2 flex items-center justify-between flex-wrap gap-2">
        <span>Source:
            <span class="kbd">secondary_screenings</span> ·
            <span class="kbd">secondary_suspected_diseases</span> ·
            <span class="kbd">alerts</span> ·
            <span class="kbd">ref_diseases</span> · Reference data <span class="kbd">rda-2026-02-01</span>
        </span>
        <span x-text="'Generated ' + (window?.from ? formatDate(window.from) + ' → ' + formatDate(window.to) : '—') + ' · PHEOC Command Centre · Uganda · v1.0'"></span>
    </footer>

    {{-- =================================================================
         ABOUT modal
       ================================================================= --}}
    <div x-show="aboutOpen" x-cloak class="fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="aboutOpen = false">
        <div class="bg-background w-full sm:max-w-lg sm:rounded-xl border border-border shadow-elevation-5 flex flex-col overflow-hidden max-h-[88vh]" @click.away="aboutOpen = false">
            <header class="px-5 pt-5 pb-3 border-b border-border">
                <span class="badge badge-brand mb-1">About this report</span>
                <h3 class="text-base font-semibold">Suspected Disease Analytics</h3>
            </header>
            <div class="overflow-y-auto px-5 py-4 space-y-2.5 text-[13px] leading-relaxed">
                <p><strong>Purpose.</strong> Show what clinicians at the border are seeing — every disease the suspicion engine flagged, before laboratory confirmation. The view is built for IHR-level decisions, not bulk drill-down.</p>
                <p><strong>Audience.</strong> IHR Focal Point, disease-specific programme leads, WHO Country Office.</p>
                <p><strong>Source.</strong> All figures derive from <code class="kbd">secondary_screenings</code> joined to <code class="kbd">secondary_suspected_diseases</code>; confirmation status comes from <code class="kbd">alerts</code> (ihr_tier IS NOT NULL AND status = CLOSED); WHO syndrome tagging from <code class="kbd">ref_diseases.who_syndrome</code>.</p>
                <p><strong>Tripwires.</strong> Two deterministic rules run on every load — VHF cluster (≥5/14d at one POE) and cholera doubling (W/W in one district). Both are pinned at v1 and need domain sign-off before re-tuning.</p>
                <p><strong>What it cannot tell you.</strong> Disease prevalence in the general population, or definitive disease attribution. See Case Confirmation for the lab loop and Geographic Risk for inbound flow.</p>
            </div>
            <footer class="px-5 py-3 border-t border-border flex justify-end">
                <button type="button" class="btn btn-default btn-xs" @click="aboutOpen = false">Close</button>
            </footer>
        </div>
    </div>

    {{-- =================================================================
         DEFINITIONS modal
       ================================================================= --}}
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

    {{-- =================================================================
         WALK-THROUGH wizard
       ================================================================= --}}
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

    {{-- =================================================================
         "Do something" launcher
       ================================================================= --}}
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

    {{-- =================================================================
         EXPORT modal
       ================================================================= --}}
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
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Full page, ready to print.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportAs('CSV')">
                    <div class="font-semibold text-[13px]">CSV — disease leaderboard</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Per-disease counts.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportAs('XLSX')">
                    <div class="font-semibold text-[13px]">Excel summary</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Disease leaderboard for spreadsheet tools.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; downloadAllPng()">
                    <div class="font-semibold text-[13px]">PNG — disease trend</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Currently selected disease, high-DPI.</p>
                </button>
            </div>
            <footer class="px-5 py-3 border-t border-border flex justify-end">
                <button type="button" class="btn btn-ghost btn-xs" @click="exportOpen = false">Cancel</button>
            </footer>
        </div>
    </div>
</div>

@push('scripts')
<style>
.hm-cell{ border-radius:4px; font-family:'JetBrains Mono',ui-monospace,monospace; font-size:11.5px; display:flex; align-items:center; justify-content:center; font-weight:600; min-height:34px; cursor:pointer; }
.hm-cell:hover{ outline: 2px solid hsl(var(--brand) / .35); outline-offset: -2px; }
</style>
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

function rptSuspectedDiseases() {
    return {
        ready: false,
        tab: 'overview',
        wizard: { open:false, step:1 },
        ask:    { open:false },
        tour:   { open:false, step:1, steps: [
            { t: 'The four questions this view answers',
              b: '<p>For IHR / WHO Country Office consumers, this view is built around exactly four questions:</p><ol class="list-decimal pl-5 mt-1 space-y-0.5"><li>Did anything fire?</li><li>Which diseases are hot?</li><li>Where are they concentrated?</li><li>Is the lab keeping up?</li></ol>' },
            { t: 'Step 1 — The KPI row',
              b: '<p>Six numbers, urgency-ordered. The Tripwires tile glows red when a deterministic rule fires. VHF suspicions next, then volume, spectrum, lead disease, and confirmation rate.</p>' },
            { t: 'Step 2 — The Overview tab',
              b: '<p>Tripwire callout strip first, then the lab-loop bar, then the disease leaderboard with VHF rings + sparklines, and the POE × disease heatmap. Click any disease row or hot heatmap cell to drill in.</p>' },
            { t: 'Step 3 — Per-Disease drill-down',
              b: '<p>Pick a disease in the selector. The 12-week trend, age cohort, and "often co-suspected with" pairs all refocus on that disease.</p>' },
            { t: 'Step 4 — The Tripwires tab',
              b: '<p>Fired rules with full methodology. Each rule is pinned at v1 and needs domain sign-off before re-tuning.</p>' },
        ]},

        aboutOpen: false,
        defOpen: false,
        defRow: null,
        exportOpen: false,

        filters: { poe:'', year:'', quarter:'', month:'', start_date:'', end_date:'' },
        meta:    { poes:{}, districts:{}, provinces:{}, years:[], quarters:{}, months:{}, genders:{} },

        kpis: {},
        topDiseases: [],
        diseaseTrend: {},
        sparklineWeekLabels: [],
        heatmap: { poes: [], diseases: [], matrix: {} },
        cosuspectPairs: [],
        labLoop: { suspected: 0, confirmed: 0, pending: 0, unresolved: 0, pct: 0 },
        ageByDisease: {},
        tripwires: { vhf_cluster: [], cholera_doubling: [] },
        insights: [],
        dataNotes: {},
        window: { from: '', to: '', generated: '' },

        selectedDisease: null,

        charts: {},

        askOptions: [
            { code:'TRIPWIRE',  label:'Inspect tripwires',         help:'Open the Tripwires tab — every fired rule with its methodology footnote.', tag:'Urgent',    badge:'badge-critical' },
            { code:'VHF',       label:'Drill into VHF burden',     help:'Open the leaderboard filtered to VHF-tagged diseases.', tag:'Investigate', badge:'badge-warning' },
            { code:'LAB',       label:'Audit the lab loop',        help:'Open Case Confirmation for time-to-confirmation distribution.', tag:'Follow-up', badge:'badge-info' },
            { code:'EXPORT',    label:'Export this view',          help:'PDF or CSV with scope, period and timestamp.', tag:'Share',      badge:'badge-success' },
        ],

        async boot() {
            this.restoreFiltersFromUrl();
            this.$watch('tab', () => this.$nextTick(() => this.renderCharts()));
            this.$watch('selectedDisease', () => this.$nextTick(() => this.renderCharts()));
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
            this.filters = { poe:'', year:'', quarter:'', month:'', start_date:'', end_date:'' };
            window.history.replaceState(null, '', window.location.pathname);
        },

        async runReport() {
            this.writeFiltersToUrl();
            try {
                const r = await rptJson(@json(url('/admin/reports/rpt-suspected-disease-analytics/data')), this.buildParams());
                const d = r?.data || {};
                this.window               = d.window || {};
                this.kpis                 = d.kpis || {};
                this.topDiseases          = d.top_diseases || [];
                this.diseaseTrend         = d.disease_trend || {};
                this.sparklineWeekLabels  = d.sparkline_week_labels || [];
                this.heatmap              = d.heatmap || { poes: [], diseases: [], matrix: {} };
                this.cosuspectPairs       = d.cosuspect_pairs || [];
                this.labLoop              = d.lab_loop || { suspected: 0, confirmed: 0, pending: 0, unresolved: 0, pct: 0 };
                this.ageByDisease         = d.age_by_disease || {};
                this.tripwires            = d.tripwires || { vhf_cluster: [], cholera_doubling: [] };
                this.insights             = d.insights || [];
                this.dataNotes            = d.data_notes || {};

                if (! this.selectedDisease && this.topDiseases.length) {
                    this.selectedDisease = this.topDiseases[0].disease_code;
                }

                this.ready = true;
                this.$nextTick(() => this.renderCharts());
            } catch (e) {
                console.error(e);
                this.ready = false;
            }
        },

        buildParams() { const p = {}; for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) p[k] = v; return p; },

        exportAs(fmt) {
            const u = new URL(@json(url('/admin/reports/rpt-suspected-disease-analytics/export')), window.location.origin);
            for (const [k,v] of Object.entries(this.buildParams())) u.searchParams.set(k, v);
            u.searchParams.set('format', fmt);
            if (fmt === 'PDF') window.open(u.toString(), '_blank', 'noopener');
            else window.location.href = u.toString();
        },

        // ───────── Formatters ─────────
        formatNum(v)  { return (v == null || v === undefined) ? '—' : Number(v).toLocaleString(); },
        formatDate(d) {
            if (!d) return '—';
            try {
                const dt = new Date(String(d).length <= 10 ? d + 'T00:00:00' : d);
                if (isNaN(dt.getTime())) return d;
                const m = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][dt.getMonth()];
                return `${dt.getDate()} ${m} ${dt.getFullYear()}`;
            } catch (e) { return d; }
        },
        windowLabel() { return this.window.from ? (this.formatDate(this.window.from) + ' → ' + this.formatDate(this.window.to)) : 'No window'; },

        // ───────── Lookups ─────────
        poeName(code) {
            return this.meta.poes?.[code] || code;
        },
        isVhf(synd) { return synd && synd.toUpperCase().includes('VHF'); },
        confirmRateBadgeClass(pct) {
            if (pct >= 50) return 'badge-success';
            if (pct >= 20) return 'badge-info';
            if (pct === 0) return 'badge-warning';
            return 'badge-secondary';
        },
        pendingPct() {
            const t = Number(this.labLoop.suspected || 0);
            return t > 0 ? Math.round(this.labLoop.pending / t * 100) : 0;
        },

        // ───────── Sparkline ─────────
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

        // ───────── Heatmap helpers ─────────
        get heatmapMax() {
            const m = this.heatmap.matrix || {};
            let max = 0;
            for (const row of Object.values(m)) for (const v of Object.values(row)) max = Math.max(max, v);
            return max || 1;
        },
        heatmapCells(poe) {
            const out = [{ col: '__label', value: 0 }];
            const row = (this.heatmap.matrix || {})[poe.code] || {};
            const max = this.heatmapMax;
            for (const dc of (this.heatmap.diseases || [])) {
                const v = row[dc] || 0;
                const dRow = this.topDiseases.find(d => d.disease_code === dc);
                const isVhf = dRow && this.isVhf(dRow.who_syndrome);
                const intensity = v === 0 ? 0 : 0.2 + 0.6 * (v / max);
                out.push({
                    col: dc, value: v, intensity,
                    color: isVhf ? '--critical' : '--info',
                });
            }
            out.push({ col: '__total', value: poe.total });
            return out;
        },

        // ───────── Per-disease drill-down ─────────
        get selectedRow() {
            return this.topDiseases.find(d => d.disease_code === this.selectedDisease) || null;
        },
        get selectedCosuspect() {
            const code = this.selectedDisease;
            if (! code) return [];
            return (this.cosuspectPairs || [])
                .filter(p => p.a === code || p.b === code)
                .map(p => ({ partner: p.a === code ? p.b : p.a, count: p.count }))
                .sort((a, b) => b.count - a.count);
        },
        cosuspectWidth(count) {
            const max = Math.max(1, ...(this.selectedCosuspect.map(p => p.count)));
            return Math.round(count / max * 100);
        },
        get ageDistribution() {
            const order = ['<5', '5-17', '18-44', '45-64', '65+', 'UNKNOWN'];
            const bands = (this.ageByDisease || {})[this.selectedDisease] || {};
            const total = Object.values(bands).reduce((a, b) => a + b, 0);
            if (total === 0) return [];
            return order
                .filter(b => bands[b] > 0)
                .map(b => ({
                    label: b,
                    count: bands[b],
                    pct: Math.round(bands[b] / total * 100),
                }));
        },
        trendCaption() {
            const arr = (this.diseaseTrend || {})[this.selectedDisease] || [];
            if (! arr.length) return 'No 12-week trend data for this disease.';
            const recent = arr.slice(-3).reduce((a, b) => a + b, 0);
            const prior  = arr.slice(0, -3).reduce((a, b) => a + b, 0);
            if (recent === 0) return 'No suspicions in the last 3 weeks.';
            if (prior === 0)  return `New activity — ${recent} cases in the last 3 weeks.`;
            const ratio = (recent / 3) / (prior / Math.max(1, arr.length - 3));
            if (ratio >= 1.5) return `Recent rate ${ratio.toFixed(1)}× the trailing average — escalate review.`;
            if (ratio <= 0.5) return 'Recent rate well below trailing average — declining.';
            return 'Recent rate consistent with trailing average.';
        },
        selectDisease(code) {
            this.selectedDisease = code;
            this.tab = 'disease';
        },

        // ───────── Modals: definitions ─────────
        openDef(key) {
            const total = this.kpis.total_suspected ?? 0;
            const defs = {
                tripwires: {
                    title: 'Tripwires fired',
                    body:  '<p>Deterministic rules that fired in this scope: <strong>VHF cluster</strong> (≥5 suspicions of one VHF disease at one POE in 14 days) and <strong>Cholera doubling</strong> (W/W doubling in one district, prior week ≥ 2).</p>',
                    src:   `Engine: SuspectedDiseaseAnalyticsInsightEngine + runTripwires() · v1 · ${this.kpis.tripwire_count || 0} fired in scope.`,
                },
                vhf: {
                    title: 'VHF suspicions',
                    body:  '<p>Total suspicion count across diseases tagged VHF in <code>ref_diseases.who_syndrome</code> — Tier-1 notifiable category.</p>',
                    src:   `Sum across VHF-tagged diseases · ${this.kpis.vhf_total || 0} across ${this.kpis.vhf_diseases || 0} disease(s).`,
                },
                total: {
                    title: 'Total suspected',
                    body:  '<p>Cases that triggered at least one suspected disease entry during the period (one per case, regardless of how many diseases were ranked).</p>',
                    src:   `COUNT(DISTINCT secondary_screening_id) FROM secondary_suspected_diseases · ${total} cases.`,
                },
                unique: {
                    title: 'Unique diseases',
                    body:  '<p>Distinct disease_code values surfacing across the cohort. A wide spectrum (≥ 8) may indicate over-firing of the suspicion engine.</p>',
                    src:   `COUNT(DISTINCT disease_code) · ${this.kpis.unique_diseases || 0} codes.`,
                },
                lead: {
                    title: 'Lead disease',
                    body:  '<p>The most-suspected disease across the cohort. The VHF chip indicates the disease is tagged VHF in the WHO syndrome catalogue.</p>',
                    src:   `Top of leaderboard · ${this.kpis.lead_disease || '—'} with ${this.kpis.lead_count || 0} cases.`,
                },
                confirmation: {
                    title: 'Confirmation rate',
                    body:  '<p>Share of suspected cases with a linked alert that is CLOSED and carries a non-null IHR tier — i.e. the lab loop closed positive for an IHR-relevant disease.</p>',
                    src:   `confirmed / total · ${this.kpis.confirmed || 0} of ${total} = ${this.kpis.confirmation_pct || 0}%.`,
                },
            };
            this.defRow = defs[key] || null;
            this.defOpen = !!this.defRow;
        },

        runAsk(code) {
            switch (code) {
                case 'TRIPWIRE':  this.tab = 'tripwires'; break;
                case 'VHF':       this.tab = 'overview'; break;
                case 'LAB':       window.location.href = @json(url('/admin/reports/rpt-case-confirmation')); break;
                case 'EXPORT':    this.exportOpen = true; break;
            }
        },

        // ───────── PNG export ─────────
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
            const filename = `rpt-suspected-disease-${slug}-${stamp}.png`;
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
            g.fillText(`Suspected Disease Analytics · ${slug} · ${lbl} · ${win} · generated ${stamp}`, 8, c.height + 18);
            out.toBlob(blob => this.downloadBlob(blob, filename), 'image/png');
        },
        downloadAllPng() {
            this.downloadChartPng('diseaseTrendChart', 'disease-trend');
        },

        // ───────── Chart rendering ─────────
        destroyCharts() {
            Object.values(this.charts).forEach(c => { try { c.destroy(); } catch (e) {} });
            this.charts = {};
        },
        renderCharts() {
            if (typeof Chart === 'undefined') return;
            this.destroyCharts();
            requestAnimationFrame(() => {
                if (this.tab === 'disease') {
                    this.renderDiseaseTrend();
                }
            });
        },

        renderDiseaseTrend() {
            const ref = this.$refs.diseaseTrendChart;
            if (!ref) return;
            const data   = (this.diseaseTrend || {})[this.selectedDisease] || [];
            const labels = (this.sparklineWeekLabels || []).map(d => this.formatDate(d));
            const isVhf  = this.selectedRow && this.isVhf(this.selectedRow.who_syndrome);
            const color  = isVhf ? tokenColor('--critical') : tokenColor('--viz-1');
            this.charts.diseaseTrend = new Chart(ref, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: this.selectedDisease || 'Trend',
                        data,
                        borderColor: color,
                        backgroundColor: color + '22',
                        tension: 0.3,
                        fill: true,
                        pointRadius: 3,
                        pointBackgroundColor: color,
                        pointBorderColor: '#fff',
                    }],
                },
                options: {
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.parsed.y} cases` } } },
                    interaction: { mode: 'nearest', axis: 'x', intersect: false },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tokenColor('--muted-foreground') } },
                        y: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground') }, grid: { color: tokenColor('--border'), drawBorder: false } },
                    },
                },
            });
        },
    };
}
</script>
@endpush
@endsection
