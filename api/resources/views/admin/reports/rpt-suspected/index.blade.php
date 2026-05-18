@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Suspected Cases')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
{{--
    R2 · Suspected Cases — REBUILT 2026-04-26 to mirror rpt-volume's premium
    chassis. Question: "Among travellers escalated to secondary screening, who
    is at risk, what syndromes did they present, and what did we do about it?"

    Composition:
      · Compact title bar (eyebrow · h1 · about ⓘ · walk-through · do · export)
      · Filters card (collapsible header · Apply / Reset)
      · 6 KPI tiles (Critical glow · Total · Isolated · Referred · Released · Avg time)
      · Tabs: Overview · Risk & Disposition · Syndromes · Case Register · Symptoms & Actions
      · Charts use Chart.js with theme tokens via CSS-variable resolution.
      · Each chart card has a "?" explainer + a "PNG" download button.
      · Insights + data notes via shared partials.

    Theme primitives only. No dark mode. No animations beyond animate-spin.
--}}
<div x-data="rptSuspected()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('rpt-suspected', wizard.open || ask.open || tour.open || aboutOpen)"
     class="space-y-4">

    {{-- ======================================================================
         HEADER · compact — title + walk-through + actions + export
       ====================================================================== --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Operations · rpt-suspected</p>
            <h1 class="text-[18px] font-semibold flex items-center gap-2">
                Suspected Cases
                <button type="button" class="rpt-explain-btn" @click="aboutOpen = true" aria-label="About this report" title="About this report">i</button>
            </h1>
            <p class="help-text mt-0.5">Who was at risk, what syndromes they presented, and what we did about it.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="windowLabel()"></span>
            </span>
            <span class="topbar-chip topbar-chip-mono" x-show="ready && (kpis.critical ?? 0) > 0">
                <span class="status-dot status-dot-danger"></span>
                <span x-text="(kpis.critical ?? 0) + ' Critical · ' + (kpis.isolated ?? 0) + ' Isolated · ' + (kpis.referred ?? 0) + ' Referred'"></span>
            </span>
            @include('admin.reports._coach', ['reportKey' => 'rpt-suspected'])
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
                <button type="button" class="rpt-explain-btn" data-chart-key="risk_donut" aria-label="What this report shows">?</button>
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
                <label class="label block mb-1">Province (EOC)</label>
                <select class="select" x-model="filters.eoc">
                    <option value="">All Provinces</option>
                    <template x-for="(name, code) in (meta.provinces || {})" :key="code">
                        <option :value="code" x-text="name"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1">Disposition</label>
                <select class="select" x-model="filters.outcome">
                    <option value="">All</option>
                    <option value="RELEASED">Released</option>
                    <option value="REFERRED">Referred</option>
                    <option value="ISOLATED">Isolated</option>
                    <option value="DELAYED">Delayed</option>
                    <option value="QUARANTINED">Quarantined</option>
                    <option value="TRANSFERRED">Transferred</option>
                    <option value="DENIED_BOARDING">Denied boarding</option>
                </select>
            </div>
            <div>
                <label class="label block mb-1">Syndrome</label>
                <select class="select" x-model="filters.classification">
                    <option value="">All</option>
                    <template x-for="s in syndromeOptions" :key="s">
                        <option :value="s" x-text="s"></option>
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
         KPI ROW
       ====================================================================== --}}
    <template x-if="ready">
        <section class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2.5">
            <div class="kpi kpi-glow">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Critical</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('critical')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value text-critical" x-text="formatNum(kpis.critical)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="(kpis.critical_pct ?? 0) + '% of all cases'"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Total cases</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('total')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.total_cases)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="(kpis.reporting_poes ?? 0) + ' POE' + ((kpis.reporting_poes ?? 0) === 1 ? '' : 's') + ' · ' + windowLabel()"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Isolated</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('isolated')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.isolated)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="dispositionPct('isolated') + '% of cohort'"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Referred</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('referred')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.referred)"></div>
                <div class="text-[10.5px] text-muted-foreground">To hospital</div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Released</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('released')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.released)"></div>
                <div class="text-[10.5px] text-muted-foreground">Cleared on site</div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Avg time</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('time')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="durationLabel(kpis.avg_disposition_seconds)"></div>
                <div class="text-[10.5px] text-muted-foreground">Open → dispositioned</div>
            </div>
        </section>
    </template>

    {{-- ======================================================================
         TABS
       ====================================================================== --}}
    <template x-if="ready">
        <section>
            <div class="tabs-list" role="tablist" aria-label="Suspected cases views">
                <button class="tabs-trigger" role="tab" :data-state="tab === 'overview' ? 'active' : null" @click="tab = 'overview'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    Overview
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'risk' ? 'active' : null" @click="tab = 'risk'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><path d="M12 9v4M12 17h.01M10.3 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    Risk &amp; Disposition
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'syndromes' ? 'active' : null" @click="tab = 'syndromes'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><path d="M21 11a9 9 0 11-3-7"/><path d="M21 4v7h-7"/></svg>
                    Syndromes
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'cases' ? 'active' : null" @click="tab = 'cases'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><path d="M4 6h16v3H4z"/><path d="M4 12h16v3H4z"/><path d="M4 18h10v3H4z"/></svg>
                    Case Register
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'symptoms' ? 'active' : null" @click="tab = 'symptoms'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><path d="M3 18h4l2-9 4 18 2-13 2 8h4"/></svg>
                    Symptoms &amp; Actions
                </button>
            </div>
        </section>
    </template>

    {{-- ====================================================================
         OVERVIEW TAB
       ==================================================================== --}}
    <template x-if="ready && tab === 'overview'">
        <section class="space-y-4">
            <div class="grid grid-cols-12 gap-4">

                {{-- Risk donut --}}
                <article class="card col-span-12 md:col-span-4">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Distribution</div>
                            <h2 class="text-base font-semibold mt-0.5">Risk Level</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('riskDonutChart','risk-donut')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="risk_donut">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[200px]"><canvas x-ref="riskDonutChart" id="riskDonutChart"></canvas></div>
                        <div class="space-y-1 mt-2">
                            <template x-for="r in riskDistribution" :key="r.level">
                                <div class="flex items-center justify-between gap-3 text-[12px]">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-sm" :style="`background: hsl(var(${riskCssVar(r.level)}))`"></span>
                                        <span class="capitalize" x-text="r.level.toLowerCase()"></span>
                                    </div>
                                    <div class="font-mono tabular-nums"><span class="font-semibold" x-text="formatNum(r.count)"></span><span class="text-muted-foreground text-[10.5px] ml-1" x-text="r.pct + '%'"></span></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </article>

                {{-- Disposition donut --}}
                <article class="card col-span-12 md:col-span-4">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Outcome</div>
                            <h2 class="text-base font-semibold mt-0.5">Final Disposition</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('dispoDonutChart','disposition-donut')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="disposition_donut">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[200px]"><canvas x-ref="dispoDonutChart" id="dispoDonutChart"></canvas></div>
                        <div class="space-y-1 mt-2">
                            <template x-if="dispositionDistribution.length === 0">
                                <p class="text-[11px] text-muted-foreground">No dispositioned cases in window.</p>
                            </template>
                            <template x-for="d in dispositionDistribution" :key="d.disposition">
                                <div class="flex items-center justify-between gap-3 text-[12px]">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-sm" :style="`background: hsl(var(${dispoCssVar(d.disposition)}))`"></span>
                                        <span class="capitalize" x-text="dispoLabel(d.disposition)"></span>
                                    </div>
                                    <div class="font-mono tabular-nums"><span class="font-semibold" x-text="formatNum(d.count)"></span><span class="text-muted-foreground text-[10.5px] ml-1" x-text="d.pct + '%'"></span></div>
                                </div>
                            </template>
                        </div>
                    </div>
                </article>

                {{-- Syndrome bars --}}
                <article class="card col-span-12 md:col-span-4">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Pattern</div>
                            <h2 class="text-base font-semibold mt-0.5">Syndrome Class</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('syndromeBarChart','syndrome-bars')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="syndrome_bars">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[200px]"><canvas x-ref="syndromeBarChart" id="syndromeBarChart"></canvas></div>
                        <p class="text-[11px] text-muted-foreground mt-2 italic" x-text="syndromeCaption()"></p>
                    </div>
                </article>
            </div>

            {{-- Risk × Disposition matrix --}}
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Cross-tab</div>
                        <h2 class="text-base font-semibold mt-0.5">Risk × Disposition Matrix</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="badge badge-secondary">Counts</span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="risk_disposition_matrix">?</button>
                    </div>
                </div>
                <div class="p-4 pt-2 overflow-auto">
                    <div class="inline-grid gap-1.5 text-[11px] min-w-[640px]"
                         :style="`grid-template-columns: 110px repeat(${visibleDispositions.length}, minmax(80px, 1fr)) 70px;`">
                        <div></div>
                        <template x-for="d in visibleDispositions" :key="'h-' + d">
                            <div class="text-center font-semibold uppercase tracking-wider text-muted-foreground text-[10px] pb-1" x-text="dispoLabel(d)"></div>
                        </template>
                        <div class="text-center font-semibold uppercase tracking-wider text-muted-foreground text-[10px] pb-1">Total</div>
                        <template x-for="r in matrixRisks" :key="'r-' + r">
                            <template x-for="cell in matrixCells(r)" :key="'c-' + r + '-' + cell.col">
                                <div :class="cell.col === '__label' ? 'font-semibold flex items-center' : (cell.col === '__total' ? 'hm-cell bg-card border font-bold' : 'hm-cell ' + cell.bgClass)"
                                     :style="cell.bgStyle">
                                    <template x-if="cell.col === '__label'">
                                        <span class="badge" :class="riskBadgeClass(r)" x-text="r"></span>
                                    </template>
                                    <template x-if="cell.col !== '__label'">
                                        <span x-text="cell.value === 0 ? '—' : cell.value"
                                              :class="cell.value === 0 ? 'text-muted-foreground' : ''"></span>
                                    </template>
                                </div>
                            </template>
                        </template>
                        <div class="font-semibold uppercase tracking-wider text-muted-foreground text-[10px] flex items-center pt-1">Total</div>
                        <template x-for="d in visibleDispositions" :key="'tc-' + d">
                            <div class="hm-cell bg-card border font-bold pt-1" x-text="dispositionColTotal(d)"></div>
                        </template>
                        <div class="hm-cell bg-card border font-bold pt-1" x-text="kpis.total_cases ?? 0"></div>
                    </div>
                </div>
            </article>

            <div class="grid grid-cols-12 gap-4">
                {{-- Sex × Risk --}}
                <article class="card col-span-12 lg:col-span-5">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Demographics</div>
                            <h2 class="text-base font-semibold mt-0.5">Sex × Risk</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('sexRiskChart','sex-risk')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="sex_risk">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[200px]"><canvas x-ref="sexRiskChart" id="sexRiskChart"></canvas></div>
                        <p class="text-[11px] text-muted-foreground mt-2 italic" x-text="sexRiskCaption()"></p>
                    </div>
                </article>

                {{-- Hourly --}}
                <article class="card col-span-12 lg:col-span-4">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Time</div>
                            <h2 class="text-base font-semibold mt-0.5">Cases by Hour</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('hourChart','hourly-tempo')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="hourly_tempo">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[200px]"><canvas x-ref="hourChart" id="hourChart"></canvas></div>
                        <p class="text-[11px] text-muted-foreground mt-2 italic" x-text="hourCaption()"></p>
                    </div>
                </article>

                {{-- Action distribution --}}
                <article class="card col-span-12 lg:col-span-3">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Actions</div>
                            <h2 class="text-base font-semibold mt-0.5">Action Taken</h2>
                        </div>
                        <button type="button" class="rpt-explain-btn" data-chart-key="action_distribution">?</button>
                    </div>
                    <div class="px-3 pb-3 space-y-2">
                        <template x-if="actionDistribution.length === 0">
                            <p class="text-[11px] text-muted-foreground">No actions logged in window.</p>
                        </template>
                        <template x-for="(a, i) in actionDistribution.slice(0, 6)" :key="a.code">
                            <div>
                                <div class="flex items-center justify-between text-[11px] mb-1">
                                    <span class="font-medium" x-text="a.code"></span>
                                    <span class="font-mono tabular-nums font-semibold" x-text="formatNum(a.count)"></span>
                                </div>
                                <div class="h-2 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full" :style="`width: ${actionWidth(a.count)}%; background: hsl(var(${actionCssVar(i)}))`"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </article>
            </div>
        </section>
    </template>

    {{-- ====================================================================
         RISK & DISPOSITION TAB
       ==================================================================== --}}
    <template x-if="ready && tab === 'risk'">
        <section class="space-y-4">
            <div class="grid grid-cols-12 gap-4">
                {{-- Risk pathway --}}
                <article class="card col-span-12 lg:col-span-7">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Decision rule (observed)</div>
                            <h2 class="text-base font-semibold mt-0.5">Risk → Disposition Pathway</h2>
                        </div>
                        <button type="button" class="rpt-explain-btn" data-chart-key="risk_pathway">?</button>
                    </div>
                    <div class="p-4 pt-2 space-y-2">
                        <template x-if="riskPathway.length === 0">
                            <p class="text-[12px] text-muted-foreground">No risk-tier breakdown available.</p>
                        </template>
                        <template x-for="row in riskPathway" :key="row.risk">
                            <div class="grid grid-cols-[100px_1fr_100px] gap-3 items-center" x-show="row.total > 0">
                                <span class="badge justify-self-start" :class="riskBadgeClass(row.risk)" x-text="row.risk + ' · ' + row.total"></span>
                                <div class="flex h-8 rounded overflow-hidden">
                                    <template x-for="seg in row.splits" :key="seg.disposition">
                                        <div class="text-[11px] flex items-center justify-center font-semibold text-white"
                                             :style="`flex: ${seg.count}; background: hsl(var(${dispoCssVar(seg.disposition)})); color: ${dispoTextColor(seg.disposition)}`">
                                            <span x-text="seg.count + ' ' + dispoLabel(seg.disposition) + ' · ' + seg.pct + '%'"></span>
                                        </div>
                                    </template>
                                </div>
                                <span class="text-[11px] text-muted-foreground text-right" x-text="pathwaySummary(row)"></span>
                            </div>
                        </template>
                        <div class="separator-h my-3"></div>
                        <div class="grid grid-cols-4 gap-3 text-center">
                            <div><div class="eyebrow">Escalation rate</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5 text-critical" x-text="(kpis.escalation_pct ?? 0) + '%'"></div><div class="text-[10px] text-muted-foreground">Iso + Ref / Total</div></div>
                            <div><div class="eyebrow">Hospital referral</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5 text-info" x-text="(kpis.referred ?? 0)"></div><div class="text-[10px] text-muted-foreground">Of <span x-text="kpis.total_cases ?? 0"></span></div></div>
                            <div><div class="eyebrow">Held / delayed</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5 text-warning" x-text="(kpis.delayed ?? 0)"></div><div class="text-[10px] text-muted-foreground" x-text="dispositionPct('delayed') + '%'"></div></div>
                            <div><div class="eyebrow">Cleared on site</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5 text-success" x-text="(kpis.released ?? 0)"></div><div class="text-[10px] text-muted-foreground" x-text="dispositionPct('released') + '%'"></div></div>
                        </div>
                    </div>
                </article>

                {{-- Risk pyramid (inline SVG) --}}
                <article class="card col-span-12 lg:col-span-5">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Severity</div>
                            <h2 class="text-base font-semibold mt-0.5">Risk Pyramid</h2>
                        </div>
                        <button type="button" class="rpt-explain-btn" data-chart-key="risk_pyramid">?</button>
                    </div>
                    <div class="p-4 pt-2">
                        <svg viewBox="0 0 380 220" class="w-full h-[200px]">
                            <polygon points="30,200 350,200 320,160 60,160" fill="hsl(var(--low))" opacity="0.85"/>
                            <text x="190" y="184" text-anchor="middle" fill="white" font-weight="700" font-size="13" font-family="Inter">
                                <tspan x-text="(riskCount('LOW')) + ' LOW · ' + riskPct('LOW') + '%'"></tspan>
                            </text>
                            <polygon points="60,160 320,160 290,120 90,120" fill="hsl(var(--medium))"/>
                            <text x="190" y="144" text-anchor="middle" fill="hsl(var(--medium-foreground))" font-weight="700" font-size="13" font-family="Inter">
                                <tspan x-text="(riskCount('MEDIUM')) + ' MEDIUM · ' + riskPct('MEDIUM') + '%'"></tspan>
                            </text>
                            <polygon points="90,120 290,120 260,80 120,80" fill="hsl(var(--high))"/>
                            <text x="190" y="104" text-anchor="middle" fill="white" font-weight="700" font-size="12" font-family="Inter">
                                <tspan x-text="(riskCount('HIGH')) + ' HIGH · ' + riskPct('HIGH') + '%'"></tspan>
                            </text>
                            <polygon points="120,80 260,80 230,40 150,40" fill="hsl(var(--critical))"/>
                            <text x="190" y="64" text-anchor="middle" fill="white" font-weight="700" font-size="12" font-family="Inter">
                                <tspan x-text="(riskCount('CRITICAL')) + ' CRITICAL · ' + riskPct('CRITICAL') + '%'"></tspan>
                            </text>
                            <text x="190" y="20" text-anchor="middle" fill="hsl(var(--muted-foreground))" font-size="9" font-style="italic" font-family="Inter">Highest concern</text>
                        </svg>
                        <div class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-[11px]">
                            <div class="flex justify-between"><span class="text-muted-foreground">Critical+High</span><span class="font-mono font-semibold" x-text="(riskCount('CRITICAL') + riskCount('HIGH')) + ' · ' + (kpis.escalation_pct ?? 0) + '%'"></span></div>
                            <div class="flex justify-between"><span class="text-muted-foreground">Med+Low</span><span class="font-mono font-semibold" x-text="(riskCount('MEDIUM') + riskCount('LOW')) + ' · ' + (100 - (kpis.escalation_pct ?? 0)).toFixed(1) + '%'"></span></div>
                            <div class="flex justify-between"><span class="text-muted-foreground">Pyramid skew</span><span class="font-mono font-semibold" x-text="pyramidSkew()"></span></div>
                            <div class="flex justify-between"><span class="text-muted-foreground">Confirmed</span><span class="font-mono font-semibold" x-text="formatNum(kpis.confirmed)"></span></div>
                        </div>
                    </div>
                </article>
            </div>

            {{-- Disposition rates by risk-level (explaining table) --}}
            <article class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between">
                    <h2 class="text-base font-semibold">Disposition Rates by Risk Level</h2>
                    <button type="button" class="btn btn-outline btn-xs" @click="exportAs('CSV')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3"><path d="M12 3v12m0 0l-4-4m4 4l4-4"/><path d="M5 21h14"/></svg>
                        CSV
                    </button>
                </div>
                <div class="overflow-auto">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">Risk Level</th>
                                <th class="table-head-th text-right">Cases</th>
                                <th class="table-head-th text-right">Released</th>
                                <th class="table-head-th text-right">Referred</th>
                                <th class="table-head-th text-right">Isolated</th>
                                <th class="table-head-th text-right">Delayed</th>
                                <th class="table-head-th text-right">Escalation %</th>
                                <th class="table-head-th">Pattern</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-for="row in matrixRisks.map(r => disposeRowFor(r))" :key="row.risk">
                                <tr class="table-row">
                                    <td class="table-cell"><span class="badge" :class="riskBadgeClass(row.risk)" x-text="row.risk"></span></td>
                                    <td class="table-cell text-right font-semibold" x-text="row.total"></td>
                                    <td class="table-cell text-right" x-text="row.released_cell"></td>
                                    <td class="table-cell text-right" x-text="row.referred_cell"></td>
                                    <td class="table-cell text-right" x-text="row.isolated_cell"></td>
                                    <td class="table-cell text-right" x-text="row.delayed_cell"></td>
                                    <td class="table-cell text-right" :class="row.escalation_pct >= 50 ? 'text-critical font-semibold' : (row.escalation_pct === 0 ? 'text-success font-semibold' : '')" x-text="row.escalation_pct + '%'"></td>
                                    <td class="table-cell font-sans" x-text="row.pattern"></td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="bg-muted/30 border-t-2 font-bold">
                            <tr>
                                <td class="table-cell">Total</td>
                                <td class="table-cell text-right" x-text="kpis.total_cases ?? 0"></td>
                                <td class="table-cell text-right" x-text="kpis.released ?? 0"></td>
                                <td class="table-cell text-right" x-text="kpis.referred ?? 0"></td>
                                <td class="table-cell text-right" x-text="kpis.isolated ?? 0"></td>
                                <td class="table-cell text-right" x-text="kpis.delayed ?? 0"></td>
                                <td class="table-cell text-right" x-text="(kpis.escalation_pct ?? 0) + '%'"></td>
                                <td class="table-cell font-sans font-normal text-[11px] text-muted-foreground">Cohort baseline</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </article>
        </section>
    </template>

    {{-- ====================================================================
         SYNDROMES TAB
       ==================================================================== --}}
    <template x-if="ready && tab === 'syndromes'">
        <section class="space-y-4">
            {{-- Top conditions chart --}}
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Leaderboard</div>
                        <h2 class="text-base font-semibold mt-0.5">Top Suspected Conditions</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('topCondChart','top-conditions')" aria-label="Download PNG">PNG</button>
                        <button type="button" class="rpt-explain-btn" data-chart-key="top_conditions">?</button>
                    </div>
                </div>
                <div class="px-3 pb-3">
                    <div class="relative h-[260px]"><canvas x-ref="topCondChart" id="topCondChart"></canvas></div>
                </div>
            </article>

            {{-- Per-syndrome table --}}
            <article class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Top Suspected Conditions — Detail</h2>
                        <span class="badge badge-secondary"><span x-text="topConditions.length"></span> diseases</span>
                    </div>
                    <button type="button" class="btn btn-outline btn-xs" @click="exportAs('CSV')">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3"><path d="M12 3v12m0 0l-4-4m4 4l4-4"/><path d="M5 21h14"/></svg>
                        CSV
                    </button>
                </div>
                <div class="overflow-auto max-h-[55vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">Disease</th>
                                <th class="table-head-th text-right">Suspected</th>
                                <th class="table-head-th text-right">Confirmed</th>
                                <th class="table-head-th text-right">Pending</th>
                                <th class="table-head-th text-right">POEs</th>
                                <th class="table-head-th">Latest detected</th>
                                <th class="table-head-th">Confirmation rate</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-if="topConditions.length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="7">No suspected conditions in window.</td></tr>
                            </template>
                            <template x-for="r in topConditions" :key="r.disease_code">
                                <tr class="table-row">
                                    <td class="table-cell font-sans font-semibold" x-text="r.disease_code"></td>
                                    <td class="table-cell text-right font-semibold" x-text="formatNum(r.suspected)"></td>
                                    <td class="table-cell text-right text-critical" x-text="formatNum(r.confirmed)"></td>
                                    <td class="table-cell text-right text-warning" x-text="formatNum(r.pending)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(r.poes)"></td>
                                    <td class="table-cell font-sans" x-text="formatDate(r.latest)"></td>
                                    <td class="table-cell"><span class="badge" :class="confirmRateBadgeClass(r)" x-text="confirmRateLabel(r)"></span></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </article>

            {{-- Weekly trend --}}
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Trajectory</div>
                        <h2 class="text-base font-semibold mt-0.5">Weekly Trend — Top 5 Conditions</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('trendChart','weekly-trend')" aria-label="Download PNG">PNG</button>
                        <button type="button" class="rpt-explain-btn" data-chart-key="weekly_trend">?</button>
                    </div>
                </div>
                <div class="px-3 pb-3">
                    <div class="relative h-[280px]"><canvas x-ref="trendChart" id="trendChart"></canvas></div>
                </div>
            </article>

            {{-- Per-POE risk table --}}
            <article class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Per-POE Risk Profile</h2>
                        <span class="badge badge-secondary">Showing <span x-text="perPoeTable.length"></span> POE<span x-show="perPoeTable.length !== 1">s</span></span>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="rpt-explain-btn" data-chart-key="per_poe_risk">?</button>
                        <button type="button" class="btn btn-outline btn-xs" @click="exportAs('CSV')">CSV</button>
                    </div>
                </div>
                <div class="overflow-auto max-h-[50vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">POE</th>
                                <th class="table-head-th text-right">Screened</th>
                                <th class="table-head-th text-right">Critical</th>
                                <th class="table-head-th text-right">High</th>
                                <th class="table-head-th text-right">Medium</th>
                                <th class="table-head-th text-right">Low</th>
                                <th class="table-head-th text-right">Suspected</th>
                                <th class="table-head-th text-right">Escalation %</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-if="perPoeTable.length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="8">No POE rollup in window.</td></tr>
                            </template>
                            <template x-for="row in perPoeTable" :key="row.poe_code">
                                <tr class="table-row">
                                    <td class="table-cell font-sans font-semibold" x-text="row.poe_name"></td>
                                    <td class="table-cell text-right" x-text="formatNum(row.screened)"></td>
                                    <td class="table-cell text-right text-critical" x-text="formatNum(row.critical)"></td>
                                    <td class="table-cell text-right text-high" x-text="formatNum(row.high)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(row.medium)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(row.low)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(row.suspected)"></td>
                                    <td class="table-cell text-right">
                                        <span class="badge" :class="escalationBadgeClass(row.escalated_pct)" x-text="row.escalated_pct + '%'"></span>
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
         CASE REGISTER TAB
       ==================================================================== --}}
    <template x-if="ready && tab === 'cases'">
        <section>
            <article class="card overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/60 flex-wrap">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Suspected Case Register</h2>
                        <span class="badge badge-secondary">Showing <span x-text="filteredCases.length"></span> of <span x-text="caseRegister.length"></span></span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="case_register">?</button>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="relative">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                            <input class="input pl-7 w-56" type="search" placeholder="Search code, syndrome, POE…" x-model="caseQuery">
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

                {{-- Mini-stats strip --}}
                <div class="grid grid-cols-2 md:grid-cols-5 divide-x divide-border border-b border-border/60">
                    <div class="px-4 py-3"><div class="eyebrow">Total cases</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="formatNum(kpis.total_cases)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">Critical</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5 text-critical" x-text="formatNum(kpis.critical)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">Active (open)</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="formatNum(activeCount)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">Dispositioned</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="formatNum(dispositionedCount)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">Median time</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="durationLabel(kpis.median_disposition_seconds)"></div></div>
                </div>

                <div class="overflow-auto max-h-[60vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th table-head-th-sort" @click="sortCases('id')">Case ID</th>
                                <th class="table-head-th">Code</th>
                                <th class="table-head-th">Sex</th>
                                <th class="table-head-th">Syndrome</th>
                                <th class="table-head-th table-head-th-sort" @click="sortCases('risk')">Risk</th>
                                <th class="table-head-th">Disposition</th>
                                <th class="table-head-th">POE</th>
                                <th class="table-head-th">Opened</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortCases('time_seconds')">Time</th>
                                <th class="table-head-th">Sync</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-if="filteredCases.length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="10">No cases match the search.</td></tr>
                            </template>
                            <template x-for="row in pagedCases" :key="row.id">
                                <tr class="table-row">
                                    <td class="table-cell text-muted-foreground" x-text="'SC-' + String(row.id).padStart(4,'0')"></td>
                                    <td class="table-cell" x-text="row.code"></td>
                                    <td class="table-cell font-sans" x-text="(row.sex || '').slice(0,1) || '—'"></td>
                                    <td class="table-cell font-sans" x-text="row.syndrome || '—'"></td>
                                    <td class="table-cell">
                                        <template x-if="row.risk"><span class="badge" :class="riskBadgeClass(row.risk)" x-text="row.risk"></span></template>
                                        <template x-if="!row.risk"><span class="text-muted-foreground">—</span></template>
                                    </td>
                                    <td class="table-cell">
                                        <template x-if="row.disposition"><span class="badge" :class="dispoBadgeClass(row.disposition)" x-text="dispoLabel(row.disposition)"></span></template>
                                        <template x-if="!row.disposition"><span class="text-muted-foreground">—</span></template>
                                    </td>
                                    <td class="table-cell font-sans" x-text="row.poe_name || '—'"></td>
                                    <td class="table-cell" x-text="formatTime(row.opened_at)"></td>
                                    <td class="table-cell text-right" x-text="row.time_seconds === null ? '—' : durationLabel(row.time_seconds)"></td>
                                    <td class="table-cell"><span class="badge" :class="syncBadgeClass(row.sync_status)" x-text="row.sync_status"></span></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between px-4 py-2.5 border-t border-border/60">
                    <span class="text-[11px] text-muted-foreground">
                        Showing
                        <span x-text="(casesPage - 1) * casesPerPage + 1"></span>–<span x-text="Math.min(filteredCases.length, casesPage * casesPerPage)"></span>
                        of <span x-text="filteredCases.length"></span> · sorted by
                        <span x-text="caseSortKey"></span> <span x-text="caseSortDir"></span>
                    </span>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="btn btn-ghost btn-xs" :disabled="casesPage <= 1" @click="casesPage = Math.max(1, casesPage - 1)">‹ Prev</button>
                        <span class="text-[11px] text-muted-foreground">Page <span x-text="casesPage"></span> of <span x-text="Math.max(1, Math.ceil(filteredCases.length / casesPerPage))"></span></span>
                        <button type="button" class="btn btn-ghost btn-xs" :disabled="casesPage >= Math.ceil(filteredCases.length / casesPerPage)" @click="casesPage = Math.min(Math.ceil(filteredCases.length / casesPerPage), casesPage + 1)">Next ›</button>
                    </div>
                </div>
            </article>
        </section>
    </template>

    {{-- ====================================================================
         SYMPTOMS & ACTIONS TAB
       ==================================================================== --}}
    <template x-if="ready && tab === 'symptoms'">
        <section class="space-y-4">
            <div class="grid grid-cols-12 gap-4">

                {{-- Symptom prevalence --}}
                <article class="card col-span-12 lg:col-span-6">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Prevalence</div>
                            <h2 class="text-base font-semibold mt-0.5">Symptoms Recorded</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('symptomChart','symptom-prevalence')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="symptom_prevalence">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[300px]"><canvas x-ref="symptomChart" id="symptomChart"></canvas></div>
                        <p class="text-[11px] text-muted-foreground mt-2 italic" x-text="symptomCaption()"></p>
                    </div>
                </article>

                {{-- Action distribution --}}
                <article class="card col-span-12 lg:col-span-6">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Response</div>
                            <h2 class="text-base font-semibold mt-0.5">Actions Performed</h2>
                        </div>
                        <button type="button" class="rpt-explain-btn" data-chart-key="action_distribution">?</button>
                    </div>
                    <div class="px-4 pb-4">
                        <table class="w-full text-[12.5px]">
                            <thead class="border-b">
                                <tr>
                                    <th class="text-left py-2 text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold">Action</th>
                                    <th class="text-right py-2 text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold">Count</th>
                                    <th class="text-right py-2 text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold">% Cases</th>
                                </tr>
                            </thead>
                            <tbody class="font-mono tabular-nums">
                                <template x-if="actionDistribution.length === 0">
                                    <tr><td class="py-3 text-center text-muted-foreground" colspan="3">No actions logged in window.</td></tr>
                                </template>
                                <template x-for="(a, i) in actionDistribution" :key="a.code">
                                    <tr class="border-b">
                                        <td class="py-2.5 font-sans">
                                            <div class="flex items-center gap-2">
                                                <span class="h-2.5 w-2.5 rounded-sm" :style="`background: hsl(var(${actionCssVar(i)}))`"></span>
                                                <span class="font-semibold" x-text="a.code"></span>
                                            </div>
                                        </td>
                                        <td class="text-right py-2.5 font-bold" x-text="formatNum(a.count)"></td>
                                        <td class="text-right py-2.5" x-text="a.pct + '%'"></td>
                                    </tr>
                                </template>
                            </tbody>
                            <tfoot class="border-t-2 font-bold" x-show="actionDistribution.length > 0">
                                <tr><td class="py-2 font-sans">Total actions</td><td class="text-right py-2" x-text="actionTotal"></td><td class="text-right py-2"></td></tr>
                            </tfoot>
                        </table>
                    </div>
                </article>
            </div>

            {{-- Symptom prevalence explaining table --}}
            <article class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Symptom Prevalence — Detail</h2>
                        <span class="badge badge-secondary"><span x-text="symptomPrevalence.length"></span> symptoms</span>
                    </div>
                    <button type="button" class="btn btn-outline btn-xs" @click="exportAs('CSV')">CSV</button>
                </div>
                <div class="overflow-auto max-h-[45vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">Symptom code</th>
                                <th class="table-head-th text-right">Count</th>
                                <th class="table-head-th text-right">% of cohort</th>
                                <th class="table-head-th">Visual</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-if="symptomPrevalence.length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="4">No symptom records in window.</td></tr>
                            </template>
                            <template x-for="s in symptomPrevalence" :key="s.code">
                                <tr class="table-row">
                                    <td class="table-cell font-sans font-semibold" x-text="s.code"></td>
                                    <td class="table-cell text-right" x-text="formatNum(s.count)"></td>
                                    <td class="table-cell text-right" x-text="s.pct + '%'"></td>
                                    <td class="table-cell">
                                        <div class="h-2 rounded-full bg-muted overflow-hidden w-40">
                                            <div class="h-full bg-info" :style="`width: ${Math.min(100, s.pct)}%`"></div>
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
         INSIGHTS + DATA NOTES
       ==================================================================== --}}
    <template x-if="ready">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @include('admin.reports._insights')
            @include('admin.reports._data_notes')
        </div>
    </template>

    @include('admin.reports._filter_wizard')
    @include('admin.reports._chart_explainer', ['reportKey' => 'rpt-suspected'])

    {{-- =================================================================
         FOOTER
       ================================================================= --}}
    <footer class="text-[11px] text-muted-foreground border-t border-border/60 pt-3 mt-2 flex items-center justify-between flex-wrap gap-2">
        <span>Source:
            <span class="kbd">secondary_screenings</span> ·
            <span class="kbd">secondary_suspected_diseases</span> ·
            <span class="kbd">secondary_symptoms</span> ·
            <span class="kbd">secondary_actions</span> ·
            <span class="kbd">alerts</span> · Reference data <span class="kbd">rda-2026-02-01</span>
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
                <h3 class="text-base font-semibold">Suspected Cases</h3>
            </header>
            <div class="overflow-y-auto px-5 py-4 space-y-2.5 text-[13px] leading-relaxed">
                <p><strong>Purpose.</strong> Track travellers escalated from primary screening into secondary assessment — what they were screened for, how risky they were judged to be, and what was done about it.</p>
                <p><strong>Audience.</strong> National analysts, PHEOC duty officers, district focal persons, hospital liaisons.</p>
                <p><strong>Source.</strong> All figures derive from <code class="kbd">secondary_screenings</code> (the case record), <code class="kbd">secondary_suspected_diseases</code>, <code class="kbd">secondary_symptoms</code>, <code class="kbd">secondary_actions</code>, joined to <code class="kbd">alerts</code> for IHR confirmation status.</p>
                <p><strong>Risk classification.</strong> CRITICAL → always escalates (Isolation or Referral). HIGH → always escalates (Referral preferred). MEDIUM → Released or Delayed. LOW → always Released.</p>
                <p><strong>What it cannot tell you.</strong> Laboratory confirmation, contact-tracing follow-up, or eventual diagnosis. See Case Confirmation, Contact Tracing and Cases Registry for that.</p>
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
                    <div class="font-semibold text-[13px]">CSV — top conditions</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Per-disease counts.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportAs('XLSX')">
                    <div class="font-semibold text-[13px]">Excel summary</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Top conditions for spreadsheet tools.</p>
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
<style>
.hm-cell{ border-radius:6px; font-family:'JetBrains Mono',ui-monospace,monospace; font-size:11px; display:flex; align-items:center; justify-content:center; font-weight:600; min-height:32px; }
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

