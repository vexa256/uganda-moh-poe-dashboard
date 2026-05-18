{{-- Alert Operations · Follow-ups (alert-followups)
    Anchor visual family: VERTICAL CARD STACK with progress rings.
    Composition: case-led units, ordered by urgency. NO master table.
    Per Paranoid v2 brief §10.1.
--}}
@extends('admin.layout')
@section('crumb', 'Alert Operations')
@section('title', 'Follow-ups')

@section('content')
<div x-data="alertopsFollowups()" x-init="boot()" class="space-y-5">

    {{-- Header band — fixed --}}
    <header class="relative flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Alert Operations · What is on your plate</p>
            <h2 class="display-md mt-1">Follow-ups</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                Every case in your scope with steps left to do, ordered by urgency. Each card is a self-contained
                unit — open one to see the steps grouped by category, with anything blocking closure called out.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 relative">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="cards.length + ' cases on your plate'"></span>
            </span>
            @include('admin.alertops._coach', ['sectionKey' => 'alert-followups'])
            <button type="button" class="btn btn-ghost btn-sm" @click="exportCsv()">Export CSV</button>
        </div>
    </header>

    {{-- Filter rail — chips --}}
    <section class="card">
        <div class="card-content !p-3 flex flex-wrap items-center gap-2">
            <button type="button" class="btn btn-sm" :class="filter.scope === 'mine' ? 'btn-primary' : 'btn-ghost'"
                    @click="filter.scope = 'mine'">My plate</button>
            <button type="button" class="btn btn-sm" :class="filter.scope === 'watching' ? 'btn-primary' : 'btn-ghost'"
                    @click="filter.scope = 'watching'">Watching</button>
            <button type="button" class="btn btn-sm" :class="filter.scope === 'all' ? 'btn-primary' : 'btn-ghost'"
                    @click="filter.scope = 'all'">Everything in my scope</button>
            <span class="separator-v"></span>
            <label class="flex items-center gap-1.5 text-[12px]">
                <input type="checkbox" x-model="filter.blockersOnly"> With blockers only
            </label>
            <label class="flex items-center gap-1.5 text-[12px]">
                <input type="checkbox" x-model="filter.overdueOnly"> Overdue only
            </label>
            <input type="search" class="input input-sm w-64 ml-auto"
                   placeholder="Search traveller, case code…" x-model.debounce.250ms="filter.search">
        </div>
    </section>

    {{-- Loading skeleton --}}
    <template x-if="!ready">
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-3">
            <template x-for="n in 4" :key="n">
                <div class="card animate-pulse"><div class="card-content h-40"></div></div>
            </template>
        </section>
    </template>

    {{-- ANCHOR — vertical card stack --}}
    <template x-if="ready">
        <section class="grid grid-cols-1 lg:grid-cols-2 gap-3" data-anchor="card-stack">
            <template x-for="card in filteredCards()" :key="card.alert_id">
                <article class="card transition hover:shadow-md"
                         :class="{ 'border-critical': card.has_overdue, 'border-warning': !card.has_overdue && card.minutes_to_due !== null && card.minutes_to_due < 360 }">
                    <div class="card-content">
                        {{-- Card header — traveller + status + risk + countdown --}}
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="eyebrow truncate"><span x-text="card.alert_code"></span></p>
                                <h3 class="text-[14px] font-semibold mt-0.5 truncate" x-text="card.alert_title"></h3>
                                <p class="text-[12px] text-muted-foreground mt-0.5">
                                    <span x-text="card.case_category"></span> ·
                                    <span x-text="card.owner_name || 'Unassigned'"></span>
                                </p>
                            </div>
                            {{-- Progress ring (inline SVG, deterministic) --}}
                            <div class="shrink-0 relative" style="width: 64px; height: 64px;">
                                <svg viewBox="0 0 36 36" class="w-16 h-16 -rotate-90">
                                    <circle cx="18" cy="18" r="15.9" fill="none" stroke="currentColor" stroke-width="3" class="text-muted/30"></circle>
                                    <circle cx="18" cy="18" r="15.9" fill="none" stroke="currentColor" stroke-width="3"
                                            :stroke-dasharray="card.progress_dash"
                                            :class="card.has_overdue ? 'text-critical' : (card.completed_pct >= 80 ? 'text-success' : 'text-brand')"
                                            stroke-linecap="round"></circle>
                                </svg>
                                <span class="absolute inset-0 flex items-center justify-center text-[11px] font-semibold tabular-nums" x-text="card.completed_pct + '%'"></span>
                            </div>
                        </div>

                        {{-- Inline metric row --}}
                        <dl class="mt-3 grid grid-cols-3 gap-2 text-center">
                            <div>
                                <dt class="text-[10px] uppercase text-muted-foreground tracking-wide">Steps left</dt>
                                <dd class="text-[15px] font-semibold tabular-nums" x-text="card.steps_remaining"></dd>
                            </div>
                            <div>
                                <dt class="text-[10px] uppercase text-muted-foreground tracking-wide">Blockers</dt>
                                <dd class="text-[15px] font-semibold tabular-nums" :class="card.blocker_count > 0 ? 'text-critical' : ''" x-text="card.blocker_count"></dd>
                            </div>
                            <div>
                                <dt class="text-[10px] uppercase text-muted-foreground tracking-wide">Time-to-close</dt>
                                <dd class="text-[15px] font-semibold tabular-nums" :class="card.minutes_to_due !== null && card.minutes_to_due < 0 ? 'text-critical' : ''" x-text="card.time_to_close_label"></dd>
                            </div>
                        </dl>

                        {{-- Next obvious action --}}
                        <p class="mt-3 rounded-md bg-muted/30 px-3 py-2 text-[12.5px]">
                            <span class="font-semibold">Next:</span>
                            <span x-text="card.next_action_label"></span>
                        </p>

                        {{-- Card actions --}}
                        <div class="mt-3 flex items-center justify-between gap-2">
                            <button type="button" class="btn btn-ghost btn-xs" @click="toggleSteps(card.alert_id)">
                                <span x-text="opened === card.alert_id ? 'Hide steps' : 'Show steps'"></span>
                            </button>
                            <a class="btn btn-ghost btn-xs" :href="caseDossierUrl(card.alert_id)">Open case dossier</a>
                        </div>

                        {{-- Step detail (collapsed by default) --}}
                        <div x-show="opened === card.alert_id" x-cloak x-collapse class="mt-3 border-t border-border pt-3 space-y-3">
                            <template x-for="(group, label) in groupSteps(card.steps)" :key="label">
                                <div>
                                    <p class="text-[10px] uppercase tracking-wide text-muted-foreground mb-1" x-text="label"></p>
                                    <ul class="space-y-1">
                                        <template x-for="s in group" :key="s.id">
                                            <li class="flex items-start justify-between gap-2 text-[12.5px]"
                                                :class="s.blocks_closure ? 'text-critical' : ''">
                                                <span class="min-w-0">
                                                    <span class="font-semibold" x-text="s.action_label"></span>
                                                    <span class="text-[11px] text-muted-foreground" x-show="s.due_at"> · due <span x-text="s.due_at_local"></span></span>
                                                    <template x-if="s.blocks_closure">
                                                        <span class="badge badge-critical ml-1 text-[9px]">Blocks closure</span>
                                                    </template>
                                                </span>
                                                <span class="shrink-0">
                                                    <button type="button" class="btn btn-ghost btn-xs"
                                                            :disabled="s.busy"
                                                            @click="markStep(s, 'COMPLETED')"
                                                            x-show="s.status !== 'COMPLETED'">Mark done</button>
                                                    <span class="badge badge-success text-[9px]" x-show="s.status === 'COMPLETED'">Done</span>
                                                </span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </template>
                        </div>
                    </div>
                </article>
            </template>

            <template x-if="filteredCards().length === 0">
                <div class="card lg:col-span-2"><div class="card-content py-10 text-center">
                    <p class="text-[14px] font-semibold">Nothing on your plate right now</p>
                    <p class="text-[12px] text-muted-foreground mt-1">Every case in your scope is either closed or has no open follow-up steps.</p>
                </div></div>
            </template>
        </section>
    </template>

    {{-- Status strip — fixed slim bar --}}
    <footer class="card">
        <div class="card-content !p-2 flex items-center justify-between text-[11px] text-muted-foreground">
            <span>
                Showing <span class="font-semibold" x-text="filteredCards().length"></span> of
                <span class="font-semibold" x-text="cards.length"></span> cases.
                <span x-show="suppressedCount" class="ml-2">Suppressed (low count): <span x-text="suppressedCount"></span></span>
            </span>
            <span x-show="ready">Last refreshed <span x-text="lastRefreshed"></span></span>
        </div>
    </footer>
