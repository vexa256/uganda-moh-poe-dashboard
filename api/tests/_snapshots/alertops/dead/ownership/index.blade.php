@extends('admin.layout')

@section('crumb', 'Alert Lifecycle')
@section('title', 'Who has each case')

@section('content')

@include('admin.alerts._partials.coach', [
    'viewKey'   => 'ownership',
    'viewTitle' => 'Who has each case',
    'oneLiner'  => 'Who is responsible for each case right now, and how cases move from one person to the next.',
    'why'       => 'Cases pass between people — picked up, escalated, handed off. When something has been missed, the first question is "who has it?" This screen answers that, plus the history of how it got there.',
    'youDo'     => 'Look at "Who has them now" to see workload across the team. Open a case to see its full handoff chain. If a case is sitting unaccepted, follow it up.',
    'connects'  => 'Every transfer logged here is also written to Case history (timeline). The Case dossier shows the same chain plus everything else for that case.',
    'glossary'  => [
        ['term'=>'Owner',         'plain'=>'The one person who is currently responsible for this case.', 'technical'=>'alerts.current_owner_user_id; null if unassigned.'],
        ['term'=>'Pickup',        'plain'=>'When someone takes responsibility for a new case.',           'technical'=>'event_code = ACKNOWLEDGED.'],
        ['term'=>'Escalation',    'plain'=>'Routing the case to a higher level (district → province → national) because it needs more help.', 'technical'=>'event_code = ESCALATED.'],
        ['term'=>'Handoff',       'plain'=>'Passing the case to a specific person who needs to accept it. Different from escalation: it is one-to-one.', 'technical'=>'event_code = HANDOFF_SENT; awaits HANDOFF_ACCEPTED.'],
        ['term'=>'Reassignment',  'plain'=>'Forcibly changing who owns the case (an admin decision, not a request).',                            'technical'=>'event_code = REASSIGNED.'],
        ['term'=>'Unaccepted',    'plain'=>'A handoff has been sent but the recipient has not yet accepted. The case is in limbo until they do.', 'technical'=>'alert_handoffs.status = SENT or ACKNOWLEDGED.'],
    ],
    'wizardOptions' => [
        ['code'=>'NOW_MATRIX', 'label'=>'See who has cases right now',   'help'=>'Workload by person and level.',                  'glyph'=>'⚏', 'tone'=>'bg-blue-50 text-blue-700'],
        ['code'=>'PENDING',    'label'=>'Show handoffs not yet accepted','help'=>'Cases sitting in limbo waiting for someone.',    'glyph'=>'!', 'tone'=>'bg-amber-50 text-amber-700'],
        ['code'=>'STREAM',     'label'=>'Recent transfers',              'help'=>'Every change of ownership in time order.',       'glyph'=>'⌚', 'tone'=>'bg-slate-100 text-slate-700'],
        ['code'=>'WALK_CHAIN', 'label'=>'Walk a case through its handoffs','help'=>'Pick a case and see who held it when.',         'glyph'=>'→', 'tone'=>'bg-violet-50 text-violet-700'],
    ],
    'charts' => [
        [
            'key'        => 'now_matrix',
            'title'      => 'Who has cases right now',
            'shows'      => 'Open and acknowledged cases grouped by their current owner and the level they sit at (district / province / national).',
            'read'       => 'Each row is one person at one level. The Total column is how many cases that person is responsible for. The Top-priority and Serious columns are the riskiest of those.',
            'good'       => 'Workload is spread across the team. No one has many top-priority cases at once.',
            'concerning' => 'A row with many top-priority cases on one person, or a long Unassigned row.',
            'do'         => 'Click the row to open the filtered list, then use a handoff or escalation to redistribute.',
            'cant'       => 'It cannot tell you who is on leave, on call, or already at capacity — only how many cases each person currently owns.',
            'source'     => 'alerts where status IN (OPEN, ACKNOWLEDGED), grouped by current_owner_user_id × routed_to_level, in your scope.',
        ],
    ],
])

