{{-- Admin · Governance · Retention & PII (gov-retention) --}}
@extends('admin.layout')
@section('crumb', 'Governance')
@section('title', $page_title)

@php
    /** @var array $coach */
    $coach = $coach ?? \App\Support\Governance\CoachManifest::forView('retention');
@endphp

@section('content')

{{-- v4 Governance coach overlay — sibling scope. --}}
@include('admin.governance._partials.coach-overlay', ['coach' => $coach, 'viewKey' => 'retention'])

<div x-data="retentionPage()" x-init="boot()" class="space-y-5">

    <section class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Governance · PII retention</p>
            <h2 class="display-md mt-1">Retention &amp; PII</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-xl">
                <span class="font-mono text-foreground">secondary_screenings</span> is the only PII home.
                Retention clock: <span class="font-semibold" x-text="retentionDays"></span> days
                (≈<span x-text="retentionYears"></span> years).
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" class="btn btn-outline btn-sm" @click="openExport()">Record export…</button>
            <button type="button" class="btn btn-brand btn-sm" @click="loadSummary()">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span class="hidden sm:inline">Refresh</span>
            </button>
        </div>
    </section>

    {{-- KPI strip --}}
    <section class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
        <div class="kpi kpi-glow">
            <p class="kpi-label">PII rows</p>
            <p class="kpi-value tabular-nums" x-text="summary ? summary.totals.total : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">named travellers · active</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Breached retention</p>
            <p class="kpi-value tabular-nums" :class="(summary && summary.totals.breached_retention > 0) ? 'text-critical' : 'text-success'"
               x-text="summary ? summary.totals.breached_retention : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">older than threshold · not voided</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Approaching 90%</p>
            <p class="kpi-value tabular-nums text-warning" x-text="summary ? summary.totals.approaching : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">review before rotation</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Voided rows</p>
            <p class="kpi-value tabular-nums text-muted-foreground" x-text="summary ? summary.totals.voided : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">soft-deleted</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Export log · entries</p>
            <p class="kpi-value tabular-nums" x-text="summary ? summary.export_log.length : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">audit-trail chained</p>
        </div>
    </section>

    {{-- Age histogram + PII coverage donut-ish --}}
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="card xl:col-span-2">
            <div class="card-header !pb-2">
                <p class="card-title">Retention clock · age histogram</p>
                <p class="card-description">Days since <span class="font-mono">created_at</span> · breached threshold flagged critical.</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading"><div class="skeleton h-40 w-full"></div></template>
                <template x-if="!loading && summary && summary.age_buckets.length">
                    <div>
                        <svg :viewBox="'0 0 700 160'" class="w-full h-40" role="img" aria-label="Age histogram">
                            <template x-for="(b, i) in summary.age_buckets" :key="'ab-' + b.key">
                                <g>
                                    <rect :x="i * (700 / summary.age_buckets.length) + 2"
                                          :y="155 - barH(b.n)"
                                          :width="(700 / summary.age_buckets.length) - 6"
                                          :height="barH(b.n)"
                                          :fill="bucketColor(b.key)" rx="2"/>
                                    <text :x="i * (700 / summary.age_buckets.length) + (700 / summary.age_buckets.length)/2"
                                          y="170 - 4" text-anchor="middle" font-size="9"
                                          fill="hsl(var(--muted-foreground))"
                                          x-text="b.key"></text>
                                </g>
                            </template>
                        </svg>
                        <div class="flex flex-wrap items-center gap-4 mt-2 text-[11px] text-muted-foreground">
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-brand"></span> &lt; 1 year</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-info"></span> 1–3y</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-warning"></span> 3–7y</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-critical"></span> &gt; 7y</span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="card">
            <div class="card-header !pb-2">
                <p class="card-title">PII coverage</p>
                <p class="card-description">% of rows that actually carry each sensitive field.</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading"><div class="skeleton h-40 w-full"></div></template>
                <template x-if="!loading && summary">
                    <ul class="space-y-1.5">
                        <template x-for="row in summary.coverage" :key="'pii-' + row.column">
                            <li>
                                <div class="flex justify-between text-[11.5px] mb-1">
                                    <span class="font-mono truncate pr-3" x-text="row.column"></span>
                                    <span class="tabular-nums text-muted-foreground">
                                        <span x-text="row.n"></span><span class="ml-1" x-text="'· ' + row.pct + '%'"></span>
                                    </span>
                                </div>
                                <div class="h-2 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full rounded-full bg-critical"
                                         :style="'width:' + row.pct + '%'"></div>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </div>
    </section>

    {{-- 90d intake trend + nationality breakdown --}}
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="card xl:col-span-2">
            <div class="card-header !pb-2">
                <p class="card-title">New PII rows · last 90 days</p>
                <p class="card-description">Daily create rate — informs how fast the retention clock ticks.</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading"><div class="skeleton h-36 w-full"></div></template>
                <template x-if="!loading && summary">
                    <svg :viewBox="'0 0 900 150'" preserveAspectRatio="none" class="w-full h-36" role="img" aria-label="90-day intake trend">
                        <template x-for="y in [0.25,0.5,0.75]" :key="'rt-gl-' + y">
                            <line :x1="0" :x2="900" :y1="150 - (150 * y)" :y2="150 - (150 * y)"
                                  stroke="hsl(var(--border))" stroke-width="0.5" stroke-dasharray="2 3"/>
                        </template>
                        <path :d="trendPath(summary.trend_90d)" fill="hsl(var(--brand)/0.15)" stroke="hsl(var(--brand))" stroke-width="1.25"/>
                    </svg>
                </template>
            </div>
        </div>
        <div class="card">
            <div class="card-header !pb-2">
                <p class="card-title">Top nationalities</p>
                <p class="card-description">Distribution of passports on file.</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading"><div class="skeleton h-36 w-full"></div></template>
                <template x-if="!loading && summary && summary.by_nation.length">
                    <ul class="space-y-1.5">
                        <template x-for="row in summary.by_nation" :key="'nat-' + row.country_code">
                            <li>
                                <div class="flex justify-between text-[11.5px] mb-1">
                                    <span class="font-mono" x-text="row.country_code"></span>
                                    <span class="tabular-nums text-muted-foreground" x-text="row.n"></span>
                                </div>
                                <div class="h-2 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full rounded-full bg-brand"
                                         :style="'width:' + pctOf(row.n, summary.by_nation[0].n) + '%'"></div>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
                <template x-if="!loading && summary && !summary.by_nation.length">
                    <p class="text-sm text-muted-foreground">No nationality data on file.</p>
                </template>
            </div>
        </div>
    </section>

    {{-- Breached + export log tabs --}}
    <section class="card">
        <div class="card-content !p-0">
            <div class="p-4 sm:p-5 border-b">
                <div class="tabs-list">
                    <template x-for="t in tabs" :key="t.key">
                        <button type="button" class="tabs-trigger"
                                :data-state="activeTab === t.key ? 'active' : 'inactive'"
                                @click="setTab(t.key)">
                            <span x-text="t.label"></span>
                            <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="tabCount(t.key)"></span>
                        </button>
                    </template>
                </div>
            </div>

            <div x-show="activeTab === 'breached'" x-cloak>
                <div class="table-wrap !rounded-none !border-0 border-t-0">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">ID</th>
                                <th class="table-head-th hidden md:table-cell">Anonymous code</th>
                                <th class="table-head-th">Traveller</th>
                                <th class="table-head-th">Days old</th>
                                <th class="table-head-th hidden lg:table-cell">POE</th>
                                <th class="table-head-th hidden md:table-cell">Nationality</th>
                            </tr>
                        </thead>
                        <tbody class="table-body">
                            <template x-if="loadingBreached">
                                <tr><td colspan="6" class="table-cell text-center py-10">
                                    <div class="inline-flex items-center gap-2 text-muted-foreground text-sm">
                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-18 0"/></svg>
                                        Loading breached…
                                    </div>
                                </td></tr>
                            </template>
                            <template x-if="!loadingBreached && breachedRows.length === 0">
                                <tr><td colspan="6" class="table-cell">
                                    <div class="empty-state">
                                        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                        <p class="text-sm font-medium">No rows have breached the retention window.</p>
                                    </div>
                                </td></tr>
                            </template>
                            <template x-for="row in breachedRows" :key="'br-' + row.id">
                                <tr class="table-row">
                                    <td class="table-cell tabular-nums font-mono text-[11px]" x-text="row.id"></td>
                                    <td class="table-cell hidden md:table-cell font-mono text-[11px]" x-text="row.traveler_anonymous_code || '—'"></td>
                                    <td class="table-cell">
                                        <div class="text-[12.5px] font-medium" x-text="row.traveler_full_name || '—'"></div>
                                        <div class="text-[10.5px] text-muted-foreground" x-text="row.client_uuid ? row.client_uuid.slice(0,8) + '…' : ''"></div>
                                    </td>
                                    <td class="table-cell">
                                        <span class="badge badge-critical" x-text="row.days_old + ' days'"></span>
                                    </td>
                                    <td class="table-cell hidden lg:table-cell font-mono text-[11px]" x-text="row.poe_code || row.district_code || '—'"></td>
                                    <td class="table-cell hidden md:table-cell font-mono text-[11px]" x-text="row.traveler_nationality_country_code || '—'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            <div x-show="activeTab === 'export'" x-cloak>
                <div class="table-wrap !rounded-none !border-0 border-t-0">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">When</th>
                                <th class="table-head-th">Actor</th>
                                <th class="table-head-th hidden md:table-cell">Purpose</th>
                                <th class="table-head-th hidden md:table-cell">Recipient</th>
                                <th class="table-head-th text-right">Rows</th>
                                <th class="table-head-th">Source</th>
                            </tr>
                        </thead>
                        <tbody class="table-body">
                            <template x-if="!summary || !summary.export_log.length">
                                <tr><td colspan="6" class="table-cell">
                                    <div class="empty-state">
                                        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                        <p class="text-sm font-medium">No PII export events have been audited yet.</p>
                                    </div>
                                </td></tr>
                            </template>
                            <template x-for="row in (summary?.export_log || [])" :key="'ex-' + row.source + '-' + row.id">
                                <tr class="table-row">
                                    <td class="table-cell font-mono text-[11px] text-muted-foreground" x-text="row.created_at"></td>
                                    <td class="table-cell font-mono text-[11.5px]" x-text="row.user_id ? ('user#' + row.user_id) : '—'"></td>
                                    <td class="table-cell hidden md:table-cell text-[12px] truncate max-w-[24rem]" x-text="row.purpose || '—'"></td>
                                    <td class="table-cell hidden md:table-cell text-[12px] truncate max-w-[16rem]" x-text="row.recipient || '—'"></td>
                                    <td class="table-cell text-right tabular-nums" x-text="row.row_count || '—'"></td>
                                    <td class="table-cell">
                                        <span class="badge badge-outline font-mono" x-text="row.source"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>

    {{-- Record-export dialog --}}
    <template x-if="exportDialog.open">
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-effect="window.adminLock.set('gov-ret-export', exportDialog.open)"
             role="dialog" aria-modal="true" aria-labelledby="gov-ret-export-title">
            <div class="absolute inset-0 bg-black/45" @click="cancelExport()"></div>
            <div class="relative w-full max-w-md rounded-xl border bg-card shadow-elevation-5">
                <header class="p-4 border-b">
                    <p class="eyebrow">Record PII export</p>
                    <p id="gov-ret-export-title" class="text-sm font-semibold mt-0.5">Chain this export to the audit log before the CSV leaves the building.</p>
                </header>
                <div class="p-4 space-y-3">
                    <div>
                        <label for="gov-ret-purpose" class="label">Purpose</label>
                        <textarea id="gov-ret-purpose" class="textarea" rows="2" maxlength="300"
                                  placeholder="IHR annual compliance pack · pp. 14-18"
                                  x-model="exportDialog.form.purpose"></textarea>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="gov-ret-count" class="label">Row count</label>
                            <input id="gov-ret-count" type="number" min="1" class="input"
                                   x-model.number="exportDialog.form.row_count">
                        </div>
                        <div>
                            <label for="gov-ret-recipient" class="label">Recipient</label>
                            <input id="gov-ret-recipient" type="text" maxlength="160" class="input"
                                   placeholder="Dr. Mwansa · Acting MoH director"
                                   x-model="exportDialog.form.recipient">
                        </div>
                    </div>
                    <div>
                        <label for="gov-ret-filter" class="label">Row filter (optional)</label>
                        <input id="gov-ret-filter" type="text" maxlength="300" class="input"
                               placeholder="created_at BETWEEN 2026-01-01 AND 2026-03-31"
                               x-model="exportDialog.form.row_filter">
                    </div>
                </div>
                <footer class="p-3 border-t flex justify-end gap-2">
                    <button type="button" class="btn btn-outline btn-sm" @click="cancelExport()">Cancel</button>
                    <button type="button" class="btn btn-brand btn-sm" :disabled="exportDialog.submitting" @click="confirmExport()">
                        <svg x-show="exportDialog.submitting" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-18 0"/></svg>
                        Chain to audit
                    </button>
                </footer>
            </div>
        </div>
    </template>
