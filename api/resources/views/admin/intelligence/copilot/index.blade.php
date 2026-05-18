{{-- Admin · Intelligence · Copilot (intel-copilot) --}}
@extends('admin.layout')
@section('crumb', 'Intelligence')
@section('title', $page_title)

@section('content')
<div x-data="copilotPage()" x-init="boot()" class="space-y-5">
    <section class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Intelligence · Deterministic copilot</p>
            <h2 class="display-md mt-1">Copilot</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-xl">
                Next-best-action recommendations + alert narrations · zero external LLM ·
                backed by <span class="font-mono">ref_engine_config</span> + operational aggregates.
            </p>
        </div>
        <button type="button" class="btn btn-brand btn-sm" @click="loadSummary()">Refresh</button>
    </section>

    <section class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="kpi kpi-glow">
            <p class="kpi-label">Open alerts</p>
            <p class="kpi-value tabular-nums" x-text="summary ? summary.totals.open_alerts : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">OPEN / ACKNOWLEDGED</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Tier-1 open</p>
            <p class="kpi-value tabular-nums text-critical" x-text="summary ? summary.totals.tier1_open : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">single-case IHR 24h</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Stuck &gt; 72h</p>
            <p class="kpi-value tabular-nums text-warning" x-text="summary ? summary.totals.stuck_alerts_72h : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">cross-ref intel-trip</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">High-risk users</p>
            <p class="kpi-value tabular-nums" x-text="summary ? summary.totals.high_risk_users : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">risk_score ≥ 80</p>
        </div>
    </section>

    {{-- Recommendations --}}
    <section class="card">
        <div class="card-header !pb-2">
            <p class="card-title">Next-best actions</p>
            <p class="card-description">Ranked recommendations for your scope.</p>
        </div>
        <div class="card-content !pt-0">
            <template x-if="loading"><div class="skeleton h-28 w-full"></div></template>
            <template x-if="!loading && (summary?.recommendations || []).length === 0">
                <div class="empty-state">
                    <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                    <p class="text-sm font-medium">No outstanding actions — inbox clean.</p>
                </div>
            </template>
            <template x-if="!loading && (summary?.recommendations || []).length">
                <ul class="space-y-2.5">
                    <template x-for="(rec, idx) in summary.recommendations" :key="'rec-' + idx">
                        <li class="card !shadow-elevation-0 !border !border-border/60">
                            <div class="card-content !p-3 flex items-start gap-3">
                                <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full font-mono text-[11px]"
                                      :class="priorityBadge(rec.priority)" x-text="idx + 1"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[13px] font-semibold" x-text="rec.title || rec.label || 'Action'"></p>
                                    <p class="text-[12px] text-muted-foreground mt-0.5" x-text="rec.detail || rec.rationale || ''"></p>
                                    <div class="mt-2 flex flex-wrap gap-1.5 text-[10.5px]">
                                        <template x-if="rec.route">
                                            <a :href="rec.route" class="badge badge-brand font-mono hover:underline" x-text="rec.route"></a>
                                        </template>
                                        <template x-if="rec.priority">
                                            <span class="badge font-mono" :class="priorityBadge(rec.priority)" x-text="rec.priority"></span>
                                        </template>
                                        <template x-if="rec.category">
                                            <span class="badge badge-outline font-mono" x-text="rec.category"></span>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </li>
                    </template>
                </ul>
            </template>
        </div>
    </section>

    {{-- Triage brief + rules --}}
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="card xl:col-span-2">
            <div class="card-header !pb-2">
                <p class="card-title">National triage brief</p>
                <p class="card-description">Deterministic narrative summary for the IHR Focal Point.</p>
            </div>
            <div class="card-content !pt-0 space-y-3 text-[12.5px]">
                <template x-if="loading"><div class="skeleton h-20 w-full"></div></template>
                <template x-if="!loading && summary?.triage_brief">
                    <div>
                        <p x-show="summary.triage_brief.headline" class="text-[13px] font-semibold" x-text="summary.triage_brief.headline"></p>
                        <p x-show="summary.triage_brief.narrative" class="text-muted-foreground whitespace-pre-line mt-2" x-text="summary.triage_brief.narrative"></p>
                        <template x-if="summary.triage_brief.highlights && summary.triage_brief.highlights.length">
                            <ul class="mt-3 grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-1 list-disc pl-5">
                                <template x-for="(h, i) in summary.triage_brief.highlights" :key="'hl-' + i">
                                    <li x-text="h"></li>
                                </template>
                            </ul>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        <div class="card">
            <div class="card-header !pb-2">
                <p class="card-title">Copilot rules</p>
                <p class="card-description">ref_engine_config keys wired to the copilot.</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="!loading && (summary?.rules || []).length === 0">
                    <p class="text-sm text-muted-foreground">No copilot-scoped rules in this environment.</p>
                </template>
                <template x-if="!loading && (summary?.rules || []).length">
                    <ul class="space-y-1.5">
                        <template x-for="r in (summary?.rules || [])" :key="'cr-' + r.id">
                            <li class="flex justify-between items-start gap-2 text-[11.5px]">
                                <div class="min-w-0 flex-1">
                                    <p class="font-mono truncate" x-text="r.config_key"></p>
                                    <p class="text-[10.5px] text-muted-foreground truncate" x-text="r.section || '—'"></p>
                                </div>
                                <span class="badge shrink-0" :class="r.is_active ? 'badge-success' : 'badge-outline'"
                                      x-text="r.is_active ? 'ON' : 'OFF'"></span>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </div>
    </section>

    {{-- Ask --}}
    <section class="card">
        <div class="card-header !pb-2">
            <p class="card-title">Ask the copilot</p>
            <p class="card-description">Deterministic Q&amp;A · returns reply + cited sources + suggested actions.</p>
        </div>
        <div class="card-content !pt-0 space-y-3">
            <div class="flex flex-col md:flex-row gap-2">
                <label for="copilot-ask" class="sr-only">Question</label>
                <input id="copilot-ask" type="text" class="input flex-1"
                       placeholder="e.g. Why is alert ALT-2026-04-24-0019 stuck?"
                       maxlength="500"
                       x-model="ask.question"
                       @keydown.enter.prevent="submitAsk()">
                <button type="button" class="btn btn-brand btn-sm"
                        :disabled="! ask.question || ask.submitting"
                        @click="submitAsk()">
                    <svg x-show="ask.submitting" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-18 0"/></svg>
                    Ask
                </button>
            </div>
            <template x-if="ask.response">
                <div class="card !p-4 space-y-3">
                    <p class="text-[13px]" x-text="ask.response.reply || '—'"></p>
                    <div x-show="(ask.response.actions || []).length">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Actions</p>
                        <ul class="list-disc pl-5 text-[12px] space-y-0.5">
                            <template x-for="(a, i) in (ask.response.actions || [])" :key="'aa-' + i">
                                <li x-text="typeof a === 'string' ? a : (a.label || JSON.stringify(a))"></li>
                            </template>
                        </ul>
                    </div>
                    <div x-show="(ask.response.sources || []).length">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Sources</p>
                        <ul class="flex flex-wrap gap-1">
                            <template x-for="(s, i) in (ask.response.sources || [])" :key="'as-' + i">
                                <li class="badge badge-outline font-mono text-[10.5px]"
                                    x-text="typeof s === 'string' ? s : (s.label || s.name || JSON.stringify(s))"></li>
                            </template>
                        </ul>
                    </div>
                    <div x-show="(ask.response.reasoning || []).length">
                        <details>
                            <summary class="cursor-pointer text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Reasoning chain</summary>
                            <ol class="list-decimal pl-5 text-[11.5px] text-muted-foreground mt-1 space-y-0.5">
                                <template x-for="(r, i) in (ask.response.reasoning || [])" :key="'ar-' + i">
                                    <li x-text="typeof r === 'string' ? r : JSON.stringify(r)"></li>
                                </template>
                            </ol>
                        </details>
                    </div>
                </div>
            </template>
        </div>
    </section>

    {{-- Alert list + narrator --}}
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="card xl:col-span-1">
            <div class="card-header !pb-2">
                <p class="card-title">Open alerts</p>
                <p class="card-description">Click to narrate.</p>
            </div>
            <div class="card-content !pt-0 max-h-[28rem] overflow-y-auto">
                <template x-if="!loading && (summary?.alerts || []).length === 0">
                    <p class="text-sm text-muted-foreground">No open alerts.</p>
                </template>
                <ul class="space-y-1.5">
                    <template x-for="a in (summary?.alerts || [])" :key="'al-' + a.id">
                        <li>
                            <button type="button" class="w-full text-left card !shadow-elevation-0 !border !border-border/60 !p-3 hover:!border-brand/60"
                                    :class="selectedAlertId === a.id ? '!border-brand' : ''"
                                    @click="selectAlert(a.id)">
                                <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <p class="font-mono text-[11.5px] truncate" x-text="a.alert_code || ('#' + a.id)"></p>
                                        <p class="text-[12.5px] font-medium truncate" x-text="a.alert_title || '—'"></p>
                                    </div>
                                    <span class="badge font-mono shrink-0" :class="riskBadge(a.risk_level)" x-text="a.risk_level || '—'"></span>
                                </div>
                                <div class="mt-1 text-[10.5px] text-muted-foreground">
                                    <span x-text="a.poe_code || a.district_code || '—'"></span>
                                    <span class="mx-1">·</span>
                                    <span x-text="a.status"></span>
                                </div>
                            </button>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
        <div class="card xl:col-span-2">
            <div class="card-header !pb-2">
                <p class="card-title">Narrative + differentials</p>
                <p class="card-description">Pick an alert on the left.</p>
            </div>
            <div class="card-content !pt-0 space-y-3">
                <template x-if="! selectedAlertId">
                    <p class="text-sm text-muted-foreground">Select an alert to narrate.</p>
                </template>
                <template x-if="narrate.loading">
                    <div class="skeleton h-32 w-full"></div>
                </template>
                <template x-if="narrate.data && ! narrate.loading">
                    <div class="space-y-3">
                        <div class="card !p-3">
                            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Narrative</p>
                            <p class="text-[13px] whitespace-pre-line"
                               x-text="renderNarrative(narrate.data.narrative)"></p>
                        </div>
                        <template x-if="(narrate.data.differentials || []).length">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Ranked differentials</p>
                                <ul class="space-y-1.5">
                                    <template x-for="d in (narrate.data.differentials || [])" :key="'df-' + (d.disease_code || d.code)">
                                        <li>
                                            <div class="flex justify-between text-[11.5px] mb-1">
                                                <span class="font-mono truncate pr-3" x-text="d.display_name || d.disease_code || d.code"></span>
                                                <span class="tabular-nums text-muted-foreground"
                                                      x-text="(d.confidence ?? d.score ?? 0) + '%'"></span>
                                            </div>
                                            <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                                                <div class="h-full rounded-full bg-brand"
                                                     :style="'width:' + Math.max(0, Math.min(100, d.confidence ?? d.score ?? 0)) + '%'"></div>
                                            </div>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <template x-if="narrate.data.close_reason">
                                <div class="card !p-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Suggested close reason</p>
                                    <p class="text-[12.5px]" x-text="renderNarrative(narrate.data.close_reason)"></p>
                                </div>
                            </template>
                            <template x-if="narrate.data.escalation">
                                <div class="card !p-3">
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Escalation rationale</p>
                                    <p class="text-[12.5px]" x-text="renderNarrative(narrate.data.escalation)"></p>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
    </section>
