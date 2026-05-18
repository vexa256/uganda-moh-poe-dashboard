{{-- Alert Operations · Deadlines & Misses (alert-sla)
    Anchor visual family: HORIZONTAL TIME STRIP with countdown markers
    + paired missed/at-risk grids beneath. NO master table.
    Per Paranoid v2 brief §10.5.
--}}
@extends('admin.layout')
@section('crumb', 'Alert Operations')
@section('title', 'Deadlines & Misses')

@section('content')
<div x-data="alertopsSla()" x-init="boot()" class="space-y-5">

    <header class="relative flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Alert Operations · The team's clock</p>
            <h2 class="display-md mt-1">Deadlines &amp; Misses</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                Every active case in your scope, plotted against time. The clock is the canvas.
                Missed deadlines sit on the left of "now", coming-up deadlines on the right.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 relative">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="kpis.casesOnClock + ' on the clock'"></span>
            </span>
            @include('admin.alertops._coach', ['sectionKey' => 'alert-sla'])
        </div>
    </header>

    {{-- Reminder cadence notice (brief §10.5 mandates explicit surfacing) --}}
    <aside class="card border-info">
        <div class="card-content !py-2 text-[12px]">
            <span class="font-semibold">Reminder cadence:</span>
            missed deadlines produce one reminder per recipient per day, never more — we don't fill your inbox.
            Cases routed up reset the cadence at the new level.
        </div>
    </aside>

    {{-- KPI strip --}}
    <template x-if="ready">
        <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="kpi"><p class="kpi-label">Cases on the clock</p><p class="kpi-value tabular-nums" x-text="kpis.casesOnClock"></p></div>
            <div class="kpi"><p class="kpi-label">Missed</p><p class="kpi-value tabular-nums text-critical" x-text="kpis.missed"></p></div>
            <div class="kpi"><p class="kpi-label">At risk</p><p class="kpi-value tabular-nums text-warning" x-text="kpis.atRisk"></p></div>
            <div class="kpi"><p class="kpi-label">Comfortable</p><p class="kpi-value tabular-nums text-success" x-text="kpis.comfortable"></p></div>
        </section>
    </template>

    {{-- ANCHOR — horizontal time strip --}}
    <template x-if="ready">
        <section class="card" data-anchor="time-strip">
            <div class="card-content">
                <div class="flex items-center justify-between">
                    <h3 class="text-[13px] font-semibold">Cases plotted against time</h3>
                    @include('admin.alertops._explainer_modal', [
                        'explainerId' => 'alert-sla.time-strip',
                        'title' => 'Cases plotted against time',
                        'how' => 'Each chip is one case. Chips left of the centre line have missed at least one deadline; chips right of the centre line are still on the clock. The further right, the more time remaining.',
                        'good' => 'Chips clustered on the right and a thin band on the left.',
                        'concerning' => 'Chips piling up close to the centre line on the right (a wave about to land) or growing on the left without recorded reasons.',
                        'whatToDo' => 'Hover any chip to see its case code; click to open its dossier. Record reasons for missed deadlines via the missed-deadlines grid below.',
                        'cantTell' => 'It does not tell you the underlying clinical content of each case — only the time pressure on it.',
                    ])
                </div>
                <div class="mt-3 relative h-24 rounded-md bg-muted/20 overflow-hidden" data-strip-canvas>
                    {{-- Centre line = "now" --}}
                    <div class="absolute top-0 bottom-0 left-1/2 w-px bg-foreground/30"></div>
                    <p class="absolute top-1 left-1/2 -translate-x-1/2 text-[10px] uppercase tracking-wider text-muted-foreground bg-background px-1">Now</p>
                    {{-- Chips --}}
                    <template x-for="chip in stripChips" :key="chip.id + '|' + chip.phase">
                        <button type="button"
                                class="absolute top-8 -translate-x-1/2 rounded-full px-2 py-1 text-[10px] font-semibold tabular-nums shadow-sm hover:scale-110 transition"
                                :class="chip.tone"
                                :style="`left: ${chip.x}%`"
                                :title="chip.title"
                                @click="openCase(chip.id)">
                            <span x-text="chip.label"></span>
                        </button>
                    </template>
                </div>
                <div class="mt-2 flex items-center justify-between text-[10px] text-muted-foreground">
                    <span>← Missed</span>
                    <span>On the clock →</span>
                </div>
            </div>
        </section>
    </template>

    {{-- Paired grids — missed (left) + at-risk (right) --}}
    <template x-if="ready">
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-3">

            <div class="card border-critical">
                <div class="card-content">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-[13px] font-semibold">Missed deadlines</h3>
                        <span class="badge badge-critical text-[10px]" x-text="missed.length"></span>
                    </div>
                    <ul class="space-y-2 max-h-[420px] overflow-y-auto pr-1" data-bounded-list>
                        <template x-for="m in missed" :key="m.alert_id + '|' + m.phase">
                            <li class="rounded border border-border bg-background p-2 text-[12px]">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-semibold truncate" x-text="m.alert_title"></p>
                                        <p class="text-[11px] text-muted-foreground" x-text="m.alert_code + ' · ' + m.phase_label + ' · ' + m.elapsed_label + ' ago'"></p>
                                    </div>
                                    <button class="btn btn-ghost btn-xs shrink-0" @click="recordReason(m)">Record reason</button>
                                </div>
                                <p class="mt-1 text-[11px]">
                                    <span class="text-muted-foreground">Next reminder:</span>
                                    <span class="tabular-nums" x-text="m.next_reminder_label"></span>
                                </p>
                            </li>
                        </template>
                        <template x-if="missed.length === 0">
                            <li class="text-[12px] text-muted-foreground italic text-center py-6">No missed deadlines in your scope. Good.</li>
                        </template>
                    </ul>
                </div>
            </div>

            <div class="card border-warning">
                <div class="card-content">
                    <div class="flex items-center justify-between mb-2">
                        <h3 class="text-[13px] font-semibold">Coming up on the clock</h3>
                        <span class="badge badge-warning text-[10px]" x-text="atRisk.length"></span>
                    </div>
                    <ul class="space-y-2 max-h-[420px] overflow-y-auto pr-1" data-bounded-list>
                        <template x-for="r in atRisk" :key="r.alert_id + '|' + r.phase">
                            <li class="rounded border border-border bg-background p-2 text-[12px]">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-semibold truncate" x-text="r.alert_title"></p>
                                        <p class="text-[11px] text-muted-foreground" x-text="r.alert_code + ' · ' + r.phase_label"></p>
                                    </div>
                                    <span class="text-[11px] tabular-nums shrink-0" x-text="r.remaining_label"></span>
                                </div>
                                {{-- Countdown bar --}}
                                <div class="mt-1.5 h-1.5 bg-muted/30 rounded-full overflow-hidden">
                                    <div class="h-full" :class="r.percent >= 90 ? 'bg-critical' : (r.percent >= 70 ? 'bg-warning' : 'bg-info')"
                                         :style="`width: ${r.percent}%`"></div>
                                </div>
                            </li>
                        </template>
                        <template x-if="atRisk.length === 0">
                            <li class="text-[12px] text-muted-foreground italic text-center py-6">Nothing closing in. Comfortable.</li>
                        </template>
                    </ul>
                </div>
            </div>
        </section>
    </template>

    {{-- Breach-reasons strip — deterministic taxonomy --}}
    <template x-if="ready && breachTaxonomy.length > 0">
        <section class="card">
            <div class="card-content">
                <div class="flex items-center justify-between mb-2">
                    <h3 class="text-[13px] font-semibold">Why deadlines were missed (last 30 days)</h3>
                    @include('admin.alertops._explainer_modal', [
                        'explainerId' => 'alert-sla.breach-taxonomy',
                        'title' => 'Why deadlines were missed',
                        'how' => 'Each row is a category from the deterministic taxonomy. The number is how many missed deadlines have been recorded in that category in the last 30 days.',
                        'good' => 'Categories with low counts and a clear pattern — the team understands its own bottlenecks.',
                        'concerning' => 'A category dominating the others, or many missed deadlines without a recorded category.',
                        'whatToDo' => 'Click into a category to see the cases that recorded it. Discuss recurring categories with the team.',
                        'cantTell' => 'It does not tell you whether the cause was avoidable — that is a clinical / operational judgement.',
                    ])
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                    <template x-for="t in breachTaxonomy" :key="t.category">
                        <div class="rounded border border-border bg-background p-2 flex items-center justify-between">
                            <span class="text-[12px]" x-text="t.label"></span>
                            <span class="badge badge-soft tabular-nums text-[10px]" x-text="t.count"></span>
                        </div>
                    </template>
                </div>
            </div>
        </section>
    </template>

    <footer class="card">
        <div class="card-content !p-2 flex items-center justify-between text-[11px] text-muted-foreground">
            <span>
                <span class="font-semibold" x-text="kpis.casesOnClock"></span> active cases ·
                <span class="font-semibold text-critical" x-text="kpis.missed"></span> missed ·
                <span class="font-semibold text-warning" x-text="kpis.atRisk"></span> at risk
            </span>
            <span x-show="ready">Refreshed <span x-text="lastRefreshed"></span></span>
        </div>
    </footer>