<div x-data="ownershipTrail()" x-init="boot()" x-effect="window.adminLock.set('page', chain.open)" class="space-y-5">

    {{-- Lens toggle --}}
    <section class="flex items-center gap-2">
        <div class="tabs-list">
            <button class="tabs-trigger" :data-state="lens==='stream'?'active':'inactive'" @click="lens='stream'; loadData()">Recent transfers</button>
            <button class="tabs-trigger" :data-state="lens==='matrix'?'active':'inactive'" @click="lens='matrix'; loadMatrix()">Who has them now</button>
        </div>
        <div class="flex-1"></div>
        <button type="button" class="text-[11px] text-blue-700 hover:underline" @click="window.alertCoach?.ownership?.interp('now_matrix')" x-show="lens==='matrix'">How to read this</button>
        <span class="text-[11px] text-muted-foreground hidden sm:inline" x-text="lens==='stream' ? 'Every change of ownership' : 'Open cases by current owner'"></span>
    </section>

    {{-- ─── STREAM lens ─── --}}
    <template x-if="lens==='stream'">
        <section class="card">
            <div class="card-content !p-0">
                <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                    <div class="flex flex-wrap gap-1.5 items-center">
                        <template x-for="c in codes" :key="c">
                            <button class="chip" :class="filters.codes.includes(c)?'chip-on':'chip-off'" @click="toggleCode(c)">
                                <span x-text="codeLabel(c)" :title="c"></span>
                                <span class="ml-1 opacity-70" x-text="counters[c] ?? 0"></span>
                            </button>
                        </template>
                        <div class="flex-1"></div>
                        <input type="search" class="input w-full sm:w-72 !h-8 text-xs" placeholder="Search summary…" x-model.debounce.300ms="filters.q" @input="loadData()">
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <select class="select w-auto !h-8 text-xs" x-model="filters.severity" @change="loadData()">
                            <option value="">Any severity</option>
                            <option value="INFO">INFO</option>
                            <option value="WARN">WARN</option>
                            <option value="ERROR">ERROR</option>
                            <option value="CRITICAL">CRITICAL</option>
                        </select>
                        <input type="date" class="input w-auto !h-8 text-xs" x-model="filters.from" @change="loadData()">
                        <span class="text-[11px] text-muted-foreground self-center">→</span>
                        <input type="date" class="input w-auto !h-8 text-xs" x-model="filters.to" @change="loadData()">
                    </div>
                </div>

                <div class="table-wrap !rounded-none !border-0">
                    <table class="table">
                        <thead class="table-head"><tr>
                            <th class="table-head-th">Event</th>
                            <th class="table-head-th hidden md:table-cell">Alert</th>
                            <th class="table-head-th hidden lg:table-cell">Actor</th>
                            <th class="table-head-th hidden md:table-cell">Summary</th>
                            <th class="table-head-th text-right">When</th>
                        </tr></thead>
                        <tbody class="table-body">
                            <template x-if="loading"><tr><td colspan="5" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading…</td></tr></template>
                            <template x-if="!loading && rows.length===0"><tr><td colspan="5" class="table-cell"><div class="empty-state"><p class="text-sm">No events match.</p></div></td></tr></template>
                            <template x-for="row in rows" :key="row.id">
                                <tr class="table-row hover:bg-muted/20 cursor-pointer" @click="openChain(row.alert_id)">
                                    <td class="table-cell"><span class="badge" :class="codeBadge(row.event_code)" x-text="codeLabel(row.event_code)" :title="row.event_code"></span></td>
                                    <td class="table-cell hidden md:table-cell text-[11.5px]">
                                        <div class="font-mono" x-text="row.alert_code"></div>
                                        <div class="text-muted-foreground truncate max-w-[180px]" x-text="row.alert_title"></div>
                                    </td>
                                    <td class="table-cell hidden lg:table-cell text-[11.5px]">
                                        <div x-text="row.actor_name || 'system'"></div>
                                        <div class="text-muted-foreground" x-text="row.actor_role"></div>
                                    </td>
                                    <td class="table-cell hidden md:table-cell text-[11.5px] truncate max-w-[280px]" x-text="row.summary"></td>
                                    <td class="table-cell text-right text-[11px] text-muted-foreground" x-text="humanTime(row.created_at)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                    <div class="p-3 flex justify-center" x-show="nextCursor">
                        <button class="btn btn-outline btn-sm" @click="loadMore()" :disabled="loadingMore">
                            <span x-show="!loadingMore">Load more</span><span x-show="loadingMore">…</span>
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </template>

    {{-- ─── MATRIX lens ─── --}}
    <template x-if="lens==='matrix'">
        <section class="space-y-4">
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-2">
                <div class="kpi"><p class="kpi-label">Open / Ack</p><p class="kpi-value tabular-nums" x-text="matrix.totals?.grand ?? 0"></p></div>
                <div class="kpi"><p class="kpi-label">Critical</p><p class="kpi-value tabular-nums text-critical" x-text="matrix.totals?.by_risk?.CRITICAL ?? 0"></p></div>
                <div class="kpi"><p class="kpi-label">High</p><p class="kpi-value tabular-nums text-high" x-text="matrix.totals?.by_risk?.HIGH ?? 0"></p></div>
                <div class="kpi"><p class="kpi-label">Med / Low</p><p class="kpi-value tabular-nums text-warning" x-text="(matrix.totals?.by_risk?.MEDIUM ?? 0) + (matrix.totals?.by_risk?.LOW ?? 0)"></p></div>
                <div class="kpi"><p class="kpi-label">Unassigned</p><p class="kpi-value tabular-nums text-muted-foreground" x-text="matrix.totals?.by_owner?.unassigned ?? 0"></p></div>
            </div>

            <div class="card">
                <div class="card-content !p-0">
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead class="table-head"><tr>
                                <th class="table-head-th sticky left-0 bg-card z-10">Owner</th>
                                <th class="table-head-th">Level</th>
                                <th class="table-head-th text-right">Critical</th>
                                <th class="table-head-th text-right">High</th>
                                <th class="table-head-th text-right">Medium</th>
                                <th class="table-head-th text-right">Low</th>
                                <th class="table-head-th text-right">Total</th>
                            </tr></thead>
                            <tbody class="table-body">
                                <template x-if="(matrix.matrix||[]).length===0"><tr><td colspan="7" class="table-cell text-center py-8 text-muted-foreground text-sm">No open / acknowledged alerts in scope.</td></tr></template>
                                <template x-for="cell in (matrix.matrix||[])" :key="(cell.owner_user_id||0)+'-'+cell.routed_to_level">
                                    <tr class="table-row">
                                        <td class="table-cell sticky left-0 bg-card z-10">
                                            <div class="flex flex-col">
                                                <span class="text-[12.5px] font-semibold" x-text="cell.owner_name"></span>
                                                <span class="text-[10.5px] text-muted-foreground" x-text="cell.owner_role"></span>
                                            </div>
                                        </td>
                                        <td class="table-cell"><span class="badge badge-outline" x-text="cell.routed_to_level"></span></td>
                                        <td class="table-cell text-right"><span class="badge" :class="cell.risks.CRITICAL?'badge-critical':'badge-outline'" x-text="cell.risks.CRITICAL"></span></td>
                                        <td class="table-cell text-right"><span class="badge" :class="cell.risks.HIGH?'badge-high':'badge-outline'" x-text="cell.risks.HIGH"></span></td>
                                        <td class="table-cell text-right"><span class="badge" :class="cell.risks.MEDIUM?'badge-medium':'badge-outline'" x-text="cell.risks.MEDIUM"></span></td>
                                        <td class="table-cell text-right"><span class="badge" :class="cell.risks.LOW?'badge-low':'badge-outline'" x-text="cell.risks.LOW"></span></td>
                                        <td class="table-cell text-right font-bold tabular-nums" x-text="cell.count"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </template>

    {{-- ─── Per-alert chain sheet ─── --}}
    <template x-if="chain.open">
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="chain.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="chain.open=false"></div>
            <div class="relative w-full sm:max-w-xl bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-5 py-3 border-b">
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Ownership chain</p>
                        <h2 class="text-[14px] font-bold truncate">Alert #<span x-text="chain.alertId"></span></h2>
                    </div>
                    <a class="btn btn-outline btn-xs" :href="'/admin/alerts?focus='+chain.alertId">Open dossier</a>
                    <button class="btn btn-ghost btn-icon-xs" @click="chain.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-5 py-5">
                    <template x-if="chain.loading"><p class="text-sm text-muted-foreground">Loading…</p></template>
                    <ol class="relative border-l pl-5 space-y-3" x-show="!chain.loading">
                        <template x-for="e in chain.events" :key="e.id">
                            <li class="text-[12px]">
                                <span class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full" :class="codeDot(e.event_code)"></span>
                                <div class="flex items-center gap-2">
                                    <span class="badge" :class="codeBadge(e.event_code)" x-text="e.event_code"></span>
                                    <span class="text-[10.5px] text-muted-foreground" x-text="humanTime(e.created_at)"></span>
                                </div>
                                <p class="mt-1" x-text="e.summary"></p>
                                <p x-show="e.actor_name" class="text-[10.5px] text-muted-foreground mt-0.5">by <span x-text="e.actor_name"></span> <span x-show="e.actor_role" x-text="'· '+e.actor_role"></span></p>
                            </li>
                        </template>
                        <template x-if="!chain.loading && chain.events.length===0"><li class="text-[12px] text-muted-foreground">No ownership events.</li></template>
                    </ol>
                </div>
            </div>
        </div>
    </template>
