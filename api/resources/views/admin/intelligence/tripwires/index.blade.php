{{-- Admin · Intelligence · Tripwires (intel-trip) --}}
@extends('admin.layout')
@section('crumb', 'Intelligence')
@section('title', $page_title)

@section('content')
<div x-data="tripwiresPage()" x-init="boot()" class="space-y-5">
    <section class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Intelligence · Deterministic alarms</p>
            <h2 class="display-md mt-1">Tripwires</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-xl">
                Stuck Alerts · Silent PoEs · Dormant Officers · Case Spikes · Unsubmitted.
            </p>
        </div>
        <button type="button" class="btn btn-brand btn-sm" @click="loadSummary()">Refresh</button>
    </section>

    <section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-6 gap-3">
        <div class="kpi kpi-glow">
            <p class="kpi-label">Tripwire health</p>
            <div class="flex items-center gap-3 mt-1">
                <div class="ring-gauge h-14 w-14">
                    <svg viewBox="0 0 40 40" class="h-14 w-14 -rotate-90" aria-hidden="true">
                        <circle cx="20" cy="20" r="16" fill="none" stroke="hsl(var(--muted))" stroke-width="4"/>
                        <circle cx="20" cy="20" r="16" fill="none" :stroke="healthColor()" stroke-width="4"
                                stroke-linecap="round"
                                :stroke-dasharray="100.53"
                                :stroke-dashoffset="summary ? (100.53 - (summary.health_score/100) * 100.53) : 100.53"/>
                    </svg>
                    <div class="ring-gauge-label">
                        <span class="text-[11px] font-bold tabular-nums" x-text="summary ? summary.health_score : '—'"></span>
                    </div>
                </div>
                <p class="text-[11.5px] text-muted-foreground" x-text="healthLabel()"></p>
            </div>
        </div>
        <template x-for="c in (summary?.cards || [])" :key="'tc-' + c.key">
            <div class="kpi cursor-pointer" :class="c.n > 0 ? 'ring-1 ring-border/60' : ''"
                 @click="activeTab = c.key">
                <p class="kpi-label" x-text="c.label"></p>
                <p class="kpi-value tabular-nums" :class="c.n > 0 ? severityTextColor(c.severity) : 'text-muted-foreground'"
                   x-text="c.n"></p>
                <p class="text-[11px] text-muted-foreground mt-1" x-text="c.hint"></p>
            </div>
        </template>
    </section>

    <section class="card">
        <div class="card-content !p-0">
            <div class="p-4 sm:p-5 border-b">
                <div class="tabs-list">
                    <template x-for="c in (summary?.cards || [])" :key="'tt-' + c.key">
                        <button type="button" class="tabs-trigger"
                                :data-state="activeTab === c.key ? 'active' : 'inactive'"
                                @click="activeTab = c.key">
                            <span x-text="c.label"></span>
                            <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="c.n"></span>
                        </button>
                    </template>
                    {{-- §2.8 / §9.3 — Suppressed By Cadence visibility tab. --}}
                    <button type="button" class="tabs-trigger"
                            :data-state="activeTab === 'suppressed' ? 'active' : 'inactive'"
                            @click="activeTab = 'suppressed'; loadSuppressed()">
                        <span>Suppressed By Cadence</span>
                        <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="(suppressed?.totals?.suppressed_dispatches ?? '·')"></span>
                    </button>
                </div>
            </div>

            {{-- Stuck Alerts --}}
            <div x-show="activeTab === 'stuck'" x-cloak>
                <div class="table-wrap !rounded-none !border-0 border-t-0">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">Alert</th>
                                <th class="table-head-th">Risk</th>
                                <th class="table-head-th">Status</th>
                                <th class="table-head-th hidden md:table-cell">PoE</th>
                                <th class="table-head-th text-right">Open for</th>
                            </tr>
                        </thead>
                        <tbody class="table-body">
                            <template x-if="!(summary?.stuck || []).length">
                                <tr><td colspan="5" class="table-cell">
                                    <div class="empty-state">
                                        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                        <p class="text-sm font-medium">No stuck alerts.</p>
                                    </div>
                                </td></tr>
                            </template>
                            <template x-for="r in (summary?.stuck || [])" :key="'st-' + r.id">
                                <tr class="table-row">
                                    <td class="table-cell font-mono text-[11.5px]" x-text="r.alert_code || ('#' + r.id)"></td>
                                    <td class="table-cell"><span class="badge font-mono" :class="riskBadge(r.risk_level)" x-text="r.risk_level || '—'"></span></td>
                                    <td class="table-cell font-mono text-[11.5px]" x-text="r.status"></td>
                                    <td class="table-cell hidden md:table-cell font-mono text-[11.5px]" x-text="r.poe_code || '—'"></td>
                                    <td class="table-cell text-right">
                                        <span class="badge" :class="r.hours_open >= 168 ? 'badge-critical' : (r.hours_open >= 72 ? 'badge-warning' : 'badge-outline')"
                                              x-text="r.hours_open + 'h'"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Silent PoEs --}}
            <div x-show="activeTab === 'silent'" x-cloak>
                <div class="p-4 sm:p-5">
                    <template x-if="!(summary?.silent || []).length">
                        <div class="empty-state">
                            <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                            <p class="text-sm font-medium">No silent PoEs.</p>
                        </div>
                    </template>
                    <ul class="flex flex-wrap gap-1.5">
                        <template x-for="p in (summary?.silent || [])" :key="'sp-' + p.poe_code">
                            <li class="badge badge-warning font-mono text-[11px]">
                                <span x-text="p.poe_code"></span>
                                <span class="mx-1 opacity-60">·</span>
                                <span x-text="p.poe_name"></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            {{-- Dormant officers --}}
            <div x-show="activeTab === 'dormant'" x-cloak>
                <div class="table-wrap !rounded-none !border-0 border-t-0">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">User</th>
                                <th class="table-head-th">Role</th>
                                <th class="table-head-th hidden md:table-cell">Email</th>
                            </tr>
                        </thead>
                        <tbody class="table-body">
                            <template x-if="!(summary?.dormant || []).length">
                                <tr><td colspan="3" class="table-cell">
                                    <div class="empty-state">
                                        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                        <p class="text-sm font-medium">No dormant officers.</p>
                                    </div>
                                </td></tr>
                            </template>
                            <template x-for="u in (summary?.dormant || [])" :key="'du-' + u.id">
                                <tr class="table-row">
                                    <td class="table-cell">
                                        <div class="font-medium text-[12.5px]" x-text="u.full_name"></div>
                                        <div class="text-[10.5px] text-muted-foreground font-mono" x-text="u.username"></div>
                                    </td>
                                    <td class="table-cell font-mono text-[11.5px]" x-text="u.role_key"></td>
                                    <td class="table-cell hidden md:table-cell text-[11.5px] text-muted-foreground" x-text="u.email"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Case spikes --}}
            <div x-show="activeTab === 'spikes'" x-cloak>
                <div class="table-wrap !rounded-none !border-0 border-t-0">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">PoE</th>
                                <th class="table-head-th text-right">Prior 7d</th>
                                <th class="table-head-th text-right">Current 7d</th>
                                <th class="table-head-th">Growth</th>
                            </tr>
                        </thead>
                        <tbody class="table-body">
                            <template x-if="!(summary?.spikes || []).length">
                                <tr><td colspan="4" class="table-cell">
                                    <div class="empty-state">
                                        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                        <p class="text-sm font-medium">No case spikes detected.</p>
                                    </div>
                                </td></tr>
                            </template>
                            <template x-for="s in (summary?.spikes || [])" :key="'sp-' + s.poe_code">
                                <tr class="table-row">
                                    <td class="table-cell font-mono text-[11.5px]" x-text="s.poe_code"></td>
                                    <td class="table-cell text-right tabular-nums text-muted-foreground" x-text="s.prev_7d"></td>
                                    <td class="table-cell text-right tabular-nums text-critical font-semibold" x-text="s.curr_7d"></td>
                                    <td class="table-cell">
                                        <span class="badge badge-critical" x-text="s.growth !== null ? ('+' + s.growth + '%') : 'new'"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Unsubmitted --}}
            <div x-show="activeTab === 'unsubmitted'" x-cloak>
                <div class="table-wrap !rounded-none !border-0 border-t-0">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">ID</th>
                                <th class="table-head-th hidden md:table-cell">client_uuid</th>
                                <th class="table-head-th">PoE</th>
                                <th class="table-head-th hidden md:table-cell">Device</th>
                                <th class="table-head-th text-right">Age</th>
                                <th class="table-head-th text-right">Attempts</th>
                            </tr>
                        </thead>
                        <tbody class="table-body">
                            <template x-if="!(summary?.unsubmitted || []).length">
                                <tr><td colspan="6" class="table-cell">
                                    <div class="empty-state">
                                        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                        <p class="text-sm font-medium">No unsubmitted screenings beyond 24h.</p>
                                    </div>
                                </td></tr>
                            </template>
                            <template x-for="u in (summary?.unsubmitted || [])" :key="'un-' + u.id">
                                <tr class="table-row">
                                    <td class="table-cell tabular-nums font-mono text-[11px]" x-text="u.id"></td>
                                    <td class="table-cell hidden md:table-cell font-mono text-[11px] truncate max-w-[12rem]" x-text="u.client_uuid.slice(0,8) + '…'"></td>
                                    <td class="table-cell font-mono text-[11.5px]" x-text="u.poe_code"></td>
                                    <td class="table-cell hidden md:table-cell font-mono text-[11px] truncate max-w-[10rem]" x-text="u.device_id"></td>
                                    <td class="table-cell text-right"><span class="badge" :class="u.hours_old >= 72 ? 'badge-critical' : 'badge-warning'" x-text="u.hours_old + 'h'"></span></td>
                                    <td class="table-cell text-right tabular-nums" x-text="u.attempts"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Suppressed By Cadence — §2.8 / §9.3 visibility surface. NOT an error state. --}}
            <div x-show="activeTab === 'suppressed'" x-cloak>
                <div class="p-4 sm:p-5 space-y-4">
                    <div class="rounded-md border bg-muted/40 p-3 text-[12px] leading-relaxed">
                        <p><strong>Why this tab exists.</strong> Reminders fire at most once every 24 hours per recipient per case per reminder-type — that is the cadence law (§2.1). This view shows reminders the law throttled in the last <span x-text="suppressed?.window_days || 7"></span> days. We are <em>not</em> showing failures; we are showing what the system protected your inbox from.</p>
                    </div>

                    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                        <div class="kpi"><p class="kpi-label">Suppressed dispatches</p><p class="kpi-value tabular-nums" x-text="(suppressed?.totals?.suppressed_dispatches ?? 0)"></p></div>
                        <div class="kpi"><p class="kpi-label">Cases under cadence</p><p class="kpi-value tabular-nums" x-text="(suppressed?.totals?.tracked_cases ?? 0)"></p></div>
                        <div class="kpi"><p class="kpi-label">Window</p><p class="kpi-value tabular-nums" x-text="(suppressed?.window_days ?? 7) + 'd'"></p></div>
                        <div class="kpi"><p class="kpi-label">Rule</p><p class="kpi-value text-[12px]">≥ 1440 min / case / recipient</p></div>
                    </div>

                    <div>
                        <h3 class="text-[13px] font-semibold mb-2">Per case · last sent &amp; next eligible</h3>
                        <div class="table-wrap">
                            <table class="table">
                                <thead class="table-head">
                                    <tr>
                                        <th class="table-head-th">Reminder type</th>
                                        <th class="table-head-th">Case</th>
                                        <th class="table-head-th">Recipient</th>
                                        <th class="table-head-th">Last dispatched</th>
                                        <th class="table-head-th">Next eligible</th>
                                        <th class="table-head-th text-right">Window</th>
                                    </tr>
                                </thead>
                                <tbody class="table-body">
                                    <template x-if="!(suppressed?.per_case || []).length">
                                        <tr><td colspan="6" class="table-cell">
                                            <div class="empty-state">
                                                <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                                <p class="text-sm font-medium">No reminder cadence activity in the window.</p>
                                            </div>
                                        </td></tr>
                                    </template>
                                    <template x-for="(r, i) in (suppressed?.per_case || [])" :key="'pc-' + i">
                                        <tr class="table-row">
                                            <td class="table-cell font-mono text-[11.5px]" x-text="r.template_code"></td>
                                            <td class="table-cell font-mono text-[11.5px]" x-text="r.related_entity_type + ' #' + r.related_entity_id"></td>
                                            <td class="table-cell font-mono text-[11.5px]" x-text="'contact #' + r.contact_id"></td>
                                            <td class="table-cell font-mono text-[11.5px]" x-text="r.last_sent_at"></td>
                                            <td class="table-cell font-mono text-[11.5px]" x-text="r.next_eligible_at"></td>
                                            <td class="table-cell text-right tabular-nums" x-text="r.window_minutes + 'm'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div>
                        <h3 class="text-[13px] font-semibold mb-2">Throttled dispatch attempts</h3>
                        <div class="table-wrap">
                            <table class="table">
                                <thead class="table-head">
                                    <tr>
                                        <th class="table-head-th">Reminder type</th>
                                        <th class="table-head-th">Case</th>
                                        <th class="table-head-th">Recipient</th>
                                        <th class="table-head-th">Reason</th>
                                        <th class="table-head-th">When</th>
                                    </tr>
                                </thead>
                                <tbody class="table-body">
                                    <template x-if="!(suppressed?.skipped || []).length">
                                        <tr><td colspan="5" class="table-cell">
                                            <div class="empty-state">
                                                <p class="text-sm font-medium">No throttled dispatches in the window.</p>
                                            </div>
                                        </td></tr>
                                    </template>
                                    <template x-for="r in (suppressed?.skipped || [])" :key="'sk-' + r.id">
                                        <tr class="table-row">
                                            <td class="table-cell font-mono text-[11.5px]" x-text="r.template_code"></td>
                                            <td class="table-cell font-mono text-[11.5px]" x-text="r.related_entity_type + ' #' + r.related_entity_id"></td>
                                            <td class="table-cell font-mono text-[11.5px]" x-text="r.recipient || ('contact #' + r.contact_id)"></td>
                                            <td class="table-cell text-[11.5px]" x-text="r.reason"></td>
                                            <td class="table-cell font-mono text-[11.5px]" x-text="r.when"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

