{{-- Admin · Governance · Data Quality (gov-dq) --}}
@extends('admin.layout')
@section('crumb', 'Governance')
@section('title', $page_title)

@php
    /** @var array $coach */
    $coach = $coach ?? \App\Support\Governance\CoachManifest::forView('dq');
@endphp

@section('content')

{{-- v4 Governance coach overlay — sibling scope. --}}
@include('admin.governance._partials.coach-overlay', ['coach' => $coach, 'viewKey' => 'dq'])

<div x-data="dqPage()" x-init="boot()" class="space-y-5">

    <section class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Governance · Data integrity</p>
            <h2 class="display-md mt-1">Data Quality</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-xl">
                Cross-table scorecard · void rates · duplicate <span class="font-mono">client_uuid</span> ·
                late syncs · sync failures · idempotency hits.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <div class="tabs-list">
                <template x-for="opt in windowOptions" :key="opt.d">
                    <button type="button" class="tabs-trigger"
                            :data-state="windowDays === opt.d ? 'active' : 'inactive'"
                            @click="setWindow(opt.d)">
                        <span x-text="opt.label"></span>
                    </button>
                </template>
            </div>
            <button type="button" class="btn btn-brand btn-sm" @click="refresh()">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span class="hidden sm:inline">Refresh</span>
            </button>
        </div>
    </section>

    {{-- KPI strip --}}
    <section class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
        <div class="kpi kpi-glow">
            <p class="kpi-label">Health score</p>
            <div class="flex items-center gap-3 mt-1">
                <div class="ring-gauge h-14 w-14">
                    <svg viewBox="0 0 40 40" class="h-14 w-14 -rotate-90" aria-hidden="true">
                        <circle cx="20" cy="20" r="16" fill="none" stroke="hsl(var(--muted))" stroke-width="4"/>
                        <circle cx="20" cy="20" r="16" fill="none" :stroke="healthColor()"
                                stroke-width="4" stroke-linecap="round"
                                :stroke-dasharray="100.53"
                                :stroke-dashoffset="summary ? (100.53 - (summary.totals.health_score/100) * 100.53) : 100.53"/>
                    </svg>
                    <div class="ring-gauge-label">
                        <span class="text-[11px] font-bold tabular-nums" x-text="summary ? summary.totals.health_score : '—'"></span>
                    </div>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-semibold" x-text="healthLabel()"></p>
                    <p class="text-[11px] text-muted-foreground">composite of all rows</p>
                </div>
            </div>
        </div>
        <div class="kpi">
            <p class="kpi-label">Total rows tracked</p>
            <p class="kpi-value tabular-nums" x-text="summary ? summary.totals.rows : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">mobile-write tables</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Voided</p>
            <p class="kpi-value tabular-nums text-warning" x-text="summary ? summary.totals.voided : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">
                <span x-text="summary ? summary.totals.void_pct + '%' : '—'"></span> of total
            </p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Sync failures</p>
            <div class="flex items-baseline gap-3 mt-1">
                <p class="kpi-value tabular-nums text-critical" x-text="summary ? summary.totals.sync_failed : '—'"></p>
                <span class="text-muted-foreground">·</span>
                <p class="kpi-value tabular-nums text-muted-foreground" x-text="summary ? summary.totals.sync_unsynced : '—'"></p>
            </div>
            <p class="text-[11px] text-muted-foreground mt-1">failed · unsynced</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Duplicate UUIDs</p>
            <p class="kpi-value tabular-nums" :class="(summary && summary.totals.duplicate_uuids > 0) ? 'text-critical' : 'text-success'"
               x-text="summary ? summary.totals.duplicate_uuids : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">should be 0 — uniqueness</p>
        </div>
    </section>

    {{-- Creation vs void trend --}}
    <section class="card">
        <div class="card-header !pb-2">
            <p class="card-title">Creation vs void · trend</p>
            <p class="card-description">Daily created (brand) vs voided (warning) across every mobile-write table.</p>
        </div>
        <div class="card-content !pt-0">
            <template x-if="loading"><div class="skeleton h-40 w-full"></div></template>
            <template x-if="!loading && summary && summary.trend.length">
                <div>
                    <svg :viewBox="'0 0 ' + trendW + ' 170'" preserveAspectRatio="none"
                         class="w-full h-40" role="img" aria-label="Creation vs void trend">
                        <template x-for="y in [0.25, 0.5, 0.75]" :key="'dq-gl-' + y">
                            <line :x1="0" :x2="trendW" :y1="170 - (170 * y)" :y2="170 - (170 * y)"
                                  stroke="hsl(var(--border))" stroke-width="0.5" stroke-dasharray="2 3"/>
                        </template>
                        <path :d="linePath(summary.trend, 'created')" fill="hsl(var(--brand)/0.15)" stroke="hsl(var(--brand))" stroke-width="1.25"/>
                        <path :d="linePath(summary.trend, 'voided', false)" fill="none" stroke="hsl(var(--warning))" stroke-width="1.25" stroke-dasharray="3 2"/>
                    </svg>
                    <div class="flex items-center gap-4 mt-2 text-[11px] text-muted-foreground">
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-brand"></span> Created</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-warning"></span> Voided</span>
                    </div>
                </div>
            </template>
        </div>
    </section>

    {{-- Scorecard grid --}}
    <section class="card">
        <div class="card-header !pb-2">
            <p class="card-title">Per-table scorecard</p>
            <p class="card-description">Click a row to browse stragglers in that table.</p>
        </div>
        <div class="card-content !pt-0 overflow-x-auto">
            <template x-if="loading"><div class="skeleton h-40 w-full"></div></template>
            <template x-if="!loading && summary">
                <table class="table min-w-[900px]">
                    <thead class="table-head">
                        <tr>
                            <th class="table-head-th">Table</th>
                            <th class="table-head-th text-right">Rows</th>
                            <th class="table-head-th">Void</th>
                            <th class="table-head-th">Late sync</th>
                            <th class="table-head-th">Sync fail</th>
                            <th class="table-head-th">Unsynced</th>
                            <th class="table-head-th text-right">Dupe UUID</th>
                            <th class="table-head-th text-right">Idem hits</th>
                            <th class="table-head-th hidden lg:table-cell">Last create</th>
                        </tr>
                    </thead>
                    <tbody class="table-body">
                        <template x-for="row in summary.scorecard" :key="'sc-' + row.table">
                            <tr class="table-row cursor-pointer" @click="setStraggler(row.table)">
                                <td class="table-cell">
                                    <div class="font-mono text-[12.5px]" x-text="row.table"></div>
                                    <div x-show="row.missing" class="text-[10.5px] text-muted-foreground">(not present in this DB)</div>
                                </td>
                                <td class="table-cell text-right tabular-nums" x-text="row.total"></td>
                                <td class="table-cell">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-16 rounded-full bg-muted overflow-hidden">
                                            <div class="h-full rounded-full bg-warning" :style="'width:' + row.void_pct + '%'"></div>
                                        </div>
                                        <span class="tabular-nums text-[11.5px] text-muted-foreground" x-text="row.void_pct + '%'"></span>
                                    </div>
                                </td>
                                <td class="table-cell">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-12 rounded-full bg-muted overflow-hidden">
                                            <div class="h-full rounded-full bg-info" :style="'width:' + row.late_pct + '%'"></div>
                                        </div>
                                        <span class="tabular-nums text-[11.5px] text-muted-foreground" x-text="row.late_syncs"></span>
                                    </div>
                                </td>
                                <td class="table-cell tabular-nums"
                                    :class="row.sync_failed > 0 ? 'text-critical font-semibold' : 'text-muted-foreground'"
                                    x-text="row.sync_failed"></td>
                                <td class="table-cell tabular-nums text-muted-foreground" x-text="row.sync_unsynced"></td>
                                <td class="table-cell text-right tabular-nums"
                                    :class="row.duplicate_uuids > 0 ? 'text-critical font-semibold' : 'text-success'"
                                    x-text="row.duplicate_uuids"></td>
                                <td class="table-cell text-right tabular-nums" x-text="row.idempotency_hits"></td>
                                <td class="table-cell hidden lg:table-cell font-mono text-[11px] text-muted-foreground" x-text="row.last_create_at || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </template>
        </div>
    </section>

    {{-- Stragglers drill-down --}}
    <section class="card">
        <div class="card-header !pb-2">
            <p class="card-title">Stragglers</p>
            <p class="card-description">Sync offenders per table — click any scorecard row above or pick a lens.</p>
        </div>
        <div class="card-content !pt-0">
            <div class="px-4 sm:px-5 pt-4 border-b flex flex-col sm:flex-row gap-3">
                <div class="tabs-list">
                    <template x-for="k in ['FAILED','UNSYNCED','LATE']" :key="'skd-' + k">
                        <button type="button" class="tabs-trigger"
                                :data-state="stragglerKind === k ? 'active' : 'inactive'"
                                @click="stragglerKind = k; loadStragglers()"
                                x-text="k"></button>
                    </template>
                </div>
                <label for="gov-dq-table" class="sr-only">Table</label>
                <select id="gov-dq-table" class="select w-auto !h-8 text-xs"
                        x-model="stragglerTable" @change="loadStragglers()">
                    <template x-for="opt in tableOptions" :key="'so-' + opt"><option :value="opt" x-text="opt"></option></template>
                </select>
            </div>
            <div class="table-wrap !rounded-none !border-0 border-t-0">
                <table class="table">
                    <thead class="table-head">
                        <tr>
                            <th class="table-head-th">ID</th>
                            <th class="table-head-th hidden md:table-cell">client_uuid</th>
                            <th class="table-head-th">Status</th>
                            <th class="table-head-th hidden md:table-cell">Scope</th>
                            <th class="table-head-th hidden md:table-cell">Last sync error</th>
                            <th class="table-head-th">Created</th>
                        </tr>
                    </thead>
                    <tbody class="table-body">
                        <template x-if="loadingStragglers">
                            <tr><td colspan="6" class="table-cell text-center py-10">
                                <div class="inline-flex items-center gap-2 text-muted-foreground text-sm">
                                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-18 0"/></svg>
                                    Loading…
                                </div>
                            </td></tr>
                        </template>
                        <template x-if="!loadingStragglers && stragglerRows.length === 0">
                            <tr><td colspan="6" class="table-cell">
                                <div class="empty-state">
                                    <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                    <p class="text-sm font-medium">No stragglers for this lens.</p>
                                </div>
                            </td></tr>
                        </template>
                        <template x-for="row in stragglerRows" :key="'st-' + row.id">
                            <tr class="table-row">
                                <td class="table-cell tabular-nums font-mono text-[11px]" x-text="row.id"></td>
                                <td class="table-cell hidden md:table-cell font-mono text-[11px] truncate max-w-[12rem]" x-text="row.client_uuid || '—'"></td>
                                <td class="table-cell">
                                    <span class="badge" :class="statusBadge(row.sync_status)" x-text="row.sync_status || '—'"></span>
                                    <span x-show="row.sync_attempt_count" class="ml-1 text-[10.5px] text-muted-foreground tabular-nums" x-text="'×' + row.sync_attempt_count"></span>
                                </td>
                                <td class="table-cell hidden md:table-cell font-mono text-[11px]"
                                    x-text="row.poe_code || row.district_code || row.country_code || '—'"></td>
                                <td class="table-cell hidden md:table-cell text-[11px] text-critical truncate max-w-[22rem]"
                                    x-text="row.last_sync_error || '—'"></td>
                                <td class="table-cell font-mono text-[11px] text-muted-foreground" x-text="row.created_at"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </section>
