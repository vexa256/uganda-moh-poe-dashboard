{{-- Admin · Intelligence · Heatmap & PoEs (intel-geo) --}}
@extends('admin.layout')
@section('crumb', 'Intelligence')
@section('title', $page_title)

@section('content')
<div x-data="geoPage()" x-init="boot()" class="space-y-5">
    <section class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Intelligence · Geospatial</p>
            <h2 class="display-md mt-1">Heatmap &amp; PoEs</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-xl">
                Case density · screening throughput · PoE-to-PoE benchmarking.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <div class="tabs-list">
                <template x-for="opt in windowOpts" :key="'gw-' + opt.d">
                    <button type="button" class="tabs-trigger"
                            :data-state="days === opt.d ? 'active' : 'inactive'"
                            @click="days = opt.d; loadSummary()"
                            x-text="opt.label"></button>
                </template>
            </div>
            <button type="button" class="btn btn-brand btn-sm" @click="loadSummary()">Refresh</button>
        </div>
    </section>

    <section class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-3">
        <div class="kpi kpi-glow">
            <p class="kpi-label">PoEs</p>
            <p class="kpi-value tabular-nums" x-text="summary ? summary.totals.poes : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">active registry</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Silent PoEs</p>
            <p class="kpi-value tabular-nums text-warning" x-text="summary ? summary.totals.silent : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">zero throughput in window</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Primary screenings</p>
            <p class="kpi-value tabular-nums" x-text="summary ? summary.totals.primary : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">throughput</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Secondary screenings</p>
            <p class="kpi-value tabular-nums text-info" x-text="summary ? summary.totals.secondary : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">case density</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Alerts</p>
            <p class="kpi-value tabular-nums text-critical" x-text="summary ? summary.totals.alerts : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">
                <span x-text="summary ? summary.totals.open_alerts : '—'"></span> open
            </p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Conversion rate</p>
            <p class="kpi-value tabular-nums"
               x-text="summary && summary.totals.primary ? ((100 * summary.totals.secondary / summary.totals.primary).toFixed(1) + '%') : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">secondary / primary</p>
        </div>
    </section>

    <section class="card">
        <div class="card-header !pb-2">
            <p class="card-title">Throughput trend</p>
            <p class="card-description">Primary (brand fill) vs secondary (critical dashed).</p>
        </div>
        <div class="card-content !pt-0">
            <template x-if="!loading && summary">
                <div>
                    <svg viewBox="0 0 900 170" preserveAspectRatio="none" class="w-full h-40" role="img" aria-label="Throughput trend">
                        <template x-for="y in [0.25,0.5,0.75]" :key="'g-gl-'+y">
                            <line x1="0" x2="900" :y1="170 - (170 * y)" :y2="170 - (170 * y)"
                                  stroke="hsl(var(--border))" stroke-width="0.5" stroke-dasharray="2 3"/>
                        </template>
                        <path :d="trendLine(summary.trend, 'primary', true)" fill="hsl(var(--brand)/0.15)" stroke="hsl(var(--brand))" stroke-width="1.25"/>
                        <path :d="trendLine(summary.trend, 'secondary', false)" fill="none" stroke="hsl(var(--critical))" stroke-width="1.25" stroke-dasharray="3 2"/>
                    </svg>
                </div>
            </template>
        </div>
    </section>

    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="card xl:col-span-2">
            <div class="card-header !pb-2">
                <p class="card-title">By province</p>
                <p class="card-description">Primary (brand) · secondary (info) · open alerts (critical).</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="!loading && summary && summary.by_province.length">
                    <ul class="space-y-2">
                        <template x-for="row in summary.by_province" :key="'bp-' + row.province">
                            <li>
                                <div class="flex justify-between text-[11.5px] mb-1">
                                    <span class="font-medium"><span x-text="row.province"></span>
                                        <span class="text-muted-foreground ml-1" x-text="'· ' + row.poes + ' PoE'"></span>
                                    </span>
                                    <span class="tabular-nums text-muted-foreground">
                                        <span x-text="row.primary"></span>
                                        <span class="mx-1">·</span>
                                        <span class="text-info" x-text="row.secondary"></span>
                                        <span class="mx-1">·</span>
                                        <span class="text-critical" x-text="row.open_alerts + ' open'"></span>
                                    </span>
                                </div>
                                <div class="h-2.5 rounded-full bg-muted overflow-hidden flex">
                                    <div class="h-full bg-brand"    :style="'width:' + pctOf(row.primary, provinceMax()) + '%'"></div>
                                    <div class="h-full bg-info"     :style="'width:' + pctOf(row.secondary, provinceMax()) + '%'"></div>
                                    <div class="h-full bg-critical" :style="'width:' + pctOf(row.open_alerts, provinceMax()) + '%'"></div>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </div>

        <div class="card">
            <div class="card-header !pb-2">
                <p class="card-title">Transport mix</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="!loading && summary && summary.by_transport.length">
                    <ul class="space-y-1.5">
                        <template x-for="row in summary.by_transport" :key="'bt-' + row.transport">
                            <li>
                                <div class="flex justify-between text-[11.5px] mb-1">
                                    <span class="font-mono" x-text="row.transport"></span>
                                    <span class="tabular-nums text-muted-foreground">
                                        <span x-text="row.primary"></span>
                                        <span class="ml-1 text-[10.5px]" x-text="'(' + row.n + ' PoE)'"></span>
                                    </span>
                                </div>
                                <div class="h-2 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full rounded-full bg-brand"
                                         :style="'width:' + pctOf(row.primary, summary.by_transport[0]?.primary || 1) + '%'"></div>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </div>
    </section>

    <section class="card">
        <div class="card-header !pb-2">
            <p class="card-title">PoE benchmark</p>
            <p class="card-description">Throughput × case density × open alerts × conversion.</p>
        </div>
        <div class="card-content !p-0 overflow-x-auto">
            <table class="table min-w-[900px]">
                <thead class="table-head">
                    <tr>
                        <th class="table-head-th">PoE</th>
                        <th class="table-head-th hidden md:table-cell">Province</th>
                        <th class="table-head-th text-right">Primary</th>
                        <th class="table-head-th text-right">Secondary</th>
                        <th class="table-head-th text-right">Alerts</th>
                        <th class="table-head-th text-right">Open</th>
                        <th class="table-head-th">Conversion</th>
                    </tr>
                </thead>
                <tbody class="table-body">
                    <template x-if="loading">
                        <tr><td colspan="7" class="table-cell text-center py-10">
                            <span class="text-muted-foreground text-sm">Loading…</span>
                        </td></tr>
                    </template>
                    <template x-if="!loading && (summary?.poes || []).length === 0">
                        <tr><td colspan="7" class="table-cell">
                            <div class="empty-state">
                                <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <p class="text-sm font-medium">No PoE activity in window.</p>
                            </div>
                        </td></tr>
                    </template>
                    <template x-for="row in (summary?.poes || [])" :key="'poe-' + row.poe_code">
                        <tr class="table-row">
                            <td class="table-cell">
                                <div class="font-medium text-[12.5px]" x-text="row.poe_name"></div>
                                <div class="font-mono text-[10.5px] text-muted-foreground" x-text="row.poe_code"></div>
                            </td>
                            <td class="table-cell hidden md:table-cell font-mono text-[11.5px]" x-text="row.province || '—'"></td>
                            <td class="table-cell text-right tabular-nums" x-text="row.primary"></td>
                            <td class="table-cell text-right tabular-nums text-info" x-text="row.secondary"></td>
                            <td class="table-cell text-right tabular-nums" x-text="row.alerts"></td>
                            <td class="table-cell text-right tabular-nums text-critical" x-text="row.open_alerts"></td>
                            <td class="table-cell">
                                <div class="flex items-center gap-2">
                                    <div class="h-1.5 w-16 rounded-full bg-muted overflow-hidden">
                                        <div class="h-full rounded-full bg-info" :style="'width:' + Math.min(100, row.conversion_pct) + '%'"></div>
                                    </div>
                                    <span class="tabular-nums text-[11.5px] text-muted-foreground" x-text="row.conversion_pct + '%'"></span>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </section>

    <section class="card" x-show="(summary?.silent || []).length">
        <div class="card-header !pb-2">
            <p class="card-title text-warning">Silent PoEs</p>
            <p class="card-description">No primary or secondary screenings in window.</p>
        </div>
        <div class="card-content !pt-0">
            <ul class="flex flex-wrap gap-1.5">
                <template x-for="p in (summary?.silent || [])" :key="'sil-' + p.poe_code">
                    <li class="badge badge-warning font-mono text-[11px]">
                        <span x-text="p.poe_code"></span>
                        <span class="mx-1 opacity-60">·</span>
                        <span x-text="p.poe_type || '—'"></span>
                    </li>
                </template>
            </ul>
        </div>
    </section>