</div>
@endsection

@push('scripts')
<script>
function ownershipTrail(){
    const headersJson=()=>({'Accept':'application/json','X-Requested-With':'XMLHttpRequest'});
    const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null&&v!==0&&v!=='0'&&!(Array.isArray(v)&&v.length===0)).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(Array.isArray(v)?v.join(','):v)).join('&');

    return {
        lens:'stream',
        codes:[], counters:{},
        filters:{codes:[], severity:'', from:'', to:'', q:''},
        rows:[], loading:false, loadingMore:false, nextCursor:null,
        matrix:{matrix:[], totals:{}}, matrixLoading:false,
        chain:{open:false, loading:false, alertId:null, events:[]},

        async boot(){
            await this.loadData();
            window.addEventListener('alert-coach:wizard', e => {
                if (!e?.detail || e.detail.view !== 'ownership') return;
                this.handleWizard(e.detail.code);
            });
        },

        handleWizard(code){
            switch (code) {
                case 'NOW_MATRIX': this.lens = 'matrix'; this.loadMatrix(); break;
                case 'STREAM':     this.lens = 'stream'; this.loadData();   break;
                case 'PENDING':
                    this.lens = 'stream';
                    this.filters.codes = ['HANDOFF_SENT','HANDOFF_ACKNOWLEDGED'];
                    this.loadData();
                    break;
                case 'WALK_CHAIN':
                    this.lens = 'stream'; this.loadData();
                    break;
            }
        },

        // Plain-language event label. Hover-title preserves the technical
        // code for users who want it.
        codeLabel(c){
            return ({
                OPENED:               'Case opened',
                ACKNOWLEDGED:         'Picked up',
                CLOSED:               'Case closed',
                REOPENED:             'Case reopened',
                ESCALATED:            'Escalated',
                REASSIGNED:           'Reassigned',
                HANDOFF_SENT:         'Handoff sent',
                HANDOFF_ACKNOWLEDGED: 'Handoff seen',
                HANDOFF_ACCEPTED:     'Handoff accepted',
                HANDOFF_REJECTED:     'Handoff rejected',
                HANDOFF_RECALLED:     'Handoff withdrawn',
            })[c] || c.replace(/_/g, ' ').toLowerCase().replace(/\b\w/, m => m.toUpperCase());
        },

        toggleCode(c){
            if(this.filters.codes.includes(c)){ this.filters.codes = this.filters.codes.filter(x=>x!==c); }
            else { this.filters.codes.push(c); }
            this.loadData();
        },

        async loadData(){
            this.loading=true; this.nextCursor=null;
            try{
                const params = {event_code:this.filters.codes, severity:this.filters.severity, from:this.filters.from, to:this.filters.to, q:this.filters.q};
                const r = await fetch('/admin/alerts/ownership/data?'+qs(params), {headers:headersJson()});
                const j = await r.json();
                if(j.success){
                    this.rows = j.data.rows;
                    this.nextCursor = j.data.next_cursor;
                    this.codes = j.meta.codes;
                    this.counters = j.meta.counters;
                    if(this.filters.codes.length===0) this.filters.codes = [...this.codes];
                    if(window.Alpine && Alpine.store('pageMeta')){ Alpine.store('pageMeta').rows=j.data.count; Alpine.store('pageMeta').kind='alert-ownership'; }
                }
            } finally { this.loading=false; }
        },
        async loadMore(){
            if(!this.nextCursor) return;
            this.loadingMore=true;
            try{
                const params = {event_code:this.filters.codes, severity:this.filters.severity, from:this.filters.from, to:this.filters.to, q:this.filters.q, cursor:this.nextCursor};
                const r = await fetch('/admin/alerts/ownership/data?'+qs(params), {headers:headersJson()});
                const j = await r.json();
                if(j.success){ this.rows = this.rows.concat(j.data.rows); this.nextCursor=j.data.next_cursor; }
            } finally { this.loadingMore=false; }
        },

        async loadMatrix(){
            this.matrixLoading=true;
            try{
                const r = await fetch('/admin/alerts/ownership/matrix', {headers:headersJson()});
                const j = await r.json();
                if(j.success){ this.matrix = j.data; if(window.Alpine && Alpine.store('pageMeta')){ Alpine.store('pageMeta').rows=j.data.totals.grand; Alpine.store('pageMeta').kind='alert-ownership'; } }
            } finally { this.matrixLoading=false; }
        },

        async openChain(alertId){
            this.chain={open:true, loading:true, alertId, events:[]};
            try{
                const r = await fetch('/admin/alerts/ownership/data?'+qs({alert_id:alertId, event_code:this.codes, per_page:100}), {headers:headersJson()});
                const j = await r.json();
                if(j.success){ this.chain.events = j.data.rows.reverse(); }
            } finally { this.chain.loading=false; }
        },

        codeBadge(c){
            if(c==='OPENED') return 'badge-info';
            if(c==='ACKNOWLEDGED') return 'badge-info';
            if(c==='ESCALATED') return 'badge-warning';
            if(c==='REASSIGNED') return 'badge-brand';
            if(c==='HANDOFF_SENT' || c==='HANDOFF_ACKNOWLEDGED') return 'badge-info';
            if(c==='HANDOFF_ACCEPTED') return 'badge-success';
            if(c==='HANDOFF_REJECTED' || c==='HANDOFF_RECALLED') return 'badge-critical';
            return 'badge-outline';
        },
        codeDot(c){
            if(c==='ESCALATED') return 'bg-warning';
            if(c==='REASSIGNED') return 'bg-brand';
            if(c==='HANDOFF_ACCEPTED') return 'bg-success';
            if(c==='HANDOFF_REJECTED' || c==='HANDOFF_RECALLED') return 'bg-critical';
            return 'bg-info';
        },
        humanTime(t){ if(!t) return '—'; try{ return new Date(t).toLocaleString(); }catch(e){ return t; } },
    };
}
</script>
@endpush
