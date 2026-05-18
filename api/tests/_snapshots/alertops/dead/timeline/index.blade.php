@extends('admin.layout')

@section('crumb', 'Alert Lifecycle')
@section('title', 'Case history')

@section('content')
<div class="space-y-5">

    @include('admin.alerts._partials.coach', [
        'viewKey'   => 'timeline',
        'viewTitle' => 'Case history',
        'oneLiner'  => 'A complete record of everything that has happened with each case, in order.',
        'why'       => 'When you need to remember exactly what happened on a case — who did what, when — this is where the system keeps the answer. Nothing here is editable; the record is permanent.',
        'youDo'     => 'Pick a case to walk through it from open to today. Or scan recent activity across cases to find one event you remember by date or person.',
        'connects'  => 'Every other Alert Operations screen writes events here. The Case dossier (Open dossier link) shows the same history for one case, plus all its other context.',
        'glossary'  => [
            ['term'=>'Event',         'plain'=>'One thing that happened on a case — a comment posted, a step finished, a deadline missed.', 'technical'=>'Row in alert_timeline_events.'],
            ['term'=>'Routine',       'plain'=>'Something normal — a comment, a status change.',                                  'technical'=>'severity = INFO.'],
            ['term'=>'Heads up',      'plain'=>'Something worth noticing — a step got stuck, a deadline is approaching.',          'technical'=>'severity = WARN.'],
            ['term'=>'Urgent',        'plain'=>'Something serious — a deadline was missed, a notification failed.',                'technical'=>'severity in (ERROR, CRITICAL).'],
            ['term'=>'Person did it', 'plain'=>'A user took the action.',                                                          'technical'=>'event_category = HUMAN.'],
            ['term'=>'Step in case',  'plain'=>'A follow-up step was updated.',                                                    'technical'=>'event_category = WORKFLOW.'],
            ['term'=>'Deadline',      'plain'=>'A breach event — the case missed a target.',                                       'technical'=>'event_category = BREACH.'],
            ['term'=>'System did it', 'plain'=>'An automatic action — usually a notification or a scheduled check.',               'technical'=>'event_category = SYSTEM.'],
        ],
        'wizardOptions' => [
            ['code'=>'WALK_CASE',    'label'=>'See everything that has happened with one case', 'help'=>'Pick a case and walk it from open to today.', 'glyph'=>'⚏', 'tone'=>'bg-blue-50 text-blue-700'],
            ['code'=>'RECENT',       'label'=>'Recent activity across all cases',               'help'=>'Stream of events in the last 24 hours.',      'glyph'=>'⌚', 'tone'=>'bg-emerald-50 text-emerald-700'],
            ['code'=>'BY_TYPE',      'label'=>'Filter by event type',                            'help'=>'Show me only deadlines / only handoffs / only comments.', 'glyph'=>'∷', 'tone'=>'bg-amber-50 text-amber-700'],
            ['code'=>'SEARCH',       'label'=>'Find a specific event',                           'help'=>'Search by case code, traveller name, or words in the summary.', 'glyph'=>'?', 'tone'=>'bg-violet-50 text-violet-700'],
        ],
        'charts' => [
            [
                'key'        => 'volume_sparkline',
                'title'      => 'Activity in the last 30 days',
                'shows'      => 'How many events happened on cases in your area, day by day.',
                'read'       => 'Each bar is one day. Taller bars are days with more activity. The amber bar (if any) is the busiest day in the window.',
                'good'       => 'A steady baseline with a few small peaks — cases are being worked.',
                'concerning' => 'A long flat run of zero (no one is recording activity) or one massive spike (a surge or a system-wide event).',
                'do'         => 'For a flat run, check whether the team is actually using the system. For a spike, click the day to see what events drove it.',
                'cant'       => 'It cannot tell you whether the activity was effective — only that it happened.',
                'source'     => 'alert_timeline_events grouped by created_at::date for events in your scope, last 30 days.',
            ],
        ],
    ])

    <div
        x-data="timelineHub({
            endpoints: {
                data: '{{ route('admin.alerts.timeline.data') }}',
                meta: '{{ route('admin.alerts.timeline.meta') }}',
                case: function (id) { return '{{ url('/admin/alerts/timeline/case') }}/' + id; },
                casefileOf: function (id) { return '{{ url('/admin/alerts') }}/' + id + '/case-file'; },
                hub:  '{{ url('/admin/alerts') }}',
            }
        })"
        x-init="boot()"
        class="space-y-3"
    >

        {{-- KPI strip --}}
        <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="kpi kpi-glow">
                <p class="kpi-label">Past 24 hours</p>
                <p class="kpi-value tabular-nums" x-text="counters.past_24h ?? '—'"></p>
                <p class="text-[11px] text-muted-foreground mt-1">events in your area</p>
            </div>
            <div class="kpi">
                <p class="kpi-label">Past 7 days</p>
                <p class="kpi-value tabular-nums text-info" x-text="counters.past_7d ?? 0"></p>
                <p class="text-[11px] text-muted-foreground mt-1">last week</p>
            </div>
            <div class="kpi">
                <p class="kpi-label">Past 30 days</p>
                <p class="kpi-value tabular-nums text-info" x-text="counters.past_30d ?? 0"></p>
                <p class="text-[11px] text-muted-foreground mt-1">last month</p>
            </div>
            <div class="kpi">
                <p class="kpi-label">All time</p>
                <p class="kpi-value tabular-nums" x-text="counters.all ?? 0"></p>
                <p class="text-[11px] text-muted-foreground mt-1">in scope</p>
            </div>
        </section>

        {{-- TABS + SEARCH --}}
        <section class="card">
            <div class="card-content !p-0">
                <div class="flex flex-col gap-3 p-4 sm:p-5 border-b min-w-0">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3 min-w-0">
                        <div class="tabs-list w-full sm:w-auto">
                            <template x-for="t in tabs" :key="t.key">
                                <button class="tabs-trigger flex-1 sm:flex-none"
                                        :data-state="lens === t.key ? 'active' : 'inactive'"
                                        @click="lens = t.key; reset()">
                                    <span x-text="t.label"></span>
                                </button>
                            </template>
                        </div>
                        <div class="flex-1"></div>
                        <input type="search" class="input w-full sm:w-72"
                               placeholder="Search by case code, name, or words…"
                               x-model.debounce.300ms="filters.q" @input="reset()">
                    </div>

                    {{-- Window + category chips --}}
                    <div class="flex flex-wrap gap-2 items-center">
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="w in windows" :key="w.key">
                                <button type="button"
                                        @click="filters.window = w.key; reset()"
                                        :class="{
                                            'inline-flex items-center rounded-full border px-2.5 py-1 text-[11.5px] font-medium whitespace-nowrap transition shrink-0': true,
                                            'border-blue-500 bg-blue-50 text-blue-700': filters.window === w.key,
                                            'border-slate-200 text-slate-600 hover:border-slate-300': filters.window !== w.key,
                                        }"
                                        x-text="w.label"></button>
                            </template>
                        </div>
                        <div class="flex-1"></div>
                        <select class="text-xs h-8 rounded-md border border-slate-300 bg-white px-2 py-1"
                                x-model="filters.severity" @change="reset()">
                            <option value="">Any seriousness</option>
                            <option value="INFO">Routine</option>
                            <option value="WARN">Heads up</option>
                            <option value="ERROR">Problem</option>
                            <option value="CRITICAL">Urgent</option>
                        </select>
                    </div>

                    {{-- BY TYPE chips --}}
                    <div x-show="lens === 'by_type'" class="flex flex-wrap gap-1.5">
                        <template x-for="c in categoryChips" :key="c.code">
                            <button class="chip"
                                    :class="filters.categories.includes(c.code) ? 'chip-on' : 'chip-off'"
                                    @click="toggleCategory(c.code)">
                                <span x-text="c.label"></span>
                                <span class="ml-1 opacity-70" x-text="c.count"></span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- ─── BY CASE LENS ─── --}}
                <template x-if="lens === 'by_case'">
                    <div class="px-4 sm:px-5 py-3">
                        <p class="text-[11.5px] text-muted-foreground mb-2">
                            Cases active in your area, most recent first. Click one to walk it from open to today.
                        </p>
                        <ul class="divide-y rounded-md border bg-card">
                            <template x-if="!loading && cases.length === 0">
                                <li class="px-3 py-3 text-[12.5px] text-muted-foreground">No cases with recent activity.</li>
                            </template>
                            <template x-for="c in cases" :key="c.alert_id">
                                <li>
                                    <button type="button"
                                            class="w-full text-left px-3 py-2.5 hover:bg-muted/40 flex items-center gap-3"
                                            @click="openCase(c.alert_id)">
                                        <span :class="severityDot(c.last_severity)"
                                              class="inline-block w-2 h-2 rounded-full shrink-0"></span>
                                        <span class="min-w-0 flex-1">
                                            <span class="text-[12.5px] font-semibold truncate" x-text="c.alert_title"></span>
                                            <span class="block text-[10.5px] text-muted-foreground">
                                                <span class="font-mono" x-text="c.alert_code"></span>
                                                · <span x-text="c.event_count"></span> events
                                                · last: <span x-text="humanTime(c.last_at)"></span>
                                            </span>
                                        </span>
                                        <span class="text-slate-300 shrink-0">→</span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>

                {{-- ─── RECENT / BY-TYPE / SEARCH share the same stream renderer ─── --}}
                <template x-if="lens !== 'by_case'">
                    <div class="table-wrap !rounded-none !border-0">
                        <table class="table">
                            <thead class="table-head">
                                <tr>
                                    <th class="table-head-th">What happened</th>
                                    <th class="table-head-th hidden md:table-cell">On case</th>
                                    <th class="table-head-th hidden lg:table-cell">By</th>
                                    <th class="table-head-th text-right">When</th>
                                </tr>
                            </thead>
                            <tbody class="table-body">
                                <template x-if="loading">
                                    <tr><td colspan="4" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading…</td></tr>
                                </template>
                                <template x-if="!loading && rows.length === 0">
                                    <tr><td colspan="4" class="table-cell text-center py-8 text-muted-foreground text-sm">No events match.</td></tr>
                                </template>
                                <template x-for="row in rows" :key="row.id">
                                    <tr class="table-row hover:bg-muted/20 cursor-pointer"
                                        @click="openCase(row.alert_id)">
                                        <td class="table-cell">
                                            <div class="flex items-start gap-2 min-w-0">
                                                <span :class="severityDot(row.severity)"
                                                      class="mt-1.5 inline-block w-2 h-2 rounded-full shrink-0"></span>
                                                <div class="min-w-0">
                                                    <div class="flex items-center gap-1.5 flex-wrap">
                                                        <span class="text-[12.5px] font-medium" x-text="row.human?.event_label"></span>
                                                        <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[9.5px] font-semibold bg-slate-100 text-slate-600"
                                                              x-text="row.human?.category_label"></span>
                                                    </div>
                                                    <p class="text-[11.5px] text-muted-foreground mt-0.5 break-words" x-text="row.summary"></p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="table-cell hidden md:table-cell text-[11.5px]">
                                            <div class="font-mono" x-text="row.alert_code"></div>
                                            <div class="text-muted-foreground truncate max-w-[180px]" x-text="row.alert_title"></div>
                                        </td>
                                        <td class="table-cell hidden lg:table-cell text-[11.5px]">
                                            <span x-text="row.actor_name || 'system'"></span>
                                            <div class="text-muted-foreground" x-text="row.actor_role || ''"></div>
                                        </td>
                                        <td class="table-cell text-right text-[11px] text-muted-foreground" x-text="humanTime(row.created_at)"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                        <div class="p-3 flex justify-center" x-show="!loading && nextCursor">
                            <button class="btn btn-outline btn-sm" @click="loadMore()" :disabled="loadingMore">
                                <span x-show="!loadingMore">Show more</span>
                                <span x-show="loadingMore">…</span>
                            </button>
                        </div>
                    </div>
                </template>

            </div>
        </section>

        {{-- ─── PER-CASE WALKTHROUGH SHEET ─── --}}
        <div x-show="walk.open" x-cloak
             class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-md flex items-end sm:items-center justify-center"
             @keydown.escape.window="walk.open = false">
            <div class="bg-white w-full h-full sm:h-auto sm:max-h-[92vh] sm:max-w-3xl sm:rounded-3xl shadow-2xl flex flex-col overflow-hidden"
                 @click.away="walk.open = false">
                <header class="px-5 sm:px-7 pt-5 pb-3 shrink-0 border-b border-slate-100">
                    <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-500 font-semibold">Case walk-through</p>
                    <h3 class="mt-1 text-base sm:text-lg font-bold text-slate-900" x-text="walk.alert?.alert_title"></h3>
                    <p class="text-[12px] text-slate-500 mt-1">
                        <span class="font-mono" x-text="walk.alert?.alert_code"></span>
                        <span x-show="walk.alert?.poe_code"> · <span x-text="walk.alert?.poe_code"></span></span>
                        <span x-show="walk.alert?.district_code"> · <span x-text="walk.alert?.district_code"></span></span>
                    </p>
                </header>
                <div class="overflow-y-auto px-5 sm:px-7 py-4 grow">
                    <template x-if="walk.loading">
                        <p class="text-sm text-muted-foreground">Loading…</p>
                    </template>
                    <ol class="relative border-l border-slate-200 pl-5 space-y-4" x-show="!walk.loading">
                        <template x-for="e in walk.events" :key="e.id">
                            <li class="text-[12.5px]">
                                <span :class="severityDotBig(e.severity)"
                                      class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full"></span>
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-[12.5px] font-semibold" x-text="e.human?.event_label"></span>
                                    <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[9.5px] font-semibold bg-slate-100 text-slate-600"
                                          x-text="e.human?.category_label"></span>
                                    <span class="ml-auto text-[10.5px] text-muted-foreground" x-text="humanTime(e.created_at)"></span>
                                </div>
                                <p class="mt-1 break-words" x-text="e.summary"></p>
                                <p class="mt-0.5 text-[10.5px] text-muted-foreground" x-show="e.actor_name">
                                    by <span x-text="e.actor_name"></span><span x-show="e.actor_role"> · <span x-text="e.actor_role"></span></span>
                                </p>
                            </li>
                        </template>
                        <template x-if="!walk.loading && walk.events.length === 0">
                            <li class="text-[12.5px] text-muted-foreground">No events recorded for this case yet.</li>
                        </template>
                    </ol>
                </div>
                <footer class="px-5 sm:px-7 py-3 border-t border-slate-100 shrink-0 flex items-center justify-end gap-2">
                    <a class="btn btn-outline btn-sm" :href="walk.alert ? endpoints.casefileOf(walk.alert.id) : '#'" x-show="walk.alert">Open dossier</a>
                    <button type="button" class="btn btn-ghost btn-sm" @click="walk.open = false">Close</button>
                </footer>
            </div>
        </div>

        {{-- TOAST --}}
        <div x-show="toast.show" x-cloak x-transition
             :class="{
                'fixed bottom-6 right-6 z-50 rounded-lg shadow-lg px-4 py-3 text-sm max-w-sm': true,
                'bg-rose-600 text-white':    toast.tone === 'error',
                'bg-emerald-600 text-white': toast.tone === 'ok',
             }"
             x-text="toast.message"></div>
    </div>
