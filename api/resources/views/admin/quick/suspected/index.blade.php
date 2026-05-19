@extends('admin.layout')

@section('crumb', 'Quick Reports')
@section('title', 'Suspected Cases')

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>

{{-- Quick-report local primitives. MUST be `text/tailwindcss` for the Play
     CDN to compile the `@apply` directives at runtime — a plain <style>
     block silently no-ops them. --}}
<style type="text/tailwindcss">
    /* ── Layout chassis ──────────────────────────────────────────────── */
    .qr-stack       { @apply space-y-4; }
    .qr-card        { @apply rounded-xl border border-border/70 bg-card text-card-foreground shadow-elevation-1; }
    .qr-card-pad    { @apply px-4 py-3; }
    .qr-card-head   { @apply flex items-center justify-between gap-3 px-4 py-3 border-b border-border/70; }
    .qr-card-title  { @apply text-[13px] font-semibold tracking-tight text-foreground; }
    .qr-card-sub    { @apply text-[11px] text-muted-foreground; }
    .qr-divider     { @apply border-t border-border/70; }

    /* ── KPI tiles (compact, one focal warn tile) ───────────────────── */
    .qr-kpi-grid    { @apply grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2.5; }
    .qr-kpi         { @apply rounded-lg border border-border/70 bg-card px-3.5 py-2.5
                            transition-colors duration-150; }
    .qr-kpi-label   { @apply text-[10px] font-semibold uppercase tracking-[.12em] text-muted-foreground; }
    .qr-kpi-value   { @apply mt-1 text-[22px] leading-none font-semibold tabular-nums text-foreground; }
    .qr-kpi-hint    { @apply mt-1.5 text-[10.5px] text-muted-foreground; }
    .qr-kpi-warn    { @apply ring-1 ring-critical/30 bg-critical/[.03]; }
    .qr-kpi-warn .qr-kpi-value  { @apply text-critical; }
    .qr-kpi-info .qr-kpi-value  { @apply text-brand-ink; }
    .qr-kpi-skel    { @apply rounded-lg border border-dashed border-border/60 px-3.5 py-3 h-[78px]
                            animate-pulse bg-muted/20; }

    /* ── Thin premium table ─────────────────────────────────────────── */
    .qr-table-wrap  { @apply relative w-full overflow-auto max-h-[640px]; }
    .qr-table       { @apply w-full text-[12.5px] border-separate;
                      border-spacing: 0; }
    .qr-table thead th {
        @apply text-[10px] font-semibold uppercase tracking-[.10em] text-muted-foreground
               bg-muted/50 backdrop-blur-sm
               px-3 py-2 text-left whitespace-nowrap
               border-b border-border/70 sticky top-0 z-10
               select-none cursor-default;
    }
    .qr-table thead th.qr-sortable { @apply cursor-pointer hover:text-foreground; }
    .qr-table thead th .qr-arrow   { @apply ml-1 text-[8px] opacity-50; }
    .qr-table thead th.qr-sorted   { @apply text-foreground; }
    .qr-table thead th.qr-sorted .qr-arrow { @apply opacity-100; }
    .qr-table tbody td {
        @apply px-3 py-1.5 border-b border-border/40 align-middle whitespace-nowrap;
    }
    .qr-table tbody tr { transition: background-color 120ms ease; }
    .qr-table tbody tr:hover td { @apply bg-muted/40; }
    .qr-table tbody tr:last-child td { @apply border-b-0; }

    .qr-cell-primary   { @apply font-medium text-foreground; }
    .qr-cell-secondary { @apply text-[10.5px] text-muted-foreground tabular-nums; }
    .qr-cell-mono      { @apply font-mono text-[11px] text-muted-foreground; }

    .qr-disease     { @apply inline-flex items-center rounded-full bg-brand-soft text-brand-ink
                            text-[10.5px] font-medium px-1.5 py-0.5 mr-1 mb-0.5 whitespace-nowrap leading-tight; }
    .qr-disease-more{ @apply inline-flex items-center rounded-full bg-muted text-muted-foreground
                            text-[10.5px] font-medium px-1.5 py-0.5 mr-1 mb-0.5 whitespace-nowrap leading-tight; }
    .qr-no-dx       { @apply text-[11px] text-muted-foreground italic; }

    /* Risk + status pills — tint with semantic colours, always include text */
    .qr-pill        { @apply inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap; }
    .qr-pill-low    { @apply bg-success/10 text-success ring-1 ring-success/30; }
    .qr-pill-med    { @apply bg-warning/15 text-warning ring-1 ring-warning/30; }
    .qr-pill-high   { @apply bg-critical/10 text-critical ring-1 ring-critical/35; }
    .qr-pill-crit   { @apply bg-critical text-critical-foreground; }
    .qr-pill-muted  { @apply bg-muted text-foreground/75 ring-1 ring-border/60; }

    /* ── Buttons / triggers ─────────────────────────────────────────── */
    .qr-icon-btn {
        @apply inline-flex h-7 w-7 items-center justify-center rounded-md
               text-[11px] font-semibold text-muted-foreground
               border border-transparent hover:bg-muted hover:text-foreground
               focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-1
               transition-colors duration-150;
    }
    .qr-link-btn {
        @apply inline-flex items-center gap-1 rounded-md px-2 py-1
               text-[11.5px] font-semibold text-brand-ink
               border border-brand-soft bg-brand-soft hover:bg-brand-soft/70
               focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-1;
    }
    .qr-empty {
        @apply rounded-lg border border-dashed border-border/70 bg-muted/20
               py-10 text-center text-muted-foreground text-[12.5px];
    }

    /* ── Chart card ─────────────────────────────────────────────────── */
    .qr-chart-wrap {
        @apply relative w-full overflow-x-hidden overflow-y-auto;
        min-height: 280px; max-height: 540px;
    }
    .qr-chart-skel {
        @apply rounded-lg bg-muted/25 animate-pulse;
        height: 320px;
    }

    /* ── Live data progress bar (replaces spinner) ──────────────────── */
    .qr-progress {
        @apply fixed inset-x-0 top-0 h-[2px] z-[60] overflow-hidden pointer-events-none;
    }
    .qr-progress::after {
        content: ''; @apply absolute inset-y-0 left-0 bg-brand;
        width: 35%; animation: qr-slide 1.1s ease-in-out infinite;
    }
    @keyframes qr-slide {
        0%   { transform: translateX(-100%); }
        100% { transform: translateX(320%); }
    }
    @media (prefers-reduced-motion: reduce) {
        .qr-progress::after, .qr-kpi-skel, .qr-chart-skel { animation: none !important; }
    }

    /* ── Explain modal ──────────────────────────────────────────────── */
    .qr-modal-bg    { @apply fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm; }
    .qr-modal-shell { @apply fixed inset-0 z-[81] flex items-stretch justify-stretch
                            p-0 sm:p-8 sm:items-center sm:justify-center; }
    .qr-modal       { @apply relative w-full sm:max-w-3xl max-h-[100dvh] sm:max-h-[88dvh]
                            flex flex-col bg-card border border-border shadow-elevation-5
                            sm:rounded-xl overflow-hidden; }
    .qr-modal-head  { @apply flex items-center justify-between gap-3 px-5 py-3 border-b border-border/70; }
    .qr-modal-body  { @apply flex-1 overflow-auto px-5 py-4 text-[13px] leading-relaxed; }
    .qr-modal-foot  { @apply flex items-center justify-end gap-2 px-5 py-3 border-t border-border/70 bg-muted/30; }
    .qr-modal-section { @apply space-y-1; }
    .qr-modal-section p:first-child { @apply text-[11px] font-semibold uppercase tracking-[.12em] text-muted-foreground; }
