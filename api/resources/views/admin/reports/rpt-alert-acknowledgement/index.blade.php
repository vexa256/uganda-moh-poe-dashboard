@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Alert Acknowledgement')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
{{--
    Alert Acknowledgement — REBUILT 2026-04-26.

    Question: "Which alerts are past SLA right now, and who's responsible?"

    This is an OPERATIONAL view, not analytical. The user is a duty officer at
    3am or a focal person looking for who dropped the ball. The action queue
    is the headliner; everything else is supporting context.

    Composition (single screen, NO tabs):
      · Compact title bar (Coach · Walk-through · Do something · Export)
      · Filters card (Apply / Reset)
      · 4 KPI tiles (Total · Acknowledged % · SLA breaches · Median ack)
      · Headliner — Unacknowledged action queue (full-width table)
      · Side-by-side — SLA-by-risk bar + Responder leaderboard
      · Insights + Data notes
--}}
<div x-data="rptAck()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('rpt-ack', wizard.open || ask.open || tour.open || aboutOpen)"
     class="space-y-4">

    {{-- HEADER --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">National Reports · rpt-alert-acknowledgement</p>
            <h1 class="text-[18px] font-semibold flex items-center gap-2">
                Alert Acknowledgement
                <button type="button" class="rpt-explain-btn" @click="aboutOpen = true" aria-label="About this report" title="About this report">i</button>
            </h1>
            <p class="help-text mt-0.5">Which alerts are past SLA right now, and who's responsible.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="windowLabel()"></span>
            </span>
            <span class="topbar-chip topbar-chip-mono" x-show="ready"
                  :class="(kpis.unacknowledged ?? 0) > 0 ? 'text-critical font-semibold' : ''">
                <span x-text="(kpis.unacknowledged ?? 0)"></span> unack
            </span>
            @include('admin.reports._coach', ['reportKey' => 'rpt-alert-acknowledgement'])
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
                <button type="button" class="rpt-explain-btn" data-chart-key="unack_queue" aria-label="What this report shows">?</button>
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
            <p class="help-text max-w-md mx-auto">Pick a year, quarter, or date range, then press Apply Filters. SLA targets per risk band are listed in the data notes.</p>
            <button type="button" class="btn btn-brand btn-sm" @click="runReport()">Run report</button>
        </div></section>
    </template>

    {{-- KPI ROW --}}
    <template x-if="ready">
        <section class="grid grid-cols-2 md:grid-cols-4 gap-2.5">
            <div class="kpi" :class="(kpis.unacknowledged ?? 0) > 0 ? '' : 'kpi-glow'">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Total alerts</span>
                </div>
                <div class="kpi-value" x-text="formatNum(kpis.total_alerts)"></div>
                <div class="text-[10.5px] text-muted-foreground" x-text="windowLabel()"></div>
            </div>
            <div class="kpi" :class="(kpis.acknowledged_pct ?? 0) >= 90 ? 'kpi-glow' : ''">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Acknowledged</span>
                </div>
                <div class="kpi-value">
                    <span x-text="kpis.acknowledged_pct ?? 0"></span><span class="text-base text-muted-foreground">%</span>
                </div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="formatNum(kpis.acknowledged)"></span> of <span x-text="formatNum(kpis.total_alerts)"></span>
                </div>
            </div>
            <div class="kpi" :class="(kpis.sla_breaches ?? 0) > 0 ? '' : 'kpi-glow'">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">SLA breaches</span>
                </div>
                <div class="kpi-value" :class="(kpis.sla_breaches ?? 0) > 0 ? 'text-critical' : ''" x-text="formatNum(kpis.sla_breaches)"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    <span x-text="kpis.sla_breach_pct ?? 0"></span>% of acknowledged
                </div>
            </div>
            <div class="kpi">
                <div class="flex items-center justify-between">
                    <span class="kpi-label">Median ack (min)</span>
                </div>
                <div class="kpi-value" x-text="formatNum(Math.round(kpis.median_ack_minutes ?? 0))"></div>
                <div class="text-[10.5px] text-muted-foreground">
                    Across <span x-text="formatNum(kpis.acknowledged)"></span> acknowledgements
                </div>
            </div>
        </section>
    </template>

    {{-- ===== HEADLINER · Unacknowledged action queue ===== --}}
    <template x-if="ready">
        <article class="card overflow-hidden">
            <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/60 flex-wrap">
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-semibold">Unacknowledged — action queue</h2>
                    <span class="badge" :class="queueBadgeClass()" x-text="(unackList || []).length + ' rows'"></span>
                    <button type="button" class="rpt-explain-btn" data-chart-key="unack_queue">?</button>
                </div>
                <div class="flex items-center gap-1.5">
                    <div class="relative">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3.5 w-3.5 absolute left-2.5 top-1/2 -translate-y-1/2 text-muted-foreground"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                        <input class="input pl-7 w-48" type="search" placeholder="Search POE / title…" x-model="queueQuery">
                    </div>
                    <select class="select w-32" x-model="queueRiskFilter">
                        <option value="">All risk</option>
                        <option value="CRITICAL">Critical only</option>
                        <option value="HIGH">High only</option>
                        <option value="MEDIUM">Medium only</option>
                        <option value="LOW">Low only</option>
                    </select>
                    <button type="button" class="btn btn-outline btn-xs" @click="exportUnack()">
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
                            <th class="table-head-th">Risk</th>
                            <th class="table-head-th">POE</th>
                            <th class="table-head-th">Province</th>
                            <th class="table-head-th">Title</th>
                            <th class="table-head-th">Opened</th>
                            <th class="table-head-th text-right">Age</th>
                            <th class="table-head-th text-right">SLA</th>
                            <th class="table-head-th">Status</th>
                        </tr>
                    </thead>
                    <tbody class="table-body font-mono tabular-nums">
                        <template x-for="(row, idx) in filteredUnack()" :key="row.alert_id">
                            <tr class="table-row" :class="row.sla_breached ? 'bg-critical-soft/40' : ''">
                                <td class="table-cell text-muted-foreground" x-text="idx + 1"></td>
                                <td class="table-cell">
                                    <span class="badge" :class="riskBadgeClass(row.risk_level)" x-text="riskLabel(row.risk_level)"></span>
                                </td>
                                <td class="table-cell font-sans font-semibold" x-text="row.poe_name"></td>
                                <td class="table-cell font-sans" x-text="row.province"></td>
                                <td class="table-cell font-sans truncate max-w-[240px]" :title="row.alert_title" x-text="row.alert_title"></td>
                                <td class="table-cell font-sans" x-text="formatDateTime(row.created_at)"></td>
                                <td class="table-cell text-right" :class="row.sla_breached ? 'text-critical font-semibold' : ''" x-text="formatAge(row.overdue_minutes)"></td>
                                <td class="table-cell text-right text-muted-foreground" x-text="formatAge(row.sla_minutes)"></td>
                                <td class="table-cell">
                                    <span class="badge" :class="row.sla_breached ? 'badge-critical' : 'badge-warning'" x-text="row.sla_breached ? 'Past SLA' : 'In window'"></span>
                                </td>
                            </tr>
                        </template>
                        <template x-if="filteredUnack().length === 0">
                            <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="9">All alerts in this window are acknowledged.</td></tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <div class="flex items-center justify-between px-4 py-2.5 border-t border-border/60">
                <span class="text-[11px] text-muted-foreground">Sorted by overdue minutes (descending). Capped at 50 rows. Past-SLA rows are tinted.</span>
            </div>
        </article>
    </template>

    {{-- ===== SUPPORTING STRIP · SLA-by-risk + Responder leaderboard ===== --}}
    <template x-if="ready">
        <div class="grid grid-cols-12 gap-4">
            <article class="card col-span-12 lg:col-span-5">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">SLA performance</div>
                        <h2 class="text-base font-semibold mt-0.5">By risk band</h2>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('slaByRiskChart','sla-by-risk')" aria-label="Download PNG">PNG</button>
                        <button type="button" class="rpt-explain-btn" data-chart-key="sla_by_risk">?</button>
                    </div>
                </div>
                <div class="px-3 pb-3">
                    <div class="relative h-[220px]"><canvas x-ref="slaByRiskChart" id="slaByRiskChart"></canvas></div>
                    <div class="overflow-auto max-h-[160px] mt-2">
                        <table class="table text-[11.5px]">
                            <thead class="table-head"><tr>
                                <th class="table-head-th">Risk</th>
                                <th class="table-head-th text-right">SLA (min)</th>
                                <th class="table-head-th text-right">Total</th>
                                <th class="table-head-th text-right">Median</th>
                                <th class="table-head-th text-right">Breaches</th>
                            </tr></thead>
                            <tbody class="table-body font-mono tabular-nums">
                                <template x-for="row in (byRisk || [])" :key="row.risk_level">
                                    <tr class="table-row">
                                        <td class="table-cell">
                                            <span class="badge" :class="riskBadgeClass(row.risk_level)" x-text="riskLabel(row.risk_level)"></span>
                                        </td>
                                        <td class="table-cell text-right text-muted-foreground" x-text="row.sla_minutes"></td>
                                        <td class="table-cell text-right" x-text="formatNum(row.total)"></td>
                                        <td class="table-cell text-right" x-text="formatNum(Math.round(row.median_minutes))"></td>
                                        <td class="table-cell text-right" :class="(row.breaches ?? 0) > 0 ? 'text-critical font-semibold' : 'text-muted-foreground'" x-text="formatNum(row.breaches)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </article>

            <article class="card col-span-12 lg:col-span-7 overflow-hidden">
                <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/60 flex-wrap">
                    <div class="flex items-center gap-2">
                        <h2 class="text-base font-semibold">Responder leaderboard</h2>
                        <span class="badge badge-secondary" x-show="!(quality.named_responders_visible ?? false)">Role + scope only</span>
                        <button type="button" class="rpt-explain-btn" data-chart-key="responder_leaderboard">?</button>
                    </div>
                    <button type="button" class="btn btn-outline btn-xs" @click="exportLeaderboard()">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3"><path d="M12 3v12m0 0l-4-4m4 4l4-4"/><path d="M5 21h14"/></svg>
                        CSV
                    </button>
                </div>
                <div class="overflow-auto max-h-[400px]">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">#</th>
                                <th class="table-head-th">Responder</th>
                                <th class="table-head-th text-right">Acked</th>
                                <th class="table-head-th text-right">Median (min)</th>
                                <th class="table-head-th text-right">Breaches</th>
                            </tr>
                        </thead>
                        <tbody class="table-body font-mono tabular-nums">
                            <template x-for="(row, i) in (leaderboard || []).slice(0, 10)" :key="i">
                                <tr class="table-row">
                                    <td class="table-cell text-muted-foreground" x-text="i + 1"></td>
                                    <td class="table-cell font-sans font-semibold" x-text="row.label"></td>
                                    <td class="table-cell text-right" x-text="formatNum(row.count)"></td>
                                    <td class="table-cell text-right" x-text="formatNum(Math.round(row.median_minutes))"></td>
                                    <td class="table-cell text-right" :class="(row.breaches ?? 0) > 0 ? 'text-critical font-semibold' : 'text-muted-foreground'" x-text="formatNum(row.breaches)"></td>
                                </tr>
                            </template>
                            <template x-if="(leaderboard || []).length === 0">
                                <tr><td class="table-cell text-center text-muted-foreground py-6" colspan="5">No named acknowledgements in window.</td></tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </article>
        </div>
    </template>

    {{-- INSIGHTS + DATA NOTES --}}
    <template x-if="ready">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            @include('admin.reports._insights')
            @include('admin.reports._data_notes')
        </div>
    </template>

    @include('admin.reports._filter_wizard')
    @include('admin.reports._chart_explainer', ['reportKey' => 'rpt-alert-acknowledgement'])

    {{-- ABOUT --}}
    <div x-show="aboutOpen" x-cloak class="fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="aboutOpen = false">
        <div class="bg-background w-full sm:max-w-lg sm:rounded-xl border border-border shadow-elevation-5 flex flex-col overflow-hidden max-h-[88vh]" @click.away="aboutOpen = false">
            <header class="px-5 pt-5 pb-3 border-b border-border">
                <span class="badge badge-brand mb-1">About this report</span>
                <h3 class="text-base font-semibold">Alert Acknowledgement</h3>
            </header>
            <div class="overflow-y-auto px-5 py-4 space-y-2.5 text-[13px] leading-relaxed">
                <p><strong>Purpose.</strong> Show which alerts are unacknowledged and which past-SLA — so the duty officer has a single, sortable action queue.</p>
                <p><strong>Audience.</strong> PHEOC duty officers, district focal persons, IHR focal point.</p>
                <p><strong>SLA matrix.</strong> CRITICAL = 60 min · HIGH = 240 min · MEDIUM = 1440 min · LOW = 1440 min. Acknowledgement is <code class="kbd">alerts.acknowledged_at IS NOT NULL</code>; named attribution comes from <code class="kbd">acknowledged_by_user_id</code>.</p>
                <p><strong>Visibility.</strong> Responder names are visible only above the national tier. Below, the leaderboard renders role + scope label.</p>
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
                    <div class="font-semibold text-[13px]">PDF — by-risk league</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Per-risk league, ready to print.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportAs('CSV')">
                    <div class="font-semibold text-[13px]">CSV — by-risk league</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Risk · SLA · totals · breaches.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportUnack()">
                    <div class="font-semibold text-[13px]">CSV — Unack queue</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Action queue with overdue minutes.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; downloadChartPng('slaByRiskChart','sla-by-risk')">
                    <div class="font-semibold text-[13px]">PNG — SLA chart</div>
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

function rptAck() {
    return {
        ready: false,
        wizard: { open:false, step:1 },
        ask:    { open:false },
        tour:   { open:false, step:1, steps: [
            { t: 'The question this view answers',
              b: '<p>Which alerts are past SLA right now, and who\'s responsible? Everything else is supporting context.</p>' },
            { t: 'Step 1 — The KPI strip',
              b: '<p>Total alerts, acknowledged %, SLA breaches, median ack minutes. The breach tile turns critical when there are any.</p>' },
            { t: 'Step 2 — The action queue',
              b: '<p>The big table is the live unack queue, sorted by overdue minutes. Past-SLA rows are tinted. Filter by risk band to focus on Critical only.</p>' },
            { t: 'Step 3 — Supporting strip',
              b: '<p>SLA performance by risk band on the left; responder leaderboard on the right (names visible only above national).</p>' },
        ]},

        aboutOpen: false,
        exportOpen: false,
        notesOpen: true,

        filters: { poe:'', sex:'', year:'', quarter:'', month:'', start_date:'', end_date:'' },
        meta:    { poes:{}, districts:{}, provinces:{}, years:[], quarters:{}, months:{}, genders:{} },

        kpis: {},
        unackList: [],
        byRisk: [],
        leaderboard: [],
        quality: {},
        insights: [],
        dataNotes: {},
        window: { from:'', to:'' },

        queueQuery: '',
        queueRiskFilter: '',

        charts: {},

        askOptions: [
            { code:'CRITICAL_ONLY', label:'Show only Critical breaches',     help:'Filter the action queue to Critical risk.', tag:'Common',     badge:'badge-critical' },
            { code:'STUCK_POE',     label:'Find a POE missing repeatedly',   help:'Search the queue by POE name.', tag:'Investigate', badge:'badge-secondary' },
            { code:'WHO_RESPONDS',  label:'Who is acknowledging the most?',  help:'Read the responder leaderboard.', tag:'Inspect',     badge:'badge-info' },
            { code:'EXPORT_QUEUE',  label:'Export the action queue',         help:'CSV with overdue minutes for hand-off.', tag:'Share',       badge:'badge-success' },
        ],

        async boot() {
            this.restoreFiltersFromUrl();
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
                const r = await rptJson(@json(url('/admin/reports/rpt-alert-acknowledgement/data')), this.buildParams());
                const d = r?.data || {};
                this.window      = d.window || {};
                this.kpis        = d.kpis || {};
                this.unackList   = d.unacknowledged_list || [];
                this.byRisk      = d.by_risk || [];
                this.leaderboard = d.responder_leaderboard || [];
                this.quality     = d.quality || {};
                this.insights    = d.insights || [];
                this.dataNotes   = d.data_notes || {};
                this.ready       = true;
                this.$nextTick(() => this.renderCharts());
                Alpine.store('pageMeta', { rows: (this.kpis.unacknowledged ?? 0), version: null, kind: 'rpt-alert-acknowledgement' });
            } catch (e) { console.error(e); this.ready = false; }
        },

        buildParams() { const p = {}; for (const [k,v] of Object.entries(this.filters)) if (v !== '' && v != null) p[k] = v; return p; },

        exportAs(fmt) {
            const u = new URL(@json(url('/admin/reports/rpt-alert-acknowledgement/export')), window.location.origin);
            for (const [k,v] of Object.entries(this.buildParams())) u.searchParams.set(k, v);
            u.searchParams.set('format', fmt);
            if (fmt === 'PDF') window.open(u.toString(), '_blank', 'noopener');
            else window.location.href = u.toString();
        },

        exportUnack() {
            const rows = this.filteredUnack();
            if (!rows.length) return;
            const headers = ['Alert ID', 'Risk', 'POE Name', 'POE Code', 'Province', 'Title', 'Opened', 'Age (min)', 'SLA (min)', 'Past SLA'];
            const lines = [headers.join(',')].concat(rows.map(r => [
                r.alert_id, r.risk_level, this.csv(r.poe_name), this.csv(r.poe_code),
                this.csv(r.province), this.csv(r.alert_title), this.csv(r.created_at),
                r.overdue_minutes, r.sla_minutes, r.sla_breached ? 'YES' : 'NO',
            ].join(',')));
            this.downloadBlob(new Blob(["﻿" + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }), 'unack-queue-' + this.stamp() + '.csv');
        },

        exportLeaderboard() {
            const rows = (this.leaderboard || []);
            if (!rows.length) return;
            const headers = ['#', 'Responder', 'Acked', 'Median (min)', 'Breaches'];
            const lines = [headers.join(',')].concat(rows.map((r, i) => [
                i + 1, this.csv(r.label), r.count, Math.round(r.median_minutes), r.breaches,
            ].join(',')));
            this.downloadBlob(new Blob(["﻿" + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }), 'responder-leaderboard-' + this.stamp() + '.csv');
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
            const filename = `rpt-alert-acknowledgement-${slug}-${stamp}.png`;
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
            g.fillText(`Alert Acknowledgement · ${slug} · ${lbl} · ${win} · generated ${stamp}`, 8, c.height + 18);
            out.toBlob(blob => this.downloadBlob(blob, filename), 'image/png');
        },

        formatNum(v)  { return (v == null || v === undefined) ? '—' : Number(v).toLocaleString(); },
        formatAge(min) {
            const m = Math.max(0, Number(min || 0));
            if (m < 60)   return m + ' m';
            if (m < 1440) return Math.floor(m / 60) + ' h ' + (m % 60) + ' m';
            const d = Math.floor(m / 1440);
            const rh = Math.floor((m % 1440) / 60);
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

        riskBadgeClass(level) {
            return level === 'CRITICAL' ? 'badge-critical'
                 : level === 'HIGH'     ? 'badge-high'
                 : level === 'MEDIUM'   ? 'badge-medium'
                 : level === 'LOW'      ? 'badge-low'
                 : 'badge-secondary';
        },
        riskLabel(level) {
            return ({ CRITICAL: 'Critical', HIGH: 'High', MEDIUM: 'Medium', LOW: 'Low' })[level] || (level || 'Unknown');
        },
        queueBadgeClass() {
            const breaches = (this.unackList || []).filter(r => r.sla_breached).length;
            if (breaches > 0) return 'badge-critical';
            if ((this.unackList || []).length > 0) return 'badge-warning';
            return 'badge-success';
        },

        filteredUnack() {
            const q = (this.queueQuery || '').toLowerCase().trim();
            const f = this.queueRiskFilter || '';
            return (this.unackList || []).filter(r => {
                if (f && r.risk_level !== f) return false;
                if (!q) return true;
                return (r.poe_name && r.poe_name.toLowerCase().includes(q))
                    || (r.poe_code && r.poe_code.toLowerCase().includes(q))
                    || (r.alert_title && r.alert_title.toLowerCase().includes(q));
            });
        },

        runAsk(code) {
            switch (code) {
                case 'CRITICAL_ONLY': this.queueRiskFilter = 'CRITICAL'; break;
                case 'STUCK_POE':     document.querySelector('input[type=search]')?.focus(); break;
                case 'WHO_RESPONDS':  document.getElementById('slaByRiskChart')?.scrollIntoView({ block: 'center' }); break;
                case 'EXPORT_QUEUE':  this.exportUnack(); break;
            }
        },

        // ────────────────────────────────────────────────
        // Chart rendering — single chart on this view.
        // ────────────────────────────────────────────────
        destroyCharts() { Object.values(this.charts).forEach(c => { try { c.destroy(); } catch (e) {} }); this.charts = {}; },
        renderCharts() {
            if (typeof Chart === 'undefined') return;
            this.destroyCharts();
            requestAnimationFrame(() => this.renderSlaByRisk());
        },

        renderSlaByRisk() {
            const ref = this.$refs.slaByRiskChart;
            if (!ref) return;
            const rows = (this.byRisk || []);
            const labels = rows.map(r => this.riskLabel(r.risk_level));
            const onTime = rows.map(r => Math.max(0, (r.acknowledged || 0) - (r.breaches || 0)));
            const breached = rows.map(r => Number(r.breaches || 0));
            const unack = rows.map(r => Math.max(0, (r.total || 0) - (r.acknowledged || 0)));
            this.charts.slaByRisk = new Chart(ref, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'Acknowledged on time', data: onTime,   backgroundColor: tokenColor('--success'), borderRadius: 3, stack: 's' },
                        { label: 'SLA breached',         data: breached, backgroundColor: tokenColor('--critical'), borderRadius: 3, stack: 's' },
                        { label: 'Unacknowledged',       data: unack,    backgroundColor: tokenColor('--warning') + 'AA', borderRadius: 3, stack: 's' },
                    ],
                },
                options: {
                    indexAxis: 'y',
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 10, color: tokenColor('--muted-foreground') } },
                        tooltip: { mode: 'index', intersect: false,
                            callbacks: { label: c => `${c.dataset.label}: ${c.parsed.x.toLocaleString()}` } },
                    },
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        x: { stacked: true, beginAtZero: true, ticks: { color: tokenColor('--muted-foreground'), precision: 0 }, grid: { color: tokenColor('--border'), drawBorder: false } },
                        y: { stacked: true, ticks: { color: tokenColor('--foreground'), font: { weight: '600' } }, grid: { display: false } },
                    },
                },
            });
        },
    };
}
</script>
@endpush
@endsection
