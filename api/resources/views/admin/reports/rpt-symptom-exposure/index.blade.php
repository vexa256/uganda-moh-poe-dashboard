@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Symptom & Exposure')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
{{--
    R7 · Symptom & Exposure — REBUILT 2026-04-26 to mirror rpt-volume's
    premium chassis. Question: "What symptoms and exposures are travellers
    presenting with, where, and is anything firing above its own baseline?"

    Composition:
      · Compact title bar (eyebrow · h1 · about ⓘ · walk-through · do · export)
      · Filters card (Apply / Reset)
      · 6 KPI tiles — one .kpi-glow on the most operationally urgent number
        (Tripwires fired if any; otherwise High-risk cases)
      · Tabs: Overview · Co-occurrence · Outbreak Detection · Exposures · Case Register
      · Charts use Chart.js with theme tokens via CSS-variable resolution.
      · Each chart card has a "?" explainer + a "PNG" download button.
      · Insights + data notes via shared partials.

    Theme primitives only. No dark mode. No animations beyond animate-spin.
--}}
<div x-data="rptSymptomExposure()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('rpt-symptom-exposure', wizard.open || ask.open || tour.open || aboutOpen)"
     class="space-y-4">

    {{-- ======================================================================
         HEADER
       ====================================================================== --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Operations · rpt-symptom-exposure</p>
            <h1 class="text-[18px] font-semibold flex items-center gap-2">
                Symptom &amp; Exposure
                <button type="button" class="rpt-explain-btn" @click="aboutOpen = true" aria-label="About this report" title="About this report">i</button>
            </h1>
            <p class="help-text mt-0.5">What we observed — symptoms, exposures, and the early-outbreak signals that fired above their own baseline.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="windowLabel()"></span>
            </span>
            <span class="topbar-chip topbar-chip-mono" x-show="ready && (kpis.tripwire_count ?? 0) > 0">
                <span class="status-dot status-dot-warn"></span>
                <span x-text="(kpis.tripwire_count ?? 0) + ' tripwire' + ((kpis.tripwire_count ?? 0) === 1 ? '' : 's') + ' fired'"></span>
            </span>
            @include('admin.reports._coach', ['reportKey' => 'rpt-symptom-exposure'])
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
                <button type="button" class="rpt-explain-btn" data-chart-key="top_symptoms" aria-label="What this report shows">?</button>
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
                <label class="label block mb-1">Syndrome</label>
                <select class="select" x-model="filters.classification">
                    <option value="">All</option>
                    <template x-for="(c, k) in classMix" :key="k">
                        <option :value="k" x-text="k"></option>
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
            <div class="kpi" :class="(kpis.tripwire_count ?? 0) > 0 ? 'kpi-glow' : ''">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Tripwires</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('tripwires')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" :class="(kpis.tripwire_count ?? 0) > 0 ? 'text-warning' : ''" x-text="formatNum(kpis.tripwire_count)"></div>
                <div class="text-[10.5px] text-muted-foreground" x-text="(kpis.tripwire_count ?? 0) > 0 ? 'Symptoms above 1.5× baseline' : 'No symptom spikes detected'"></div>
            </div>
            <div class="kpi" :class="(kpis.tripwire_count ?? 0) === 0 ? 'kpi-glow' : ''">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">High-risk cases</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('high_risk')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value text-critical" x-text="formatNum(kpis.high_risk)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="(kpis.high_risk_pct ?? 0) + '% of cohort'"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Secondary cases</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('secondary')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.secondary)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="(kpis.reporting_poes ?? 0) + ' POE' + ((kpis.reporting_poes ?? 0) === 1 ? '' : 's') + ' · ' + windowLabel()"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Distinct symptoms</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('distinct_symptoms')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.distinct_symptoms)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="kpis.top_symptom ? (kpis.top_symptom + ' · ' + (kpis.top_symptom_pct ?? 0) + '%') : '—'"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">≥3 symptoms</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('multi_symptom')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.multi_symptom)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="(kpis.multi_symptom_pct ?? 0) + '% of cohort'"></span>
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">YES exposures</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('exposure_yes')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.exposure_yes_cases)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="(kpis.distinct_exposures ?? 0) + ' distinct codes'"></span>
                </div>
            </div>
        </section>
    </template>

    {{-- ======================================================================
         TABS
       ====================================================================== --}}
    <template x-if="ready">
        <section>
            <div class="tabs-list" role="tablist" aria-label="Symptom and exposure views">
                <button class="tabs-trigger" role="tab" :data-state="tab === 'overview' ? 'active' : null" @click="tab = 'overview'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    Overview
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'cooccur' ? 'active' : null" @click="tab = 'cooccur'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><circle cx="9" cy="9" r="5"/><circle cx="15" cy="15" r="5"/></svg>
                    Co-occurrence
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'outbreak' ? 'active' : null" @click="tab = 'outbreak'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><path d="M12 9v4M12 17h.01M10.3 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                    Outbreak Detection
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'exposures' ? 'active' : null" @click="tab = 'exposures'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 010 18M12 3a14 14 0 000 18"/></svg>
                    Exposures
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'cases' ? 'active' : null" @click="tab = 'cases'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><path d="M4 6h16v3H4z"/><path d="M4 12h16v3H4z"/><path d="M4 18h10v3H4z"/></svg>
                    High-risk Cases
                </button>
            </div>
        </section>
    </template>

    {{-- ====================================================================
         OVERVIEW TAB
       ==================================================================== --}}
    <template x-if="ready && tab === 'overview'">
        <section class="space-y-4">
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Top reports · last period</div>
                        <h2 class="text-base font-semibold mt-0.5">Top-15 Symptoms</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="badge badge-warning" x-show="(kpis.tripwire_count ?? 0) > 0">
                            <span class="h-1.5 w-1.5 rounded-full bg-warning"></span>
                            <span x-text="(kpis.tripwire_count ?? 0) + ' trending'"></span>
                        </span>
                        <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('topSymptomsChart','top-symptoms')" aria-label="Download PNG">PNG</button>
                        <button type="button" class="rpt-explain-btn" data-chart-key="top_symptoms">?</button>
                    </div>
                </div>
                <div class="px-3 pb-3">
                    <div class="relative h-[340px]"><canvas x-ref="topSymptomsChart" id="topSymptomsChart"></canvas></div>
                    <p class="text-[11px] text-muted-foreground mt-2 italic" x-text="topSymptomsCaption()"></p>
                </div>
            </article>

            {{-- Top symptoms detail table --}}
            <article class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Top Symptoms — Detail</h2>
                        <span class="badge badge-secondary"><span x-text="topSymptoms.length"></span> rows</span>
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
                                <th class="table-head-th">Symptom</th>
                                <th class="table-head-th text-right">Reports</th>
                                <th class="table-head-th text-right">% of cohort</th>
                                <th class="table-head-th text-right">≥3-symp co-occur</th>
                                <th class="table-head-th text-right">Last-7d</th>
                                <th class="table-head-th text-right">Spike ratio</th>
                                <th class="table-head-th">Status</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-if="topSymptoms.length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="7">No symptoms recorded in window.</td></tr>
                            </template>
                            <template x-for="r in topSymptoms" :key="r.symptom">
                                <tr class="table-row">
                                    <td class="table-cell font-sans font-semibold" x-text="r.symptom"></td>
                                    <td class="table-cell text-right font-semibold" x-text="formatNum(r.count)"></td>
                                    <td class="table-cell text-right" x-text="r.pct + '%'"></td>
                                    <td class="table-cell text-right" x-text="formatNum(r.coOccurrences)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(r.recent7)"></td>
                                    <td class="table-cell text-right" x-text="r.ratio || '0'"></td>
                                    <td class="table-cell">
                                        <span class="badge" :class="r.trending ? 'badge-warning' : 'badge-success'" x-text="r.trending ? 'Trending' : 'Baseline'"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </article>

            <div class="grid grid-cols-12 gap-4">
                {{-- Symptom × Syndrome stack --}}
                <article class="card col-span-12 lg:col-span-7">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Pattern</div>
                            <h2 class="text-base font-semibold mt-0.5">Symptom × Syndrome Classification</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('classBarChart','sympt-by-class')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="sympt_by_class">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[300px]"><canvas x-ref="classBarChart" id="classBarChart"></canvas></div>
                    </div>
                </article>

                {{-- Syndrome mix --}}
                <article class="card col-span-12 lg:col-span-5">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Distribution</div>
                            <h2 class="text-base font-semibold mt-0.5">Syndrome Mix</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('classDonutChart','class-mix')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="class_mix">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3 grid grid-cols-2 gap-3 items-start">
                        <div class="relative h-[200px]"><canvas x-ref="classDonutChart" id="classDonutChart"></canvas></div>
                        <div class="space-y-1 text-[11.5px]">
                            <template x-if="Object.keys(classMix).length === 0">
                                <p class="text-muted-foreground">No classifications recorded.</p>
                            </template>
                            <template x-for="(count, key) in classMix" :key="key">
                                <div class="flex items-center justify-between gap-2">
                                    <span class="truncate" x-text="key"></span>
                                    <span class="font-mono tabular-nums">
                                        <span class="font-semibold" x-text="formatNum(count)"></span>
                                        <span class="text-muted-foreground text-[10px] ml-1" x-text="classPct(count) + '%'"></span>
                                    </span>
                                </div>
                            </template>
                        </div>
                    </div>
                </article>
            </div>
        </section>
    </template>

    {{-- ====================================================================
         CO-OCCURRENCE TAB
       ==================================================================== --}}
    <template x-if="ready && tab === 'cooccur'">
        <section class="space-y-4">
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Cross-tab</div>
                        <h2 class="text-base font-semibold mt-0.5">Symptom Co-occurrence Matrix</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="badge badge-secondary">Counts</span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="cooccurrence">?</button>
                    </div>
                </div>
                <div class="p-4 pt-2 overflow-auto">
                    <template x-if="!cooccurrence.symptoms || cooccurrence.symptoms.length < 2">
                        <p class="text-[12px] text-muted-foreground py-4">Not enough symptom data to compute co-occurrence.</p>
                    </template>
                    <template x-if="cooccurrence.symptoms && cooccurrence.symptoms.length >= 2">
                        <div class="inline-block min-w-full">
                            <div class="inline-grid gap-1 text-[10.5px]"
                                 :style="`grid-template-columns: 140px repeat(${cooccurrence.symptoms.length}, 50px);`">
                                {{-- Header row --}}
                                <div></div>
                                <template x-for="col in cooccurrence.symptoms" :key="'h-' + col">
                                    <div class="text-[9px] uppercase tracking-wider text-muted-foreground font-semibold flex items-end justify-center pb-1 truncate" :title="col" x-text="col.slice(0, 8)"></div>
                                </template>
                                {{-- Body rows --}}
                                <template x-for="row in cooccurrence.symptoms" :key="'r-' + row">
                                    <template x-for="cell in cooccurrenceCells(row)" :key="'c-' + row + '-' + cell.col">
                                        <template x-if="cell.col === '__label'">
                                            <div class="text-[11px] font-semibold flex items-center pr-2 truncate" :title="row" x-text="row"></div>
                                        </template>
                                        <template x-if="cell.col !== '__label'">
                                            <div class="hm-cell"
                                                 :class="cell.value === 0 ? 'bg-muted/40 text-muted-foreground' : ''"
                                                 :style="cell.value === 0 ? '' : `background: hsl(var(--info) / ${cell.intensity}); color: ${cell.intensity > 0.55 ? 'white' : 'hsl(var(--info))'};`"
                                                 :title="row + ' × ' + cell.col + ' = ' + cell.value"
                                                 x-text="cell.value === 0 ? '—' : cell.value"></div>
                                        </template>
                                    </template>
                                </template>
                            </div>
                            <p class="text-[11px] text-muted-foreground mt-3 italic">Diagonal = symptom&rsquo;s standalone count. Off-diagonal = how often both appeared in the same case.</p>
                        </div>
                    </template>
                </div>
            </article>

            {{-- Sex × symptom load --}}
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Demographics</div>
                        <h2 class="text-base font-semibold mt-0.5">Sex × Symptom Load</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('sexLoadChart','sex-symptom-load')" aria-label="Download PNG">PNG</button>
                        <button type="button" class="rpt-explain-btn" data-chart-key="sex_symptom_load">?</button>
                    </div>
                </div>
                <div class="px-3 pb-3">
                    <div class="relative h-[200px]"><canvas x-ref="sexLoadChart" id="sexLoadChart"></canvas></div>
                </div>
            </article>
        </section>
    </template>

    {{-- ====================================================================
         OUTBREAK DETECTION TAB
       ==================================================================== --}}
    <template x-if="ready && tab === 'outbreak'">
        <section class="space-y-4">
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Tripwires (deterministic)</div>
                        <h2 class="text-base font-semibold mt-0.5">Symptoms Above Baseline</h2>
                    </div>
                    <button type="button" class="rpt-explain-btn" data-chart-key="tripwires">?</button>
                </div>
                <div class="p-4 pt-2">
                    <template x-if="tripwires.length === 0">
                        <div class="rounded-md border border-success/40 bg-success-soft/40 px-3 py-3 text-[12.5px] flex items-start gap-2.5">
                            <span class="mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full shrink-0 text-[10px] font-bold uppercase bg-success text-success-foreground">✓</span>
                            <div>
                                <p class="font-semibold">No tripwires fired</p>
                                <p class="text-muted-foreground text-[11.5px] mt-0.5">No symptom&rsquo;s last-7-day rate exceeds 1.5× the trailing 30-day baseline.</p>
                            </div>
                        </div>
                    </template>
                    <ul class="grid grid-cols-1 md:grid-cols-2 gap-2" role="list" x-show="tripwires.length > 0">
                        <template x-for="tw in tripwires" :key="tw.symptom">
                            <li class="rounded-md border border-warning/60 bg-warning-soft/40 px-3 py-2.5 text-[12.5px]">
                                <div class="flex items-start justify-between gap-2">
                                    <p class="font-semibold flex items-center gap-1.5">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 text-warning"><path d="M12 9v4M12 17h.01M10.3 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                                        <span x-text="tw.symptom"></span>
                                    </p>
                                    <span class="badge badge-warning"><span x-text="tw.ratio + '×'"></span></span>
                                </div>
                                <p class="text-muted-foreground text-[11.5px] mt-1">
                                    <span x-text="tw.recent"></span> reports in last 7 days · ratio against trailing 30-day baseline.
                                </p>
                            </li>
                        </template>
                    </ul>
                </div>
            </article>

            {{-- Weekly high-risk stream --}}
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Trajectory</div>
                        <h2 class="text-base font-semibold mt-0.5">High-risk Cases per Week</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="badge badge-critical"><span class="h-1.5 w-1.5 rounded-full bg-critical"></span>High-risk</span>
                        <span class="badge badge-secondary"><span class="h-1.5 w-1.5 rounded-full bg-muted-foreground"></span>Total</span>
                        <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('streamChart','high-risk-stream')" aria-label="Download PNG">PNG</button>
                        <button type="button" class="rpt-explain-btn" data-chart-key="high_risk_stream">?</button>
                    </div>
                </div>
                <div class="px-3 pb-3">
                    <div class="relative h-[260px]"><canvas x-ref="streamChart" id="streamChart"></canvas></div>
                    <p class="text-[11px] text-muted-foreground mt-2 italic" x-text="streamCaption()"></p>
                </div>
            </article>

            {{-- Onset curve --}}
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Onset</div>
                        <h2 class="text-base font-semibold mt-0.5">Symptom Onset · last 30 days</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('onsetChart','onset-curve')" aria-label="Download PNG">PNG</button>
                        <button type="button" class="rpt-explain-btn" data-chart-key="onset_curve">?</button>
                    </div>
                </div>
                <div class="px-3 pb-3">
                    <div class="relative h-[200px]"><canvas x-ref="onsetChart" id="onsetChart"></canvas></div>
                </div>
            </article>

            {{-- Per-POE high-risk profile --}}
            <article class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Per-POE High-risk Profile</h2>
                        <span class="badge badge-secondary">Showing <span x-text="perPoeTable.length"></span> POE<span x-show="perPoeTable.length !== 1">s</span></span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="per_poe_risk">?</button>
                    </div>
                    <button type="button" class="btn btn-outline btn-xs" @click="exportAs('CSV')">CSV</button>
                </div>
                <div class="overflow-auto max-h-[50vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">POE</th>
                                <th class="table-head-th text-right">Screened</th>
                                <th class="table-head-th text-right">High-risk</th>
                                <th class="table-head-th text-right">High-risk %</th>
                                <th class="table-head-th text-right">≥3 symptoms</th>
                                <th class="table-head-th text-right">YES exposure</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-if="perPoeTable.length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="6">No POE data in window.</td></tr>
                            </template>
                            <template x-for="row in perPoeTable" :key="row.poe_code">
                                <tr class="table-row">
                                    <td class="table-cell font-sans font-semibold" x-text="row.poe_name"></td>
                                    <td class="table-cell text-right" x-text="formatNum(row.screened)"></td>
                                    <td class="table-cell text-right text-critical font-semibold" x-text="formatNum(row.high_risk)"></td>
                                    <td class="table-cell text-right">
                                        <span class="badge" :class="highRiskBadgeClass(row.high_risk_pct)" x-text="row.high_risk_pct + '%'"></span>
                                    </td>
                                    <td class="table-cell text-right" x-text="formatNum(row.multi_symptom)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(row.with_exposure)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    </template>

    {{-- ====================================================================
         EXPOSURES TAB
       ==================================================================== --}}
    <template x-if="ready && tab === 'exposures'">
        <section class="space-y-4">
            <div class="grid grid-cols-12 gap-4">
                {{-- Exposure category donut --}}
                <article class="card col-span-12 md:col-span-5">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Distribution</div>
                            <h2 class="text-base font-semibold mt-0.5">Exposure Categories</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('expDonutChart','exposure-categories')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="exposure_donut">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[260px]"><canvas x-ref="expDonutChart" id="expDonutChart"></canvas></div>
                    </div>
                </article>

                {{-- Exposure category table --}}
                <article class="card col-span-12 md:col-span-7">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Underlying figures</div>
                            <h2 class="text-base font-semibold mt-0.5">Exposure Counts (by category)</h2>
                        </div>
                    </div>
                    <div class="px-4 pb-4">
                        <table class="w-full text-[12.5px]">
                            <thead class="border-b">
                                <tr>
                                    <th class="text-left py-2 text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold">Category</th>
                                    <th class="text-right py-2 text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold">Reports</th>
                                    <th class="text-right py-2 text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold">% of cohort</th>
                                </tr>
                            </thead>
                            <tbody class="font-mono tabular-nums">
                                <template x-if="Object.keys(exposureCategories).length === 0">
                                    <tr><td class="py-3 text-center text-muted-foreground" colspan="3">No exposures recorded.</td></tr>
                                </template>
                                <template x-for="(count, code) in exposureCategories" :key="code">
                                    <tr class="border-b">
                                        <td class="py-2 font-sans font-semibold" x-text="code"></td>
                                        <td class="text-right py-2" x-text="formatNum(count)"></td>
                                        <td class="text-right py-2" x-text="exposureCatPct(count) + '%'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </article>
            </div>

            {{-- Exposure detail table --}}
            <article class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Exposure Detail</h2>
                        <span class="badge badge-secondary"><span x-text="exposureDetail.length"></span> codes</span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="exposure_detail">?</button>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="relative">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                            <input class="input pl-7 w-48" type="search" placeholder="Search exposure…" x-model="exposureQuery">
                        </div>
                        <button type="button" class="btn btn-outline btn-xs" @click="exportAs('CSV')">CSV</button>
                    </div>
                </div>
                <div class="overflow-auto max-h-[55vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">Exposure code</th>
                                <th class="table-head-th">Category</th>
                                <th class="table-head-th text-right">YES</th>
                                <th class="table-head-th text-right">NO</th>
                                <th class="table-head-th text-right">UNKNOWN</th>
                                <th class="table-head-th text-right">Total</th>
                                <th class="table-head-th">YES rate</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-if="filteredExposures.length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="7">No exposures match.</td></tr>
                            </template>
                            <template x-for="row in filteredExposures" :key="row.code">
                                <tr class="table-row">
                                    <td class="table-cell font-sans font-semibold" x-text="row.code"></td>
                                    <td class="table-cell font-sans" x-text="row.category"></td>
                                    <td class="table-cell text-right text-critical font-semibold" x-text="formatNum(row.yes)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(row.no)"></td>
                                    <td class="table-cell text-right text-muted-foreground" x-text="formatNum(row.unknown)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(row.total)"></td>
                                    <td class="table-cell">
                                        <div class="flex items-center gap-2">
                                            <div class="h-2 rounded-full bg-muted overflow-hidden flex-1 min-w-[40px]">
                                                <div class="h-full bg-critical" :style="`width: ${yesRate(row)}%`"></div>
                                            </div>
                                            <span class="text-[11px] text-muted-foreground" x-text="yesRate(row) + '%'"></span>
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
         HIGH-RISK CASE REGISTER TAB
       ==================================================================== --}}
    <template x-if="ready && tab === 'cases'">
        <section>
            <article class="card overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/60 flex-wrap">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">High-risk Case Register</h2>
                        <span class="badge badge-secondary">Showing <span x-text="filteredCases.length"></span> of <span x-text="highCases.length"></span></span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="high_case_register">?</button>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <div class="relative">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                            <input class="input pl-7 w-56" type="search" placeholder="Search code, POE, syndrome…" x-model="caseQuery">
                        </div>
                        <button type="button" class="btn btn-outline btn-xs" @click="exportAs('CSV')">CSV</button>
                    </div>
                </div>

                {{-- Mini-stats --}}
                <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-border border-b border-border/60">
                    <div class="px-4 py-3"><div class="eyebrow">High-risk</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5 text-critical" x-text="formatNum(kpis.high_risk)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">≥3 symptoms</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="formatNum(kpis.multi_symptom)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">YES exposures</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="formatNum(kpis.exposure_yes_cases)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">Tripwires</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5" :class="(kpis.tripwire_count ?? 0) > 0 ? 'text-warning' : ''" x-text="formatNum(kpis.tripwire_count)"></div></div>
                </div>

                <div class="overflow-auto max-h-[60vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">Case</th>
                                <th class="table-head-th">Sex</th>
                                <th class="table-head-th">Age</th>
                                <th class="table-head-th">Risk</th>
                                <th class="table-head-th">Syndrome</th>
                                <th class="table-head-th">POE</th>
                                <th class="table-head-th">Opened</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortCases('symptom_count')">Symptoms</th>
                                <th class="table-head-th text-right table-head-th-sort" @click="sortCases('exposure_count')">YES expos.</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-if="pagedCases.length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="9">No high-risk cases match the search.</td></tr>
                            </template>
                            <template x-for="row in pagedCases" :key="row.id">
                                <tr class="table-row align-top">
                                    <td class="table-cell">
                                        <div class="font-semibold" x-text="row.code"></div>
                                        <div class="text-[10px] text-muted-foreground" x-text="'#' + row.id"></div>
                                    </td>
                                    <td class="table-cell font-sans" x-text="(row.sex || '—').slice(0, 1) || '—'"></td>
                                    <td class="table-cell" x-text="row.age || '—'"></td>
                                    <td class="table-cell">
                                        <template x-if="row.risk"><span class="badge" :class="riskBadgeClass(row.risk)" x-text="row.risk"></span></template>
                                        <template x-if="!row.risk"><span class="text-muted-foreground">—</span></template>
                                    </td>
                                    <td class="table-cell font-sans" x-text="row.syndrome || '—'"></td>
                                    <td class="table-cell font-sans" x-text="row.poe_name || '—'"></td>
                                    <td class="table-cell" x-text="formatDate(row.opened_at)"></td>
                                    <td class="table-cell text-right">
                                        <div class="flex flex-col items-end gap-0.5">
                                            <span class="font-semibold" x-text="(row.symptoms || []).length"></span>
                                            <span class="text-[10px] text-muted-foreground font-sans truncate max-w-[180px]" :title="(row.symptoms || []).join(', ')" x-text="(row.symptoms || []).slice(0, 3).join(', ') + ((row.symptoms || []).length > 3 ? ' +' + ((row.symptoms || []).length - 3) : '')"></span>
                                        </div>
                                    </td>
                                    <td class="table-cell text-right">
                                        <div class="flex flex-col items-end gap-0.5">
                                            <span class="font-semibold" :class="(row.exposures || []).length > 0 ? 'text-critical' : ''" x-text="(row.exposures || []).length"></span>
                                            <span class="text-[10px] text-muted-foreground font-sans truncate max-w-[180px]" :title="(row.exposures || []).join(', ')" x-text="(row.exposures || []).slice(0, 2).join(', ') + ((row.exposures || []).length > 2 ? ' +' + ((row.exposures || []).length - 2) : '')"></span>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div class="flex items-center justify-between px-4 py-2.5 border-t border-border/60">
                    <span class="text-[11px] text-muted-foreground">
                        Showing
                        <span x-text="(casesPage - 1) * casesPerPage + 1"></span>–<span x-text="Math.min(filteredCases.length, casesPage * casesPerPage)"></span>
                        of <span x-text="filteredCases.length"></span>
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
         INSIGHTS + DATA NOTES
       ==================================================================== --}}
    <template x-if="ready">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @include('admin.reports._insights')
            @include('admin.reports._data_notes')
        </div>
    </template>

    @include('admin.reports._filter_wizard')
    @include('admin.reports._chart_explainer', ['reportKey' => 'rpt-symptom-exposure'])

    {{-- =================================================================
         FOOTER
       ================================================================= --}}
    <footer class="text-[11px] text-muted-foreground border-t border-border/60 pt-3 mt-2 flex items-center justify-between flex-wrap gap-2">
        <span>Source:
            <span class="kbd">secondary_screenings</span> ·
            <span class="kbd">secondary_symptoms</span> ·
            <span class="kbd">secondary_exposures</span> · Reference data <span class="kbd">rda-2026-02-01</span>
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
                <h3 class="text-base font-semibold">Symptom &amp; Exposure</h3>
            </header>
            <div class="overflow-y-auto px-5 py-4 space-y-2.5 text-[13px] leading-relaxed">
                <p><strong>Purpose.</strong> Show what we observed — the symptoms travellers reported and the exposures they disclosed. This is the upstream signal layer that drives most other dashboards, so anomalies here usually surface elsewhere first.</p>
                <p><strong>Audience.</strong> Epidemiologists, outbreak analysts, PHEOC duty officers.</p>
                <p><strong>Source.</strong> All figures derive from <code class="kbd">secondary_screenings</code>, <code class="kbd">secondary_symptoms</code> (where <code>is_present = 1</code>) and <code class="kbd">secondary_exposures</code> (full YES / NO / UNKNOWN response).</p>
                <p><strong>High-risk definition.</strong> A case is flagged high-risk when it has ≥3 reported symptoms, OR a YES exposure response, OR risk_level HIGH/CRITICAL.</p>
                <p><strong>What it cannot tell you.</strong> Disease attribution, laboratory confirmation, contact-tracing follow-up. See Suspected Cases, Case Confirmation and Contact Tracing for that.</p>
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
                    <div class="font-semibold text-[13px]">CSV — top symptoms</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Per-symptom rollup.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportAs('XLSX')">
                    <div class="font-semibold text-[13px]">Excel summary</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Top symptoms for spreadsheet tools.</p>
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
.hm-cell{ border-radius:4px; font-family:'JetBrains Mono',ui-monospace,monospace; font-size:11px; display:flex; align-items:center; justify-content:center; font-weight:600; min-height:34px; }
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