function rptSuspected() {
    return {
        ready: false,
        tab: 'overview',
        wizard: { open:false, step:1 },
        ask:    { open:false },
        tour:   { open:false, step:1, steps: [
            { t: 'The question this view answers',
              b: '<p>This page answers: <em>among the travellers escalated to secondary screening, who was at risk, what syndromes did they present, and what did we do about them?</em></p>' },
            { t: 'Step 1 — The KPI row',
              b: '<p>Six numbers, urgency-ordered. The Critical tile (highlighted) is your most-time-sensitive figure. The four disposition tiles tell the operational story. The avg-time tile is the throughput indicator.</p>' },
            { t: 'Step 2 — The Overview tab',
              b: '<p>Three donuts and a bar chart give you the situational picture. Below them is the Risk × Disposition matrix — your audit grid for whether decision rules are being followed.</p>' },
            { t: 'Step 3 — Risk &amp; Disposition',
              b: '<p>The Pathway diagram shows the decision rule visually: LOW always cleared, CRITICAL+HIGH always escalated, MEDIUM split. Beside it, the pyramid gives you severity at a glance.</p>' },
            { t: 'Step 4 — Investigation tools',
              b: '<p>The Case Register tab is your line list — searchable, sortable, exportable. The Syndromes tab gives you the cross-tab and per-syndrome breakdown. The Symptoms tab shows what was recorded and what was done.</p>' },
        ]},

        aboutOpen: false,
        defOpen: false,
        defRow: null,
        exportOpen: false,

        filters: { poe:'', sex:'', year:'', quarter:'', month:'', eoc:'', classification:'', outcome:'', start_date:'', end_date:'' },
        meta:    { poes:{}, districts:{}, provinces:{}, years:[], quarters:{}, months:{}, genders:{} },

        kpis: {},
        riskDistribution: [],
        dispositionDistribution: [],
        syndromeDistribution: [],
        riskMatrix: {},
        riskMatrixKeys: { risks: ['CRITICAL','HIGH','MEDIUM','LOW'], dispositions: ['RELEASED','REFERRED','ISOLATED','QUARANTINED','TRANSFERRED','DELAYED','DENIED_BOARDING','OTHER'] },
        riskPathway: [],
        sexRisk: {},
        hourOfDay: [],
        symptomPrevalence: [],
        actionDistribution: [],
        perPoeTable: [],
        perProvinceTable: [],
        caseRegister: [],
        topConditions: [],
        trend: { weeks: [], week_labels: [], series: {} },
        outcomes: [],
        insights: [],
        dataNotes: {},
        window: { from:'', to:'', generated:'', latest:'' },

        // Case register state
        caseQuery: '',
        caseSortKey: 'id',
        caseSortDir: 'desc',
        casesPage: 1,
        casesPerPage: 25,

        charts: {},

        askOptions: [
            { code:'CRITICAL',  label:'Review all CRITICAL cases',         help:'Filter the case register to risk = CRITICAL — the most time-sensitive cohort.', tag:'Urgent',     badge:'badge-critical' },
            { code:'CLUSTER',   label:'Trace a syndrome cluster',          help:'Open the Syndromes tab; check the per-disease table for clustering.', tag:'Investigate',badge:'badge-warning' },
            { code:'REFERRALS', label:'Check referral acknowledgements',   help:'Open Alert Acknowledgement to verify hospital arrival.', tag:'Follow-up',  badge:'badge-info' },
            { code:'EXPORT',    label:'Export this view',                  help:'PDF or CSV with scope, period and timestamp.', tag:'Share',      badge:'badge-success' },
            { code:'BACKLOG',   label:'Find the dispositioned backlog',    help:'Use the Case Register to find OPEN / IN_PROGRESS rows.', tag:'Inspect',    badge:'badge-secondary' },
            { code:'BY_POE',    label:'Compare risk profiles by POE',      help:'Open Syndromes tab — Per-POE Risk Profile table.', tag:'Compare',    badge:'badge-secondary' },
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
        resetFilters() {
            this.filters = { poe:'', sex:'', year:'', quarter:'', month:'', eoc:'', classification:'', outcome:'', start_date:'', end_date:'' };
            window.history.replaceState(null, '', window.location.pathname);
        },

        async runReport() {
            this.writeFiltersToUrl();
            try {
                const r = await rptJson(@json(url('/admin/reports/rpt-suspected/data')), this.buildParams());
                const d = r?.data || {};
                this.window                  = d.window || {};
                this.kpis                    = d.kpis || {};
                this.riskDistribution        = d.risk_distribution || [];
                this.dispositionDistribution = d.disposition_distribution || [];
                this.syndromeDistribution    = d.syndrome_distribution || [];
                this.riskMatrix              = d.risk_x_disposition || {};
                this.riskMatrixKeys          = d.risk_x_disposition_keys || this.riskMatrixKeys;
                this.riskPathway             = d.risk_pathway || [];
                this.sexRisk                 = d.sex_risk || {};
                this.hourOfDay               = d.hour_of_day || [];
                this.symptomPrevalence       = d.symptom_prevalence || [];
                this.actionDistribution      = d.action_distribution || [];
                this.perPoeTable             = d.per_poe_table || [];
                this.perProvinceTable        = d.per_province_table || [];
                this.caseRegister            = d.case_register || [];
                this.topConditions           = d.top_conditions || [];
                this.trend                   = d.trend || { weeks: [], week_labels: [], series: {} };
                this.outcomes                = d.outcomes || [];
                this.insights                = d.insights || [];
                this.dataNotes               = d.data_notes || {};
                this.casesPage               = 1;
                this.ready                   = true;
                this.$nextTick(() => this.renderCharts());
            } catch (e) {
                console.error(e);
                this.ready = false;
            }
        },

        buildParams() { const p = {}; for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) p[k] = v; return p; },

        exportAs(fmt) {
            const u = new URL(@json(url('/admin/reports/rpt-suspected/export')), window.location.origin);
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
        formatTime(d) {
            if (!d) return '—';
            try {
                const dt = new Date(d);
                if (isNaN(dt.getTime())) return d;
                return dt.toISOString().slice(11, 16);
            } catch (e) { return d; }
        },
        windowLabel() { return this.window.from ? (this.formatDate(this.window.from) + ' → ' + this.formatDate(this.window.to)) : 'No window'; },
        durationLabel(secs) {
            if (secs == null) return '—';
            const s = Number(secs);
            if (s < 60) return `${s}s`;
            const m = Math.floor(s / 60);
            if (m < 60) return `${m}m ${s % 60}s`;
            const h = Math.floor(m / 60);
            return `${h}h ${m % 60}m`;
        },

        // ───────── KPI helpers ─────────
        dispositionPct(field) {
            const total = Number(this.kpis.total_cases || 0);
            const v = Number(this.kpis[field] || 0);
            return total > 0 ? Number((v / total * 100).toFixed(1)) : 0;
        },

        // ───────── Risk / disposition / sync helpers ─────────
        riskCssVar(level) {
            return ({ CRITICAL:'--critical', HIGH:'--high', MEDIUM:'--medium', LOW:'--low' })[level] || '--muted-foreground';
        },
        riskBadgeClass(level) {
            return ({ CRITICAL:'badge-critical', HIGH:'badge-high', MEDIUM:'badge-medium', LOW:'badge-low' })[level] || 'badge-secondary';
        },
        riskCount(level) {
            const r = (this.riskDistribution || []).find(x => x.level === level);
            return r ? r.count : 0;
        },
        riskPct(level) {
            const r = (this.riskDistribution || []).find(x => x.level === level);
            return r ? r.pct : 0;
        },
        dispoCssVar(disposition) {
            return ({
                RELEASED:'--success', REFERRED:'--info', ISOLATED:'--critical',
                DELAYED:'--warning', QUARANTINED:'--high', TRANSFERRED:'--viz-4',
                DENIED_BOARDING:'--viz-5', OTHER:'--muted-foreground',
            })[disposition] || '--muted-foreground';
        },
        dispoTextColor(disposition) {
            return disposition === 'DELAYED' ? 'hsl(var(--warning-foreground))' : 'white';
        },
        dispoLabel(disposition) {
            const map = {
                RELEASED:'Released', REFERRED:'Referred', ISOLATED:'Isolated',
                DELAYED:'Delayed', QUARANTINED:'Quarantined', TRANSFERRED:'Transferred',
                DENIED_BOARDING:'Denied', OTHER:'Other',
            };
            return map[disposition] || disposition;
        },
        dispoBadgeClass(disposition) {
            return ({
                RELEASED:'badge-success', REFERRED:'badge-info', ISOLATED:'badge-critical',
                DELAYED:'badge-warning', QUARANTINED:'badge-high', TRANSFERRED:'badge-default',
                DENIED_BOARDING:'badge-secondary', OTHER:'badge-secondary',
            })[disposition] || 'badge-secondary';
        },
        syncBadgeClass(s) {
            return ({ SYNCED:'badge-success', UNSYNCED:'badge-warning', FAILED:'badge-critical' })[s] || 'badge-secondary';
        },
        actionCssVar(idx) {
            const slot = (idx % 8) + 1;
            return `--viz-${slot}`;
        },
        actionWidth(count) {
            const max = Math.max(1, ...((this.actionDistribution || []).map(a => a.count)));
            return Math.round((count / max) * 100);
        },

        // ───────── Risk × Disposition matrix helpers ─────────
        get matrixRisks() { return this.riskMatrixKeys?.risks || ['CRITICAL','HIGH','MEDIUM','LOW']; },
        get visibleDispositions() {
            const rows = this.riskMatrix || {};
            const cols = this.riskMatrixKeys?.dispositions || [];
            return cols.filter(d => this.matrixRisks.some(r => (rows[r] || {})[d] > 0));
        },
        matrixCells(risk) {
            const out = [{ col: '__label', value: 0 }];
            const row = (this.riskMatrix || {})[risk] || {};
            const max = Math.max(1, ...this.matrixRisks.flatMap(r => Object.values((this.riskMatrix || {})[r] || {})));
            for (const d of this.visibleDispositions) {
                const v = row[d] || 0;
                const intensity = v === 0 ? 0 : 0.2 + 0.6 * (v / max);
                out.push({
                    col: d, value: v,
                    bgClass: v === 0 ? 'bg-muted/40 text-muted-foreground' : '',
                    bgStyle: v === 0 ? '' : `background: hsl(var(${this.dispoCssVar(d)}) / ${intensity}); color: ${this.dispoTextColor(d)};`,
                });
            }
            const total = this.matrixRisks.includes(risk) ? Object.values(row).reduce((a, b) => a + b, 0) : 0;
            out.push({ col: '__total', value: total, bgClass: '', bgStyle: '' });
            return out;
        },
        dispositionColTotal(d) {
            return this.matrixRisks.reduce((s, r) => s + (((this.riskMatrix || {})[r] || {})[d] || 0), 0);
        },

        // ───────── Risk pathway ─────────
        pathwaySummary(row) {
            const escalated = (row.splits || []).filter(s => ['REFERRED','ISOLATED','QUARANTINED','TRANSFERRED'].includes(s.disposition))
                .reduce((a, b) => a + b.count, 0);
            if (row.total === 0) return '—';
            const pct = Math.round(escalated / row.total * 100);
            if (pct === 100) return '100% escalated';
            if (pct === 0)   return '100% cleared';
            return `${pct}% escalated`;
        },
        disposeRowFor(risk) {
            const row = (this.riskMatrix || {})[risk] || {};
            const total = this.matrixRisks.includes(risk) ? Object.values(row).reduce((a, b) => a + b, 0) : 0;
            const cell = (k) => {
                const v = row[k] || 0;
                if (v === 0) return '0';
                const pct = total > 0 ? Math.round(v / total * 100) : 0;
                return `${v} (${pct}%)`;
            };
            const escalated = (row.REFERRED || 0) + (row.ISOLATED || 0) + (row.QUARANTINED || 0) + (row.TRANSFERRED || 0);
            const escalation_pct = total > 0 ? Math.round(escalated / total * 100) : 0;
            const released = row.RELEASED || 0;
            const delayed  = row.DELAYED || 0;
            let pattern = 'Mixed';
            if (risk === 'LOW' && total > 0 && released === total) pattern = 'All cleared on site';
            else if ((risk === 'CRITICAL' || risk === 'HIGH') && total > 0 && escalated === total) pattern = 'All escalated';
            else if (risk === 'MEDIUM' && total > 0 && (released + delayed) === total) pattern = 'Released or held';
            return {
                risk,
                total,
                released_cell: cell('RELEASED'),
                referred_cell: cell('REFERRED'),
                isolated_cell: cell('ISOLATED'),
                delayed_cell:  cell('DELAYED'),
                escalation_pct,
                pattern,
            };
        },

        // ───────── Pyramid / synd helpers ─────────
        pyramidSkew() {
            const c = this.riskCount('CRITICAL') + this.riskCount('HIGH');
            const m = this.riskCount('MEDIUM') + this.riskCount('LOW');
            if (c === 0 && m === 0) return '—';
            if (c > m) return 'Top-heavy';
            if (m > c * 2) return 'Wide base';
            return 'Balanced';
        },
        get syndromeOptions() {
            return (this.syndromeDistribution || []).map(s => s.code);
        },
        syndromeCaption() {
            if (!this.syndromeDistribution.length) return 'No syndromes recorded for this cohort.';
            const top = this.syndromeDistribution[0];
            return `${top.code} leads with ${top.count} cases (${top.pct}%). Spread across ${this.syndromeDistribution.length} syndrome${this.syndromeDistribution.length === 1 ? '' : 's'}.`;
        },
        sexRiskCaption() {
            const sex = this.sexRisk || {};
            const totals = {};
            for (const g of ['MALE','FEMALE']) {
                const r = sex[g] || {};
                totals[g] = (r.CRITICAL||0) + (r.HIGH||0) + (r.MEDIUM||0) + (r.LOW||0);
            }
            if (!totals.MALE && !totals.FEMALE) return 'Insufficient sex-stratified data.';
            const critPct = (g) => totals[g] > 0 ? ((sex[g]?.CRITICAL || 0) / totals[g] * 100).toFixed(1) : '0.0';
            return `Critical share — Male ${critPct('MALE')}% vs Female ${critPct('FEMALE')}%.`;
        },
        hourCaption() {
            const max = Math.max(...(this.hourOfDay || []).map(h => h.count), 0);
            if (max === 0) return 'No hourly tempo recorded.';
            const peak = (this.hourOfDay || []).find(h => h.count === max);
            return `Peak at ${String(peak?.hour || 0).padStart(2, '0')}:00 with ${max} cases.`;
        },
        symptomCaption() {
            if (!this.symptomPrevalence.length) return 'No symptom records in window.';
            const top = this.symptomPrevalence[0];
            return `${top.code} leads with ${top.count} cases (${top.pct}% of cohort).`;
        },

        // ───────── Top conditions table helpers ─────────
        confirmRateLabel(r) {
            const s = Number(r.suspected || 0);
            if (s === 0) return '0%';
            const pct = Math.round((Number(r.confirmed || 0) / s) * 100);
            return `${pct}% (${r.confirmed}/${r.suspected})`;
        },
        confirmRateBadgeClass(r) {
            const s = Number(r.suspected || 0);
            if (s === 0) return 'badge-secondary';
            const pct = (Number(r.confirmed || 0) / s) * 100;
            if (pct >= 50) return 'badge-success';
            if (pct >= 10) return 'badge-info';
            if (pct === 0 && s >= 5) return 'badge-warning';
            return 'badge-secondary';
        },
        escalationBadgeClass(pct) {
            if (pct >= 50) return 'badge-critical';
            if (pct >= 25) return 'badge-warning';
            if (pct >= 10) return 'badge-medium';
            return 'badge-low';
        },

        // ───────── Action / counts ─────────
        get actionTotal() {
            return (this.actionDistribution || []).reduce((a, b) => a + (b.count || 0), 0);
        },
        get activeCount() {
            return (this.caseRegister || []).filter(c => ['OPEN','IN_PROGRESS'].includes(c.case_status)).length;
        },
        get dispositionedCount() {
            return (this.caseRegister || []).filter(c => !['OPEN','IN_PROGRESS'].includes(c.case_status)).length;
        },

        // ───────── Case register filter / sort / paging ─────────
        get filteredCases() {
            const q = (this.caseQuery || '').toLowerCase().trim();
            const rows = q
                ? (this.caseRegister || []).filter(r =>
                    (r.code || '').toLowerCase().includes(q)
                    || (r.syndrome || '').toLowerCase().includes(q)
                    || (r.poe_name || '').toLowerCase().includes(q)
                    || (r.risk || '').toLowerCase().includes(q)
                    || (r.disposition || '').toLowerCase().includes(q))
                : (this.caseRegister || []).slice();

            const key = this.caseSortKey;
            const dir = this.caseSortDir === 'asc' ? 1 : -1;
            const riskRank = { CRITICAL:4, HIGH:3, MEDIUM:2, LOW:1, null:0 };
            rows.sort((a, b) => {
                let av, bv;
                if (key === 'risk') { av = riskRank[a.risk] || 0; bv = riskRank[b.risk] || 0; }
                else if (key === 'time_seconds') { av = a.time_seconds == null ? -1 : a.time_seconds; bv = b.time_seconds == null ? -1 : b.time_seconds; }
                else { av = a[key]; bv = b[key]; }
                if (av < bv) return -1 * dir;
                if (av > bv) return  1 * dir;
                return 0;
            });
            return rows;
        },
        get pagedCases() {
            const start = (this.casesPage - 1) * this.casesPerPage;
            return this.filteredCases.slice(start, start + this.casesPerPage);
        },
        sortCases(key) {
            if (this.caseSortKey === key) {
                this.caseSortDir = this.caseSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                this.caseSortKey = key;
                this.caseSortDir = 'desc';
            }
            this.casesPage = 1;
        },

        // ───────── Modals: definitions ─────────
        openDef(key) {
            const total = this.kpis.total_cases ?? 0;
            const defs = {
                critical: {
                    title: 'Critical cases',
                    body:  '<p>Cases assessed at the highest risk tier — emergency signs present or syndrome scoring above the threshold. Always escalated.</p>',
                    src:   `risk_level = CRITICAL · ${this.kpis.critical || 0} of ${total} = ${(this.kpis.critical_pct || 0).toFixed(1)}%.`,
                },
                total: {
                    title: 'Total cases',
                    body:  '<p>Every secondary screening record opened during the period — these are travellers who progressed beyond primary screening.</p>',
                    src:   `COUNT(*) FROM secondary_screenings WHERE deleted_at IS NULL · ${total} records.`,
                },
                isolated: {
                    title: 'Isolated',
                    body:  '<p>Travellers placed in isolation pending further evaluation. Used for CRITICAL and some HIGH cases.</p>',
                    src:   `final_disposition = ISOLATED · ${this.kpis.isolated || 0} of ${total}.`,
                },
                referred: {
                    title: 'Referred to hospital',
                    body:  '<p>Travellers referred to a designated treatment facility for clinical management.</p>',
                    src:   `final_disposition = REFERRED · ${this.kpis.referred || 0} of ${total}.`,
                },
                released: {
                    title: 'Released',
                    body:  '<p>Travellers cleared on site with safety guidelines. Used for LOW and stable MEDIUM cases.</p>',
                    src:   `final_disposition = RELEASED · ${this.kpis.released || 0} of ${total}.`,
                },
                time: {
                    title: 'Average time to disposition',
                    body:  '<p>Mean elapsed time between secondary screening opening and final disposition.</p>',
                    src:   `AVG(dispositioned_at − opened_at) · ${this.durationLabel(this.kpis.avg_disposition_seconds)}.`,
                },
            };
            this.defRow = defs[key] || null;
            this.defOpen = !!this.defRow;
        },

        runAsk(code) {
            switch (code) {
                case 'CRITICAL':  this.tab = 'cases'; this.caseQuery = 'critical'; break;
                case 'CLUSTER':   this.tab = 'syndromes'; break;
                case 'REFERRALS': window.location.href = @json(url('/admin/reports/rpt-alert-acknowledgement')); break;
                case 'EXPORT':    this.exportOpen = true; break;
                case 'BACKLOG':   this.tab = 'cases'; this.caseSortKey = 'time_seconds'; this.caseSortDir = 'desc'; break;
                case 'BY_POE':    this.tab = 'syndromes'; break;
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
            const filename = `rpt-suspected-${slug}-${stamp}.png`;
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
            g.fillText(`Suspected Cases · ${slug} · ${lbl} · ${win} · generated ${stamp}`, 8, c.height + 18);
            out.toBlob(blob => this.downloadBlob(blob, filename), 'image/png');
        },
        downloadAllPng() {
            ['riskDonutChart','dispoDonutChart','syndromeBarChart','sexRiskChart','hourChart','topCondChart','trendChart','symptomChart'].forEach(id => {
                const slug = id.replace(/Chart$/, '').replace(/[A-Z]/g, m => '-' + m.toLowerCase()).replace(/^-/, '');
                this.downloadChartPng(id, slug);
            });
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
                if (this.tab === 'overview') {
                    this.renderRiskDonut();
                    this.renderDispoDonut();
                    this.renderSyndromeBars();
                    this.renderSexRisk();
                    this.renderHourly();
                } else if (this.tab === 'syndromes') {
                    this.renderTopConditions();
                    this.renderTrend();
                } else if (this.tab === 'symptoms') {
                    this.renderSymptoms();
                }
            });
        },

        renderRiskDonut() {
            const ref = this.$refs.riskDonutChart;
            if (!ref) return;
            const labels = (this.riskDistribution || []).map(r => r.level);
            const data   = (this.riskDistribution || []).map(r => r.count);
            const colors = labels.map(l => tokenColor(this.riskCssVar(l)));
            this.charts.riskDonut = new Chart(ref, {
                type: 'doughnut',
                data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
                options: {
                    cutout: '64%',
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.label}: ${c.parsed.toLocaleString()}` } } },
                },
            });
        },

        renderDispoDonut() {
            const ref = this.$refs.dispoDonutChart;
            if (!ref) return;
            const labels = (this.dispositionDistribution || []).map(d => this.dispoLabel(d.disposition));
            const data   = (this.dispositionDistribution || []).map(d => d.count);
            const colors = (this.dispositionDistribution || []).map(d => tokenColor(this.dispoCssVar(d.disposition)));
            this.charts.dispoDonut = new Chart(ref, {
                type: 'doughnut',
                data: { labels, datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }] },
                options: {
                    cutout: '64%',
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.label}: ${c.parsed.toLocaleString()}` } } },
                },
            });
        },

        renderSyndromeBars() {
            const ref = this.$refs.syndromeBarChart;
            if (!ref) return;
            const rows = (this.syndromeDistribution || []).slice(0, 8);
            const labels = rows.map(s => s.code);
            const data   = rows.map(s => s.count);
            const colors = rows.map((_, i) => tokenColor(`--viz-${(i % 8) + 1}`));
            this.charts.syndromeBar = new Chart(ref, {
                type: 'bar',
                data: { labels, datasets: [{ data, backgroundColor: colors, borderRadius: 3 }] },
                options: {
                    indexAxis: 'y',
                    plugins: { legend: { display: false } },
                    scales: {
                        x: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground') }, grid: { color: tokenColor('--border'), drawBorder: false } },
                        y: { ticks: { color: tokenColor('--foreground'), font: { weight: '600' } }, grid: { display: false } },
                    },
                },
            });
        },

        renderSexRisk() {
            const ref = this.$refs.sexRiskChart;
            if (!ref) return;
            const sex = this.sexRisk || {};
            const labels = ['Female', 'Male', 'Other / Unknown'];
            const female = sex.FEMALE || {};
            const male   = sex.MALE   || {};
            const other  = sex.OTHER  || {};
            const datasetFor = (level, color) => ({
                label: level,
                data: [female[level] || 0, male[level] || 0, other[level] || 0],
                backgroundColor: color,
                stack: 'g',
                borderRadius: 3,
            });
            this.charts.sexRisk = new Chart(ref, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        datasetFor('CRITICAL', tokenColor('--critical')),
                        datasetFor('HIGH',     tokenColor('--high')),
                        datasetFor('MEDIUM',   tokenColor('--medium')),
                        datasetFor('LOW',      tokenColor('--low')),
                    ],
                },
                options: {
                    indexAxis: 'y',
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, color: tokenColor('--muted-foreground') } } },
                    scales: {
                        x: { stacked: true, beginAtZero: true, ticks: { color: tokenColor('--muted-foreground') }, grid: { color: tokenColor('--border'), drawBorder: false } },
                        y: { stacked: true, ticks: { color: tokenColor('--foreground'), font: { weight: '600' } }, grid: { display: false } },
                    },
                },
            });
        },

        renderHourly() {
            const ref = this.$refs.hourChart;
            if (!ref) return;
            const rows = this.hourOfDay || [];
            const labels = rows.map(h => String(h.hour).padStart(2, '0') + ':00');
            const data   = rows.map(h => h.count);
            const max = Math.max(1, ...data);
            const colors = data.map(v => v >= max ? tokenColor('--critical') : tokenColor('--viz-1'));
            this.charts.hour = new Chart(ref, {
                type: 'bar',
                data: { labels, datasets: [{ data, backgroundColor: colors, borderRadius: 2 }] },
                options: {
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.parsed.y} cases` } } },
                    scales: {
                        x: { ticks: { color: tokenColor('--muted-foreground'), maxRotation: 0, autoSkip: true, autoSkipPadding: 8 }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground') }, grid: { color: tokenColor('--border'), drawBorder: false } },
                    },
                },
            });
        },

        renderTopConditions() {
            const ref = this.$refs.topCondChart;
            if (!ref) return;
            const rows = (this.topConditions || []).slice(0, 10);
            const labels = rows.map(r => r.disease_code);
            this.charts.topCond = new Chart(ref, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'Suspected', data: rows.map(r => r.suspected),                    backgroundColor: tokenColor('--viz-1'), borderRadius: 3, stack: 's' },
                        { label: 'Confirmed', data: rows.map(r => r.confirmed),                    backgroundColor: tokenColor('--critical'), borderRadius: 3, stack: 'c' },
                        { label: 'Pending',   data: rows.map(r => r.pending),                      backgroundColor: tokenColor('--warning'),  borderRadius: 3, stack: 'p' },
                    ],
                },
                options: {
                    indexAxis: 'y',
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, color: tokenColor('--muted-foreground') } } },
                    scales: {
                        x: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground') }, grid: { color: tokenColor('--border'), drawBorder: false } },
                        y: { ticks: { color: tokenColor('--foreground'), font: { weight: '600' } }, grid: { display: false } },
                    },
                },
            });
        },

        renderTrend() {
            const ref = this.$refs.trendChart;
            if (!ref) return;
            const labels = (this.trend.week_labels || this.trend.weeks || []).map(d => this.formatDate(d));
            const palette = ['--critical','--warning','--viz-2','--viz-4','--info'];
            const datasets = Object.entries(this.trend.series || {}).map(([k, vals], i) => ({
                label: k,
                data: Object.values(vals),
                borderColor: tokenColor(palette[i % palette.length]),
                backgroundColor: tokenColor(palette[i % palette.length]) + '22',
                tension: 0.3,
                fill: false,
                pointRadius: 3,
            }));
            this.charts.trend = new Chart(ref, {
                type: 'line',
                data: { labels, datasets },
                options: {
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, color: tokenColor('--muted-foreground') } } },
                    interaction: { mode: 'nearest', axis: 'x', intersect: false },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tokenColor('--muted-foreground') } },
                        y: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground') }, grid: { color: tokenColor('--border'), drawBorder: false } },
                    },
                },
            });
        },

        renderSymptoms() {
            const ref = this.$refs.symptomChart;
            if (!ref) return;
            const rows = (this.symptomPrevalence || []).slice(0, 12);
            const labels = rows.map(s => s.code);
            const data   = rows.map(s => s.count);
            const colors = rows.map((_, i) => tokenColor(`--viz-${(i % 8) + 1}`));
            this.charts.symptom = new Chart(ref, {
                type: 'bar',
                data: { labels, datasets: [{ data, backgroundColor: colors, borderRadius: 3 }] },
                options: {
                    indexAxis: 'y',
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.parsed.x} cases` } } },
                    scales: {
                        x: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground') }, grid: { color: tokenColor('--border'), drawBorder: false } },
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
