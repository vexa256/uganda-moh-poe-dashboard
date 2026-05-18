{{-- Alert Operations · Case History (alert-timeline)
    Anchor visual family: VERTICAL EVENT SPINE — each entry is an icon
    + plain-language one-line summary + actor + time. Outside a case,
    a recent-activity feed in the same shape. NO master table.
    Per Paranoid v2 brief §10.6.
--}}
@extends('admin.layout')
@section('crumb', 'Alert Operations')
@section('title', 'Case History')

@section('content')
<div x-data="alertopsTimeline()" x-init="boot()" class="space-y-5">

    <header class="relative flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Alert Operations · The complete record, in order</p>
            <h2 class="display-md mt-1">Case History</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                Every event on every case in your scope, told in plain language. Pick a case to read its full
                chronicle; otherwise, see the recent-activity feed across the cases you can reach.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2 relative">
            @include('admin.alertops._coach', ['sectionKey' => 'alert-timeline'])
        </div>
    </header>

    {{-- Filter rail --}}
    <section class="card">
        <div class="card-content !p-3 flex flex-wrap items-center gap-2">
            <input type="search" class="input input-sm w-72" placeholder="Search by case, traveller, or event…"
                   x-model.debounce.250ms="filter.search">
            <select class="select select-sm" x-model="filter.window">
                <option value="past_24h">Past 24 hours</option>
                <option value="past_7d">Past 7 days</option>
                <option value="past_30d">Past 30 days</option>
                <option value="all">All time</option>
            </select>
            <select class="select select-sm" x-model="filter.category">
                <option value="">All categories</option>
                <template x-for="c in categoriesInUse" :key="c"><option :value="c" x-text="categoryLabel(c)"></option></template>
            </select>
            <span class="ml-auto text-[11px] text-muted-foreground" x-show="ready">
                <span x-text="filteredEvents().length"></span> events
            </span>
        </div>
    </section>

    {{-- ANCHOR — vertical event spine --}}
    <template x-if="ready">
        <section class="card" data-anchor="event-spine">
            <div class="card-content">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-[13px] font-semibold" x-text="caseId ? 'Chronicle for ' + caseTitle : 'Recent activity in your scope'"></h3>
                    @include('admin.alertops._explainer_modal', [
                        'explainerId' => 'alert-timeline.spine',
                        'title' => 'The event spine',
                        'how' => 'Each entry on the spine is one event in the case (or one event in your scope if no case is selected). Most recent at the top. Each icon represents the event category — system, human, email, workflow, breach, or clinical.',
                        'good' => 'A spine where entries follow a sensible rhythm: opened → acknowledged → worked → closed.',
                        'concerning' => 'Long silences punctuated by escalations, or many breach entries clustered together — the case stalled and the system noticed.',
                        'whatToDo' => 'A breach entry leads to alert-sla; a comment or evidence entry leads to alert-caseroom; an open follow-up leads to alert-followups.',
                        'cantTell' => 'It does not tell you the body of every event in detail; click an entry for the full payload.',
                    ])
                </div>

                <ol class="relative pl-6">
                    {{-- Spine line --}}
                    <span class="absolute left-2 top-0 bottom-0 w-px bg-border"></span>

                    <template x-for="(e, idx) in filteredEvents()" :key="e.id">
                        <li class="relative pb-4">
                            {{-- Icon dot on the spine --}}
                            <span class="absolute -left-[14px] top-0 inline-flex h-5 w-5 items-center justify-center rounded-full text-[10px] font-bold"
                                  :class="iconTone(e.event_category)" x-text="iconChar(e.event_category)"></span>
                            <div class="ml-2">
                                <p class="text-[12.5px]">
                                    <span class="font-semibold" x-text="e.summary || ('Event ' + e.event_code)"></span>
                                    <template x-if="!caseId">
                                        <span class="text-muted-foreground"> · <a class="hover:underline" :href="`{{ url('/admin/alerts/timeline') }}?alert_id=${e.alert_id}`" x-text="e.alert_code || 'case'"></a></span>
                                    </template>
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    <span x-text="e.actor_name || 'system'"></span>
                                    <span x-show="e.actor_role"> · <span x-text="e.actor_role"></span></span>
                                    · <time :datetime="e.created_at" :title="e.created_at" x-text="formatTime(e.created_at)"></time>
                                    <span class="badge badge-soft text-[9px] ml-1" x-text="categoryLabel(e.event_category)"></span>
                                    <template x-if="e.severity && e.severity !== 'INFO'">
                                        <span class="badge text-[9px] ml-1" :class="severityClass(e.severity)" x-text="e.severity.toLowerCase()"></span>
                                    </template>
                                </p>
                            </div>
                        </li>
                    </template>

                    <template x-if="filteredEvents().length === 0">
                        <li class="ml-2 py-6 text-[12px] text-muted-foreground italic text-center">
                            No events match the current filters in this period.
                        </li>
                    </template>
                </ol>
            </div>
        </section>
    </template>

    <footer class="card">
        <div class="card-content !p-2 text-[11px] text-muted-foreground">
            <span x-show="ready">
                <span class="font-semibold" x-text="(counters.past_24h || 0)"></span> in 24 h ·
                <span class="font-semibold" x-text="(counters.past_7d  || 0)"></span> in 7 d ·
                <span class="font-semibold" x-text="(counters.past_30d || 0)"></span> in 30 d
            </span>
        </div>
    </footer>