</div>

@push('scripts')
<script>
function alertopsFollowups() {
    return {
        ready: false,
        cards: [],
        opened: null,
        lastRefreshed: '',
        suppressedCount: 0,
        filter: { scope: 'all', blockersOnly: false, overdueOnly: false, search: '' },

        async boot() {
            await this.refresh();
            window.addEventListener('alertops-wizard', (e) => this.handleWizard(e.detail));
        },

        async refresh() {
            try {
                const r = await fetch('{{ route('admin.alerts.followups.data') }}', { headers: { Accept: 'application/json' } });
                const j = await r.json();
                this.cards = this.aggregateRows(j.rows || []);
                this.suppressedCount = (j.suppressed_count || 0);
            } catch (e) { console.error(e); }
            this.ready = true;
            this.lastRefreshed = new Date().toLocaleTimeString();
        },

        // Reduce flat follow-up rows into per-case cards. Single-pass O(n).
        aggregateRows(rows) {
            const byAlert = new Map();
            for (const r of rows) {
                if (! byAlert.has(r.alert_id)) {
                    byAlert.set(r.alert_id, {
                        alert_id: r.alert_id,
                        alert_code: r.alert_code,
                        alert_title: r.alert_title || '—',
                        case_category: r.human?.classification_label || r.risk_level || '—',
                        owner_name: r.owner_name || null,
                        steps: [],
                        steps_total: 0,
                        steps_completed: 0,
                        blocker_count: 0,
                        has_overdue: false,
                        minutes_to_due: null,
                        next_action_label: null,
                    });
                }
                const card = byAlert.get(r.alert_id);
                card.steps.push(r);
                card.steps_total++;
                if (r.status === 'COMPLETED') card.steps_completed++;
                if (r.blocks_closure && r.status !== 'COMPLETED' && r.status !== 'NOT_APPLICABLE') card.blocker_count++;
                if (r.is_overdue) card.has_overdue = true;
                if (r.minutes_to_due !== null && (card.minutes_to_due === null || r.minutes_to_due < card.minutes_to_due)) {
                    card.minutes_to_due = r.minutes_to_due;
                }
                if (! card.next_action_label && r.status === 'PENDING') {
                    card.next_action_label = r.action_label;
                }
            }
            for (const c of byAlert.values()) {
                const remaining = c.steps.filter(s => s.status === 'PENDING' || s.status === 'IN_PROGRESS');
                c.steps_remaining = remaining.length;
                c.completed_pct = c.steps_total === 0 ? 0 : Math.round((c.steps_completed / c.steps_total) * 100);
                c.progress_dash = `${(c.completed_pct / 100 * 100).toFixed(2)}, 100`;
                c.next_action_label = c.next_action_label || (remaining[0]?.action_label) || 'No remaining steps.';
                c.time_to_close_label = c.minutes_to_due === null
                    ? '—'
                    : (c.minutes_to_due < 0
                        ? Math.round(-c.minutes_to_due / 60) + 'h overdue'
                        : (c.minutes_to_due < 60
                            ? c.minutes_to_due + 'm'
                            : Math.round(c.minutes_to_due / 60) + 'h'));
            }
            // Sort: overdue first, then by minutes_to_due asc, then by steps_remaining desc.
            return Array.from(byAlert.values()).sort((a, b) => {
                if (a.has_overdue !== b.has_overdue) return a.has_overdue ? -1 : 1;
                const am = a.minutes_to_due === null ? Number.POSITIVE_INFINITY : a.minutes_to_due;
                const bm = b.minutes_to_due === null ? Number.POSITIVE_INFINITY : b.minutes_to_due;
                if (am !== bm) return am - bm;
                return b.steps_remaining - a.steps_remaining;
            });
        },

        filteredCards() {
            let r = this.cards;
            if (this.filter.blockersOnly) r = r.filter(c => c.blocker_count > 0);
            if (this.filter.overdueOnly)  r = r.filter(c => c.has_overdue);
            if (this.filter.search) {
                const q = this.filter.search.toLowerCase();
                r = r.filter(c =>
                    (c.alert_title || '').toLowerCase().includes(q) ||
                    (c.alert_code || '').toLowerCase().includes(q));
            }
            // The mine/watching scopes need ownership data we'd have to fetch
            // separately — for v1 of the rebuild, mine = "my plate" filter is
            // a no-op (all cases in my scope already filtered server-side via
            // ScopeFilter::applyToAlertFollowups).
            return r;
        },

        toggleSteps(id) { this.opened = this.opened === id ? null : id; },

        groupSteps(steps) {
            const groups = {};
            for (const s of steps) {
                const key = s.blocks_closure ? 'Blocks closure' : (s.assigned_to_role || 'Standard follow-up');
                groups[key] = groups[key] || [];
                groups[key].push({ ...s, due_at_local: s.due_at ? new Date(s.due_at).toLocaleString() : '' });
            }
            return groups;
        },

        async markStep(step, newStatus) {
            const idemKey = (crypto.randomUUID ? crypto.randomUUID() : ('idem-' + Date.now()));
            // Optimistic UI per brief §11
            const oldStatus = step.status;
            step.status = newStatus;
            step.busy = true;
            try {
                const r = await fetch(`{{ url('/admin/alerts/followups') }}/${step.id}`, {
                    method: 'PATCH',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                        'Idempotency-Key': idemKey,
                    },
                    body: JSON.stringify({ status: newStatus }),
                });
                if (! r.ok) throw new Error('server rejected');
                await this.refresh();
            } catch (e) {
                step.status = oldStatus;
                alert('That change could not be saved. ' + e.message);
            } finally {
                step.busy = false;
            }
        },

        caseDossierUrl(id) { return `{{ url('/admin/alerts') }}/${id}/case-file`; },

        handleWizard({ section, action }) {
            if (section !== 'alert-followups') return;
            if (action === 'overdue-only') { this.filter.overdueOnly = true; this.filter.blockersOnly = false; }
            if (action === 'find-blockers') { this.filter.blockersOnly = true; this.filter.overdueOnly = false; }
            if (action === 'walk-a-case' && this.cards.length > 0) { this.opened = this.cards[0].alert_id; window.scrollTo({ top: 0, behavior: 'smooth' }); }
        },

        exportCsv() {
            const headers = ['Case code','Title','Owner','Steps left','Blockers','Time-to-close','Has overdue'];
            const rows = this.filteredCards().map(c => [c.alert_code, c.alert_title, c.owner_name || '', c.steps_remaining, c.blocker_count, c.time_to_close_label, c.has_overdue ? 'Yes' : 'No']);
            const csv = [headers, ...rows].map(row => row.map(v => '"' + String(v).replace(/"/g,'""') + '"').join(',')).join('\r\n');
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
            const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'alert-followups.csv'; a.click(); URL.revokeObjectURL(a.href);
        },
    };
}
</script>
@endpush
@endsection