</div>

@push('scripts')
<script>
    const INTEL_GEO_URLS = { summary: @json(route('admin.intelligence.geo.summary')) };
    function geoPage() {
        return {
            days: 30,
            windowOpts: [{d:7,label:'7d'},{d:30,label:'30d'},{d:90,label:'90d'}],
            loading: true, summary: null,

            async boot() { await this.loadSummary(); },

            async loadSummary() {
                this.loading = true;
                try {
                    const r = await fetch(INTEL_GEO_URLS.summary + '?days=' + this.days, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.summary = j.data;
                } catch (e) {}
                this.loading = false;
            },

            provinceMax() {
                const rows = this.summary?.by_province || [];
                if (!rows.length) return 1;
                return Math.max(1, ...rows.map(r => Math.max(r.primary, r.secondary, r.open_alerts)));
            },

            trendLine(points, key, fill) {
                if (!points || !points.length) return '';
                const w = 900, h = 170;
                const maxV = Math.max(1, ...points.map(p => Math.max(p.primary || 0, p.secondary || 0)));
                const step = w / Math.max(points.length - 1, 1);
                const pts = points.map((p, i) => [i * step, h - ((p[key] || 0) / maxV) * (h - 4) - 2]);
                const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(2) + ' ' + p[1].toFixed(2)).join(' ');
                return fill ? (line + ' L ' + w + ' ' + h + ' L 0 ' + h + ' Z') : line;
            },

            pctOf(v, m) { return m > 0 ? Math.round(v / m * 100) : 0; },
        };
    }
</script>
@endpush
@endsection
