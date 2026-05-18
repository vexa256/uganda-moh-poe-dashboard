@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Symptom Distribution')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
{{--
    R12 · Symptom Distribution — REBUILT 2026-04-26 as a lean 2-tab clinical
    chassis. Audience: Clinical Lead, IHR Focal Point, Surveillance Officer.

    Four questions this view answers, in order:
      1. Is our symptom register being filled at all?  → Coverage tile (glow if <60%)
      2. What are clinicians seeing most?              → Top-15 league
      3. Which symptoms predict confirmation?          → Symptom × Outcome matrix
      4. What does a typical case look like?           → Symptom-load distribution

    Tabs (2, deliberate): Overview · Per-Symptom drilldown.

    Honest relabel: schema has no clinical severity grade. What we measure is
    symptom *load* — count of symptoms per case. Labels reflect this.

    Removed deliberately: per-POE symptom cuts (operational — belongs in R7),
    seasonality strip (meaningless on single-month windows), full co-occurrence
    matrix (heavy, lives in R7).
--}}
<div x-data="rptSymptomDistribution()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('rpt-symptom-distribution', wizard.open || ask.open || tour.open || aboutOpen)"
     class="space-y-4">

    {{-- HEADER --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Operations · rpt-symptom-distribution</p>
            <h1 class="text-[18px] font-semibold flex items-center gap-2">
                Symptom Distribution
                <button type="button" class="rpt-explain-btn" @click="aboutOpen = true" aria-label="About this report" title="About this report">i</button>
            </h1>
            <p class="help-text mt-0.5">Clinical signal — what symptoms clinicians are seeing and which ones predict confirmation.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="windowLabel()"></span>
            </span>
            <span class="topbar-chip topbar-chip-mono" x-show="ready && (kpis.dictionary_coverage ?? 0) < 60">
                <span class="status-dot status-dot-warn"></span>
                <span x-text="'Coverage ' + (kpis.dictionary_coverage ?? 0) + '% — distributions suspect'"></span>
            </span>
            @include('admin.reports._coach', ['reportKey' => 'rpt-symptom-distribution'])
            <button type="button" class="btn btn-soft-brand btn-xs" @click="openTour()">Walk-through</button>
            <button type="button" class="btn btn-outline btn-xs" @click="ask.open = true">Do something</button>
            <button type="button" class="btn btn-default btn-xs" @click="exportOpen = true">Export</button>
        </div>
    </section>

    {{-- FILTERS CARD --}}
    <section class="card">
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-border/60">
            <div class="flex items-center gap-2">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 text-muted-foreground"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
                <span class="text-[13px] font-semibold">Filters</span>
                <button type="button" class="rpt-explain-btn" data-chart-key="frequency_league" aria-label="What this report shows">?</button>
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

    <template x-if="!ready">
        <section class="card"><div class="card-content py-10 text-center space-y-3">
            <h2 class="text-[15px] font-semibold">Configure the period for this report</h2>
            <p class="help-text max-w-md mx-auto">Pick a year, quarter, or date range, then press Apply Filters. Filters always reflect into the URL — share the URL to share the picture.</p>
            <button type="button" class="btn btn-brand btn-sm" @click="runReport()">Run report</button>
        </div></section>
    </template>

    {{-- KPI ROW --}}
    <template x-if="ready">
        <section class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-2.5">
            <div class="kpi" :class="(kpis.dictionary_coverage ?? 0) < 60 ? 'kpi-glow' : ''">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Coverage</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('coverage')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" :class="coverageColor()">
                    <span x-text="kpis.dictionary_coverage ?? 0"></span><span class="text-base text-muted-foreground">%</span>
                </div>
                <div class="text-[10.5px] text-muted-foreground" x-text="(kpis.dictionary_coverage ?? 0) < 60 ? 'Distributions suspect' : 'Cases with ≥1 symptom'"></div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Total screened</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('screened')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.total_screened)"></div>
                <div class="text-[10.5px] text-muted-foreground" x-text="windowLabel()"></div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">With symptoms</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('with_symptoms')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.cases_with_symptoms)"></div>
                <div class="text-[10.5px] text-muted-foreground"><span x-text="(kpis.avg_symptoms_per_case ?? 0) + ' avg / case'"></span></div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Distinct symptoms</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('distinct')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.distinct_symptoms)"></div>
                <div class="text-[10.5px] text-muted-foreground">Across the cohort</div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Top symptom</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('top')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value text-base font-bold flex items-center gap-1.5 truncate">
                    <span class="truncate" :title="kpis.top_symptom || ''" x-text="kpis.top_symptom || '—'"></span>
                </div>
                <div class="text-[10.5px] text-muted-foreground"><span x-text="(kpis.top_symptom_count ?? 0) + ' cases · ' + (kpis.top_symptom_pct ?? 0) + '%'"></span></div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">High-load</span>
                    <button type="button" class="btn btn-ghost btn-icon-xs h-5 w-5" @click="openDef('multi')" aria-label="Definition">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-2.5 w-2.5"><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></svg>
                    </button>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.multi_symptom)"></div>
                <div class="text-[10.5px] text-muted-foreground"><span x-text="(kpis.multi_symptom_pct ?? 0) + '% with ≥3 symptoms'"></span></div>
            </div>
        </section>
    </template>

    {{-- TABS · 2 (deliberate) --}}
    <template x-if="ready">
        <section>
            <div class="tabs-list" role="tablist" aria-label="Symptom distribution views">
                <button class="tabs-trigger" role="tab" :data-state="tab === 'overview' ? 'active' : null" @click="tab = 'overview'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                    Overview
                </button>
                <button class="tabs-trigger" role="tab" :data-state="tab === 'symptom' ? 'active' : null" @click="tab = 'symptom'">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5"><circle cx="12" cy="12" r="9"/><path d="M9 12h6"/><path d="M12 9v6"/></svg>
                    Per-Symptom
                </button>
            </div>
        </section>
    </template>

    {{-- OVERVIEW TAB --}}
    <template x-if="ready && tab === 'overview'">
        <section class="space-y-4">

            <div class="grid grid-cols-12 gap-4">
                {{-- Coverage gauge --}}
                <article class="card col-span-12 md:col-span-5">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Data quality first</div>
                            <h2 class="text-base font-semibold mt-0.5">Dictionary Coverage</h2>
                        </div>
                        <button type="button" class="rpt-explain-btn" data-chart-key="coverage_gauge">?</button>
                    </div>
                    <div class="p-4 pt-2">
                        <div class="flex items-center gap-4">
                            <svg viewBox="0 0 120 120" class="h-28 w-28 shrink-0">
                                <circle cx="60" cy="60" r="48" fill="none" stroke="hsl(var(--muted))" stroke-width="14"/>
                                <circle cx="60" cy="60" r="48" fill="none"
                                        :stroke="coverageStroke()"
                                        stroke-width="14"
                                        stroke-linecap="round"
                                        :stroke-dasharray="coverageDash()"
                                        transform="rotate(-90 60 60)"/>
                                <text x="60" y="56" text-anchor="middle" font-size="22" font-weight="700" font-family="JetBrains Mono">
                                    <tspan x-text="(kpis.dictionary_coverage ?? 0) + '%'"></tspan>
                                </text>
                                <text x="60" y="72" text-anchor="middle" font-size="9" fill="hsl(var(--muted-foreground))" font-family="Inter">coverage</text>
                            </svg>
                            <div class="flex-1 space-y-1.5">
                                <div class="flex items-center justify-between text-[12px]"><span class="text-muted-foreground">Cases with symptoms</span><span class="font-mono font-semibold" x-text="formatNum(kpis.cases_with_symptoms)"></span></div>
                                <div class="flex items-center justify-between text-[12px]"><span class="text-muted-foreground">Total screened</span><span class="font-mono font-semibold" x-text="formatNum(kpis.total_screened)"></span></div>
                                <div class="flex items-center justify-between text-[12px] pt-2 border-t border-border/60">
                                    <span class="text-muted-foreground">Threshold</span>
                                    <span class="badge" :class="(kpis.dictionary_coverage ?? 0) >= 80 ? 'badge-success' : ((kpis.dictionary_coverage ?? 0) >= 60 ? 'badge-warning' : 'badge-critical')">
                                        <span x-text="(kpis.dictionary_coverage ?? 0) >= 80 ? '≥ 80% target' : ((kpis.dictionary_coverage ?? 0) >= 60 ? 'Below 80%' : 'Below 60% — alarm')"></span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>

                {{-- Symptom-load bands --}}
                <article class="card col-span-12 md:col-span-7">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div>
                            <div class="eyebrow">Per-case load</div>
                            <h2 class="text-base font-semibold mt-0.5">Symptom-load Distribution</h2>
                        </div>
                        <div class="flex items-center gap-1.5">
                            <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('loadBandsChart','load-bands')" aria-label="Download PNG">PNG</button>
                            <button type="button" class="rpt-explain-btn" data-chart-key="load_bands">?</button>
                        </div>
                    </div>
                    <div class="px-3 pb-3">
                        <div class="relative h-[180px]"><canvas x-ref="loadBandsChart" id="loadBandsChart"></canvas></div>
                        <p class="text-[11px] text-muted-foreground mt-2 italic">Symptom <em>load</em> per case — schema does not carry clinical severity. <span x-text="loadCaption()"></span></p>
                    </div>
                </article>
            </div>

            {{-- Top-15 league --}}
            <article class="card overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/60 flex-wrap">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Top-15 Symptoms</h2>
                        <span class="badge badge-secondary"><span x-text="frequency.length"></span> rows</span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="frequency_league">?</button>
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
                                <th class="table-head-th">Symptom</th>
                                <th class="table-head-th text-right">Cases</th>
                                <th class="table-head-th text-right">Prevalence</th>
                                <th class="table-head-th">Top outcome</th>
                                <th class="table-head-th">Top suspected disease</th>
                                <th class="table-head-th">Confirmation</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-if="frequency.length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="6">No symptoms recorded in window.</td></tr>
                            </template>
                            <template x-for="r in frequency" :key="r.symptom_code">
                                <tr class="table-row cursor-pointer" @click="selectSymptom(r.symptom_code)">
                                    <td class="table-cell font-sans font-semibold" x-text="r.symptom_code"></td>
                                    <td class="table-cell text-right font-semibold" x-text="formatNum(r.count)"></td>
                                    <td class="table-cell text-right">
                                        <div class="flex items-center justify-end gap-2">
                                            <div class="h-2 rounded-full bg-muted overflow-hidden w-20">
                                                <div class="h-full bg-info" :style="`width: ${Math.min(100, r.rate)}%`"></div>
                                            </div>
                                            <span x-text="r.rate + '%'"></span>
                                        </div>
                                    </td>
                                    <td class="table-cell">
                                        <template x-if="r.top_outcome"><span class="badge" :class="outcomeBadgeClass(r.top_outcome)" x-text="dispoLabel(r.top_outcome) + ' (' + r.top_outcome_n + ')'"></span></template>
                                        <template x-if="!r.top_outcome"><span class="text-muted-foreground">—</span></template>
                                    </td>
                                    <td class="table-cell font-sans" x-text="r.top_disease ? (r.top_disease + ' (' + r.top_disease_n + ')') : '—'"></td>
                                    <td class="table-cell">
                                        <template x-if="r.confirmed_pct === null"><span class="badge badge-secondary text-[9px]" title="n < 5">—</span></template>
                                        <template x-if="r.confirmed_pct !== null"><span class="badge" :class="confirmRateBadgeClass(r.confirmed_pct)" x-text="r.confirmed_pct + '%'"></span></template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </article>

            {{-- Outcome matrix --}}
            <article class="card">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Cross-tab</div>
                        <h2 class="text-base font-semibold mt-0.5">Symptom × Outcome</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <span class="badge badge-secondary">Counts</span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="outcome_matrix">?</button>
                    </div>
                </div>
                <div class="p-4 pt-2 overflow-auto">
                    <template x-if="!(outcomeMatrix.symptoms && outcomeMatrix.symptoms.length)">
                        <p class="text-[12px] text-muted-foreground py-2">Not enough data to compose the outcome matrix.</p>
                    </template>
                    <template x-if="outcomeMatrix.symptoms && outcomeMatrix.symptoms.length">
                        <div class="inline-grid gap-1 text-[11px] min-w-[640px]"
                             :style="`grid-template-columns: 180px repeat(${outcomeMatrix.outcomes.length}, minmax(72px, 1fr)) 60px;`">
                            <div></div>
                            <template x-for="oc in outcomeMatrix.outcomes" :key="'h-' + oc">
                                <div class="text-center font-semibold uppercase tracking-wider text-muted-foreground text-[9.5px] pb-1" x-text="dispoLabel(oc)"></div>
                            </template>
                            <div class="text-center font-semibold uppercase tracking-wider text-muted-foreground text-[9.5px] pb-1">Total</div>
                            <template x-for="symp in outcomeMatrix.symptoms" :key="'r-' + symp">
                                <template x-for="cell in outcomeCells(symp)" :key="'c-' + symp + '-' + cell.col">
                                    <template x-if="cell.col === '__label'">
                                        <div class="text-[12px] font-semibold flex items-center pr-2 truncate cursor-pointer hover:text-brand" :title="symp" @click="selectSymptom(symp)" x-text="symp"></div>
                                    </template>
                                    <template x-if="cell.col === '__total'">
                                        <div class="hm-cell bg-card border font-bold" x-text="cell.value"></div>
                                    </template>
                                    <template x-if="cell.col !== '__label' && cell.col !== '__total'">
                                        <div class="hm-cell"
                                             :class="cell.value === 0 ? 'bg-muted/40 text-muted-foreground' : ''"
                                             :style="cell.value === 0 ? '' : `background: hsl(var(${cell.color}) / ${cell.intensity}); color: ${cell.intensity > 0.55 ? 'white' : 'hsl(var(--foreground))'};`"
                                             :title="symp + ' × ' + cell.col + ' = ' + cell.value"
                                             x-text="cell.value === 0 ? '—' : cell.value"></div>
                                    </template>
                                </template>
                            </template>
                        </div>
                    </template>
                </div>
            </article>

            {{-- Top co-occurrence pairs --}}
            <article class="card overflow-hidden">
                <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between flex-wrap gap-2">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Top Co-occurrence Pairs</h2>
                        <span class="badge badge-secondary"><span x-text="topPairs.length"></span> pairs ≥ 3</span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="pairs">?</button>
                    </div>
                </div>
                <div class="overflow-auto max-h-[40vh]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">Symptom A</th>
                                <th class="table-head-th">Symptom B</th>
                                <th class="table-head-th text-right">Cases (both)</th>
                                <th class="table-head-th">Visual</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-if="topPairs.length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="4">No co-occurrence pairs at the ≥ 3 threshold.</td></tr>
                            </template>
                            <template x-for="p in topPairs" :key="p.a + '|' + p.b">
                                <tr class="table-row">
                                    <td class="table-cell font-sans font-semibold cursor-pointer hover:text-brand" @click="selectSymptom(p.a)" x-text="p.a"></td>
                                    <td class="table-cell font-sans font-semibold cursor-pointer hover:text-brand" @click="selectSymptom(p.b)" x-text="p.b"></td>
                                    <td class="table-cell text-right" x-text="p.count"></td>
                                    <td class="table-cell">
                                        <div class="h-2 rounded-full bg-muted overflow-hidden w-40">
                                            <div class="h-full bg-info" :style="`width: ${pairWidth(p.count)}%`"></div>
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

    {{-- PER-SYMPTOM TAB --}}
    <template x-if="ready && tab === 'symptom'">
        <section class="space-y-4">

            <article class="card">
                <div class="px-4 py-3 border-b border-border/60 flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-[12.5px] font-semibold">Symptom</span>
                        <select class="select w-auto min-w-[220px]" x-model="selectedSymptom">
                            <template x-for="s in frequency" :key="s.symptom_code">
                                <option :value="s.symptom_code" x-text="s.symptom_code + ' (' + s.count + ')'"></option>
                            </template>
                        </select>
                        <button type="button" class="rpt-explain-btn" data-chart-key="per_symptom">?</button>
                    </div>
                    <div class="text-[11px] text-muted-foreground">
                        <span x-show="selectedRow && selectedRow.confirmed_pct !== null">
                            Confirmation: <span class="font-mono font-semibold" :class="confirmRateColor(selectedRow.confirmed_pct)" x-text="selectedRow.confirmed_pct + '%'"></span>
                        </span>
                        <span x-show="selectedRow && selectedRow.confirmed_pct === null" class="text-muted-foreground">Confirmation: — (n &lt; 5)</span>
                    </div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 divide-x divide-border border-b border-border/60">
                    <div class="px-4 py-3"><div class="eyebrow">Cases</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="formatNum(selectedRow?.count)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">Prevalence</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="(selectedRow?.rate ?? 0) + '%'"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">Confirmed</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5 text-success" x-text="formatNum(selectedRow?.confirmed)"></div></div>
                    <div class="px-4 py-3"><div class="eyebrow">Co-occurring</div><div class="text-xl font-bold tabular-nums font-mono mt-0.5" x-text="(selectedDrill?.cobs?.length ?? 0)"></div></div>
                </div>
            </article>

            <div class="grid grid-cols-12 gap-4">
                <article class="card col-span-12 md:col-span-6">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div><div class="eyebrow">Outcomes</div><h2 class="text-base font-semibold mt-0.5">Where these cases ended up</h2></div>
                    </div>
                    <div class="p-4 pt-2 space-y-2">
                        <template x-if="!(selectedDrill?.outcome_breakdown?.length)">
                            <p class="text-[12px] text-muted-foreground">No outcome data.</p>
                        </template>
                        <template x-for="o in (selectedDrill?.outcome_breakdown || [])" :key="o.outcome">
                            <div>
                                <div class="flex items-center justify-between text-[11.5px] mb-1">
                                    <span><span class="badge mr-1" :class="outcomeBadgeClass(o.outcome)" x-text="dispoLabel(o.outcome)"></span></span>
                                    <span class="font-mono tabular-nums"><span class="font-semibold" x-text="o.count"></span><span class="text-muted-foreground text-[10px] ml-1" x-text="o.pct + '%'"></span></span>
                                </div>
                                <div class="h-2 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full" :style="`width: ${o.pct}%; background: hsl(var(${outcomeCssVar(o.outcome)}))`"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </article>

                <article class="card col-span-12 md:col-span-6">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div><div class="eyebrow">Disease attribution</div><h2 class="text-base font-semibold mt-0.5">Top suspected diseases</h2></div>
                    </div>
                    <div class="p-4 pt-2 space-y-2">
                        <template x-if="!(selectedDrill?.disease_breakdown?.length)">
                            <p class="text-[12px] text-muted-foreground">No disease attribution.</p>
                        </template>
                        <template x-for="d in (selectedDrill?.disease_breakdown || [])" :key="d.disease">
                            <div>
                                <div class="flex items-center justify-between text-[11.5px] mb-1">
                                    <span class="font-semibold" x-text="d.disease"></span>
                                    <span class="font-mono tabular-nums font-semibold" x-text="d.count"></span>
                                </div>
                                <div class="h-2 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full" :style="`width: ${diseaseWidth(d.count)}%; background: hsl(var(--viz-1))`"></div>
                                </div>
                            </div>
                        </template>
                    </div>
                </article>
            </div>

            <div class="grid grid-cols-12 gap-4">
                <article class="card col-span-12 md:col-span-5">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div><div class="eyebrow">Risk tier</div><h2 class="text-base font-semibold mt-0.5">Risk-level mix</h2></div>
                    </div>
                    <div class="p-4 pt-2 space-y-2">
                        <template x-if="!(selectedDrill?.risk_breakdown?.length)">
                            <p class="text-[12px] text-muted-foreground">No risk data.</p>
                        </template>
                        <template x-for="r in (selectedDrill?.risk_breakdown || [])" :key="r.risk">
                            <div class="flex items-center justify-between text-[12px]">
                                <span class="badge" :class="riskBadgeClass(r.risk)" x-text="r.risk"></span>
                                <span class="font-mono tabular-nums font-semibold" x-text="r.count"></span>
                            </div>
                        </template>
                    </div>
                </article>

                <article class="card col-span-12 md:col-span-7">
                    <div class="flex items-center justify-between p-4 pb-2">
                        <div><div class="eyebrow">Companion symptoms</div><h2 class="text-base font-semibold mt-0.5">Often co-occurs with</h2></div>
                    </div>
                    <div class="p-4 pt-2 space-y-2">
                        <template x-if="!(selectedDrill?.cobs?.length)">
                            <p class="text-[12px] text-muted-foreground">No co-occurrence pairs at the ≥ 3 threshold.</p>
                        </template>
                        <template x-for="c in (selectedDrill?.cobs || [])" :key="c.partner">
                            <div class="flex items-center justify-between gap-3">
                                <button type="button" class="text-[12.5px] font-semibold hover:text-brand text-left truncate flex-1" @click="selectSymptom(c.partner)" x-text="c.partner"></button>
                                <div class="h-2 rounded-full bg-muted overflow-hidden flex-1 max-w-[200px]">
                                    <div class="h-full bg-info" :style="`width: ${cobsWidth(c.count)}%`"></div>
                                </div>
                                <span class="font-mono tabular-nums font-semibold w-10 text-right" x-text="c.count"></span>
                            </div>
                        </template>
                    </div>
                </article>
            </div>
        </section>
    </template>

    <template x-if="ready">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @include('admin.reports._insights')
            @include('admin.reports._data_notes')
        </div>
    </template>

    @include('admin.reports._filter_wizard')
    @include('admin.reports._chart_explainer', ['reportKey' => 'rpt-symptom-distribution'])

    <footer class="text-[11px] text-muted-foreground border-t border-border/60 pt-3 mt-2 flex items-center justify-between flex-wrap gap-2">
        <span>Source:
            <span class="kbd">secondary_screenings</span> ·
            <span class="kbd">secondary_symptoms</span> ·
            <span class="kbd">secondary_suspected_diseases</span> ·
            <span class="kbd">alerts</span> · Reference data <span class="kbd">rda-2026-02-01</span>
        </span>
        <span x-text="'Generated ' + (window?.from ? formatDate(window.from) + ' → ' + formatDate(window.to) : '—') + ' · PHEOC Command Centre · Uganda · v1.0'"></span>
    </footer>

    {{-- ABOUT modal --}}
    <div x-show="aboutOpen" x-cloak class="fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="aboutOpen = false">
        <div class="bg-background w-full sm:max-w-lg sm:rounded-xl border border-border shadow-elevation-5 flex flex-col overflow-hidden max-h-[88vh]" @click.away="aboutOpen = false">
            <header class="px-5 pt-5 pb-3 border-b border-border">
                <span class="badge badge-brand mb-1">About this report</span>
                <h3 class="text-base font-semibold">Symptom Distribution</h3>
            </header>
            <div class="overflow-y-auto px-5 py-4 space-y-2.5 text-[13px] leading-relaxed">
                <p><strong>Purpose.</strong> Show what clinicians on the border are seeing — symptom frequencies, which symptoms predict confirmation, and how cases distribute across symptom-load buckets.</p>
                <p><strong>Audience.</strong> Clinical Lead, IHR Focal Point, Surveillance Officer.</p>
                <p><strong>Source.</strong> All figures derive from <code class="kbd">secondary_symptoms</code> (with <code>is_present = 1</code>) joined to <code class="kbd">secondary_screenings</code>; confirmation status comes from <code class="kbd">alerts</code> (ihr_tier IS NOT NULL AND status = CLOSED).</p>
                <p><strong>Honest note.</strong> The schema does not carry a clinical severity grade (mild / moderate / severe). What this view labels "load" is the count of symptoms recorded per case — not severity.</p>
                <p><strong>What it cannot tell you.</strong> Symptom severity, onset duration, or symptom-by-exposure causality — see Symptom &amp; Exposure for outbreak-detection patterns.</p>
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

    {{-- "Do something" launcher --}}
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
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Full page, ready to print.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportAs('CSV')">
                    <div class="font-semibold text-[13px]">CSV — top-15 league</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Per-symptom counts.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportAs('XLSX')">
                    <div class="font-semibold text-[13px]">Excel summary</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Top-15 league for spreadsheet tools.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; downloadAllPng()">
                    <div class="font-semibold text-[13px]">PNG — load bands</div>
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