</div>

@push('scripts')
<script>
function alertopsTimeline() {
    return {
        ready: false,
        events: [],
        counters: {},
        categoriesInUse: [],
        caseId: null,
        caseTitle: '',
        filter: { search: '', window: 'past_7d', category: '' },

        async boot() {
            const url = new URL(window.location.href);
            this.caseId = url.searchParams.get('alert_id');
            await Promise.all([this.loadMeta(), this.loadEvents()]);
        },

        async loadMeta() {
            try {
                const r = await fetch('{{ route('admin.alerts.timeline.meta') }}', { headers: { Accept: 'application/json' } });
                const j = await r.json();
                this.counters = j.counters || {};
                this.categoriesInUse = (j.categories || []).map(c => c.event_category || c.category || c);
            } catch (e) { console.error(e); }
        },

        async loadEvents() {
            try {
                const params = new URLSearchParams();
                if (this.caseId) params.append('alert_id', this.caseId);
                params.append('window', this.filter.window);
                if (this.filter.category) params.append('category', this.filter.category);
                const r = await fetch('{{ route('admin.alerts.timeline.data') }}?' + params.toString(),
                                      { headers: { Accept: 'application/json' } });
                const j = await r.json();
                this.events = j.events || j.rows || [];
                if (this.caseId && this.events.length > 0) {
                    this.caseTitle = this.events[0].alert_title || ('Case ' + this.events[0].alert_code);
                }
            } catch (e) { console.error(e); }
            this.ready = true;
        },

        filteredEvents() {
            let r = this.events;
            if (this.filter.search) {
                const q = this.filter.search.toLowerCase();
                r = r.filter(e =>
                    (e.summary || '').toLowerCase().includes(q) ||
                    (e.alert_title || '').toLowerCase().includes(q) ||
                    (e.alert_code || '').toLowerCase().includes(q) ||
                    (e.actor_name || '').toLowerCase().includes(q)
                );
            }
            return r;
        },

        iconChar(cat) {
            return ({ SYSTEM: '✓', HUMAN: '◷', EMAIL: '✉', WORKFLOW: '↔', BREACH: '!', CLINICAL: '✚' })[cat] || '·';
        },
        iconTone(cat) {
            return ({
                SYSTEM:   'bg-muted text-muted-foreground',
                HUMAN:    'bg-brand/15 text-brand',
                EMAIL:    'bg-info/15 text-info',
                WORKFLOW: 'bg-success/15 text-success',
                BREACH:   'bg-critical/15 text-critical',
                CLINICAL: 'bg-warning/15 text-warning',
            })[cat] || 'bg-muted text-muted-foreground';
        },
        categoryLabel(cat) {
            return ({ SYSTEM: 'System', HUMAN: 'Human', EMAIL: 'Email', WORKFLOW: 'Workflow', BREACH: 'Breach', CLINICAL: 'Clinical' })[cat] || (cat || '—');
        },
        severityClass(sev) {
            return ({ WARN: 'badge-warning', ERROR: 'badge-critical', CRITICAL: 'badge-critical' })[sev] || 'badge-soft';
        },

        formatTime(ts) {
            if (! ts) return '';
            const m = (Date.now() - new Date(ts).getTime()) / 60000;
            if (m < 60) return Math.round(m) + ' min ago';
            if (m < 1440) return Math.round(m / 60) + ' h ago';
            return new Date(ts).toLocaleString();
        },
    };
}
</script>
@endpush
@endsection