function rptSymptomExposure() {
    return {
        ready: false,
        tab: 'overview',
        wizard: { open:false, step:1 },
        ask:    { open:false },
        tour:   { open:false, step:1, steps: [
            { t: 'The question this view answers',
              b: '<p>This page answers: <em>what symptoms and exposures are travellers presenting with, where, and is anything firing above its own baseline?</em></p>' },
            { t: 'Step 1 — The KPI row',
              b: '<p>Six numbers, urgency-ordered. The Tripwires tile glows when any symptom\'s last-7-day rate exceeds 1.5× its trailing 30-day baseline. The high-risk count is the cohort flagged for ≥3 symptoms or YES exposure.</p>' },
            { t: 'Step 2 — The Overview tab',
              b: '<p>Top-15 symptoms first (trending bars highlighted), then symptom × syndrome stacked bars to confirm which classifications absorb which symptoms.</p>' },
            { t: 'Step 3 — The Co-occurrence tab',
              b: '<p>The matrix is your cluster lens. Tight clusters of 3+ symptoms appearing together in many cases suggest an underlying pattern.</p>' },
            { t: 'Step 4 — The Outbreak tab',
              b: '<p>Tripwires show what fired; the high-risk stream shows whether it is a passing burst or a building trend; the per-POE table tells you which border to engage first.</p>' },
        ]},

        aboutOpen: false,
        defOpen: false,
        defRow: null,
        exportOpen: false,

        filters: { poe:'', sex:'', year:'', quarter:'', month:'', classification:'', start_date:'', end_date:'' },
        meta:    { poes:{}, districts:{}, provinces:{}, years:[], quarters:{}, months:{}, genders:{} },

        kpis: {},
        topSymptoms: [],
        cooccurrence: { symptoms: [], matrix: {} },
        symptomByClass: {},
        classMix: {},
        exposureCategories: {},
        exposureDetail: [],
        sexSymptomLoad: { MALE:{none:0,low:0,high:0}, FEMALE:{none:0,low:0,high:0}, OTHER:{none:0,low:0,high:0} },
        onsetByDay: {},
        perPoeTable: [],
        stream: { weeks: [], week_labels: [], high_risk: {}, total: {} },
        highCases: [],
        tripwires: [],
        insights: [],
        dataNotes: {},
        window: { from:'', to:'', generated:'' },

        // Cases / exposures search & paging
        caseQuery: '',
        casesPage: 1,
        casesPerPage: 25,
        caseSortKey: 'opened_at',
        caseSortDir: 'desc',
        exposureQuery: '',

        charts: {},

        askOptions: [
            { code:'TRIPWIRE',  label:'Inspect tripwires',                help:'Open the Outbreak Detection tab — every fired spike with its ratio.', tag:'Urgent',      badge:'badge-warning' },
            { code:'CLUSTER',   label:'Find a symptom cluster',          help:'Open the Co-occurrence tab — look for tight 3+ symptom blocks.', tag:'Investigate', badge:'badge-info' },
            { code:'EXPOSURE',  label:'Audit exposure capture quality',  help:'Open the Exposures tab — check UNKNOWN share per code.', tag:'Quality',     badge:'badge-secondary' },
            { code:'BY_POE',    label:'Compare high-risk by POE',        help:'Open the Outbreak Detection tab — Per-POE High-risk Profile table.', tag:'Compare',     badge:'badge-secondary' },
            { code:'CASES',     label:'Open the high-risk line list',    help:'Filter and export the case register.', tag:'Inspect',     badge:'badge-info' },
            { code:'EXPORT',    label:'Export this view',                help:'PDF or CSV with scope, period and timestamp.', tag:'Share',       badge:'badge-success' },
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
            this.filters = { poe:'', sex:'', year:'', quarter:'', month:'', classification:'', start_date:'', end_date:'' };
            window.history.replaceState(null, '', window.location.pathname);
        },

        async runReport() {
            this.writeFiltersToUrl();
            try {
                const r = await rptJson(@json(url('/admin/reports/rpt-symptom-exposure/data')), this.buildParams());
                const d = r?.data || {};
                this.window             = d.window || {};
                this.kpis               = d.kpis || {};
                this.topSymptoms        = d.top_symptoms || [];
                this.cooccurrence       = d.cooccurrence || { symptoms: [], matrix: {} };
                this.symptomByClass     = d.symptom_by_class || {};
                this.classMix           = d.classification_mix || {};
                this.exposureCategories = d.exposure_categories || {};
                this.exposureDetail     = d.exposure_detail || [];
                this.sexSymptomLoad     = d.sex_symptom_load || this.sexSymptomLoad;
                this.onsetByDay         = d.onset_by_day || {};
                this.perPoeTable        = d.per_poe_table || [];
                this.stream             = d.stream || { weeks: [], week_labels: [], high_risk: {}, total: {} };
                this.highCases          = d.high_cases || [];
                this.tripwires          = d.tripwires || [];
                this.insights           = d.insights || [];
                this.dataNotes          = d.data_notes || {};
                this.casesPage          = 1;
                this.ready              = true;
                this.$nextTick(() => this.renderCharts());
            } catch (e) {
                console.error(e);
                this.ready = false;
            }
        },

        buildParams() { const p = {}; for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) p[k] = v; return p; },

        exportAs(fmt) {
            const u = new URL(@json(url('/admin/reports/rpt-symptom-exposure/export')), window.location.origin);
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

        // ───────── Captions ─────────
        topSymptomsCaption() {
            if (!this.topSymptoms.length) return 'No symptoms recorded for this cohort.';
            const top = this.topSymptoms[0];
            const trending = this.tripwires.length;
            const tw = trending > 0 ? ` · ${trending} symptom${trending === 1 ? '' : 's'} trending above baseline` : '';
            return `${top.symptom} leads with ${top.count} reports (${top.pct}%).${tw}`;
        },
        streamCaption() {
            const totals = Object.values(this.stream.high_risk || {});
            if (!totals.length) return 'No weekly high-risk data.';
            const peak = Math.max(...totals);
            const peakIdx = totals.indexOf(peak);
            const wk = (this.stream.week_labels || [])[peakIdx];
            return `Peak ${peak} high-risk case${peak === 1 ? '' : 's'} ${wk ? 'in week ending ' + this.formatDate(wk) : ''}.`;
        },

        // ───────── Risk / category helpers ─────────
        riskBadgeClass(level) {
            return ({ CRITICAL:'badge-critical', HIGH:'badge-high', MEDIUM:'badge-medium', LOW:'badge-low' })[level] || 'badge-secondary';
        },
        highRiskBadgeClass(pct) {
            if (pct >= 50) return 'badge-critical';
            if (pct >= 30) return 'badge-warning';
            if (pct >= 15) return 'badge-medium';
            return 'badge-low';
        },
        classPct(count) {
            const total = Object.values(this.classMix || {}).reduce((a, b) => a + b, 0);
            return total > 0 ? Math.round(count / total * 100) : 0;
        },
        exposureCatPct(count) {
            const total = Number(this.kpis.secondary || 0);
            return total > 0 ? Math.round(count / total * 100) : 0;
        },
        yesRate(row) {
            return row.total > 0 ? Math.round(row.yes / row.total * 100) : 0;
        },

        // ───────── Co-occurrence matrix helpers ─────────
        cooccurrenceCells(rowKey) {
            const row = (this.cooccurrence.matrix || {})[rowKey] || {};
            const out = [{ col: '__label', value: 0 }];
            const symptoms = this.cooccurrence.symptoms || [];
            const max = Math.max(1, ...symptoms.flatMap(r => {
                const inner = (this.cooccurrence.matrix || {})[r] || {};
                return symptoms.filter(c => c !== r).map(c => inner[c] || 0);
            }));
            for (const col of symptoms) {
                const v = row[col] || 0;
                const isDiag = col === rowKey;
                const intensity = v === 0 ? 0 : (isDiag ? 0.85 : 0.2 + 0.6 * (v / max));
                out.push({ col, value: v, intensity });
            }
            return out;
        },

        // ───────── Exposure search ─────────
        get filteredExposures() {
            const q = (this.exposureQuery || '').toLowerCase().trim();
            if (!q) return this.exposureDetail;
            return (this.exposureDetail || []).filter(r =>
                (r.code || '').toLowerCase().includes(q)
                || (r.category || '').toLowerCase().includes(q));
        },

        // ───────── Case register filter / sort / page ─────────
        get filteredCases() {
            const q = (this.caseQuery || '').toLowerCase().trim();
            const rows = q
                ? (this.highCases || []).filter(r =>
                    (r.code || '').toLowerCase().includes(q)
                    || (r.poe_name || '').toLowerCase().includes(q)
                    || (r.syndrome || '').toLowerCase().includes(q)
                    || (r.risk || '').toLowerCase().includes(q)
                    || (r.symptoms || []).some(s => s.toLowerCase().includes(q))
                    || (r.exposures || []).some(s => s.toLowerCase().includes(q)))
                : (this.highCases || []).slice();

            const key = this.caseSortKey;
            const dir = this.caseSortDir === 'asc' ? 1 : -1;
            rows.sort((a, b) => {
                let av, bv;
                if (key === 'symptom_count') { av = (a.symptoms || []).length; bv = (b.symptoms || []).length; }
                else if (key === 'exposure_count') { av = (a.exposures || []).length; bv = (b.exposures || []).length; }
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
            const total = this.kpis.secondary ?? 0;
            const defs = {
                tripwires: {
                    title: 'Tripwires fired',
                    body:  '<p>Symptoms whose last-7-day report rate exceeds the trailing 30-day baseline by the spike threshold (default 1.5×).</p><p>Each tripwire is logged in the Outbreak Detection tab with its recent count and ratio.</p>',
                    src:   `Spike detection over secondary_symptoms · ${this.kpis.tripwire_count || 0} fired in window.`,
                },
                high_risk: {
                    title: 'High-risk cases',
                    body:  '<p>A case is flagged high-risk when ANY of the following holds:</p><ul class="list-disc pl-5 space-y-0.5"><li>≥3 symptoms recorded as present</li><li>at least one YES exposure response</li><li>risk_level is HIGH or CRITICAL</li></ul>',
                    src:   `Union of three rules over the cohort · ${this.kpis.high_risk || 0} of ${total} cases (${this.kpis.high_risk_pct || 0}%).`,
                },
                secondary: {
                    title: 'Secondary cases',
                    body:  '<p>Every secondary screening record opened during the period — these are travellers who progressed beyond primary screening into the case-detection workflow.</p>',
                    src:   `COUNT(*) FROM secondary_screenings WHERE deleted_at IS NULL · ${total} records.`,
                },
                distinct_symptoms: {
                    title: 'Distinct symptoms',
                    body:  '<p>Unique symptom_code values reported as present across the cohort. The leader carries the largest share.</p>',
                    src:   `COUNT(DISTINCT symptom_code) FROM secondary_symptoms WHERE is_present = 1 · ${this.kpis.distinct_symptoms || 0} distinct codes.`,
                },
                multi_symptom: {
                    title: '≥3 symptoms (multi-symptom)',
                    body:  '<p>Cases where three or more present-symptom rows were recorded. Drives the high-risk flag.</p>',
                    src:   `Cases with COUNT(secondary_symptoms.is_present = 1) ≥ 3 · ${this.kpis.multi_symptom || 0} of ${total} (${this.kpis.multi_symptom_pct || 0}%).`,
                },
                exposure_yes: {
                    title: 'YES exposure cases',
                    body:  '<p>Cases with at least one secondary_exposures row whose response is YES — explicit confirmation of an outbreak-relevant exposure.</p>',
                    src:   `COUNT(DISTINCT secondary_screening_id) FROM secondary_exposures WHERE response = YES · ${this.kpis.exposure_yes_cases || 0} cases.`,
                },
            };
            this.defRow = defs[key] || null;
            this.defOpen = !!this.defRow;
        },

        runAsk(code) {
            switch (code) {
                case 'TRIPWIRE':  this.tab = 'outbreak'; break;
                case 'CLUSTER':   this.tab = 'cooccur'; break;
                case 'EXPOSURE':  this.tab = 'exposures'; break;
                case 'BY_POE':    this.tab = 'outbreak'; break;
                case 'CASES':     this.tab = 'cases'; break;
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
            const filename = `rpt-symptom-exposure-${slug}-${stamp}.png`;
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
            g.fillText(`Symptom & Exposure · ${slug} · ${lbl} · ${win} · generated ${stamp}`, 8, c.height + 18);
            out.toBlob(blob => this.downloadBlob(blob, filename), 'image/png');
        },
        downloadAllPng() {
            ['topSymptomsChart','classBarChart','classDonutChart','sexLoadChart','streamChart','onsetChart','expDonutChart'].forEach(id => {
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
                    this.renderTopSymptoms();
                    this.renderClassBar();
                    this.renderClassDonut();
                } else if (this.tab === 'cooccur') {
                    this.renderSexLoad();
                } else if (this.tab === 'outbreak') {
                    this.renderStream();
                    this.renderOnset();
                } else if (this.tab === 'exposures') {
                    this.renderExpDonut();
                }
            });
        },

        renderTopSymptoms() {
            const ref = this.$refs.topSymptomsChart;
            if (!ref) return;
            const labels = (this.topSymptoms || []).map(r => r.symptom);
            const data   = (this.topSymptoms || []).map(r => r.count);
            const colors = (this.topSymptoms || []).map(r => r.trending ? tokenColor('--warning') : tokenColor('--viz-1'));
            this.charts.topSymptoms = new Chart(ref, {
                type: 'bar',
                data: { labels, datasets: [{ data, backgroundColor: colors, borderRadius: 3 }] },
                options: {
                    indexAxis: 'y',
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.parsed.x} reports` } } },
                    scales: {
                        x: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground') }, grid: { color: tokenColor('--border'), drawBorder: false } },
                        y: { ticks: { color: tokenColor('--foreground'), font: { weight: '600' } }, grid: { display: false } },
                    },
                },
            });
        },

        renderClassBar() {
            const ref = this.$refs.classBarChart;
            if (!ref) return;
            const classes  = Object.keys(this.symptomByClass || {});
            const symptoms = classes.length ? Object.keys(this.symptomByClass[classes[0]] || {}) : [];
            const palette  = ['--viz-1','--viz-5','--viz-3','--viz-4','--viz-7'];
            const datasets = classes.map((cls, i) => ({
                label: cls,
                data: symptoms.map(s => this.symptomByClass[cls][s] || 0),
                backgroundColor: tokenColor(palette[i % palette.length]),
                borderRadius: 3,
            }));
            this.charts.classBar = new Chart(ref, {
                type: 'bar',
                data: { labels: symptoms, datasets },
                options: {
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, color: tokenColor('--muted-foreground') } } },
                    scales: {
                        x: { stacked: true, ticks: { color: tokenColor('--foreground') }, grid: { display: false } },
                        y: { stacked: true, beginAtZero: true, ticks: { color: tokenColor('--muted-foreground') }, grid: { color: tokenColor('--border'), drawBorder: false } },
                    },
                },
            });
        },

        renderClassDonut() {
            const ref = this.$refs.classDonutChart;
            if (!ref) return;
            const entries = Object.entries(this.classMix || {});
            const palette = ['--viz-1','--viz-5','--viz-3','--viz-4','--viz-7','--viz-2','--viz-6','--viz-8'];
            this.charts.classDonut = new Chart(ref, {
                type: 'doughnut',
                data: {
                    labels: entries.map(e => e[0]),
                    datasets: [{
                        data: entries.map(e => e[1]),
                        backgroundColor: entries.map((_, i) => tokenColor(palette[i % palette.length])),
                        borderWidth: 2, borderColor: '#fff',
                    }],
                },
                options: {
                    cutout: '60%',
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.label}: ${c.parsed.toLocaleString()}` } } },
                },
            });
        },

        renderSexLoad() {
            const ref = this.$refs.sexLoadChart;
            if (!ref) return;
            const labels = ['Female', 'Male', 'Other / Unknown'];
            const f = this.sexSymptomLoad.FEMALE || {none:0, low:0, high:0};
            const m = this.sexSymptomLoad.MALE   || {none:0, low:0, high:0};
            const o = this.sexSymptomLoad.OTHER  || {none:0, low:0, high:0};
            const datasetFor = (key, color, label) => ({
                label,
                data: [f[key] || 0, m[key] || 0, o[key] || 0],
                backgroundColor: color,
                stack: 'load',
                borderRadius: 3,
            });
            this.charts.sexLoad = new Chart(ref, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        datasetFor('none', tokenColor('--low'),     '0 symptoms'),
                        datasetFor('low',  tokenColor('--medium'),  '1–2 symptoms'),
                        datasetFor('high', tokenColor('--critical'),'≥3 symptoms'),
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

        renderStream() {
            const ref = this.$refs.streamChart;
            if (!ref) return;
            const labels = (this.stream.week_labels || this.stream.weeks || []).map(d => this.formatDate(d));
            this.charts.stream = new Chart(ref, {
                type: 'line',
                data: {
                    labels,
                    datasets: [
                        {
                            label: 'High-risk',
                            data: Object.values(this.stream.high_risk || {}),
                            borderColor: tokenColor('--critical'),
                            backgroundColor: tokenColor('--critical') + '33',
                            tension: 0.3,
                            fill: true,
                            pointRadius: 3,
                        },
                        {
                            label: 'Total cohort',
                            data: Object.values(this.stream.total || {}),
                            borderColor: tokenColor('--muted-foreground'),
                            backgroundColor: 'transparent',
                            borderDash: [4, 3],
                            tension: 0.3,
                            fill: false,
                            pointRadius: 2,
                        },
                    ],
                },
                options: {
                    plugins: { legend: { display: false } },
                    interaction: { mode: 'nearest', axis: 'x', intersect: false },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tokenColor('--muted-foreground') } },
                        y: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground') }, grid: { color: tokenColor('--border'), drawBorder: false } },
                    },
                },
            });
        },

        renderOnset() {
            const ref = this.$refs.onsetChart;
            if (!ref) return;
            const entries = Object.entries(this.onsetByDay || {});
            const labels = entries.map(([d]) => this.formatDate(d));
            const data   = entries.map(([_, v]) => v);
            const max = Math.max(1, ...data);
            const colors = data.map(v => v === max && max > 0 ? tokenColor('--warning') : tokenColor('--info'));
            this.charts.onset = new Chart(ref, {
                type: 'bar',
                data: { labels, datasets: [{ data, backgroundColor: colors, borderRadius: 2 }] },
                options: {
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.parsed.y} symptoms` } } },
                    scales: {
                        x: { ticks: { color: tokenColor('--muted-foreground'), maxRotation: 0, autoSkip: true, autoSkipPadding: 12 }, grid: { display: false } },
                        y: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground') }, grid: { color: tokenColor('--border'), drawBorder: false } },
                    },
                },
            });
        },

        renderExpDonut() {
            const ref = this.$refs.expDonutChart;
            if (!ref) return;
            const entries = Object.entries(this.exposureCategories || {});
            const palette = ['--critical','--warning','--info','--viz-2','--viz-4','--viz-7','--viz-5','--viz-8'];
            this.charts.expDonut = new Chart(ref, {
                type: 'doughnut',
                data: {
                    labels: entries.map(e => e[0]),
                    datasets: [{
                        data: entries.map(e => e[1]),
                        backgroundColor: entries.map((_, i) => tokenColor(palette[i % palette.length])),
                        borderWidth: 2, borderColor: '#fff',
                    }],
                },
                options: {
                    cutout: '60%',
                    plugins: { legend: { position: 'right', labels: { boxWidth: 10, color: tokenColor('--muted-foreground') } } },
                },
            });
        },
    };
}
</script>
@endpush
@endsection