</div>

@push('scripts')
<script>
    const INTEL_COPILOT_URLS = {
        summary: @json(route('admin.intelligence.copilot.summary')),
        narrate: @json(url('/admin/intelligence/copilot/alerts')),
        ask:     @json(route('admin.intelligence.copilot.ask')),
    };

    function copilotPage() {
        return {
            loading: true, summary: null,
            selectedAlertId: null,
            narrate: { loading: false, data: null },
            ask: { question: '', submitting: false, response: null },

            async boot() { await this.loadSummary(); },

            async loadSummary() {
                this.loading = true;
                try {
                    const r = await fetch(INTEL_COPILOT_URLS.summary, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.summary = j.data;
                } catch (e) {}
                this.loading = false;
            },

            async selectAlert(id) {
                this.selectedAlertId = id;
                this.narrate = { loading: true, data: null };
                try {
                    const r = await fetch(INTEL_COPILOT_URLS.narrate + '/' + id + '/narrate', { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.narrate.data = j.data;
                } catch (e) {}
                this.narrate.loading = false;
            },

            async submitAsk() {
                if (! this.ask.question) return;
                this.ask.submitting = true;
                this.ask.response = null;
                try {
                    const r = await fetch(INTEL_COPILOT_URLS.ask, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json', 'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        },
                        body: JSON.stringify({
                            question: this.ask.question,
                            alert_id: this.selectedAlertId,
                            route:    '/admin/intelligence/copilot',
                        }),
                    });
                    const j = await r.json();
                    if (j.ok) this.ask.response = j.data;
                } catch (e) {}
                this.ask.submitting = false;
            },

            priorityBadge(p) {
                const pu = (p || '').toString().toUpperCase();
                return {
                    'badge-critical': pu === 'CRITICAL' || pu === 'P0',
                    'badge-warning':  pu === 'HIGH' || pu === 'P1',
                    'badge-info':     pu === 'MEDIUM' || pu === 'P2',
                    'badge-outline':  ! ['CRITICAL','P0','HIGH','P1','MEDIUM','P2'].includes(pu),
                };
            },
            riskBadge(r) {
                return {
                    'badge-critical': r === 'CRITICAL',
                    'badge-warning':  r === 'HIGH',
                    'badge-info':     r === 'MEDIUM',
                    'badge-outline':  r === 'LOW' || !r,
                };
            },

            renderNarrative(v) {
                if (v === null || v === undefined) return '—';
                if (typeof v === 'string') return v;
                if (typeof v === 'object') {
                    if (v.text) return v.text;
                    if (v.summary) return v.summary;
                    if (Array.isArray(v.lines)) return v.lines.join('\n');
                    return JSON.stringify(v, null, 2);
                }
                return String(v);
            },
        };
    }
</script>
@endpush
@endsection
