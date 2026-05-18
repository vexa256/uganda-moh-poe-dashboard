@extends('admin.layout')

@section('crumb', 'My Reports')
@section('title', 'Screening Volume')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<style>
    /* Local micro-polish for the report — every colour resolves to a theme token. */
    .kpi-card        { background: hsl(var(--background)); border: 1px solid hsl(var(--border));
                       border-radius: 14px; padding: 14px 16px; min-height: 102px;
                       display: flex; flex-direction: column; gap: 4px; }
    .kpi-card-brand  { background: linear-gradient(135deg, hsl(var(--brand-soft)) 0%, hsl(var(--background)) 80%);
                       border-color: hsl(var(--brand) / .35); }
    .kpi-card-danger { background: hsl(var(--critical-soft)); border-color: hsl(var(--critical) / .35); }
    .kpi-card-good   { background: hsl(var(--success-soft));  border-color: hsl(var(--success) / .35);  }
    .kpi-eyebrow     { font-size: 10.5px; text-transform: uppercase; letter-spacing: .08em;
                       color: hsl(var(--muted-foreground)); font-weight: 600; }
    .kpi-value       { font-size: 28px; font-weight: 700; line-height: 1.1; color: hsl(var(--foreground)); }
    .kpi-sub         { font-size: 11.5px; color: hsl(var(--muted-foreground)); line-height: 1.35; }
    .kpi-pill        { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600;
                       padding: 2px 8px; border-radius: 9999px; }
    .kpi-pill-good   { background: hsl(var(--success) / .14); color: hsl(var(--success)); }
    .kpi-pill-warn   { background: hsl(38 92% 92%); color: hsl(28 80% 38%); }
    .kpi-pill-bad    { background: hsl(var(--critical) / .14); color: hsl(var(--critical)); }
    .kpi-pill-neutral{ background: hsl(var(--accent)); color: hsl(var(--accent-foreground)); }

    .chart-card      { background: hsl(var(--background)); border: 1px solid hsl(var(--border));
                       border-radius: 14px; }
    .chart-card-head { display: flex; align-items: center; gap: 10px; padding: 12px 14px;
                       border-bottom: 1px solid hsl(var(--border) / .6); }
    /* Chart bodies are at least 320px tall; horizontal-bar bodies grow with
       category count so long entry-point names never get cut. The dynamic
       height is set inline on the wrapper via Alpine binding. */
    .chart-card-body { padding: 14px; min-height: 320px; position: relative; }
    .chart-card-body--scroll { overflow-y: auto; max-height: 480px; }
    .chart-card-body--tall { min-height: 380px; }
    .chart-toolbar   { margin-left: auto; display: flex; gap: 6px; align-items: center; }
    .chart-toolbar button { font-size: 11px; padding: 3px 8px; border-radius: 6px;
                            border: 1px solid hsl(var(--border)); background: hsl(var(--background));
                            color: hsl(var(--muted-foreground)); font-weight: 600; cursor: pointer;
                            transition: all .12s ease; }
    .chart-toolbar button:hover { background: hsl(var(--accent)); color: hsl(var(--foreground));
                                   border-color: hsl(var(--foreground) / .4); }

    .data-table      { width: 100%; border-collapse: collapse; font-size: 12.5px; }
    .data-table thead th { text-align: left; padding: 9px 12px; background: hsl(var(--muted));
                           color: hsl(var(--muted-foreground)); text-transform: uppercase;
                           letter-spacing: .04em; font-size: 10.5px; font-weight: 700;
                           border-bottom: 1px solid hsl(var(--border)); }
    .data-table tbody td { padding: 8px 12px; border-bottom: 1px solid hsl(var(--border) / .6);
                            color: hsl(var(--foreground)); }
    .data-table tbody tr:hover td { background: hsl(var(--accent) / .5); }

    /* Full-screen chart explainer modal — narrative + table side-by-side. */
    .rpt-fs-modal     { position: fixed; inset: 0; z-index: 100; background: hsl(var(--background));
                        display: flex; flex-direction: column; }
    .rpt-fs-head      { display: flex; align-items: center; gap: 12px; padding: 16px 24px;
                        border-bottom: 1px solid hsl(var(--border)); }
    .rpt-fs-body      { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1.2fr);
                        gap: 0; overflow: hidden; flex: 1; }
    @media (max-width: 1024px) {
        .rpt-fs-body  { grid-template-columns: 1fr; grid-template-rows: auto 1fr; }
    }
    .rpt-fs-narrative { padding: 24px 28px; overflow-y: auto;
                        border-right: 1px solid hsl(var(--border)); }
    .rpt-fs-narrative h4 { font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
                            color: hsl(var(--muted-foreground)); font-weight: 700; margin-top: 18px; }
    .rpt-fs-narrative p  { font-size: 13.5px; line-height: 1.55; margin-top: 4px; color: hsl(var(--foreground)); }
    .rpt-fs-narrative h4:first-child { margin-top: 0; }
    .rpt-fs-table-wrap { padding: 20px 24px; overflow: auto; }

    /* Holding queue ring */
    .ring-meter      { position: relative; display: inline-flex; align-items: center;
                       justify-content: center; width: 92px; height: 92px; }
    .ring-meter svg  { transform: rotate(-90deg); width: 100%; height: 100%; }
    .ring-meter-val  { position: absolute; font-size: 16px; font-weight: 700; color: hsl(var(--foreground)); }
</style>
@endpush