</div>

@push('scripts')
<script>
    const GOV_DQ_URLS = {
        summary:    @json(route('admin.governance.data-quality.summary')),
        stragglers: @json(route('admin.governance.data-quality.stragglers')),
    };

    function dqPage() {
        return {
            windowDays: 30,
            windowOptions: [
                { d: 7,  label: '7d'  },
                { d: 30, label: '30d' },
                { d: 90, label: '90d' },
            ],
            loading: true, loadingStragglers: false,
            summary: null,
            trendW: 600,
            stragglerRows: [],
            stragglerKind: 'FAILED',
            stragglerTable: 'primary_screenings',
            tableOptions: ['primary_screenings','secondary_screenings','aggregated_submissions','alerts','alert_followups','notifications'],

            async boot() {
                await this.loadSummary();
                await this.loadStragglers();
            },

            setWindow(d) { this.windowDays = d; this.loadSummary(); },

            refresh() { this.loadSummary(); this.loadStragglers(); },

            async loadSummary() {
                this.loading = true;
                try {
                    const r = await fetch(GOV_DQ_URLS.summary + '?days=' + this.windowDays, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) {
                        this.summary = j.data;
                        this.trendW  = Math.max(100, (this.summary.trend?.length || 1) * 10);
                    }
                } catch (e) {}
                this.loading = false;
            },

            async loadStragglers() {
                this.loadingStragglers = true;
                try {
                    const p = new URLSearchParams({ kind: this.stragglerKind, table: this.stragglerTable });
                    const r = await fetch(GOV_DQ_URLS.stragglers + '?' + p.toString(), { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.stragglerRows = j.data.rows;
                } catch (e) {}
                this.loadingStragglers = false;
            },

            setStraggler(table) { this.stragglerTable = table; this.loadStragglers(); },

            linePath(points, key, fill = true) {
                if (!points || !points.length) return '';
                const w = this.trendW, h = 170;
                const maxV = Math.max(1, ...points.map(p => p[key] || 0));
                const step = w / Math.max(points.length - 1, 1);
                const pts = points.map((p, i) => [i * step, h - ((p[key] || 0) / maxV) * (h - 2) - 1]);
                const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(2) + ' ' + p[1].toFixed(2)).join(' ');
                return fill ? (line + ' L ' + w + ' ' + h + ' L 0 ' + h + ' Z') : line;
            },

            healthColor() {
                if (!this.summary) return 'hsl(var(--muted-foreground))';
                const s = this.summary.totals.health_score;
                if (s >= 90) return 'hsl(var(--success))';
                if (s >= 70) return 'hsl(var(--brand))';
                if (s >= 50) return 'hsl(var(--warning))';
                return 'hsl(var(--critical))';
            },
            healthLabel() {
                if (!this.summary) return '—';
                const s = this.summary.totals.health_score;
                if (s >= 90) return 'Excellent';
                if (s >= 70) return 'Healthy';
                if (s >= 50) return 'Watch';
                return 'Action required';
            },
            statusBadge(s) {
                return {
                    'badge-critical': s === 'FAILED',
                    'badge-warning':  s === 'UNSYNCED',
                    'badge-success':  s === 'SYNCED',
                };
            },
        };
    }
</script>
@endpush
@endsection