</div>

<script>
function timelineHub(opts) {
    return {
        endpoints: opts.endpoints,
        lens: 'recent',                 // recent · by_case · by_type · search
        rows: [], cases: [], loading: true, loadingMore: false, nextCursor: null,
        meta: { event_codes: [], categories: [], severities: [] },
        counters: {},
        filters: { q: '', window: 'past_7d', severity: '', categories: [] },

        tabs: [
            { key: 'recent',  label: 'Recent activity' },
            { key: 'by_case', label: 'By case' },
            { key: 'by_type', label: 'By event type' },
            { key: 'search',  label: 'Search' },
        ],

        windows: [
            { key: 'past_24h', label: 'Past 24 hours' },
            { key: 'past_7d',  label: 'Past 7 days' },
            { key: 'past_14d', label: 'Past 14 days' },
            { key: 'past_30d', label: 'Past 30 days' },
            { key: '',         label: 'Any time' },
        ],

        walk:  { open:false, loading:false, alert:null, events:[] },
        toast: { show:false, message:'', tone:'ok', timer:null },

        async boot() {
            await this.loadMeta();
            await this.loadData();
            window.addEventListener('alert-coach:wizard', e => {
                if (!e?.detail || e.detail.view !== 'timeline') return;
                this.handleWizard(e.detail.code);
            });
        },

        get categoryChips() {
            return (this.meta.categories || []).map(c => ({
                code: c.code, label: c.label, count: c.count,
            }));
        },

        csrfToken() { return document.querySelector('meta[name="csrf-token"]').content; },
        showToast(msg, tone='ok', ms=2500) {
            clearTimeout(this.toast.timer);
            Object.assign(this.toast, { show:true, message:msg, tone });
            this.toast.timer = setTimeout(() => this.toast.show = false, ms);
        },
        async fetchJson(url) {
            try {
                const res = await fetch(url, { credentials:'same-origin', headers:{
                    'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':this.csrfToken(),
                }});
                const body = await res.json().catch(() => ({}));
                if (!res.ok || body.success === false) {
                    this.showToast(body?.error?.human || body?.message || 'Something went wrong.', 'error');
                    return null;
                }
                return body;
            } catch (e) { this.showToast('Lost connection. Try again.', 'error'); return null; }
        },

        async loadMeta() {
            const body = await this.fetchJson(this.endpoints.meta);
            if (!body) return;
            this.meta = body.data;
            this.counters = body.data.counters || {};
        },

        buildParams(extra={}) {
            const p = new URLSearchParams();
            if (this.filters.q)        p.set('q', this.filters.q);
            if (this.filters.window)   p.set('window', this.filters.window);
            if (this.filters.severity) p.set('severity', this.filters.severity);
            if (this.lens === 'by_type' && this.filters.categories.length) {
                p.set('category', this.filters.categories.join(','));
            }
            for (const [k, v] of Object.entries(extra)) p.set(k, String(v));
            return p;
        },

        async reset() { this.nextCursor = null; await this.loadData(); },

        async loadData() {
            this.loading = true;
            const body = await this.fetchJson(`${this.endpoints.data}?${this.buildParams().toString()}`);
            this.loading = false;
            if (!body) return;

            this.rows = body.data.rows || [];
            this.nextCursor = body.data.next_cursor;

            if (this.lens === 'by_case') {
                this.cases = this.collapseCases(this.rows);
            } else {
                this.cases = [];
            }
        },
        async loadMore() {
            if (!this.nextCursor) return;
            this.loadingMore = true;
            const body = await this.fetchJson(`${this.endpoints.data}?${this.buildParams({ cursor: this.nextCursor }).toString()}`);
            this.loadingMore = false;
            if (!body) return;
            const more = body.data.rows || [];
            this.rows = this.rows.concat(more);
            this.nextCursor = body.data.next_cursor;
            if (this.lens === 'by_case') this.cases = this.collapseCases(this.rows);
        },

        collapseCases(rows) {
            const byId = new Map();
            for (const r of rows) {
                if (!r.alert_id) continue;
                const cur = byId.get(r.alert_id) || {
                    alert_id: r.alert_id, alert_code: r.alert_code, alert_title: r.alert_title,
                    event_count: 0, last_at: r.created_at, last_severity: r.severity,
                };
                cur.event_count += 1;
                if (r.created_at && (!cur.last_at || r.created_at > cur.last_at)) {
                    cur.last_at = r.created_at; cur.last_severity = r.severity;
                }
                byId.set(r.alert_id, cur);
            }
            return Array.from(byId.values()).sort((a,b) => (b.last_at || '').localeCompare(a.last_at || ''));
        },

        toggleCategory(code) {
            const i = this.filters.categories.indexOf(code);
            if (i >= 0) this.filters.categories.splice(i, 1);
            else this.filters.categories.push(code);
            this.reset();
        },

        async openCase(alertId) {
            this.walk = { open:true, loading:true, alert:null, events:[] };
            const body = await this.fetchJson(this.endpoints.case(alertId));
            this.walk.loading = false;
            if (!body) { this.walk.open = false; return; }
            this.walk.alert  = body.data.alert;
            this.walk.events = body.data.events || [];
        },

        handleWizard(code) {
            switch (code) {
                case 'WALK_CASE': this.lens = 'by_case'; this.filters.window = 'past_30d'; this.reset(); break;
                case 'RECENT':    this.lens = 'recent';  this.filters.window = 'past_24h'; this.reset(); break;
                case 'BY_TYPE':   this.lens = 'by_type'; this.filters.window = 'past_7d';  this.reset(); break;
                case 'SEARCH':    this.lens = 'search';  this.filters.window = '';         setTimeout(() => document.querySelector('input[type=search]')?.focus(), 200); break;
            }
        },

        severityDot(sev) {
            return ({
                INFO:     'bg-slate-400',
                WARN:     'bg-amber-500',
                ERROR:    'bg-rose-500',
                CRITICAL: 'bg-rose-600',
            })[sev] || 'bg-slate-400';
        },
        severityDotBig(sev) {
            return ({
                INFO:     'bg-slate-300',
                WARN:     'bg-amber-400',
                ERROR:    'bg-rose-500',
                CRITICAL: 'bg-rose-600',
            })[sev] || 'bg-slate-300';
        },
        humanTime(t) {
            if (!t) return '—';
            try {
                const d = new Date(t);
                const now = new Date();
                const diffMs = now - d;
                if (diffMs < 60_000) return 'just now';
                if (diffMs < 3_600_000) return Math.round(diffMs/60_000) + ' min ago';
                if (diffMs < 86_400_000) return Math.round(diffMs/3_600_000) + ' h ago';
                if (diffMs < 7 * 86_400_000) return Math.round(diffMs/86_400_000) + ' d ago';
                return d.toLocaleDateString();
            } catch (e) { return t; }
        },
    };
}
</script>
@endsection