</style>
@endpush

@section('content')
<div x-data="qrSuspected()" x-init="boot()"
     class="qr-stack"
     :aria-busy="loading ? 'true' : 'false'">

    {{-- Live progress strip — no spinner. --}}
    <div class="qr-progress" x-show="loading" x-cloak></div>

    {{-- ──────────────── HEADER ──────────────── --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Quick Reports · qr-suspected</p>
            <h1 class="text-[20px] font-semibold tracking-tight">Suspected Cases</h1>
            <p class="help-text mt-1" x-text="headline()">
                Who we suspect, what we suspect, and how risky they are.
            </p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2" aria-label="Report metadata">
            <span class="topbar-chip" x-show="ready" x-cloak>
                <span class="status-dot status-dot-live"></span>
                <span x-text="payload.window?.label || ''"></span>
            </span>
            <span class="topbar-chip" x-show="ready && payload.scope?.label" x-cloak x-text="payload.scope?.label"></span>
            <button type="button" class="btn btn-outline btn-xs" @click="exportCsvFull()" :disabled="!ready" aria-label="Export full CSV">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12m0 0l-4-4m4 4l4-4M5 21h14"/></svg>
                Export
            </button>
        </div>
    </section>

    {{-- ──────────────── FILTERS ──────────────── --}}
    <section class="qr-card">
        <div class="qr-card-head">
            <div class="flex items-center gap-2 min-w-0">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4 text-muted-foreground shrink-0"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
                <span class="qr-card-title">Filters</span>
                <span class="qr-card-sub truncate" x-text="filtersSummary()"></span>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" class="btn btn-ghost btn-xs text-muted-foreground" @click="resetFilters()">Reset</button>
                <button type="button" class="btn btn-brand btn-xs" @click="loadData()">Apply</button>
            </div>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 p-4">
            <div>
                <label class="label block mb-1 text-[11px]" for="qr-f-days">Window</label>
                <select id="qr-f-days" class="select" x-model="filters.days">
                    <option value="7">Past 7 days</option>
                    <option value="14">Past 14 days</option>
                    <option value="30">Past 30 days</option>
                    <option value="60">Past 60 days</option>
                    <option value="90">Past 90 days</option>
                </select>
            </div>
            <div>
                <label class="label block mb-1 text-[11px]" for="qr-f-poe">Point of entry</label>
                <select id="qr-f-poe" class="select" x-model="filters.poe">
                    <option value="">All entry points</option>
                    <template x-for="(name, code) in (payload.meta?.poes || {})" :key="code">
                        <option :value="code" x-text="name"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1 text-[11px]" for="qr-f-risk">Risk level</label>
                <select id="qr-f-risk" class="select" x-model="filters.risk">
                    <option value="">All risk levels</option>
                    <template x-for="r in (payload.meta?.risks || [])" :key="r">
                        <option :value="r" x-text="prettyRisk(r)"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1 text-[11px]" for="qr-f-status">Case status</label>
                <select id="qr-f-status" class="select" x-model="filters.status">
                    <option value="">All statuses</option>
                    <template x-for="s in (payload.meta?.statuses || [])" :key="s">
                        <option :value="s" x-text="prettyStatus(s)"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1 text-[11px]" for="qr-f-sex">Sex</label>
                <select id="qr-f-sex" class="select" x-model="filters.sex">
                    <option value="">All sexes</option>
                    <template x-for="g in (payload.meta?.sexes || [])" :key="g">
                        <option :value="g" x-text="prettySex(g)"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="label block mb-1 text-[11px]" for="qr-f-q">Search traveller</label>
                <input id="qr-f-q" type="text" class="select"
                       placeholder="Name, document, code…"
                       x-model="filters.q"
                       @keydown.enter.prevent="loadData()">
            </div>
        </div>
    </section>

    {{-- ──────────────── KPI ROW ──────────────── --}}
    <section aria-label="Key indicators">
        {{-- Skeletons keep geometry stable during first paint --}}
        <template x-if="!ready">
            <div class="qr-kpi-grid">
                <div class="qr-kpi-skel"></div>
                <div class="qr-kpi-skel"></div>
                <div class="qr-kpi-skel"></div>
                <div class="qr-kpi-skel"></div>
                <div class="qr-kpi-skel"></div>
            </div>
        </template>
        <div class="qr-kpi-grid" x-show="ready" x-cloak>
            <div class="qr-kpi">
                <p class="qr-kpi-label">Suspected cases</p>
                <p class="qr-kpi-value" x-text="fmt(payload.kpis?.total)"></p>
                <p class="qr-kpi-hint" x-text="payload.window?.label ? 'Within ' + payload.window.label : 'Within window'"></p>
            </div>
            <div class="qr-kpi qr-kpi-info">
                <p class="qr-kpi-label">With diagnosis</p>
                <p class="qr-kpi-value" x-text="fmt(payload.kpis?.with_disease)"></p>
                <p class="qr-kpi-hint">At least one disease attached</p>
            </div>
            <div class="qr-kpi" :class="(payload.kpis?.high_risk ?? 0) > 0 && 'qr-kpi-warn'">
                <p class="qr-kpi-label">High / critical risk</p>
                <p class="qr-kpi-value" x-text="fmt(payload.kpis?.high_risk)"></p>
                <p class="qr-kpi-hint">Risk tier HIGH or CRITICAL</p>
            </div>
            <div class="qr-kpi">
                <p class="qr-kpi-label">Still open</p>
                <p class="qr-kpi-value" x-text="fmt(payload.kpis?.open)"></p>
                <p class="qr-kpi-hint">Case status open or in progress</p>
            </div>
            <div class="qr-kpi">
                <p class="qr-kpi-label">In last 24 hours</p>
                <p class="qr-kpi-value" x-text="fmt(payload.kpis?.last_24h)"></p>
                <p class="qr-kpi-hint">Opened since yesterday</p>
            </div>
        </div>
    </section>

    {{-- ──────────────── CHART (single col-12) ──────────────── --}}
    <section class="qr-card" aria-label="Top suspected diseases">
        <div class="qr-card-head">
            <div class="min-w-0">
                <h2 class="qr-card-title" x-text="payload.chart?.title || 'Top suspected diseases'"></h2>
                <p class="qr-card-sub" x-text="chartSub()"></p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" class="qr-icon-btn" @click="openExplain()" title="Explain this chart" aria-label="Explain this chart" x-ref="explainTrigger">?</button>
                <button type="button" class="btn btn-outline btn-xs" @click="exportChartPng()" :disabled="!chartHasData">PNG</button>
                <button type="button" class="btn btn-outline btn-xs" @click="exportChartCsv()" :disabled="!chartHasData">CSV</button>
            </div>
        </div>
        <div class="p-4">
            <template x-if="!ready">
                <div class="qr-chart-skel"></div>
            </template>
            <div class="qr-chart-wrap" x-show="ready && chartHasData" x-cloak>
                <canvas x-ref="chart" role="img" aria-label="Top suspected diseases bar chart"></canvas>
            </div>
            <div class="qr-empty" x-show="ready && !chartHasData" x-cloak>
                <p>No suspected diseases recorded in this window.</p>
                <p class="mt-1 text-[11.5px]">Cases without a diagnostic hypothesis are still listed below.</p>
            </div>
        </div>
    </section>

    {{-- ──────────────── TABLE ──────────────── --}}
    <section class="qr-card overflow-hidden" aria-label="Case register">
        <div class="qr-card-head">
            <div class="min-w-0">
                <h2 class="qr-card-title">Case register</h2>
                <p class="qr-card-sub" x-text="tableSub()"></p>
            </div>
            <div class="flex items-center gap-1.5 shrink-0">
                <button type="button" class="btn btn-outline btn-xs" @click="exportCsvFull()" :disabled="!ready" aria-label="Export full case register">
                    Export full CSV
                </button>
            </div>
        </div>

        <div class="qr-table-wrap">
            <table class="qr-table">
                <thead>
                    <tr>
                        <th class="qr-sortable" :class="sortClass('opened_at')" @click="setSort('opened_at')">
                            Opened <span class="qr-arrow" x-text="sortArrow('opened_at')"></span>
                        </th>
                        <th>Traveller</th>
                        <th>Suspected diseases</th>
                        <th class="qr-sortable" :class="sortClass('risk')" @click="setSort('risk')">
                            Risk <span class="qr-arrow" x-text="sortArrow('risk')"></span>
                        </th>
                        <th class="qr-sortable" :class="sortClass('status')" @click="setSort('status')">
                            Status <span class="qr-arrow" x-text="sortArrow('status')"></span>
                        </th>
                        <th>Point of entry</th>
                        <th>Disposition</th>
                        <th class="text-right pr-3">Case file</th>
                    </tr>
                </thead>
                <tbody>
                    {{-- Skeleton rows during first load --}}
                    <template x-if="!ready">
                        <template x-for="i in 6" :key="i">
                            <tr>
                                <td colspan="8">
                                    <div class="h-5 my-1 rounded bg-muted/30 animate-pulse"></div>
                                </td>
                            </tr>
                        </template>
                    </template>

                    <template x-for="row in sortedRows()" :key="row.id">
                        <tr>
                            <td>
                                <div class="qr-cell-primary" x-text="row.opened_at_label"></div>
                                <div class="qr-cell-mono">#<span x-text="row.id"></span></div>
                            </td>
                            <td>
                                <div class="qr-cell-primary" x-text="row.traveller_name || 'Unknown traveller'"></div>
                                <div class="qr-cell-secondary" x-text="rowDemographics(row)"></div>
                            </td>
                            <td>
                                <template x-if="(row.diseases?.length || 0) > 0">
                                    <div class="flex flex-wrap max-w-[280px]">
                                        <template x-for="(d, i) in (row.diseases || []).slice(0, 3)" :key="i">
                                            <span class="qr-disease" x-text="d"></span>
                                        </template>
                                        <span class="qr-disease-more"
                                              x-show="(row.diseases?.length || 0) > 3"
                                              x-text="`+${(row.diseases.length - 3)} more`"></span>
                                    </div>
                                </template>
                                <span class="qr-no-dx" x-show="(row.diseases?.length || 0) === 0">No diagnosis recorded</span>
                            </td>
                            <td><span :class="riskPill(row.risk)" x-text="prettyRisk(row.risk)"></span></td>
                            <td><span class="qr-pill qr-pill-muted" x-text="prettyStatus(row.status)"></span></td>
                            <td x-text="row.poe_name || '—'"></td>
                            <td x-text="prettyDisposition(row.disposition)"></td>
                            <td class="text-right pr-3">
                                <a :href="row.case_file_url" class="qr-link-btn" aria-label="Open full case file">
                                    View
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 17L17 7M9 7h8v8"/></svg>
                                </a>
                            </td>
                        </tr>
                    </template>

                    <template x-if="ready && (payload.table?.length || 0) === 0">
                        <tr><td colspan="8">
                            <div class="qr-empty my-2">
                                <p>No suspected cases match the current filters.</p>
                                <p class="mt-1 text-[11.5px]">Try widening the window or clearing a filter.</p>
                            </div>
                        </td></tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="qr-card-pad qr-divider flex items-center justify-between">
            <p class="qr-card-sub" x-text="tableFooter()"></p>
            <p class="qr-card-sub">Updated <span x-text="lastLoadedLabel()"></span></p>
        </div>
    </section>

    {{-- ──────────────── EXPLAIN MODAL ──────────────── --}}
    <template x-teleport="body">
        <div x-show="explainOpen" x-cloak>
            <div class="qr-modal-bg" @click="closeExplain()" aria-hidden="true"></div>
            <div class="qr-modal-shell" @keydown.escape.window="closeExplain()">
                <div class="qr-modal" role="dialog" aria-modal="true" aria-labelledby="qr-explain-title" x-trap.noscroll.inert="explainOpen">
                    <div class="qr-modal-head">
                        <div class="min-w-0">
                            <p class="eyebrow">About this chart</p>
                            <h3 id="qr-explain-title" class="text-[15px] font-semibold mt-0.5 truncate" x-text="payload.chart?.title || 'Top suspected diseases'"></h3>
                        </div>
                        <button type="button" class="qr-icon-btn" @click="closeExplain()" aria-label="Close">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <div class="qr-modal-body space-y-5">
                        <div class="qr-modal-section">
                            <p>What this measures</p>
                            <p x-text="explainWhat()"></p>
                        </div>
                        <div class="qr-modal-section">
                            <p>How to read it</p>
                            <p x-text="explainHowToRead()"></p>
                        </div>
                        <div class="qr-modal-section">
                            <p>What to do next</p>
                            <p x-text="explainNext()"></p>
                        </div>
                        <div class="qr-modal-section">
                            <p>Source data</p>
                            <div class="qr-table-wrap mt-2 max-h-[280px]">
                                <table class="qr-table">
                                    <thead>
                                        <tr><th x-text="explainCategoryHeader()"></th><th class="text-right pr-3">Cases</th><th class="text-right pr-3">% share</th></tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(lbl, i) in (payload.chart?.labels || [])" :key="i">
                                            <tr>
                                                <td x-text="lbl"></td>
                                                <td class="text-right pr-3 tabular-nums" x-text="payload.chart.values[i]"></td>
                                                <td class="text-right pr-3 tabular-nums text-muted-foreground" x-text="explainPct(i)"></td>
                                            </tr>
                                        </template>
                                        <template x-if="(payload.chart?.labels?.length || 0) === 0">
                                            <tr><td colspan="3" class="text-center text-muted-foreground py-4">No diagnoses recorded in this window.</td></tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="qr-modal-foot">
                        <button type="button" class="btn btn-outline btn-xs" @click="exportChartCsv()" :disabled="!chartHasData">Download chart CSV</button>
                        <button type="button" class="btn btn-default btn-xs" @click="closeExplain()">Close</button>
                    </div>
                </div>
            </div>
        </div>
    </template>
</div>

@push('scripts')
<script>
    const QR_SUSPECTED = {
        endpointData:   @json(url('/admin/quick-reports/suspected-cases/data')),
        endpointExport: @json(url('/admin/quick-reports/suspected-cases/export')),
        statusLabels: {
            OPEN: 'Open', IN_PROGRESS: 'In progress', DISPOSITIONED: 'Dispositioned',
            CLOSED: 'Closed', REOPENED: 'Reopened', ACKNOWLEDGED: 'Acknowledged',
        },
        sexLabels:  { MALE: 'Male', FEMALE: 'Female', OTHER: 'Other', UNKNOWN: 'Unknown' },
        riskLabels: { LOW: 'Low', MEDIUM: 'Medium', HIGH: 'High', CRITICAL: 'Critical' },
        riskOrder:  { LOW: 1, MEDIUM: 2, HIGH: 3, CRITICAL: 4 },
        statusOrder:{ OPEN: 1, IN_PROGRESS: 2, ACKNOWLEDGED: 3, DISPOSITIONED: 4, REOPENED: 5, CLOSED: 6 },
    };

    function qrSuspected() {
        return {
            ready: false,
            loading: false,
            chartHasData: false,
            explainOpen: false,
            chart: null,
            chartReady: false,    // canvas-render lifecycle (false during destroy)
            lastLoadedAt: null,
            abortCtrl: null,

            filters: {
                days: '7', poe: '', risk: '', status: '', sex: '', q: '',
            },
            sort: { key: 'opened_at', dir: 'desc' },
            payload: {
                window: null, scope: null, kpis: {}, chart: { labels: [], values: [] },
                table: [], table_full: [], total_rows: 0, shown_rows: 0, meta: {},
            },

            // ───── Lifecycle ─────
            boot() {
                this.readFiltersFromUrl();
                this.loadData();
                for (const k of ['days','poe','risk','status','sex']) {
                    this.$watch(`filters.${k}`, () => this.loadData());
                }
                // Wait until Chart.js script tag (deferred) has resolved.
                if (typeof Chart === 'undefined') {
                    const wait = setInterval(() => {
                        if (typeof Chart !== 'undefined') { clearInterval(wait); this.renderChart(); }
                    }, 60);
                }
            },

            readFiltersFromUrl() {
                const u = new URL(window.location.href);
                for (const k of Object.keys(this.filters)) {
                    const v = u.searchParams.get(k);
                    if (v !== null) this.filters[k] = v;
                }
            },

            writeFiltersToUrl() {
                const u = new URL(window.location.href);
                for (const [k, v] of Object.entries(this.filters)) {
                    if (v === '' || v === null || v === undefined) u.searchParams.delete(k);
                    else u.searchParams.set(k, v);
                }
                window.history.replaceState({}, '', u);
            },

            async loadData() {
                this.writeFiltersToUrl();
                if (this.abortCtrl) this.abortCtrl.abort();
                this.abortCtrl = new AbortController();

                const params = new URLSearchParams();
                for (const [k, v] of Object.entries(this.filters)) {
                    if (v !== '' && v !== null && v !== undefined) params.set(k, v);
                }
                this.loading = true;
                try {
                    const res = await fetch(`${QR_SUSPECTED.endpointData}?${params.toString()}`, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        signal: this.abortCtrl.signal,
                    });
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    const body = await res.json();
                    if (!body || !body.success) throw new Error('Bad response');
                    this.payload = body.data || this.payload;
                    this.lastLoadedAt = new Date();
                    this.ready = true;
                    Alpine.store('pageMeta').rows = this.payload.total_rows ?? null;
                    this.$nextTick(() => this.renderChart());
                } catch (e) {
                    if (e.name === 'AbortError') return;     // superseded by a fresh load
                    console.error('[qr-suspected] load failed', e);
                    this.ready = true;
                } finally {
                    this.loading = false;
                }
            },

            resetFilters() {
                this.filters = { days: '7', poe: '', risk: '', status: '', sex: '', q: '' };
                this.loadData();
            },

            // ───── Chart render (in-place update; only destroy when canvas changes) ─────
            renderChart() {
                const labels = this.payload?.chart?.labels || [];
                const values = this.payload?.chart?.values || [];
                const colors = this.payload?.chart?.colors || [];
                this.chartHasData = labels.length > 0;
                if (!this.chartHasData) {
                    if (this.chart) { this.chart.destroy(); this.chart = null; }
                    return;
                }

                const wrap = this.$refs.chart?.parentElement;
                if (!wrap) return;
                const desired = Math.max(280, labels.length * 36 + 60);
                wrap.style.height = Math.min(540, desired) + 'px';

                // Per-bar colours direct from the controller. Darker variants
                // for the border give each bar a crisp Material edge.
                const fillColors   = colors.map((c, i) => c || '#1E88E5');
                const borderColors = fillColors.map(c => this.darken(c, 18));

                if (this.chart) {
                    this.chart.data.labels = labels;
                    this.chart.data.datasets[0].data = values;
                    this.chart.data.datasets[0].backgroundColor = fillColors;
                    this.chart.data.datasets[0].borderColor     = borderColors;
                    this.chart.update('none');
                    return;
                }

                this.chart = new Chart(this.$refs.chart.getContext('2d'), {
                    type: 'bar',
                    data: {
                        labels,
                        datasets: [{
                            label: 'Cases',
                            data: values,
                            backgroundColor: fillColors,
                            borderColor: borderColors,
                            borderWidth: 1.5,
                            borderRadius: 4,
                            barThickness: 'flex',
                            maxBarThickness: 24,
                            hoverBackgroundColor: fillColors.map(c => this.darken(c, 8)),
                        }],
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        animation: { duration: 260 },
                        layout: { padding: { left: 4, right: 28, top: 4, bottom: 4 } },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: { precision: 0, font: { size: 11 }, color: '#475569' },
                                grid: { color: 'rgba(15, 23, 42, .06)', drawBorder: false },
                            },
                            y: {
                                ticks: { autoSkip: false, font: { size: 11.5, weight: '500' }, color: '#0F172A' },
                                grid: { display: false, drawBorder: false },
                            },
                        },
                        plugins: {
                            legend: { display: false },
                            tooltip: {
                                backgroundColor: '#0F172A',
                                titleFont: { weight: '600', size: 12 },
                                bodyFont: { size: 11.5 },
                                padding: 10,
                                cornerRadius: 6,
                                displayColors: true,
                                callbacks: {
                                    title: items => items[0].label,
                                    label: item => {
                                        const n = item.parsed.x;
                                        return `${n.toLocaleString()} ${n === 1 ? 'case' : 'cases'}`;
                                    },
                                },
                            },
                        },
                    },
                });
            },

            /**
             * Darken a hex colour by N% (returns hex). Used to derive crisp
             * bar borders and hover fills from the Material palette.
             */
            darken(hex, pct) {
                const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
                if (!m) return hex;
                const n = parseInt(m[1], 16);
                const r = Math.max(0, Math.round(((n >> 16) & 0xff) * (1 - pct/100)));
                const g = Math.max(0, Math.round(((n >>  8) & 0xff) * (1 - pct/100)));
                const b = Math.max(0, Math.round(( n        & 0xff) * (1 - pct/100)));
                return '#' + [r, g, b].map(v => v.toString(16).padStart(2, '0')).join('');
            },

            // ───── Sort (client-side over the visible 20) ─────
            setSort(key) {
                if (this.sort.key === key) {
                    this.sort.dir = this.sort.dir === 'desc' ? 'asc' : 'desc';
                } else {
                    this.sort.key = key;
                    this.sort.dir = key === 'opened_at' ? 'desc' : 'asc';
                }
            },
            sortClass(k) { return this.sort.key === k ? 'qr-sorted' : ''; },
            sortArrow(k) {
                if (this.sort.key !== k) return '▾';
                return this.sort.dir === 'desc' ? '▼' : '▲';
            },
            sortedRows() {
                const rows = (this.payload.table || []).slice();
                const key  = this.sort.key;
                const dir  = this.sort.dir === 'desc' ? -1 : 1;
                rows.sort((a, b) => {
                    let av, bv;
                    if (key === 'risk')   { av = QR_SUSPECTED.riskOrder[a.risk] || 0;
                                            bv = QR_SUSPECTED.riskOrder[b.risk] || 0; }
                    else if (key === 'status') { av = QR_SUSPECTED.statusOrder[a.status] || 99;
                                                 bv = QR_SUSPECTED.statusOrder[b.status] || 99; }
                    else                  { av = a.opened_at_iso || ''; bv = b.opened_at_iso || ''; }
                    if (av < bv) return -1 * dir;
                    if (av > bv) return  1 * dir;
                    return 0;
                });
                return rows;
            },

            // ───── Display helpers ─────
            fmt(n) { return (n ?? 0).toLocaleString(); },
            prettyStatus(s) { return QR_SUSPECTED.statusLabels[s] || (s || '—'); },
            prettySex(s)    { return QR_SUSPECTED.sexLabels[s]    || (s || '—'); },
            prettyRisk(r)   { return QR_SUSPECTED.riskLabels[r]   || (r || '—'); },
            prettyDisposition(d) {
                if (!d) return '—';
                return d.charAt(0) + d.slice(1).toLowerCase().replaceAll('_', ' ');
            },
            riskPill(r) {
                const base = 'qr-pill';
                if (r === 'CRITICAL') return base + ' qr-pill-crit';
                if (r === 'HIGH')     return base + ' qr-pill-high';
                if (r === 'MEDIUM')   return base + ' qr-pill-med';
                if (r === 'LOW')      return base + ' qr-pill-low';
                return base + ' qr-pill-muted';
            },
            rowDemographics(row) {
                const bits = [];
                if (row.age !== null && row.age !== undefined) bits.push(`${row.age}y`);
                if (row.sex)         bits.push(this.prettySex(row.sex));
                if (row.nationality) bits.push(row.nationality);
                // Alert code intentionally NOT mixed into demographics —
                // it's a system identifier, not a traveller attribute. The
                // case-file link in the action column carries it.
                return bits.join(' · ') || '—';
            },

            // ───── Dynamic copy (re-runs every render) ─────
            headline() {
                if (!this.ready) return 'Loading suspected cases…';
                const n = this.payload.total_rows ?? 0;
                const w = this.payload.window?.label || '';
                const scope = this.payload.scope?.label || '';
                if (n === 0) return `No suspected cases${w ? ' for ' + w : ''}.`;
                const word = n === 1 ? 'case' : 'cases';
                // Window is shown in the topbar chip; subtitle carries count + scope.
                return `${n.toLocaleString()} suspected ${word}${scope ? ' · ' + scope : ''}`;
            },
            chartSub() {
                if (!this.ready) return '';
                // Server emits a kind-aware subtitle; prefer it. Fall back to
                // a count-based summary for older payloads.
                const fromServer = this.payload?.chart?.subtitle;
                if (fromServer) return fromServer;
                const total = (this.payload.chart?.values || []).reduce((s, v) => s + v, 0);
                const wd    = this.payload.kpis?.with_disease ?? 0;
                if (!total) return 'No diagnoses recorded yet — cases are still listed in the register.';
                return `${total.toLocaleString()} disease mentions across ${wd.toLocaleString()} diagnosed ${wd === 1 ? 'case' : 'cases'}.`;
            },
            tableSub() {
                if (!this.ready) return '';
                const t = this.payload.total_rows ?? 0;
                const s = this.payload.shown_rows ?? 0;
                if (t === 0) return 'No cases match the current filters.';
                if (s >= t)  return `All ${t.toLocaleString()} cases shown.`;
                return `${s} most recent of ${t.toLocaleString()} · Export full CSV for the complete set.`;
            },
            tableFooter() { return this.tableSub(); },

            filtersSummary() {
                const f = this.filters;
                const bits = [];
                if (f.days)   bits.push(`past ${f.days} d`);
                if (f.poe)    bits.push(`POE ${f.poe}`);
                if (f.risk)   bits.push(`risk ${f.risk}`);
                if (f.status) bits.push(`status ${this.prettyStatus(f.status).toLowerCase()}`);
                if (f.sex)    bits.push(`sex ${this.prettySex(f.sex).toLowerCase()}`);
                if (f.q)      bits.push(`q "${f.q}"`);
                return bits.length ? '· ' + bits.join(' · ') : '· defaults';
            },

            lastLoadedLabel() {
                if (!this.lastLoadedAt) return '—';
                const pad = n => String(n).padStart(2, '0');
                return `${pad(this.lastLoadedAt.getHours())}:${pad(this.lastLoadedAt.getMinutes())}`;
            },

            explainCategoryHeader() {
                switch (this.payload.chart?.kind) {
                    case 'diseases': return 'Suspected disease';
                    case 'risk':     return 'Risk tier';
                    case 'poe':      return 'Point of entry';
                    case 'day':      return 'Day';
                    default:         return 'Category';
                }
            },
            explainWhat() {
                const w = this.payload.window?.label || 'this window';
                switch (this.payload.chart?.kind) {
                    case 'diseases':
                        return `How often each suspected disease appears across the secondary screenings opened in ${w}. One screening can contribute to more than one disease — clinicians often note two or three differential hypotheses.`;
                    case 'risk':
                        return `How risky the screening officers rated each case in ${w}. Diseases were not yet recorded on these cases, so the chart falls back to the risk dimension.`;
                    case 'poe':
                        return `Where the suspected cases were opened in ${w}. Neither diseases nor risk had been assessed at the time of opening, so the chart falls back to the point-of-entry dimension.`;
                    case 'day':
                        return `When the suspected cases were opened, day by day, across ${w}.`;
                    default:
                        return `There are no suspected cases in ${w}. Widen the date range or clear a filter to see something here.`;
                }
            },
            explainHowToRead() {
                switch (this.payload.chart?.kind) {
                    case 'diseases': return 'Bars are sorted from most-suspected to least. The longer the bar, the more screenings flagged that disease as a possibility. The “Other” bar collects every disease beyond the top 12 so the visible categories stay legible.';
                    case 'risk':     return 'Bars are sorted by risk tier — Low, Medium, High, Critical, then Not assessed. The colour mirrors the urgency: green for Low, deep orange for High, red for Critical.';
                    case 'poe':      return 'Bars are sorted from busiest entry point to quietest. The “Other” bar groups entry points beyond the top 12 so the labels stay readable.';
                    case 'day':      return 'Bars run left-to-right as days. A single tall bar means a cluster; a flat line means steady activity.';
                    default:         return '';
                }
            },
            explainNext() {
                switch (this.payload.chart?.kind) {
                    case 'diseases': return 'The top one or two diseases are where laboratory confirmation and contact-tracing capacity should focus. Click View on any row to open the full case file.';
                    case 'risk':     return 'Critical and High bars demand same-day clinical review. Click any row in the register to read the case file.';
                    case 'poe':      return 'Entry points generating the most suspected cases warrant staffing review and supervisor outreach. Click any row to read the case file.';
                    case 'day':      return 'Day-on-day spikes deserve a quick look — they often precede a confirmed cluster. Click any row to read the case file.';
                    default:         return '';
                }
            },
            explainPct(i) {
                const v = this.payload.chart?.values?.[i] ?? 0;
                const t = (this.payload.chart?.values || []).reduce((s, x) => s + x, 0);
                if (!t) return '—';
                return ((v / t) * 100).toFixed(1) + '%';
            },

            // ───── Explain modal — focus restore on close ─────
            openExplain()  { this.explainOpen = true; },
            closeExplain() {
                this.explainOpen = false;
                this.$nextTick(() => this.$refs.explainTrigger?.focus());
            },

            // ───── Exports ─────
            exportChartPng() {
                if (!this.chart) return;
                const url = this.chart.toBase64Image('image/png', 1.0);
                const a = document.createElement('a');
                a.href = url;
                a.download = `qr-suspected-chart-${this.fileStamp()}.png`;
                document.body.appendChild(a); a.click(); a.remove();
            },
            exportChartCsv() {
                const labels = this.payload?.chart?.labels || [];
                const values = this.payload?.chart?.values || [];
                const lines  = ['Suspected disease,Cases'];
                for (let i = 0; i < labels.length; i++) {
                    const safe = `"${String(labels[i]).replace(/"/g, '""')}"`;
                    lines.push(`${safe},${values[i] ?? 0}`);
                }
                const blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `qr-suspected-chart-${this.fileStamp()}.csv`;
                document.body.appendChild(a); a.click(); a.remove();
                setTimeout(() => URL.revokeObjectURL(a.href), 60_000);
            },
            exportCsvFull() {
                const params = new URLSearchParams();
                for (const [k, v] of Object.entries(this.filters)) {
                    if (v !== '' && v !== null && v !== undefined) params.set(k, v);
                }
                params.set('format', 'CSV');
                window.location.href = `${QR_SUSPECTED.endpointExport}?${params.toString()}`;
            },
            fileStamp() {
                const d = new Date();
                const pad = n => String(n).padStart(2, '0');
                return `${d.getFullYear()}${pad(d.getMonth()+1)}${pad(d.getDate())}-${pad(d.getHours())}${pad(d.getMinutes())}`;
            },
        };
    }
</script>
@endpush
@endsection
