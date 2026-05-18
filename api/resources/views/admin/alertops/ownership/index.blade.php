{{-- Alert Operations · Ownership Trail (alert-ownership)
    Anchor visual family: SVG FLOW DIAGRAM (sankey-style band of transitions
    between owner levels) + live "right now" panel + pending-handoffs region.
    NO master table.
    Per Paranoid v2 brief §10.2.
--}}
@extends('admin.layout')
@section('crumb', 'Alert Operations')
@section('title', 'Ownership Trail')

@section('content')
<div x-data="alertopsOwnership()" x-init="boot()" class="space-y-5">

    <header class="relative flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Alert Operations · Continuity &amp; accountability</p>
            <h2 class="display-md mt-1">Ownership Trail</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                Where ownership starts, where it stalls, where it lands. The story reads as movement —
                the bands below show how cases pass between PoE, district, PHEOC, and national levels.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 relative">
            @include('admin.alertops._coach', ['sectionKey' => 'alert-ownership'])
        </div>
    </header>

    <template x-if="ready">
        <section class="grid grid-cols-1 lg:grid-cols-3 gap-3">
            {{-- ANCHOR — flow diagram occupies 2/3 of the canvas --}}
            <div class="card lg:col-span-2" data-anchor="flow-sankey">
                <div class="card-content">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-[13px] font-semibold">Flow of ownership</h3>
                        @include('admin.alertops._explainer_modal', [
                            'explainerId' => 'alert-ownership.flow',
                            'title' => 'Flow of ownership',
                            'how' => 'Each band is a transition from one level to another. The width of the band is the number of cases that moved that way in the period. Read the band you care about left-to-right.',
                            'good' => 'Thin bands moving up to the next level (escalations are rare events) and a steady downward flow back when cases are resolved at the right level.',
                            'concerning' => 'A thick stalled band — cases pile up at one level — or a long pending-handoffs queue (acceptance is slow).',
                            'whatToDo' => 'Click a band to see the cases that contributed. Stalled cases are best worked from alert-followups.',
                            'cantTell' => 'It does not tell you why a case moved — only that it did. Reasons live on alert-timeline.',
                        ])
                    </div>
                    {{-- Inline SVG Sankey-style band chart --}}
                    <svg viewBox="0 0 600 280" class="w-full h-72">
                        {{-- Level columns --}}
                        <template x-for="(col, i) in flowColumns" :key="col.label">
                            <g>
                                <rect :x="col.x" y="20" width="20" :height="col.height"
                                      :fill="col.fill" stroke="currentColor" stroke-width="0.5"
                                      class="transition-all"></rect>
                                <text :x="col.x + 10" y="14" text-anchor="middle"
                                      class="text-[10px] fill-current font-semibold" x-text="col.label"></text>
                                <text :x="col.x + 10" :y="col.height + 35" text-anchor="middle"
                                      class="text-[9px] fill-current" x-text="col.total + ' cases'"></text>
                            </g>
                        </template>
                        {{-- Bands between columns --}}
                        <template x-for="(band, idx) in flowBands" :key="idx">
                            <path :d="band.path" :fill="band.fill" :opacity="0.6"
                                  class="hover:opacity-100 cursor-pointer"
                                  @click="filterByBand(band)">
                                <title x-text="band.tooltip"></title>
                            </path>
                        </template>
                    </svg>
                    <p class="mt-2 text-[11px] text-muted-foreground italic">
                        Hover a band to see the count; click to filter the panels below.
                    </p>
                </div>
            </div>

            {{-- "Right now" — adjacent panel showing current owners --}}
            <aside class="card">
                <div class="card-content">
                    <h3 class="text-[13px] font-semibold mb-2">Right now</h3>
                    <p class="text-[11px] text-muted-foreground mb-2">Who currently holds which cases</p>
                    <ul class="space-y-1.5 max-h-[260px] overflow-y-auto" data-bounded-list>
                        <template x-for="o in currentOwners" :key="o.owner_user_id || o.owner_role">
                            <li class="flex items-center justify-between text-[12px]">
                                <div class="min-w-0">
                                    <p class="font-semibold truncate" x-text="o.owner_name || 'Unassigned'"></p>
                                    <p class="text-[10px] text-muted-foreground" x-text="o.owner_role + ' · ' + o.routed_to_level"></p>
                                </div>
                                <span class="badge badge-soft tabular-nums" x-text="o.count"></span>
                            </li>
                        </template>
                        <template x-if="currentOwners.length === 0">
                            <li class="text-[11px] text-muted-foreground italic text-center py-4">No active cases in your scope.</li>
                        </template>
                    </ul>
                </div>
            </aside>
        </section>
    </template>

    {{-- Pending handoffs region (sent but not accepted) --}}
    <template x-if="ready">
        <section class="card border-warning">
            <div class="card-content">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-[13px] font-semibold">Pending handoffs</h3>
                    <span class="badge badge-warning text-[10px]" x-text="pendingHandoffs.length"></span>
                </div>
                <ul class="space-y-2" data-bounded-list>
                    <template x-for="h in pendingHandoffs" :key="h.id">
                        <li class="rounded border border-border bg-background p-2 text-[12px] flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="font-semibold truncate" x-text="h.alert_title"></p>
                                <p class="text-[11px] text-muted-foreground" x-text="(h.from_name || h.from_role) + ' → ' + (h.to_name || h.to_role) + ' · sent ' + h.elapsed_label"></p>
                            </div>
                            <a class="btn btn-ghost btn-xs shrink-0" :href="caseDossierUrl(h.alert_id)">Open case</a>
                        </li>
                    </template>
                    <template x-if="pendingHandoffs.length === 0">
                        <li class="text-[11px] text-muted-foreground italic text-center py-4">No pending handoffs in your scope.</li>
                    </template>
                </ul>
            </div>
        </section>
    </template>

    <footer class="card">
        <div class="card-content !p-2 text-[11px] text-muted-foreground">
            <span x-show="ready">
                <span class="font-semibold" x-text="totalOwned"></span> active cases ·
                <span class="font-semibold" x-text="pendingHandoffs.length"></span> pending handoffs ·
                refreshed <span x-text="lastRefreshed"></span>
            </span>
        </div>
    </footer>