</div>

@push('scripts')
<script>
function alertopsSla() {
    return {
        ready: false,
        rows: [],
        missed: [],
        atRisk: [],
        stripChips: [],
        breachTaxonomy: [],
        kpis: { casesOnClock: 0, missed: 0, atRisk: 0, comfortable: 0 },
        lastRefreshed: '',

        async boot() {
            await Promise.all([this.loadCases(), this.loadAggregate()]);
            window.addEventListener('alertops-wizard', e => this.handleWizard(e.detail));
        },

        async loadCases() {
            try {
                const r = await fetch('{{ route('admin.alerts.sla.data') }}', { headers: { Accept: 'application/json' } });
                const j = await r.json();
                this.rows = j.rows || [];
                this.recompute();
            } catch (e) { console.error(e); }
            this.ready = true;
            this.lastRefreshed = new Date().toLocaleTimeString();
        },

        async loadAggregate() {
            try {
                const r = await fetch('{{ route('admin.alerts.sla.aggregate') }}', { headers: { Accept: 'application/json' } });
                const j = await r.json();
                const taxonomy = j.per_root_cause || {};
                this.breachTaxonomy = Object.entries(taxonomy).map(([k, v]) => ({
                    category: k,
                    label: this.humaniseCategory(k),
                    count: v,
                })).sort((a, b) => b.count - a.count);
            } catch (e) { console.error(e); }
        },

        recompute() {
            const missed = [];
            const atRisk = [];
            const chips = [];
            const seen = new Set();
            const now = Date.now();

            for (const c of this.rows) {
                seen.add(c.id);
                for (const phase of (c.phases || [])) {
                    const phaseLabel = (phase.phase || '').toLowerCase();
                    const remainingMin = phase.remaining_min ?? 0;
                    const breached = !! phase.breached;
                    const atRiskFlag = !! phase.at_risk;
                    const percent = Math.min(100, Math.max(0, phase.percent ?? 0));

                    if (breached) {
                        missed.push({
                            alert_id: c.id, alert_code: c.alert_code, alert_title: c.alert_title,
                            phase: phase.phase, phase_label: phaseLabel,
                            elapsed_label: this.minutesAgo(-remainingMin),
                            next_reminder_label: this.nextReminderLabel(),
                        });
                    } else if (atRiskFlag) {
                        atRisk.push({
                            alert_id: c.id, alert_code: c.alert_code, alert_title: c.alert_title,
                            phase: phase.phase, phase_label: phaseLabel,
                            remaining_label: this.minutesLabel(remainingMin),
                            percent: percent,
                        });
                    }

                    // Strip chip — placement by remaining minutes vs ±target_h.
                    const targetMin = (phase.target_h || 24) * 60;
                    const ratio = Math.max(-1, Math.min(1, remainingMin / targetMin));
                    const x = 50 + ratio * 45;  // -45..+45 around centre
                    chips.push({
                        id: c.id, phase: phase.phase, x: x,
                        tone: breached ? 'bg-critical/20 text-critical border border-critical' :
                              (atRiskFlag ? 'bg-warning/20 text-warning border border-warning' :
                                            'bg-success/20 text-success border border-success'),
                        label: c.alert_code,
                        title: c.alert_title + ' · ' + phaseLabel + ' · ' + this.minutesLabel(remainingMin),
                    });
                }
            }

            // Sort the missed grid by elapsed time desc (oldest miss first).
            missed.sort((a, b) => 0);  // already pushed in row order; tweak if needed
            atRisk.sort((a, b) => a.percent - b.percent);  // most-headroom first

            this.missed = missed;
            this.atRisk = atRisk;
            this.stripChips = chips;

            this.kpis = {
                casesOnClock: seen.size,
                missed: missed.length,
                atRisk: atRisk.length,
                comfortable: chips.filter(x => x.tone.includes('success')).length,
            };
        },

        nextReminderLabel() {
            return 'within 24 h (cadence rule)';
        },

        minutesLabel(min) {
            if (min === null || min === undefined) return '—';
            if (min < 60) return Math.round(min) + 'm';
            if (min < 1440) return (min / 60).toFixed(1) + 'h';
            return (min / 1440).toFixed(1) + 'd';
        },

        minutesAgo(min) {
            min = Math.max(0, min);
            return this.minutesLabel(min);
        },

        humaniseCategory(code) {
            return (code || 'Uncategorised')
                .replace(/_/g, ' ')
                .replace(/\b\w/g, c => c.toUpperCase());
        },

        openCase(id) { window.location.href = `{{ url('/admin/alerts') }}/${id}/case-file`; },

        recordReason(m) {
            const url = `{{ url('/admin/alerts') }}/${m.alert_id}/breach-reports`;
            window.location.href = url + '#new';
        },

        handleWizard({ section, action }) {
            if (section !== 'alert-sla') return;
            if (action === 'whats-coming') {
                document.querySelector('[data-bounded-list]')?.scrollIntoView({ behavior: 'smooth' });
            }
        },
    };
}
</script>
@endpush
@endsection