function rptSymptomDistribution() {
    return {
        ready: false,
        tab: 'overview',
        wizard: { open:false, step:1 },
        ask:    { open:false },
        tour:   { open:false, step:1, steps: [
            { t: 'The four questions this view answers',
              b: '<p>For Clinical Lead / IHR Focal Point consumers, this view answers exactly four questions:</p><ol class="list-decimal pl-5 mt-1 space-y-0.5"><li>Is our symptom register being filled at all?</li><li>What are clinicians seeing most?</li><li>Which symptoms predict confirmation?</li><li>What does a typical case look like?</li></ol>' },
            { t: 'Step 1 — Coverage first',
              b: '<p>The Coverage tile glows when below 60%. If it glows, every distribution on this page is suspect — fix the symptom-register data quality before drawing conclusions.</p>' },
            { t: 'Step 2 — Top-15 league',
              b: '<p>Sorted by count. The Confirmation chip is suppressed at n &lt; 5 to avoid false signal. Click any row to drill into per-symptom view.</p>' },
            { t: 'Step 3 — Outcome matrix',
              b: '<p>Cross-tab of symptoms vs outcomes. CONFIRMED comes from the alerts join, not disposition. Symptoms whose CONFIRMED column lights up are predictive.</p>' },
            { t: 'Step 4 — Per-Symptom drilldown',
              b: '<p>Pick a symptom. The four panels recompose: outcomes, top diseases, risk mix, and co-occurring symptoms — all for that one symptom.</p>' },
        ]},

        aboutOpen: false,
        defOpen: false,
        defRow: null,
        exportOpen: false,

        filters: { poe:'', year:'', quarter:'', month:'', start_date:'', end_date:'' },
        meta:    { poes:{}, districts:{}, provinces:{}, years:[], quarters:{}, months:{}, genders:{} },

        kpis: {},
        frequency: [],
        topPairs: [],
        perSymptom: {},
        outcomeMatrix: { symptoms: [], outcomes: [], matrix: {} },
        loadBands: { '0': 0, '1-2': 0, '3-5': 0, '6+': 0 },
        insights: [],
        dataNotes: {},
        window: { from:'', to:'', months: 0 },

        selectedSymptom: null,

        charts: {},

        askOptions: [
            { code:'COVERAGE',   label:'Audit dictionary coverage',  help:'When coverage < 60% the rest of the report is suspect. Engage screening supervisors.', tag:'Quality',     badge:'badge-warning' },
            { code:'CONFIRM',    label:'Find symptoms that predict', help:'Sort the league by Confirmation %. Pick the top predictor.', tag:'Investigate', badge:'badge-info' },
            { code:'COCLUSTER',  label:'Look for clusters',          help:'Open the Top Pairs table for the most-frequent co-occurring symptoms.', tag:'Investigate', badge:'badge-secondary' },
            { code:'EXPORT',     label:'Export this view',           help:'PDF or CSV with scope, period and timestamp.', tag:'Share',       badge:'badge-success' },
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
            this.filters = { poe:'', year:'', quarter:'', month:'', start_date:'', end_date:'' };
            window.history.replaceState(null, '', window.location.pathname);
        },

        async runReport() {
            this.writeFiltersToUrl();
            try {
                const r = await rptJson(@json(url('/admin/reports/rpt-symptom-distribution/data')), this.buildParams());
                const d = r?.data || {};
                this.window         = d.window || { months: 0 };
                this.kpis           = d.kpis || {};
                this.frequency      = d.frequency || [];
                this.topPairs       = d.top_pairs || [];
                this.perSymptom     = d.per_symptom || {};
                this.outcomeMatrix  = d.outcome_matrix || { symptoms: [], outcomes: [], matrix: {} };
                this.loadBands      = d.load_bands || { '0':0, '1-2':0, '3-5':0, '6+':0 };
                this.insights       = d.insights || [];
                this.dataNotes      = d.data_notes || {};

                if (! this.selectedSymptom && this.frequency.length) {
                    this.selectedSymptom = this.frequency[0].symptom_code;
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
            const u = new URL(@json(url('/admin/reports/rpt-symptom-distribution/export')), window.location.origin);
            for (const [k,v] of Object.entries(this.buildParams())) u.searchParams.set(k, v);
            u.searchParams.set('format', fmt);
            if (fmt === 'PDF') window.open(u.toString(), '_blank', 'noopener');
            else window.location.href = u.toString();
        },

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

        coverageColor() {
            const v = Number(this.kpis.dictionary_coverage || 0);
            if (v >= 80) return 'text-success';
            if (v >= 60) return 'text-warning';
            return 'text-critical';
        },
        coverageStroke() {
            const v = Number(this.kpis.dictionary_coverage || 0);
            if (v >= 80) return tokenColor('--success');
            if (v >= 60) return tokenColor('--warning');
            return tokenColor('--critical');
        },
        coverageDash() {
            const v = Math.min(100, Math.max(0, Number(this.kpis.dictionary_coverage || 0)));
            const c = 2 * Math.PI * 48;
            const arc = (v / 100) * c;
            return `${arc.toFixed(2)} ${(c - arc).toFixed(2)}`;
        },

        outcomeCssVar(o) {
            return ({
                RELEASED:'--success', REFERRED:'--info', ISOLATED:'--critical',
                DELAYED:'--warning', QUARANTINED:'--high', TRANSFERRED:'--viz-4',
                DENIED_BOARDING:'--viz-5', CONFIRMED:'--brand', PENDING:'--muted-foreground',
                OTHER:'--muted-foreground',
            })[o] || '--muted-foreground';
        },
        outcomeBadgeClass(o) {
            return ({
                RELEASED:'badge-success', REFERRED:'badge-info', ISOLATED:'badge-critical',
                DELAYED:'badge-warning', QUARANTINED:'badge-high', TRANSFERRED:'badge-default',
                DENIED_BOARDING:'badge-secondary', CONFIRMED:'badge-brand', PENDING:'badge-secondary',
                OTHER:'badge-secondary',
            })[o] || 'badge-secondary';
        },
        dispoLabel(o) {
            return ({
                RELEASED:'Released', REFERRED:'Referred', ISOLATED:'Isolated',
                DELAYED:'Delayed', QUARANTINED:'Quarantined', TRANSFERRED:'Transferred',
                DENIED_BOARDING:'Denied', CONFIRMED:'Confirmed', PENDING:'Pending',
            })[o] || o;
        },
        riskBadgeClass(r) {
            return ({ CRITICAL:'badge-critical', HIGH:'badge-high', MEDIUM:'badge-medium', LOW:'badge-low' })[r] || 'badge-secondary';
        },
        confirmRateBadgeClass(pct) {
            if (pct >= 50) return 'badge-success';
            if (pct >= 20) return 'badge-info';
            if (pct === 0) return 'badge-secondary';
            return 'badge-warning';
        },
        confirmRateColor(pct) {
            if (pct >= 50) return 'text-success';
            if (pct >= 20) return 'text-info';
            return 'text-warning';
        },

        get outcomeMatrixMax() {
            const m = this.outcomeMatrix.matrix || {};
            let max = 0;
            for (const row of Object.values(m)) for (const v of Object.values(row)) max = Math.max(max, v);
            return max || 1;
        },
        outcomeCells(symptom) {
            const out = [{ col: '__label', value: 0 }];
            const row = (this.outcomeMatrix.matrix || {})[symptom] || {};
            const max = this.outcomeMatrixMax;
            let total = 0;
            for (const oc of (this.outcomeMatrix.outcomes || [])) {
                const v = row[oc] || 0;
                total += v;
                const intensity = v === 0 ? 0 : 0.2 + 0.6 * (v / max);
                out.push({
                    col: oc,
                    value: v,
                    intensity,
                    color: this.outcomeCssVar(oc),
                });
            }
            out.push({ col: '__total', value: total });
            return out;
        },

        loadCaption() {
            const total = (this.loadBands['0'] || 0) + (this.loadBands['1-2'] || 0) + (this.loadBands['3-5'] || 0) + (this.loadBands['6+'] || 0);
            if (! total) return '';
            const high = ((this.loadBands['3-5'] || 0) + (this.loadBands['6+'] || 0));
            const pct = Math.round(high / total * 100);
            return `${pct}% of cases carry 3+ symptoms.`;
        },
        pairWidth(count) {
            const max = Math.max(1, ...((this.topPairs || []).map(p => p.count)));
            return Math.round(count / max * 100);
        },
        diseaseWidth(count) {
            const max = Math.max(1, ...((this.selectedDrill?.disease_breakdown || []).map(d => d.count)));
            return Math.round(count / max * 100);
        },
        cobsWidth(count) {
            const max = Math.max(1, ...((this.selectedDrill?.cobs || []).map(c => c.count)));
            return Math.round(count / max * 100);
        },

        get selectedRow() { return this.frequency.find(s => s.symptom_code === this.selectedSymptom) || null; },
        get selectedDrill() { return this.perSymptom[this.selectedSymptom] || null; },
        selectSymptom(code) {
            this.selectedSymptom = code;
            this.tab = 'symptom';
        },

        openDef(key) {
            const t = this.kpis.total_screened ?? 0;
            const defs = {
                coverage: {
                    title: 'Dictionary coverage',
                    body:  '<p>Share of cases that have at least one symptom recorded. Below 60% means most cases have no symptom data — every distribution on this page should be treated as suspect.</p>',
                    src:   `cases_with_symptoms / total_screened · ${this.kpis.cases_with_symptoms || 0} of ${t} = ${this.kpis.dictionary_coverage || 0}%.`,
                },
                screened: {
                    title: 'Total screened',
                    body:  '<p>Every secondary screening record opened during the period — the denominator for coverage and prevalence.</p>',
                    src:   `COUNT(*) FROM secondary_screenings · ${t} records.`,
                },
                with_symptoms: {
                    title: 'Cases with symptoms',
                    body:  '<p>Cases with at least one row in <code>secondary_symptoms</code> where <code>is_present = 1</code>.</p>',
                    src:   `COUNT(DISTINCT secondary_screening_id) · ${this.kpis.cases_with_symptoms || 0} cases · ${this.kpis.avg_symptoms_per_case || 0} symptoms / case on average.`,
                },
                distinct: {
                    title: 'Distinct symptoms',
                    body:  '<p>Unique symptom_code values reported as present across the cohort.</p>',
                    src:   `COUNT(DISTINCT symptom_code) · ${this.kpis.distinct_symptoms || 0} codes.`,
                },
                top: {
                    title: 'Top symptom',
                    body:  '<p>The most-reported symptom across the cohort with its case count and prevalence percentage.</p>',
                    src:   `Leader of the league · ${this.kpis.top_symptom || '—'} (${this.kpis.top_symptom_count || 0} cases, ${this.kpis.top_symptom_pct || 0}%).`,
                },
                multi: {
                    title: 'High-load cases',
                    body:  '<p>Cases with three or more symptoms recorded. Symptom-load is not clinical severity — the schema does not carry severity grading.</p>',
                    src:   `Cases with COUNT(secondary_symptoms.is_present = 1) ≥ 3 · ${this.kpis.multi_symptom || 0} cases (${this.kpis.multi_symptom_pct || 0}%).`,
                },
            };
            this.defRow = defs[key] || null;
            this.defOpen = !!this.defRow;
        },

        runAsk(code) {
            switch (code) {
                case 'COVERAGE':  this.tab = 'overview'; break;
                case 'CONFIRM':   this.tab = 'overview'; break;
                case 'COCLUSTER': this.tab = 'overview'; break;
                case 'EXPORT':    this.exportOpen = true; break;
            }
        },

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
            const filename = `rpt-symptom-distribution-${slug}-${stamp}.png`;
            const footerH = 32;
            const out = document.createElement('canvas');
            out.width  = c.width;
            out.height = c.height + footerH * (c.height / Math.max(1, c.clientHeight));
            const g = out.getContext('2d');
            g.fillStyle = '#fff'; g.fillRect(0, 0, out.width, out.height);
            g.drawImage(c, 0, 0);
            g.fillStyle = '#475569';
            g.font = '11px Inter, system-ui, sans-serif';
            const win = (this.window?.from && this.window?.to) ? `${this.window.from} → ${this.window.to}` : '—';
            g.fillText(`Symptom Distribution · ${slug} · ${win} · generated ${stamp}`, 8, c.height + 18);
            out.toBlob(blob => this.downloadBlob(blob, filename), 'image/png');
        },
        downloadAllPng() {
            this.downloadChartPng('loadBandsChart', 'load-bands');
        },

        destroyCharts() {
            Object.values(this.charts).forEach(c => { try { c.destroy(); } catch (e) {} });
            this.charts = {};
        },
        renderCharts() {
            if (typeof Chart === 'undefined') return;
            this.destroyCharts();
            requestAnimationFrame(() => {
                if (this.tab === 'overview') {
                    this.renderLoadBands();
                }
            });
        },
        renderLoadBands() {
            const ref = this.$refs.loadBandsChart;
            if (!ref) return;
            const labels = ['0 symptoms', '1–2', '3–5', '6+'];
            const data   = [
                this.loadBands['0'] || 0,
                this.loadBands['1-2'] || 0,
                this.loadBands['3-5'] || 0,
                this.loadBands['6+'] || 0,
            ];
            const colors = [
                tokenColor('--muted-foreground'),
                tokenColor('--low'),
                tokenColor('--medium'),
                tokenColor('--critical'),
            ];
            this.charts.loadBands = new Chart(ref, {
                type: 'bar',
                data: { labels, datasets: [{ data, backgroundColor: colors, borderRadius: 3 }] },
                options: {
                    plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => `${c.parsed.y} cases` } } },
                    scales: {
                        x: { ticks: { color: tokenColor('--foreground'), font: { weight: '600' } }, grid: { display: false } },
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