@push('scripts')
<script>
    const INTEL_TRIP_URLS = {
        summary:    @json(route('admin.intelligence.tripwires.summary')),
        suppressed: @json(route('admin.intelligence.tripwires.suppressed-by-cadence')),
    };
    function tripwiresPage() {
        return {
            loading: true, summary: null, suppressed: null, activeTab: 'stuck',

            async boot() { await this.loadSummary(); },

            async loadSummary() {
                this.loading = true;
                try {
                    const r = await fetch(INTEL_TRIP_URLS.summary, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.summary = j.data;
                } catch (e) {}
                this.loading = false;
            },

            async loadSuppressed() {
                if (this.suppressed) return; // load once per session unless explicit refresh
                try {
                    const r = await fetch(INTEL_TRIP_URLS.suppressed + '?days=7', { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.suppressed = j.data;
                } catch (e) {}
            },

            healthColor() {
                const s = this.summary?.health_score ?? 100;
                if (s >= 90) return 'hsl(var(--success))';
                if (s >= 70) return 'hsl(var(--brand))';
                if (s >= 50) return 'hsl(var(--warning))';
                return 'hsl(var(--critical))';
            },
            healthLabel() {
                const s = this.summary?.health_score ?? 100;
                if (s >= 90) return 'All tripwires clear';
                if (s >= 70) return 'Minor signals';
                if (s >= 50) return 'Operator attention required';
                return 'Critical — multiple tripwires hit';
            },
            severityTextColor(s) {
                return { 'text-critical': s === 'critical', 'text-warning': s === 'warning', 'text-info': s === 'info' };
            },
            riskBadge(r) {
                return {
                    'badge-critical': r === 'CRITICAL', 'badge-warning': r === 'HIGH',
                    'badge-info': r === 'MEDIUM', 'badge-outline': r === 'LOW' || !r,
                };
            },
        };
    }
</script>
@endpush
@endsection