</div>

@push('scripts')
<script>
    const GOV_RET_URLS = {
        summary:  @json(route('admin.governance.retention.summary')),
        breached: @json(route('admin.governance.retention.breached')),
        export:   @json(route('admin.governance.retention.exports.record')),
    };

    function retentionPage() {
        return {
            retentionDays: @json($retention_days),
            retentionYears: (@json($retention_days) / 365).toFixed(1),
            loading: true, loadingBreached: false,
            summary: null,
            breachedRows: [],
            activeTab: 'breached',
            tabs: [
                { key: 'breached', label: 'Breached rows' },
                { key: 'export',   label: 'Export log'    },
            ],
            exportDialog: {
                open: false, submitting: false,
                form: { purpose: '', row_count: 1, recipient: '', row_filter: '' },
            },

            async boot() {
                await this.loadSummary();
                await this.loadBreached();
            },

            setTab(k) { this.activeTab = k; },

            async loadSummary() {
                this.loading = true;
                try {
                    const r = await fetch(GOV_RET_URLS.summary, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.summary = j.data;
                } catch (e) {}
                this.loading = false;
            },

            async loadBreached() {
                this.loadingBreached = true;
                try {
                    const r = await fetch(GOV_RET_URLS.breached, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.breachedRows = j.data.rows;
                } catch (e) {}
                this.loadingBreached = false;
            },

            openExport() { this.exportDialog.open = true; },
            cancelExport() { this.exportDialog.open = false; },
            async confirmExport() {
                this.exportDialog.submitting = true;
                try {
                    const r = await fetch(GOV_RET_URLS.export, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        },
                        body: JSON.stringify(this.exportDialog.form),
                    });
                    if (r.ok) {
                        this.exportDialog.open = false;
                        this.exportDialog.form = { purpose: '', row_count: 1, recipient: '', row_filter: '' };
                        this.loadSummary();
                    }
                } catch (e) {}
                this.exportDialog.submitting = false;
            },

            tabCount(k) {
                if (k === 'breached') return this.breachedRows.length;
                if (k === 'export')   return this.summary?.export_log?.length || 0;
                return 0;
            },

            barH(n) {
                const max = Math.max(1, ...(this.summary?.age_buckets || []).map(b => b.n));
                return Math.max(0, Math.min(150, (n / max) * 140));
            },
            bucketColor(key) {
                if (key === '7y+')   return 'hsl(var(--critical))';
                if (key === '3-7y')  return 'hsl(var(--warning))';
                if (key === '1-3y')  return 'hsl(var(--info))';
                return 'hsl(var(--brand))';
            },
            trendPath(points) {
                if (!points || !points.length) return '';
                const w = 900, h = 150;
                const maxV = Math.max(1, ...points.map(p => p.n || 0));
                const step = w / Math.max(points.length - 1, 1);
                const pts = points.map((p, i) => [i * step, h - ((p.n || 0) / maxV) * (h - 2) - 1]);
                const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(2) + ' ' + p[1].toFixed(2)).join(' ');
                return line + ' L ' + w + ' ' + h + ' L 0 ' + h + ' Z';
            },
            pctOf(v, max) { return max > 0 ? Math.round(v / max * 100) : 0; },
        };
    }
</script>
@endpush
@endsection