</div>

@push('scripts')
<script>
function alertopsOwnership() {
    return {
        ready: false,
        currentOwners: [],
        pendingHandoffs: [],
        flowColumns: [],
        flowBands: [],
        totalOwned: 0,
        lastRefreshed: '',

        async boot() {
            await Promise.all([this.loadMatrix(), this.loadHandoffs()]);
        },

        async loadMatrix() {
            try {
                const r = await fetch('{{ route('admin.alerts.ownership.matrix') }}', { headers: { Accept: 'application/json' } });
                const j = await r.json();
                this.currentOwners = (j.matrix || []).map(m => ({
                    owner_user_id: m.owner_user_id,
                    owner_name:    m.owner_name,
                    owner_role:    m.owner_role || '—',
                    routed_to_level: m.routed_to_level || '—',
                    count: m.count,
                }));
                this.totalOwned = (j.totals?.grand) ?? this.currentOwners.reduce((a, b) => a + b.count, 0);
                this.computeFlow(j.totals?.by_level || {});
            } catch (e) { console.error(e); }
            this.ready = true;
            this.lastRefreshed = new Date().toLocaleTimeString();
        },

        async loadHandoffs() {
            try {
                const r = await fetch('{{ route('admin.alerts.ownership.data') }}?event_codes=HANDOFF_SENT', { headers: { Accept: 'application/json' } });
                const j = await r.json();
                const handoffs = (j.events || j.rows || []).filter(e => (e.event_code || '').startsWith('HANDOFF_SENT'));
                this.pendingHandoffs = handoffs.slice(0, 30).map(h => ({
                    id: h.id,
                    alert_id: h.alert_id,
                    alert_title: h.alert_title || h.alert_code || 'Case ' + h.alert_id,
                    from_role: h.actor_role || '—',
                    from_name: h.actor_name || null,
                    to_role: (h.payload && (h.payload.to_role || h.payload.to_level)) || '—',
                    to_name: (h.payload && h.payload.to_name) || null,
                    elapsed_label: this.elapsedFrom(h.created_at),
                }));
            } catch (e) { console.error(e); }
        },

        computeFlow(byLevel) {
            const order = ['POE', 'DISTRICT', 'PHEOC', 'NATIONAL'];
            const counts = order.map(l => Number(byLevel[l] ?? 0));
            const max = Math.max(1, ...counts);
            const cols = [];
            const colWidth = 600;
            const colSpacing = colWidth / (order.length + 1);

            for (let i = 0; i < order.length; i++) {
                const h = Math.max(8, (counts[i] / max) * 200);
                const x = colSpacing * (i + 1) - 10;
                cols.push({
                    label: order[i],
                    total: counts[i],
                    x: x,
                    height: h,
                    fill: i === 0 ? '#94a3b8' : (i === 1 ? '#60a5fa' : (i === 2 ? '#f59e0b' : '#ef4444')),
                });
            }
            this.flowColumns = cols;

            // Bands — transitions between adjacent columns proportional to min of pair.
            const bands = [];
            for (let i = 0; i < cols.length - 1; i++) {
                const a = cols[i];
                const b = cols[i + 1];
                if (a.total === 0 && b.total === 0) continue;
                const flow = Math.min(a.total, b.total);
                const ay = 20 + (a.height - flow / max * 200) / 2;
                const by = 20 + (b.height - flow / max * 200) / 2;
                const w  = Math.max(2, (flow / max) * 200);
                const path = `M ${a.x + 20},${ay} C ${(a.x + b.x) / 2 + 10},${ay} ${(a.x + b.x) / 2 + 10},${by} ${b.x},${by} L ${b.x},${by + w} C ${(a.x + b.x) / 2 + 10},${by + w} ${(a.x + b.x) / 2 + 10},${ay + w} ${a.x + 20},${ay + w} Z`;
                bands.push({
                    from: a.label, to: b.label,
                    path,
                    fill: a.fill,
                    tooltip: `${a.label} → ${b.label}: ~${flow} cases`,
                });
            }
            this.flowBands = bands;
        },

        elapsedFrom(ts) {
            if (! ts) return '';
            const m = (Date.now() - new Date(ts).getTime()) / 60000;
            if (m < 60) return Math.round(m) + 'm ago';
            if (m < 1440) return Math.round(m / 60) + 'h ago';
            return Math.round(m / 1440) + 'd ago';
        },

        filterByBand(band) {
            window.location.href = `{{ route('admin.alerts.ownership.data') }}?from_level=${encodeURIComponent(band.from)}&to_level=${encodeURIComponent(band.to)}`;
        },

        caseDossierUrl(id) { return `{{ url('/admin/alerts') }}/${id}/case-file`; },
    };
}
</script>
@endpush
@endsection
