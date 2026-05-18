{{-- Admin · Intelligence · Disease Ranking (intel-rank) — REBUILT 2026-04-26 --}}
@extends('admin.layout')
@section('crumb', 'Intelligence')
@section('title', $page_title)

@push('head')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
{{--
    Disease Ranking — what signal is the platform picking up, ranked.

    Question: "Which syndromes / diseases are firing right now, and which
    are accelerating fastest vs the previous period?"

    Composition (single screen, no tabs):
      · Compact title bar (Walk-through · About · Export)
      · Window selector (7d · 14d · 30d) + Refresh
      · KPI strip (Total cases · Distinct syndromes · Critical · High)
      · Headliner — Syndrome leaderboard with risk-mix bars + deltas
      · Side-by-side — Trend (Chart.js line) + Confidence bands (donut, only when populated)
      · Footer — Disease drill-down table when secondary_suspected_diseases has rows

    The view degrades gracefully when the intelligence engine has not yet
    populated secondary_suspected_diseases — the syndrome leaderboard always
    has data.
--}}
<div x-data="rankPage()" x-init="boot()"
     x-effect="window.adminLock && window.adminLock.set('intel-rank', tour.open || aboutOpen || exportOpen)"
     class="space-y-4">

    {{-- HEADER --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Intelligence · intel-rank</p>
            <h1 class="text-[18px] font-semibold flex items-center gap-2">
                Disease Ranking
                <button type="button" class="rpt-explain-btn" @click="aboutOpen = true" aria-label="About this view" title="About this view">i</button>
            </h1>
            <p class="help-text mt-0.5">Which syndromes and diseases are firing now, and which are accelerating vs the previous period.</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="summary">
                <span class="status-dot status-dot-live"></span>
                <span x-text="windowLabel()"></span>
            </span>
            <span class="topbar-chip topbar-chip-mono" x-show="summary">
                <span x-text="summary?.buckets[primaryWindow]?.n ?? 0"></span> cases
            </span>
            <span class="topbar-chip" x-show="summary && !summary.engine_populated">
                <span class="status-dot status-dot-warn"></span>
                Engine pending — syndromic only
            </span>
            <button type="button" class="btn btn-soft-brand btn-xs" @click="openTour()">Walk-through</button>
            <button type="button" class="btn btn-outline btn-xs" @click="exportOpen = true">Export</button>
            <button type="button" class="btn btn-brand btn-xs" @click="loadSummary()" :disabled="loading">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3" :class="loading ? 'animate-spin' : ''"><path d="M21 12a9 9 0 11-3-7"/><path d="M21 4v7h-7"/></svg>
                <span x-text="loading ? 'Loading…' : 'Refresh'"></span>
            </button>
        </div>
    </section>

    {{-- WINDOW SELECTOR --}}
    <section class="card">
        <div class="flex items-center justify-between px-4 py-2.5 flex-wrap gap-3">
            <div class="flex items-center gap-2">
                <span class="text-[13px] font-semibold">Rolling window</span>
                <p class="text-[11.5px] text-muted-foreground">All counts compare against the immediately prior period of the same length.</p>
            </div>
            <div class="tabs-list" role="tablist" aria-label="Window selector">
                <template x-for="opt in windowOpts" :key="'w-' + opt.key">
                    <button type="button" class="tabs-trigger"
                            :data-state="primaryWindow === opt.key ? 'active' : null"
                            @click="primaryWindow = opt.key">
                        <span x-text="opt.label"></span>
                    </button>
                </template>
            </div>
        </div>
    </section>

    {{-- KPI STRIP --}}
    <section class="grid grid-cols-2 md:grid-cols-4 gap-2.5" x-show="summary">
        <div class="kpi kpi-glow">
            <span class="kpi-label">Cases · <span x-text="primaryWindow"></span></span>
            <div class="kpi-value tabular-nums" x-text="formatNum(summary?.buckets[primaryWindow]?.n ?? 0)"></div>
            <div class="kpi-delta" :class="deltaClass(summary?.buckets[primaryWindow]?.n ?? 0, summary?.prev_buckets[primaryWindow]?.n ?? 0)">
                <span x-text="deltaStr(summary?.buckets[primaryWindow]?.n ?? 0, summary?.prev_buckets[primaryWindow]?.n ?? 0)"></span>
                <span class="text-muted-foreground">vs prior</span>
            </div>
        </div>
        <div class="kpi">
            <span class="kpi-label">Distinct syndromes</span>
            <div class="kpi-value tabular-nums" x-text="formatNum(summary?.buckets[primaryWindow]?.distinct_syndromes ?? 0)"></div>
            <div class="text-[10.5px] text-muted-foreground">In the <span x-text="primaryWindow"></span> window</div>
        </div>
        <div class="kpi" :class="(summary?.buckets[primaryWindow]?.critical_n ?? 0) > 0 ? '' : 'kpi-glow'">
            <span class="kpi-label">Critical risk</span>
            <div class="kpi-value tabular-nums"
                 :class="(summary?.buckets[primaryWindow]?.critical_n ?? 0) > 0 ? 'text-critical' : ''"
                 x-text="formatNum(summary?.buckets[primaryWindow]?.critical_n ?? 0)"></div>
            <div class="text-[10.5px] text-muted-foreground">Of cases assessed CRITICAL</div>
        </div>
        <div class="kpi">
            <span class="kpi-label">High risk</span>
            <div class="kpi-value tabular-nums" x-text="formatNum(summary?.buckets[primaryWindow]?.high_n ?? 0)"></div>
            <div class="text-[10.5px] text-muted-foreground">Of cases assessed HIGH</div>
        </div>
    </section>

    {{-- COLD STATE --}}
    <template x-if="!loading && !summary">
        <section class="card"><div class="card-content py-10 text-center space-y-3">
            <h2 class="text-[15px] font-semibold">No signal in the window yet</h2>
            <p class="help-text max-w-md mx-auto">There are no secondary screenings on file for this rolling window. Hit Refresh once data starts flowing in from the field.</p>
            <button type="button" class="btn btn-brand btn-sm" @click="loadSummary()">Refresh</button>
        </div></section>
    </template>

    {{-- ============================================================
         HEADLINER · Syndrome leaderboard
       ============================================================ --}}
    <template x-if="summary && (summary.syndromes || []).length > 0">
        <article class="card overflow-hidden">
            <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/60 flex-wrap">
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-semibold">Syndrome leaderboard · 30 days</h2>
                    <span class="badge badge-secondary"><span x-text="(summary.syndromes || []).length"></span> syndromes</span>
                </div>
                <div class="flex items-center gap-1.5">
                    <button type="button" class="btn btn-outline btn-xs" @click="exportSyndromes()">
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
                            <th class="table-head-th">Syndrome</th>
                            <th class="table-head-th text-right">Cases</th>
                            <th class="table-head-th text-right">Δ vs prior 30d</th>
                            <th class="table-head-th">Risk mix</th>
                            <th class="table-head-th text-right">Critical</th>
                            <th class="table-head-th">Priority</th>
                        </tr>
                    </thead>
                    <tbody class="table-body font-mono tabular-nums">
                        <template x-for="(row, idx) in summary.syndromes" :key="row.code">
                            <tr class="table-row" :class="row.is_priority && row.delta_pct >= 50 ? 'bg-critical-soft/40' : ''">
                                <td class="table-cell text-muted-foreground" x-text="idx + 1"></td>
                                <td class="table-cell">
                                    <div class="font-sans font-semibold text-[13px]" x-text="row.display_name"></div>
                                    <div class="font-mono text-[10.5px] text-muted-foreground" x-text="row.code"></div>
                                </td>
                                <td class="table-cell text-right font-semibold" x-text="formatNum(row.n)"></td>
                                <td class="table-cell text-right">
                                    <span class="badge font-mono" :class="deltaBadge(row.delta_pct)"
                                          x-text="(row.delta_pct > 0 ? '+' : '') + row.delta_pct + '%'"></span>
                                </td>
                                <td class="table-cell">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2 w-32 rounded-full bg-muted overflow-hidden flex" role="img"
                                             :aria-label="row.code + ' risk mix: ' + row.critical + ' critical, ' + row.high + ' high, ' + row.medium + ' medium, ' + row.low + ' low'">
                                            <div class="h-full" style="background:hsl(var(--critical))" :style="`background:hsl(var(--critical)); width:${stackPct(row.critical, row.n)}%`"></div>
                                            <div class="h-full" :style="`background:hsl(var(--high)); width:${stackPct(row.high, row.n)}%`"></div>
                                            <div class="h-full" :style="`background:hsl(var(--medium)); width:${stackPct(row.medium, row.n)}%`"></div>
                                            <div class="h-full" :style="`background:hsl(var(--low)); width:${stackPct(row.low, row.n)}%`"></div>
                                        </div>
                                        <span class="text-[11px] tabular-nums text-muted-foreground">
                                            <span class="text-critical" x-text="row.critical"></span>·<span class="text-high" x-text="row.high"></span>·<span class="text-medium" x-text="row.medium"></span>·<span class="text-low" x-text="row.low"></span>
                                        </span>
                                    </div>
                                </td>
                                <td class="table-cell text-right" :class="row.critical > 0 ? 'text-critical font-semibold' : 'text-muted-foreground'" x-text="formatNum(row.critical)"></td>
                                <td class="table-cell">
                                    <span class="badge" :class="row.is_priority ? 'badge-warning' : 'badge-secondary'" x-text="row.is_priority ? 'Priority' : 'Routine'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </article>
    </template>

    {{-- ============================================================
         SUPPORTING STRIP · Trend + Confidence bands (when populated)
       ============================================================ --}}
    <template x-if="summary && (summary.syndromes || []).length > 0">
        <div class="grid grid-cols-12 gap-4">
            {{-- Top-5 trend chart --}}
            <article class="card col-span-12 xl:col-span-8">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Trend</div>
                        <h2 class="text-base font-semibold mt-0.5">Top-5 syndromes · last 30 days</h2>
                        <p class="text-[11.5px] text-muted-foreground mt-0.5">One line per syndrome · daily count from the screener's syndrome_classification.</p>
                    </div>
                    <div class="flex items-center gap-1.5">
                        <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('syndromeTrendChart','syndrome-trend')" aria-label="Download PNG">PNG</button>
                    </div>
                </div>
                <div class="px-3 pb-3">
                    <div class="relative h-[260px]"><canvas x-ref="syndromeTrendChart" id="syndromeTrendChart"></canvas></div>
                </div>
            </article>

            {{-- Confidence bands — only when engine populated --}}
            <article class="card col-span-12 xl:col-span-4" x-show="summary.engine_populated">
                <div class="flex items-center justify-between p-4 pb-2">
                    <div>
                        <div class="eyebrow">Engine confidence</div>
                        <h2 class="text-base font-semibold mt-0.5">Confidence bands · 30d</h2>
                    </div>
                    <button type="button" class="btn btn-ghost btn-xs" @click="downloadChartPng('confidenceBandChart','confidence-bands')" aria-label="Download PNG">PNG</button>
                </div>
                <div class="px-3 pb-3">
                    <div class="relative h-[200px]"><canvas x-ref="confidenceBandChart" id="confidenceBandChart"></canvas></div>
                    <div class="space-y-1.5 mt-3 text-[12.5px]">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm" style="background:hsl(var(--success))"></span><span>HIGH ≥ 80</span></div>
                            <span class="font-mono tabular-nums font-semibold" x-text="formatNum(summary.bands.high)"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm" style="background:hsl(var(--warning))"></span><span>MEDIUM 50-79</span></div>
                            <span class="font-mono tabular-nums font-semibold" x-text="formatNum(summary.bands.medium)"></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2"><span class="h-2.5 w-2.5 rounded-sm" style="background:hsl(var(--critical))"></span><span>LOW &lt; 50</span></div>
                            <span class="font-mono tabular-nums font-semibold" x-text="formatNum(summary.bands.low)"></span>
                        </div>
                    </div>
                </div>
            </article>

            {{-- "Engine pending" placeholder when SSD is empty --}}
            <article class="card col-span-12 xl:col-span-4" x-show="!summary.engine_populated">
                <div class="card-content py-6 text-center space-y-2">
                    <div class="eyebrow">Engine status</div>
                    <p class="text-[13px] font-semibold">Confidence bands not available</p>
                    <p class="text-[11.5px] text-muted-foreground max-w-xs mx-auto">
                        The disease-suspicion engine has not yet run for this window.
                        Once it populates <code class="kbd">secondary_suspected_diseases</code>,
                        confidence bands and per-disease ranking will activate here.
                    </p>
                </div>
            </article>
        </div>
    </template>

    {{-- ============================================================
         OPTIONAL · Disease drill-down (only when SSD has rows)
       ============================================================ --}}
    <template x-if="summary && summary.engine_populated && (summary.diseases || []).length > 0">
        <article class="card overflow-hidden">
            <div class="flex items-center justify-between gap-3 px-4 py-3 border-b border-border/60 flex-wrap">
                <div class="flex items-center gap-2">
                    <h2 class="text-base font-semibold">Disease drill-down · 30 days</h2>
                    <span class="badge badge-secondary"><span x-text="(summary.diseases || []).length"></span> diseases</span>
                </div>
                <button type="button" class="btn btn-outline btn-xs" @click="exportDiseases()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-3 w-3"><path d="M12 3v12m0 0l-4-4m4 4l4-4"/><path d="M5 21h14"/></svg>
                    CSV
                </button>
            </div>
            <div class="overflow-auto max-h-[55vh]">
                <table class="table">
                    <thead class="table-head">
                        <tr>
                            <th class="table-head-th">#</th>
                            <th class="table-head-th">Disease</th>
                            <th class="table-head-th">Tier</th>
                            <th class="table-head-th text-right">Cases</th>
                            <th class="table-head-th">Confidence split</th>
                            <th class="table-head-th text-right">Avg conf</th>
                            <th class="table-head-th text-right">Δ vs prior</th>
                        </tr>
                    </thead>
                    <tbody class="table-body font-mono tabular-nums">
                        <template x-for="(row, idx) in summary.diseases" :key="row.disease_code">
                            <tr class="table-row">
                                <td class="table-cell text-muted-foreground" x-text="idx + 1"></td>
                                <td class="table-cell">
                                    <div class="font-sans font-semibold text-[13px]" x-text="row.display_name"></div>
                                    <div class="font-mono text-[10.5px] text-muted-foreground" x-text="row.disease_code"></div>
                                </td>
                                <td class="table-cell">
                                    <span class="badge" :class="tierBadgeClass(row.ihr_tier)" x-text="tierLabel(row.ihr_tier)"></span>
                                </td>
                                <td class="table-cell text-right font-semibold" x-text="formatNum(row.n)"></td>
                                <td class="table-cell">
                                    <div class="flex items-center gap-2">
                                        <div class="h-2 w-28 rounded-full bg-muted overflow-hidden flex">
                                            <div class="h-full" :style="`background:hsl(var(--success)); width:${stackPct(row.high, row.n)}%`"></div>
                                            <div class="h-full" :style="`background:hsl(var(--warning)); width:${stackPct(row.medium, row.n)}%`"></div>
                                            <div class="h-full" :style="`background:hsl(var(--critical)); width:${stackPct(row.low, row.n)}%`"></div>
                                        </div>
                                        <span class="text-[11px] tabular-nums text-muted-foreground">
                                            <span class="text-success" x-text="row.high"></span>·<span class="text-warning" x-text="row.medium"></span>·<span class="text-critical" x-text="row.low"></span>
                                        </span>
                                    </div>
                                </td>
                                <td class="table-cell text-right" x-text="row.avg_conf ?? '—'"></td>
                                <td class="table-cell text-right">
                                    <span class="badge font-mono" :class="deltaBadge(row.delta_pct)" x-text="(row.delta_pct > 0 ? '+' : '') + row.delta_pct + '%'"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </article>
    </template>

    {{-- =================================================================
         ABOUT modal
       ================================================================= --}}
    <div x-show="aboutOpen" x-cloak class="fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="aboutOpen = false">
        <div class="bg-background w-full sm:max-w-lg sm:rounded-xl border border-border shadow-elevation-5 flex flex-col overflow-hidden max-h-[88vh]" @click.away="aboutOpen = false">
            <header class="px-5 pt-5 pb-3 border-b border-border">
                <span class="badge badge-brand mb-1">About this view</span>
                <h3 class="text-base font-semibold">Disease Ranking</h3>
            </header>
            <div class="overflow-y-auto px-5 py-4 space-y-2.5 text-[13px] leading-relaxed">
                <p><strong>Purpose.</strong> Rank the syndromes (and diseases when the suspicion engine has run) firing across the country in the rolling 7 / 14 / 30-day window, with delta against the immediately prior window of the same length.</p>
                <p><strong>Audience.</strong> National PHEOC analysts and the IHR focal point. Below national, the scoped Situation Room replaces this view.</p>
                <p><strong>Source.</strong> Primary leaderboard reads <code class="kbd">secondary_screenings.syndrome_classification</code>. Disease drill-down reads <code class="kbd">secondary_suspected_diseases</code> (rank_order = 1) joined to <code class="kbd">ref_diseases</code> for display name + IHR tier.</p>
                <p><strong>Engine status.</strong> When <code class="kbd">secondary_suspected_diseases</code> has rows, confidence bands and the disease drill-down activate. Until then, the page shows the syndromic rollup only.</p>
                <p><strong>What it cannot tell you.</strong> Whether the syndrome flag was correct (use Case Confirmation), or geographic concentration (use Heatmap & POEs).</p>
            </div>
            <footer class="px-5 py-3 border-t border-border flex justify-end">
                <button type="button" class="btn btn-default btn-xs" @click="aboutOpen = false">Close</button>
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

    {{-- EXPORT modal --}}
    <div x-show="exportOpen" x-cloak class="fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm flex items-end sm:items-center justify-center"
         @keydown.escape.window="exportOpen = false">
        <div class="bg-background w-full sm:max-w-lg sm:rounded-xl border border-border shadow-elevation-5 flex flex-col overflow-hidden" @click.away="exportOpen = false">
            <header class="px-5 pt-5 pb-3 border-b border-border">
                <h3 class="text-base font-semibold">Export</h3>
                <p class="text-[12px] text-muted-foreground">Pick a format. Window and timestamp are baked into the file footer.</p>
            </header>
            <div class="px-5 py-4 grid grid-cols-2 gap-2">
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportSyndromes()">
                    <div class="font-semibold text-[13px]">CSV — Syndromes</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Leaderboard with risk mix and deltas.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; exportDiseases()" :disabled="!summary?.engine_populated">
                    <div class="font-semibold text-[13px]" :class="summary?.engine_populated ? '' : 'text-muted-foreground'">CSV — Diseases</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Available once the engine has run.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; downloadChartPng('syndromeTrendChart','syndrome-trend')">
                    <div class="font-semibold text-[13px]">PNG — Trend chart</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">High-DPI for slide decks.</p>
                </button>
                <button type="button" class="card p-3 text-left hover:shadow-elevation-3" @click="exportOpen=false; downloadChartPng('confidenceBandChart','confidence-bands')" :disabled="!summary?.engine_populated">
                    <div class="font-semibold text-[13px]" :class="summary?.engine_populated ? '' : 'text-muted-foreground'">PNG — Confidence donut</div>
                    <p class="text-[11.5px] text-muted-foreground mt-0.5">Available once the engine has run.</p>
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
const INTEL_RANK_URLS = { summary: @json(route('admin.intelligence.rank.summary')) };

if (typeof Chart !== 'undefined') { Chart.defaults.animation = false; Chart.defaults.maintainAspectRatio = false; }

function tokenColor(token) {
    try {
        const v = getComputedStyle(document.documentElement).getPropertyValue(token).trim();
        return v ? `hsl(${v})` : '#0EA5E9';
    } catch (e) { return '#0EA5E9'; }
}

function rankPage() {
    return {
        loading: true,
        summary: null,
        primaryWindow: '7d',
        windowOpts: [
            { key: '7d',  label: '7 days'  },
            { key: '14d', label: '14 days' },
            { key: '30d', label: '30 days' },
        ],

        aboutOpen: false,
        exportOpen: false,
        tour: { open: false, step: 1, steps: [
            { t: 'The question this view answers',
              b: '<p>Which syndromes and diseases are firing across the country, and which are accelerating fastest vs the immediately prior period of the same length?</p>' },
            { t: 'Step 1 — KPI strip',
              b: '<p>Total cases, distinct syndromes, critical and high counts. The KPI delta vs prior tells you direction at a glance. Switch the rolling window to compare 7d / 14d / 30d trends.</p>' },
            { t: 'Step 2 — Syndrome leaderboard',
              b: '<p>The headliner. Sorted by case count, with risk-mix bars (critical · high · medium · low). Priority syndromes (VHF, SARI, JAUNDICE, NEUROLOGICAL) are tagged. A row tinted critical-soft is a priority syndrome with a 50%+ jump.</p>' },
            { t: 'Step 3 — Trend chart',
              b: '<p>One line per top-5 syndrome over the last 30 days. Watch the shape — a sharp upturn on the right edge is the signal worth investigating.</p>' },
            { t: 'Step 4 — Disease drill-down',
              b: '<p>This activates only when the disease-suspicion engine has run. Until then, the syndromic leaderboard is your reading.</p>' },
        ]},

        charts: {},

        async boot() {
            await this.loadSummary();
            this.$watch('primaryWindow', () => this.$nextTick(() => this.renderCharts()));
        },

        async loadSummary() {
            this.loading = true;
            try {
                const r = await fetch(INTEL_RANK_URLS.summary, {
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });
                const j = await r.json();
                if (j.ok) {
                    this.summary = j.data;
                    this.$nextTick(() => this.renderCharts());
                    Alpine.store('pageMeta', { rows: this.summary.buckets[this.primaryWindow]?.n ?? 0, version: null, kind: 'intel-rank' });
                }
            } catch (e) { console.error(e); }
            this.loading = false;
        },

        openTour() { this.tour.open = true; this.tour.step = 1; },

        formatNum(v) { return (v == null) ? '—' : Number(v).toLocaleString(); },
        windowLabel() {
            const map = { '7d': 'Last 7 days', '14d': 'Last 14 days', '30d': 'Last 30 days' };
            return map[this.primaryWindow] || this.primaryWindow;
        },

        deltaClass(curr, prev) {
            if (!prev && curr === 0) return 'kpi-delta-flat';
            if (curr > prev) return 'kpi-delta-down';   // more cases is bad-direction here
            if (curr < prev) return 'kpi-delta-up';
            return 'kpi-delta-flat';
        },
        deltaStr(curr, prev) {
            if (!prev) return curr > 0 ? '+ new' : 'flat';
            const d = Math.round(((curr - prev) / prev) * 100);
            return (d > 0 ? '+' : '') + d + '%';
        },
        deltaBadge(d) {
            if (d > 50)  return 'badge-critical';
            if (d > 20)  return 'badge-warning';
            if (d > 0)   return 'badge-medium';
            if (d < -20) return 'badge-success';
            return 'badge-secondary';
        },
        tierBadgeClass(t) {
            return t === 1 ? 'badge-critical'
                 : t === 2 ? 'badge-warning'
                 : t === 3 ? 'badge-info'
                 : 'badge-secondary';
        },
        tierLabel(t) { return t ? ('Tier ' + t) : '—'; },
        stackPct(v, w) { return w > 0 ? Math.max(0, Math.min(100, (v / w) * 100)) : 0; },

        // ── CSV helpers ──────────────────────────────────────────
        csv(v) { const s = v == null ? '' : String(v); return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; },
        stamp() { const d = new Date(); return d.toISOString().slice(0,10) + '-' + d.toTimeString().slice(0,8).replace(/:/g,''); },
        downloadBlob(blob, filename) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url; a.download = filename; document.body.appendChild(a); a.click();
            setTimeout(() => { document.body.removeChild(a); URL.revokeObjectURL(url); }, 250);
        },
        exportSyndromes() {
            const rows = (this.summary?.syndromes || []);
            if (!rows.length) return;
            const headers = ['#', 'Code', 'Display name', 'Cases', 'Δ vs prior 30d %', 'Critical', 'High', 'Medium', 'Low', 'Priority'];
            const lines = [headers.join(',')].concat(rows.map((r, i) => [
                i + 1, this.csv(r.code), this.csv(r.display_name), r.n, r.delta_pct,
                r.critical, r.high, r.medium, r.low, r.is_priority ? 'YES' : 'NO',
            ].join(',')));
            this.downloadBlob(new Blob(["﻿" + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }), 'intel-rank-syndromes-' + this.stamp() + '.csv');
        },
        exportDiseases() {
            const rows = (this.summary?.diseases || []);
            if (!rows.length) return;
            const headers = ['#', 'Code', 'Display name', 'Tier', 'Cases', 'Δ vs prior 30d %', 'Avg conf', 'High', 'Medium', 'Low'];
            const lines = [headers.join(',')].concat(rows.map((r, i) => [
                i + 1, this.csv(r.disease_code), this.csv(r.display_name),
                r.ihr_tier ?? '', r.n, r.delta_pct, r.avg_conf ?? '',
                r.high, r.medium, r.low,
            ].join(',')));
            this.downloadBlob(new Blob(["﻿" + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }), 'intel-rank-diseases-' + this.stamp() + '.csv');
        },
        downloadChartPng(canvasId, slug) {
            const c = document.getElementById(canvasId);
            if (!c) return;
            const stamp = this.stamp();
            const filename = `intel-rank-${slug}-${stamp}.png`;
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
            const lbl = (window.__SCOPE_LABEL__ || 'Scope: National');
            g.fillText(`Disease Ranking · ${slug} · ${lbl} · ${this.windowLabel()} · generated ${stamp}`, 8, c.height + 18);
            out.toBlob(blob => this.downloadBlob(blob, filename), 'image/png');
        },

        // ── Chart rendering ──────────────────────────────────────
        destroyCharts() { Object.values(this.charts).forEach(c => { try { c.destroy(); } catch (e) {} }); this.charts = {}; },
        renderCharts() {
            if (typeof Chart === 'undefined' || !this.summary) return;
            this.destroyCharts();
            requestAnimationFrame(() => {
                this.renderTrend();
                if (this.summary.engine_populated) this.renderConfidenceBands();
            });
        },

        renderTrend() {
            const ref = this.$refs.syndromeTrendChart;
            if (!ref || !this.summary) return;
            const trend = this.summary.syndrome_trend || [];
            const codes = this.summary.top_syndromes || [];
            if (!trend.length || !codes.length) return;

            const labels = trend.map(p => {
                const d = new Date(p.day + 'T00:00:00');
                return (d.getDate()) + ' ' + ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
            });
            const palette = ['--viz-1', '--viz-2', '--viz-3', '--viz-4', '--viz-5'];
            const datasets = codes.map((code, i) => ({
                label: code,
                data: trend.map(p => p[code] || 0),
                borderColor: tokenColor(palette[i % palette.length]),
                backgroundColor: tokenColor(palette[i % palette.length]) + '22',
                tension: 0.3,
                fill: false,
                pointRadius: 2,
                pointHoverRadius: 4,
                borderWidth: 1.5,
            }));
            this.charts.trend = new Chart(ref, {
                type: 'line',
                data: { labels, datasets },
                options: {
                    plugins: {
                        legend: { position: 'bottom', labels: { color: tokenColor('--muted-foreground'), boxWidth: 10 } },
                        tooltip: { mode: 'index', intersect: false },
                    },
                    interaction: { mode: 'nearest', axis: 'x', intersect: false },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: tokenColor('--muted-foreground'), maxTicksLimit: 10 } },
                        y: { beginAtZero: true, ticks: { color: tokenColor('--muted-foreground'), precision: 0 }, grid: { color: tokenColor('--border'), drawBorder: false } },
                    },
                },
            });
        },

        renderConfidenceBands() {
            const ref = this.$refs.confidenceBandChart;
            if (!ref || !this.summary) return;
            const b = this.summary.bands || { high: 0, medium: 0, low: 0 };
            this.charts.confidenceBand = new Chart(ref, {
                type: 'doughnut',
                data: {
                    labels: ['High ≥ 80', 'Medium 50-79', 'Low < 50'],
                    datasets: [{
                        data: [b.high, b.medium, b.low],
                        backgroundColor: [tokenColor('--success'), tokenColor('--warning'), tokenColor('--critical')],
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
    };
}
</script>
@endpush
@endsection