@section('content')
{{--
    Screening Volume & WHO Indicators Analysis — R10 surface.

    Reads the JSON payload from /admin/reports/rpt-volume/data and renders:
      · 8 WHO indicator KPI cards (Total Screened, Cleared at Booth, Sent for
        Full Check, Share Sent, Full Checks Completed, Officers Found Risk,
        Risk Rate %, Sent on for Care)
      · Holding queue status block (live, not date-bounded)
      · Risk classification breakdown table
      · Six charts, each with Explain / PNG / CSV buttons:
          1. Travellers Screened by Point of Entry
          2. Full Checks Completed by Point of Entry
          3. Sex split at the booth vs full-check
          4. Month-by-month volume trend
          5. Quarter-by-quarter volume trend
          6. Year-by-year volume comparison
      · Plain-language data quality notes

    Default filter window when nothing is supplied: past 7 days.
--}}
<div x-data="rptVolume()" x-init="boot()" class="space-y-4">

    {{-- ===================================================================
         HEADER
       =================================================================== --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Operations &middot; rpt-volume</p>
            <h1 class="text-[20px] font-semibold leading-tight">Screening Volume &amp; Risk Indicators</h1>
            <p class="help-text mt-1">
                How many travellers we screened, where the work happened, and where officers found risk.
                Numbers below are bound to the filters; the default view is the past 7 days.
            </p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="payload.window?.label || ''"></span>
            </span>
            <span class="topbar-chip topbar-chip-mono" x-show="ready">
                Holding queue:
                <span class="ml-1" x-text="payload.kpis?.holding ?? 0"></span>
            </span>
        </div>
    </section>

    {{-- ===================================================================
         FILTERS CARD
       =================================================================== --}}
    <section class="card">
        <div class="flex items-center justify-between px-4 py-2.5 border-b border-border/60">
            <div class="flex items-center gap-2">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 text-muted-foreground"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
                <span class="text-[13px] font-semibold">Choose what to look at</span>
            </div>
            <div class="flex items-center gap-1.5">
                <button type="button" class="btn btn-ghost btn-xs text-muted-foreground" @click="resetFilters()">Reset to past 7 days</button>
                <button type="button" class="btn btn-brand btn-xs" @click="runReport()">Apply</button>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 p-4">
            <div>
                <label class="label block mb-1">Point of Entry</label>
                <select class="select w-full" x-model="filters.poe">
                    <option value="">All entry points</option>
                    <template x-for="(name, code) in (payload.meta?.poes || {})" :key="code">
                        <option :value="code" x-text="name"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1">Traveller sex</label>
                <select class="select w-full" x-model="filters.sex">
                    <option value="">Everyone</option>
                    <template x-for="(label, code) in (payload.meta?.sex_options || {})" :key="code">
                        <option :value="code" x-text="label"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1">Year</label>
                <select class="select w-full" x-model="filters.year">
                    <option value="">Any year</option>
                    <template x-for="y in (payload.meta?.years || [])" :key="y">
                        <option :value="y" x-text="y"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1">Quarter</label>
                <select class="select w-full" x-model="filters.quarter">
                    <option value="">Any quarter</option>
                    <template x-for="q in (payload.meta?.quarters || [])" :key="q">
                        <option :value="q" x-text="'Q' + q"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1">Month</label>
                <select class="select w-full" x-model="filters.month">
                    <option value="">Any month</option>
                    <template x-for="(name, num) in (payload.meta?.months || {})" :key="num">
                        <option :value="num" x-text="name"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1">Custom dates</label>
                <div class="flex items-center gap-1.5">
                    <input type="date" class="input w-full text-[12px]" x-model="filters.start_date" aria-label="Start date">
                    <input type="date" class="input w-full text-[12px]" x-model="filters.end_date"   aria-label="End date">
                </div>
            </div>
        </div>
        <div class="px-4 pb-3 -mt-1 text-[11.5px] text-muted-foreground">
            Tip: choose a year + quarter, or a year + month, or set custom dates. If you leave everything blank the report falls back to the last seven days.
        </div>
    </section>

    {{-- ===================================================================
         WHO INDICATOR DECK — 8 KPI CARDS
       =================================================================== --}}
    <section class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-3" x-show="ready">
        <div class="kpi-card kpi-card-brand">
            <span class="kpi-eyebrow">Total travellers screened</span>
            <span class="kpi-value" x-text="fmt(payload.kpis?.total_screened ?? 0)"></span>
            <span class="kpi-sub">Everyone who passed through the screening counters (cleared at booth + sent for full check).</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-eyebrow">Cleared at the booth</span>
            <span class="kpi-value" x-text="fmt(payload.kpis?.primary ?? 0)"></span>
            <span class="kpi-sub">Travellers screened at primary and let through with no concerns.</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-eyebrow">Sent for full check</span>
            <span class="kpi-value" x-text="fmt(payload.kpis?.referred_for_secondary ?? 0)"></span>
            <span class="kpi-sub">Travellers booked into secondary screening for a closer look.</span>
        </div>
        <div class="kpi-card" :class="pctTone(payload.kpis?.pct_unwell, 10, 20)">
            <span class="kpi-eyebrow">Share sent for full check</span>
            <span class="kpi-value" x-text="pctTxt(payload.kpis?.pct_unwell)"></span>
            <span class="kpi-sub">Out of every 100 travellers screened, this is how many were sent for a closer look.</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-eyebrow">Full checks completed</span>
            <span class="kpi-value" x-text="fmt(payload.kpis?.total_secondary ?? 0)"></span>
            <span class="kpi-sub">Secondary screenings that have been finished and signed off.</span>
        </div>
        <div class="kpi-card" :class="(payload.kpis?.notifiable_cases ?? 0) > 0 ? 'kpi-card-danger' : ''">
            <span class="kpi-eyebrow">Officers found risk</span>
            <span class="kpi-value" x-text="fmt(payload.kpis?.notifiable_cases ?? 0)"></span>
            <span class="kpi-sub">Completed full checks where the risk level was High or Critical.</span>
        </div>
        <div class="kpi-card" :class="pctTone(payload.kpis?.pct_notifiable, 5, 10)">
            <span class="kpi-eyebrow">Risk rate</span>
            <span class="kpi-value" x-text="pctTxt(payload.kpis?.pct_notifiable)"></span>
            <span class="kpi-sub">Of the full checks that finished, this is the share where officers found risk.</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-eyebrow">Sent on for care</span>
            <span class="kpi-value" x-text="fmt(payload.kpis?.facility_referrals ?? 0)"></span>
            <span class="kpi-sub">Travellers referred to a hospital or transferred onward for treatment.</span>
        </div>
    </section>

    {{-- ===================================================================
         HOLDING QUEUE STATUS
       =================================================================== --}}
    <section class="card" x-show="ready">
        <div class="px-4 py-2.5 border-b border-border/60 flex items-center gap-2">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 text-muted-foreground"><path d="M12 8v4l3 3M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <span class="text-[13px] font-semibold">Travellers waiting right now</span>
            <span class="text-[11.5px] text-muted-foreground">live · not bound to the date filter</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 p-4 items-center">
            <div class="flex items-center gap-3">
                <div class="ring-meter">
                    <svg viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="hsl(var(--muted))" stroke-width="3"></circle>
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="hsl(var(--brand))" stroke-width="3"
                                stroke-linecap="round"
                                :stroke-dasharray="(100 - Math.max(0, Math.min(100, payload.kpis?.pct_holding_flagged ?? 0))) + ' 100'"></circle>
                    </svg>
                    <span class="ring-meter-val" x-text="fmt(payload.kpis?.holding ?? 0)"></span>
                </div>
                <div>
                    <p class="text-[12.5px] font-semibold">Travellers in holding</p>
                    <p class="kpi-sub">Open or in-progress secondary screenings, scoped to your geography.</p>
                </div>
            </div>
            <div class="kpi-card">
                <span class="kpi-eyebrow">Waiting under 20 min</span>
                <span class="kpi-value" x-text="fmt(payload.kpis?.holding_under_20 ?? 0)"></span>
                <span class="kpi-sub">Within the normal triage window.</span>
            </div>
            <div class="kpi-card" :class="(payload.kpis?.holding_over_20 ?? 0) > 0 ? 'kpi-card-danger' : 'kpi-card-good'">
                <span class="kpi-eyebrow">Waiting over 20 min</span>
                <span class="kpi-value" x-text="fmt(payload.kpis?.holding_over_20 ?? 0)"></span>
                <span class="kpi-sub">These travellers have been waiting too long &mdash; check staffing now.</span>
            </div>
            <div class="kpi-card" :class="pctTone(payload.kpis?.pct_holding_flagged, 10, 25)">
                <span class="kpi-eyebrow">Share waiting too long</span>
                <span class="kpi-value" x-text="pctTxt(payload.kpis?.pct_holding_flagged)"></span>
                <span class="kpi-sub">Aim: under 10%. Above 25% means the queue is backed up.</span>
            </div>
        </div>
    </section>

    {{-- ===================================================================
         SECONDARY WHO METRICS + DATA COMPLETENESS
       =================================================================== --}}
    <section class="grid grid-cols-1 md:grid-cols-3 gap-3" x-show="ready">
        <div class="kpi-card">
            <span class="kpi-eyebrow">Care referrals that were high-risk</span>
            <span class="kpi-value" x-text="pctTxt(payload.kpis?.pct_referred_notif)"></span>
            <span class="kpi-sub">Of travellers sent on for care, this share were rated High or Critical risk.</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-eyebrow">Records with sex recorded</span>
            <span class="kpi-value" x-text="fmt(payload.kpis?.gender_coverage ?? 0)"></span>
            <span class="kpi-sub">Out of <span x-text="fmt(payload.kpis?.total_screened ?? 0)"></span> travellers screened.</span>
        </div>
        <div class="kpi-card" :class="(payload.kpis?.gender_gap ?? 0) > 0 ? 'kpi-card-danger' : 'kpi-card-good'">
            <span class="kpi-eyebrow">Records missing sex</span>
            <span class="kpi-value" x-text="fmt(payload.kpis?.gender_gap ?? 0)"></span>
            <span class="kpi-sub">These travellers need their sex recorded for full reporting.</span>
        </div>
    </section>

    {{-- ===================================================================
         RISK CLASSIFICATION BREAKDOWN TABLE
       =================================================================== --}}
    <section class="card" x-show="ready">
        <div class="chart-card-head">
            <div class="flex items-center gap-2">
                <span class="text-[13px] font-semibold">Risk levels recorded</span>
            </div>
            <div class="chart-toolbar">
                <button type="button" @click="explainChart('classification')">Explain</button>
                <button type="button" @click="exportTableCsv('classification')">CSV</button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Risk level (plain English)</th>
                        <th class="text-right">Travellers</th>
                        <th class="text-right">Share of full checks</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in (payload.classification_breakdown || [])" :key="row.code">
                        <tr>
                            <td x-text="row.label"></td>
                            <td class="text-right tabular-nums" x-text="fmt(row.count)"></td>
                            <td class="text-right tabular-nums" x-text="row.pct.toFixed(1) + '%'"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </section>

    {{-- ===================================================================
         CHART GRID — six charts, each with Explain / PNG / CSV
       =================================================================== --}}
    <section class="grid grid-cols-1 lg:grid-cols-2 gap-4" x-show="ready">

        {{-- Chart 1: Travellers screened by POE (horizontal bars; height grows
             with category count so long entry-point names never compress) --}}
        <div class="chart-card">
            <div class="chart-card-head">
                <span class="text-[13px] font-semibold">Travellers screened by entry point</span>
                <div class="chart-toolbar">
                    <button type="button" @click="explainChart('poe_primary')">Explain</button>
                    <button type="button" @click="exportPng('chart-poe-primary', 'travellers-by-entry-point.png')">PNG</button>
                    <button type="button" @click="exportChartCsv('poe_primary')">CSV</button>
                </div>
            </div>
            <div class="chart-card-body chart-card-body--scroll"
                 :style="'height:' + horizontalBarHeight(payload.poe_breakdown_primary?.length) + 'px'">
                <canvas id="chart-poe-primary"></canvas>
            </div>
        </div>

        {{-- Chart 2: Full checks completed by POE --}}
        <div class="chart-card">
            <div class="chart-card-head">
                <span class="text-[13px] font-semibold">Full checks completed by entry point</span>
                <div class="chart-toolbar">
                    <button type="button" @click="explainChart('poe_secondary')">Explain</button>
                    <button type="button" @click="exportPng('chart-poe-secondary', 'full-checks-by-entry-point.png')">PNG</button>
                    <button type="button" @click="exportChartCsv('poe_secondary')">CSV</button>
                </div>
            </div>
            <div class="chart-card-body chart-card-body--scroll"
                 :style="'height:' + horizontalBarHeight(payload.poe_breakdown_secondary?.length) + 'px'">
                <canvas id="chart-poe-secondary"></canvas>
            </div>
        </div>

        {{-- Chart 3: Sex split — booth vs full check --}}
        <div class="chart-card">
            <div class="chart-card-head">
                <span class="text-[13px] font-semibold">Sex of travellers at booth vs full check</span>
                <div class="chart-toolbar">
                    <button type="button" @click="explainChart('gender')">Explain</button>
                    <button type="button" @click="exportPng('chart-gender', 'sex-split.png')">PNG</button>
                    <button type="button" @click="exportChartCsv('gender')">CSV</button>
                </div>
            </div>
            <div class="chart-card-body">
                <canvas id="chart-gender"></canvas>
            </div>
        </div>

        {{-- Chart 4: Monthly trend --}}
        <div class="chart-card">
            <div class="chart-card-head">
                <span class="text-[13px] font-semibold">Month-by-month volume</span>
                <div class="chart-toolbar">
                    <button type="button" @click="explainChart('monthly')">Explain</button>
                    <button type="button" @click="exportPng('chart-monthly', 'monthly-volume.png')">PNG</button>
                    <button type="button" @click="exportChartCsv('monthly')">CSV</button>
                </div>
            </div>
            <div class="chart-card-body">
                <canvas id="chart-monthly"></canvas>
            </div>
        </div>

        {{-- Chart 5: Quarterly trend --}}
        <div class="chart-card">
            <div class="chart-card-head">
                <span class="text-[13px] font-semibold">Quarter-by-quarter volume</span>
                <div class="chart-toolbar">
                    <button type="button" @click="explainChart('quarterly')">Explain</button>
                    <button type="button" @click="exportPng('chart-quarterly', 'quarterly-volume.png')">PNG</button>
                    <button type="button" @click="exportChartCsv('quarterly')">CSV</button>
                </div>
            </div>
            <div class="chart-card-body">
                <canvas id="chart-quarterly"></canvas>
            </div>
        </div>

        {{-- Chart 6: Yearly comparison --}}
        <div class="chart-card">
            <div class="chart-card-head">
                <span class="text-[13px] font-semibold">Year-by-year comparison</span>
                <div class="chart-toolbar">
                    <button type="button" @click="explainChart('yearly')">Explain</button>
                    <button type="button" @click="exportPng('chart-yearly', 'yearly-volume.png')">PNG</button>
                    <button type="button" @click="exportChartCsv('yearly')">CSV</button>
                </div>
            </div>
            <div class="chart-card-body">
                <canvas id="chart-yearly"></canvas>
            </div>
        </div>
    </section>

    {{-- ===================================================================
         PER-POE LEAGUE TABLE
       =================================================================== --}}
    <section class="card" x-show="ready">
        <div class="chart-card-head">
            <span class="text-[13px] font-semibold">Entry point league — all numbers side by side</span>
            <div class="chart-toolbar">
                <button type="button" @click="explainChart('poe_table')">Explain</button>
                <button type="button" @click="exportTableCsv('poe_table')">CSV</button>
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Entry point</th>
                        <th>Region</th>
                        <th>District</th>
                        <th class="text-right">Booth screenings</th>
                        <th class="text-right">Full checks</th>
                        <th class="text-right">Risk found</th>
                        <th class="text-right">Sent for care</th>
                        <th class="text-right">Risk rate</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="row in (payload.poe_breakdown_table || [])" :key="row.poe_code">
                        <tr>
                            <td x-text="row.poe_name"></td>
                            <td class="text-muted-foreground" x-text="row.province"></td>
                            <td class="text-muted-foreground" x-text="row.district"></td>
                            <td class="text-right tabular-nums" x-text="fmt(row.primary)"></td>
                            <td class="text-right tabular-nums" x-text="fmt(row.secondary)"></td>
                            <td class="text-right tabular-nums" x-text="fmt(row.notifiable)"></td>
                            <td class="text-right tabular-nums" x-text="fmt(row.referred)"></td>
                            <td class="text-right tabular-nums" x-text="pctTxt(row.notifiable_pct)"></td>
                        </tr>
                    </template>
                    <tr x-show="(payload.poe_breakdown_table || []).length === 0">
                        <td colspan="8" class="text-center text-muted-foreground py-6">No entry points reported screenings in this window.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    {{-- ===================================================================
         DATA NOTES
       =================================================================== --}}
    <section class="card p-4 text-[12px] text-muted-foreground space-y-2" x-show="ready">
        <p><span class="font-semibold text-foreground">What these numbers come from.</span>
           Booth screenings come from the primary screening records. Full checks, risk levels, and care referrals come from the secondary screening records. The holding queue counts records that are still open or in progress.</p>
        <p><span class="font-semibold text-foreground">When numbers are hidden.</span>
           If fewer than five travellers fall into a slice, the percentage shows a dash. This protects against misleading single-digit ratios.</p>
        <p><span class="font-semibold text-foreground">What is bound to your filters.</span>
           Everything on this page except the holding queue. The holding queue is always the live state for your geography.</p>
    </section>

    {{-- ===================================================================
         LOADING STATE
       =================================================================== --}}
    <div x-show="!ready" class="card p-8 text-center text-muted-foreground">
        <div class="animate-spin h-6 w-6 border-2 border-current border-r-transparent rounded-full mx-auto mb-2"></div>
        Loading the report&hellip;
    </div>

    {{-- ===================================================================
         FULL-SCREEN EXPLAIN MODAL — narrative + table
       =================================================================== --}}
    <template x-teleport="body">
        <div x-show="explain.open" x-cloak class="rpt-fs-modal" role="dialog" aria-modal="true"
             @keydown.escape.window="explain.open = false">
            <div class="rpt-fs-head">
                <div class="min-w-0">
                    <p class="eyebrow">How to read this chart</p>
                    <h2 class="text-[18px] font-semibold leading-tight" x-text="explain.title"></h2>
                </div>
                <div class="flex-1"></div>
                <button type="button" class="btn btn-outline btn-sm" @click="explain.open = false">Close</button>
            </div>
            <div class="rpt-fs-body">
                <div class="rpt-fs-narrative">
                    <h4>What it shows</h4>
                    <p x-text="explain.shows"></p>
                    <h4>How to read it</h4>
                    <p x-text="explain.read"></p>
                    <h4>What good looks like</h4>
                    <p x-text="explain.good"></p>
                    <h4>What is concerning</h4>
                    <p x-text="explain.concerning"></p>
                    <h4>What to do if it is concerning</h4>
                    <p x-text="explain.todo"></p>
                </div>
                <div class="rpt-fs-table-wrap">
                    <table class="data-table" x-show="explain.table?.rows?.length">
                        <thead>
                            <tr>
                                <template x-for="h in (explain.table?.headers || [])" :key="h">
                                    <th x-text="h"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, i) in (explain.table?.rows || [])" :key="i">
                                <tr>
                                    <template x-for="(cell, j) in row" :key="j">
                                        <td x-text="cell"></td>
                                    </template>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <p x-show="!(explain.table?.rows?.length)" class="text-muted-foreground text-center mt-8">
                        No data in this slice yet.
                    </p>
                </div>
            </div>
        </div>
    </template>

</div>{{-- /x-data --}}

@php
    $dataEndpoint = url('/admin/reports/rpt-volume/data');
@endphp

@push('scripts')
<script>
function rptVolume() {
    return {
        endpoint: @json($dataEndpoint),
        defaultDays: {{ (int) ($defaultDays ?? 7) }},
        ready: false,
        payload: {},
        filters: { poe: '', sex: '', year: '', quarter: '', month: '', start_date: '', end_date: '' },
        charts: {},
        explain: { open: false, key: '', title: '', shows: '', read: '', good: '', concerning: '', todo: '', table: { headers: [], rows: [] } },

        /* ---------- Boot ---------- */
        boot() {
            this.hydrateFiltersFromUrl();
            this.runReport();
        },

        hydrateFiltersFromUrl() {
            try {
                const sp = new URLSearchParams(window.location.search);
                for (const k of Object.keys(this.filters)) {
                    if (sp.has(k)) this.filters[k] = sp.get(k) || '';
                }
            } catch (e) { /* defensive */ }
        },

        async runReport() {
            this.ready = false;
            const params = new URLSearchParams();
            for (const [k, v] of Object.entries(this.filters)) {
                if (v !== '' && v !== null && v !== undefined) params.append(k, v);
            }
            // Reflect filters in URL (shareable) without reloading.
            try {
                const newUrl = window.location.pathname + (params.toString() ? '?' + params.toString() : '');
                window.history.replaceState({}, '', newUrl);
            } catch (e) {}

            try {
                const res = await fetch(this.endpoint + (params.toString() ? '?' + params.toString() : ''), {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) throw new Error('HTTP ' + res.status);
                const body = await res.json();
                this.payload = body.data || {};
                await this.$nextTick();
                this.renderAllCharts();
                this.ready = true;
            } catch (e) {
                console.error('[rpt-volume] failed to load', e);
                this.payload = { kpis: {}, meta: {} };
                this.ready  = true;
            }
        },

        resetFilters() {
            this.filters = { poe: '', sex: '', year: '', quarter: '', month: '', start_date: '', end_date: '' };
            this.runReport();
        },

        /* ---------- Formatting ---------- */
        fmt(n) {
            if (n === null || n === undefined) return '—';
            const v = Number(n);
            if (!Number.isFinite(v)) return '—';
            return v.toLocaleString();
        },
        pctTxt(n) {
            if (n === null || n === undefined) return '— (n<5)';
            const v = Number(n);
            if (!Number.isFinite(v)) return '— (n<5)';
            return v.toFixed(1) + '%';
        },
        pctTone(n, warn, bad) {
            if (n === null || n === undefined) return '';
            const v = Number(n);
            if (!Number.isFinite(v)) return '';
            if (v >= bad)  return 'kpi-card-danger';
            if (v >= warn) return '';
            return 'kpi-card-good';
        },

        /* ---------- Chart rendering ---------- */
        themeColour(name) {
            // Resolves an HSL CSS variable to a chart-usable colour string.
            const root = getComputedStyle(document.documentElement);
            const v = root.getPropertyValue('--' + name).trim();
            return v ? `hsl(${v})` : '#888';
        },
        themeAlpha(name, a) {
            const root = getComputedStyle(document.documentElement);
            const v = root.getPropertyValue('--' + name).trim();
            return v ? `hsl(${v} / ${a})` : `rgba(120,120,120,${a})`;
        },

        destroyChart(key) {
            if (this.charts[key]) { this.charts[key].destroy(); delete this.charts[key]; }
        },

        renderAllCharts() {
            this.renderPoePrimary();
            this.renderPoeSecondary();
            this.renderGender();
            this.renderMonthly();
            this.renderQuarterly();
            this.renderYearly();
        },

        /* Minimum vertical slot per bar for a horizontal bar chart so every
           label gets enough room — international charting principle: a label
           must never be hidden or shortened to fit the chart. */
        horizontalBarHeight(count) {
            const n = Math.max(0, Number(count) || 0);
            return Math.max(320, n * 34 + 80);
        },

        commonOpts(horizontal, timeAxis = false) {
            const grid   = this.themeAlpha('border', .8);
            const text   = this.themeColour('muted-foreground');
            const isMobile = window.matchMedia && window.matchMedia('(max-width: 640px)').matches;
            const tickFont = { size: isMobile ? 10 : 11 };
            return {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: horizontal ? 'y' : 'x',
                layout: { padding: { left: 4, right: 12, top: 4, bottom: 4 } },
                animation: { duration: 280 },
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: text, font: tickFont, boxWidth: 12, padding: 14 },
                    },
                    tooltip: {
                        enabled: true,
                        backgroundColor: this.themeColour('foreground'),
                        titleColor: this.themeColour('background'),
                        bodyColor:  this.themeColour('background'),
                        padding: 10, borderRadius: 8, cornerRadius: 8,
                        callbacks: {
                            // Always show the full label, even when the axis tick is abbreviated.
                            title: (items) => items.length ? String(items[0].label) : '',
                        },
                    },
                },
                scales: {
                    x: {
                        beginAtZero: !horizontal,
                        ticks: {
                            color: text,
                            font: tickFont,
                            autoSkip: timeAxis || !horizontal,
                            maxTicksLimit: timeAxis ? (isMobile ? 6 : 12) : undefined,
                            maxRotation: 0,
                            minRotation: 0,
                        },
                        grid: { color: grid, drawBorder: false },
                    },
                    y: {
                        beginAtZero: horizontal ? false : true,
                        ticks: {
                            color: text,
                            font: tickFont,
                            autoSkip: !horizontal,    // categorical y-axis: show every label
                            maxRotation: 0,
                            minRotation: 0,
                        },
                        grid: { color: grid, drawBorder: false },
                    },
                },
            };
        },

        renderPoePrimary() {
            this.destroyChart('poePrimary');
            const rows = (this.payload.poe_breakdown_primary || []);
            const ctx  = document.getElementById('chart-poe-primary');
            if (!ctx || !rows.length) return;
            this.charts.poePrimary = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: rows.map(r => r.poe_name),
                    datasets: [{
                        label: 'Travellers screened',
                        data:  rows.map(r => r.count),
                        backgroundColor: this.themeAlpha('brand', .85),
                        borderColor:     this.themeColour('brand-ink'),
                        borderWidth: 1, borderRadius: 4,
                        barThickness: 'flex', maxBarThickness: 26,
                    }],
                },
                options: this.commonOpts(true),
            });
        },

        renderPoeSecondary() {
            this.destroyChart('poeSecondary');
            const rows = (this.payload.poe_breakdown_secondary || []);
            const ctx  = document.getElementById('chart-poe-secondary');
            if (!ctx || !rows.length) return;
            this.charts.poeSecondary = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: rows.map(r => r.poe_name),
                    datasets: [{
                        label: 'Full checks completed',
                        data:  rows.map(r => r.count),
                        backgroundColor: this.themeAlpha('brand-ink', .75),
                        borderColor:     this.themeColour('brand-ink'),
                        borderWidth: 1, borderRadius: 4,
                        barThickness: 'flex', maxBarThickness: 26,
                    }],
                },
                options: this.commonOpts(true),
            });
        },

        renderGender() {
            this.destroyChart('gender');
            const p = this.payload.gender?.primary   || { MALE: 0, FEMALE: 0, OTHER: 0, UNKNOWN: 0 };
            const s = this.payload.gender?.secondary || { MALE: 0, FEMALE: 0, OTHER: 0, UNKNOWN: 0 };
            const ctx = document.getElementById('chart-gender');
            if (!ctx) return;
            this.charts.gender = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: ['Men', 'Women', 'Other', 'Not recorded'],
                    datasets: [
                        {
                            label: 'At the booth',
                            data:  [p.MALE || 0, p.FEMALE || 0, p.OTHER || 0, p.UNKNOWN || 0],
                            backgroundColor: this.themeAlpha('brand', .85),
                            borderRadius: 4,
                        },
                        {
                            label: 'At full check',
                            data:  [s.MALE || 0, s.FEMALE || 0, s.OTHER || 0, s.UNKNOWN || 0],
                            backgroundColor: this.themeAlpha('brand-ink', .75),
                            borderRadius: 4,
                        },
                    ],
                },
                options: this.commonOpts(false),
            });
        },

        _trendChart(canvasId, rows, key) {
            this.destroyChart(key);
            const ctx = document.getElementById(canvasId);
            if (!ctx || !rows.length) return;
            this.charts[key] = new Chart(ctx, {
                type: key === 'monthly' ? 'line' : 'bar',
                data: {
                    labels: rows.map(r => r.label),
                    datasets: [
                        {
                            label: 'Travellers at booth',
                            data:  rows.map(r => r.primary),
                            backgroundColor: this.themeAlpha('brand', .85),
                            borderColor:     this.themeColour('brand'),
                            borderWidth: 2,
                            tension: .25, fill: false,
                            borderRadius: 4,
                            pointRadius: key === 'monthly' ? 3 : 0,
                            pointHoverRadius: 5,
                        },
                        {
                            label: 'Full checks completed',
                            data:  rows.map(r => r.secondary),
                            backgroundColor: this.themeAlpha('brand-ink', .75),
                            borderColor:     this.themeColour('brand-ink'),
                            borderWidth: 2,
                            tension: .25, fill: false,
                            borderRadius: 4,
                            pointRadius: key === 'monthly' ? 3 : 0,
                            pointHoverRadius: 5,
                        },
                    ],
                },
                options: this.commonOpts(false, /* timeAxis */ true),
            });
        },
        renderMonthly()   { this._trendChart('chart-monthly',   this.payload.trend?.monthly   || [], 'monthly'); },
        renderQuarterly() { this._trendChart('chart-quarterly', this.payload.trend?.quarterly || [], 'quarterly'); },
        renderYearly()    { this._trendChart('chart-yearly',    this.payload.trend?.yearly    || [], 'yearly'); },

        /* ---------- Exports ---------- */
        exportPng(canvasId, fileName) {
            const ctx = document.getElementById(canvasId);
            if (!ctx) return;
            // Composite over the background so PNGs don't render transparent.
            const out = document.createElement('canvas');
            out.width  = ctx.width;
            out.height = ctx.height;
            const g = out.getContext('2d');
            g.fillStyle = this.themeColour('background');
            g.fillRect(0, 0, out.width, out.height);
            g.drawImage(ctx, 0, 0);
            const url = out.toDataURL('image/png');
            const a = document.createElement('a');
            a.href = url; a.download = fileName || 'chart.png';
            document.body.appendChild(a); a.click(); a.remove();
        },

        _downloadCsv(name, headers, rows) {
            const esc = (v) => {
                if (v === null || v === undefined) return '';
                const s = String(v);
                return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
            };
            const lines = [];
            lines.push(headers.map(esc).join(','));
            for (const r of rows) lines.push(r.map(esc).join(','));
            const blob = new Blob([lines.join('\n')], { type: 'text/csv;charset=utf-8' });
            const url  = URL.createObjectURL(blob);
            const a    = document.createElement('a');
            a.href = url; a.download = name; document.body.appendChild(a); a.click(); a.remove();
            URL.revokeObjectURL(url);
        },

        exportChartCsv(key) {
            const t = this._chartTable(key);
            if (!t) return;
            this._downloadCsv(t.fileName, t.headers, t.rows);
        },

        exportTableCsv(key) {
            const t = this._chartTable(key);
            if (!t) return;
            this._downloadCsv(t.fileName, t.headers, t.rows);
        },

        /* Build a [headers, rows, fileName] tuple for any chart/table key. */
        _chartTable(key) {
            const p = this.payload || {};
            if (key === 'poe_primary') {
                const rows = (p.poe_breakdown_primary || []).map(r => [r.poe_name, r.poe_code, r.count]);
                return { fileName: 'travellers-by-entry-point.csv', headers: ['Entry point', 'Code', 'Travellers screened'], rows };
            }
            if (key === 'poe_secondary') {
                const rows = (p.poe_breakdown_secondary || []).map(r => [r.poe_name, r.poe_code, r.count]);
                return { fileName: 'full-checks-by-entry-point.csv', headers: ['Entry point', 'Code', 'Full checks completed'], rows };
            }
            if (key === 'gender') {
                const pr = p.gender?.primary   || {};
                const se = p.gender?.secondary || {};
                const rows = [
                    ['Men',          pr.MALE    || 0, se.MALE    || 0],
                    ['Women',        pr.FEMALE  || 0, se.FEMALE  || 0],
                    ['Other',        pr.OTHER   || 0, se.OTHER   || 0],
                    ['Not recorded', pr.UNKNOWN || 0, se.UNKNOWN || 0],
                ];
                return { fileName: 'sex-split.csv', headers: ['Sex group', 'At booth', 'At full check'], rows };
            }
            if (key === 'monthly')   return this._trendTable('monthly',   p.trend?.monthly,   'monthly-volume.csv',   'Month');
            if (key === 'quarterly') return this._trendTable('quarterly', p.trend?.quarterly, 'quarterly-volume.csv', 'Quarter');
            if (key === 'yearly')    return this._trendTable('yearly',    p.trend?.yearly,    'yearly-volume.csv',    'Year');
            if (key === 'classification') {
                const rows = (p.classification_breakdown || []).map(r => [r.label, r.count, (r.pct ?? 0).toFixed(1) + '%']);
                return { fileName: 'risk-levels.csv', headers: ['Risk level', 'Travellers', 'Share %'], rows };
            }
            if (key === 'poe_table') {
                const rows = (p.poe_breakdown_table || []).map(r => [
                    r.poe_name, r.province, r.district, r.poe_type,
                    r.primary, r.secondary, r.notifiable, r.referred,
                    r.notifiable_pct === null ? '—' : (r.notifiable_pct + '%'),
                    r.referred_pct   === null ? '—' : (r.referred_pct   + '%'),
                ]);
                return {
                    fileName: 'entry-point-league.csv',
                    headers: ['Entry point', 'Region', 'District', 'Type', 'Booth screenings', 'Full checks', 'Risk found', 'Sent for care', 'Risk rate', 'Care referral rate'],
                    rows,
                };
            }
            return null;
        },
        _trendTable(key, rows, fileName, label) {
            const r = (rows || []).map(x => [x.label, x.primary, x.secondary]);
            return { fileName, headers: [label, 'Travellers at booth', 'Full checks completed'], rows: r };
        },

        /* ---------- Full-screen Explain modal ---------- */
        explainChart(key) {
            const dict = this._explainDict()[key] || null;
            const tbl  = this._chartTable(key);
            if (!dict) return;
            this.explain.key        = key;
            this.explain.title      = dict.title;
            this.explain.shows      = dict.shows;
            this.explain.read       = dict.read;
            this.explain.good       = dict.good;
            this.explain.concerning = dict.concerning;
            this.explain.todo       = dict.todo;
            this.explain.table      = tbl ? { headers: tbl.headers, rows: tbl.rows } : { headers: [], rows: [] };
            this.explain.open       = true;
        },

        _explainDict() {
            return {
                poe_primary: {
                    title: 'Travellers screened by entry point',
                    shows: 'Each bar is one entry point. The length of the bar is the number of travellers screened at the booth in your selected window.',
                    read: 'Bars are sorted with the busiest entry point at the top. Hover any bar to see the exact count.',
                    good: 'Volumes roughly match the normal traffic profile for each entry point.',
                    concerning: 'A major entry point missing from the chart entirely, or a sudden swap in the league standings.',
                    todo: 'If a major entry point is missing, check whether screeners are submitting records that day. If the order changed, ask the focal person what changed on the ground.',
                },
                poe_secondary: {
                    title: 'Full checks completed by entry point',
                    shows: 'Each bar is the number of secondary screenings that were finished and signed off at that entry point.',
                    read: 'Compare each bar against the matching bar in the booth-screenings chart — full checks should be a small fraction of total booth volume.',
                    good: 'Full checks land at roughly 1 – 5 percent of booth volume for the same entry point.',
                    concerning: 'An entry point with high booth volume but very few full checks, or full checks dropping suddenly week-on-week.',
                    todo: 'For entry points with low full-check counts, confirm the secondary screeners are on shift and the referral pathway is working.',
                },
                gender: {
                    title: 'Sex of travellers at booth vs full check',
                    shows: 'For each sex group, two bars: how many were recorded at the booth and how many at the full check.',
                    read: 'The full-check bar should always be smaller than the booth bar. Compare the shape of the men column to the women column.',
                    good: 'Sex split roughly mirrors travel patterns for the corridor (often close to 50 / 50 for symmetric borders).',
                    concerning: 'A large "Not recorded" bar means data quality is low. A sudden flip in the men-to-women ratio that no business reason explains.',
                    todo: 'If "Not recorded" is large, retrain officers on the booth form. If the ratio flipped, cross-check with Geographic Risk for an origin change.',
                },
                monthly: {
                    title: 'Month-by-month volume',
                    shows: 'For each month in the window, two lines: travellers at the booth (line) and full checks completed (line).',
                    read: 'Read left to right is time. A widening gap between the two lines means more travellers are being kept at the booth.',
                    good: 'Two lines that move together, with the full-check line a steady fraction of the booth line.',
                    concerning: 'A persistent fall in either line, or the full-check line growing while the booth line stays flat.',
                    todo: 'If the booth line falls, check the Reporting Rhythm in the entry-point league. If only the full-check line moves, audit the secondary referral criteria.',
                },
                quarterly: {
                    title: 'Quarter-by-quarter volume',
                    shows: 'Total travellers at the booth and full checks completed, grouped by calendar quarter.',
                    read: 'Each pair of bars is one quarter. Reading left to right is time.',
                    good: 'Slow seasonal pattern in line with travel volumes; no quarter falls off a cliff.',
                    concerning: 'A quarter where booth volume looks normal but full checks collapse, or vice versa.',
                    todo: 'For a collapsed quarter, drill into the monthly view to find the exact week it broke.',
                },
                yearly: {
                    title: 'Year-by-year comparison',
                    shows: 'Annual totals for travellers at the booth and full checks completed.',
                    read: 'Useful for seeing how this year compares to last year. Bars are sorted oldest to newest.',
                    good: 'Year-on-year growth in booth volume tracking border opening and travel recovery.',
                    concerning: 'A year with high booth volume but very few full checks suggests the secondary triage broke that year.',
                    todo: 'For a problem year, narrow the date filter to the quarter that broke and read the entry-point league for that window.',
                },
                classification: {
                    title: 'Risk levels recorded',
                    shows: 'Of every full check that finished, how many landed in each risk level.',
                    read: 'The top row is the most severe (officers escalated immediately). The bottom row is the least severe (cleared).',
                    good: 'A pyramid: more Low than Critical. Critical should be a small share of the cohort.',
                    concerning: 'Critical and High together crossing 30 percent of the cohort, especially if rising week-on-week.',
                    todo: 'Open Suspected Cases to see which conditions are driving the escalation.',
                },
                poe_table: {
                    title: 'Entry point league — all numbers side by side',
                    shows: 'For every entry point that submitted at least one screening, the full set of booth, full-check, risk and care-referral counts plus the risk rate.',
                    read: 'Sorted with the busiest entry point at the top. The risk-rate column suppresses to a dash if there are fewer than five full checks (n<5).',
                    good: 'A spread of activity across entry points without outliers.',
                    concerning: 'One entry point dominating risk or referrals beyond its share of total volume.',
                    todo: 'For an outlier entry point, open Geographic Risk to check whether the origin profile changed.',
                },
            };
        },
    };
}
</script>
@endpush

@endsection
