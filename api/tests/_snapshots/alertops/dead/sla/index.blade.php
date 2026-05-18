@extends('admin.layout')

@section('crumb', 'Alert Lifecycle')
@section('title', 'Deadlines & misses')

@section('content')

@include('admin.alerts._partials.coach', [
    'viewKey'   => 'sla',
    'viewTitle' => 'Deadlines & misses',
    'oneLiner'  => 'Every case has deadlines. This screen shows which are coming up, which were missed, and why.',
    'why'       => 'A missed deadline does not change the case state — it just means we are slow. The system reminds the responsible person once a day until it is done. The screen also asks, after a miss, why it happened and what we will do differently.',
    'youDo'     => 'Look at "Coming up" to see what is due in the next 24 hours. Look at "Missed" to see which cases need attention now. After a miss, click "Record why" to log the cause — that data is what we use to fix the system over time.',
    'connects'  => 'Every miss writes an event to Case history. The Case dossier shows a per-case timeline that includes the deadlines and any notes you logged.',
    'glossary'  => [
        ['term'=>'Deadline',        'plain'=>'A target time the case should be at a stage by — picked up, acknowledged, resolved.', 'technical'=>'Computed from alerts.created_at + per-phase target_h.'],
        ['term'=>'Spot it',         'plain'=>'How long it took someone to notice the case (Detect phase).',        'technical'=>'DETECT — created_at → first ACKNOWLEDGED.'],
        ['term'=>'Notify',          'plain'=>'How long it took to inform the next level if needed (Notify phase).', 'technical'=>'NOTIFY — escalation/handoff window.'],
        ['term'=>'Respond',         'plain'=>'How long the response took once it began (Respond phase).',           'technical'=>'RESPOND — acknowledged → closed.'],
        ['term'=>'Missed',          'plain'=>'A deadline went past without the milestone being met. The case stays open and the responsible person is reminded once every 24 hours until done.', 'technical'=>'phase.elapsed_h > target_h AND no closing event.'],
        ['term'=>'Reason',          'plain'=>'A short note saying why the deadline was missed — for example "no responder available" or "awaiting external information".', 'technical'=>'alert_breach_reports.root_cause_category + root_cause_text.'],
        ['term'=>'Mitigation',      'plain'=>'What you will do so this kind of miss does not happen again.',        'technical'=>'alert_breach_reports.mitigation_plan.'],
        ['term'=>'24-hour rule',    'plain'=>'Reminders go out at most once every 24 hours per case per recipient. The system never spams.', 'technical'=>'NotificationDispatcher::SUPPRESSION_MINUTES = 1440 for reminder templates.'],
    ],
    'wizardOptions' => [
        ['code'=>'COMING_UP',     'label'=>'See deadlines coming up',          'help'=>'What is due in the next 24 hours.',                'glyph'=>'⌚', 'tone'=>'bg-blue-50 text-blue-700'],
        ['code'=>'MISSED',        'label'=>'See deadlines I have missed',      'help'=>'Cases that are past a target.',                    'glyph'=>'!', 'tone'=>'bg-rose-50 text-rose-700'],
        ['code'=>'RECORD_WHY',    'label'=>'Record why a deadline was missed', 'help'=>'Pick a missed case and log the reason + plan.',    'glyph'=>'⌨', 'tone'=>'bg-amber-50 text-amber-700'],
        ['code'=>'AGGREGATE',     'label'=>'See the patterns across many misses','help'=>'Aggregate view — by risk, district, root cause.', 'glyph'=>'∷', 'tone'=>'bg-violet-50 text-violet-700'],
    ],
    'charts' => [
        [
            'key'        => 'phase_bars',
            'title'      => 'Per-case progress bars (Spot it / Notify / Respond)',
            'shows'      => 'For each open case, how much of the deadline has elapsed in each phase.',
            'read'       => 'Each bar is one phase. Green is well under target. Amber is more than three quarters used. Red is over the target — the deadline is missed.',
            'good'       => 'Most rows are green or amber across all three phases.',
            'concerning' => 'Multiple red bars on the same row, or many rows with red in the same phase (a systemic failure).',
            'do'         => 'Open the case and find out why the phase stalled. If the deadline is missed, file a "why" note so we can fix the underlying issue.',
            'cant'       => 'It cannot tell you the responsible person was on leave or unreachable — only that the time elapsed.',
            'source'     => 'alerts in your scope where status IN (OPEN, ACKNOWLEDGED), with phase elapsed/target computed from created_at and the FSM events.',
        ],
        [
            'key'        => 'aggregate_breakdown',
            'title'      => 'Aggregate breakdowns (by risk, district, reason)',
            'shows'      => 'How misses are distributed — across risk levels, across districts, and across the reasons people gave.',
            'read'       => 'Each list is sorted highest count first. The number is misses; "/ open" tells you how many cases of that type were even at risk of missing.',
            'good'       => 'Misses concentrate in one or two reasons that you have a fix for.',
            'concerning' => 'A long tail of "Other" reasons — meaning the categories do not capture what is going wrong.',
            'do'         => 'If a category is unclear, propose a new one. If a district leads the list, look at staffing and routing for that district.',
            'cant'       => 'It cannot tell you whether a missed deadline was avoidable — only that it was missed.',
            'source'     => 'alert_breach_reports grouped by phase / risk_level / district / root_cause_category in your scope.',
        ],
    ],
])

<div x-data="slaBoard()" x-init="boot()" x-effect="window.adminLock.set('page', rcaWiz.open)" class="space-y-5">

    {{-- Lens toggle --}}
    <section class="flex items-center gap-2">
        <div class="tabs-list">
            <button class="tabs-trigger" :data-state="lens==='atrisk'?'active':'inactive'" @click="lens='atrisk'; loadData()">Coming up &amp; missed</button>
            <button class="tabs-trigger" :data-state="lens==='reports'?'active':'inactive'" @click="lens='reports'; loadReports()">Reasons recorded</button>
            <button class="tabs-trigger" :data-state="lens==='aggregate'?'active':'inactive'" @click="lens='aggregate'; loadAggregate()">Patterns</button>
        </div>
        <div class="flex-1"></div>
        <button type="button" class="text-[11px] text-blue-700 hover:underline" @click="window.alertCoach?.sla?.interp(lens==='aggregate' ? 'aggregate_breakdown' : 'phase_bars')">How to read this</button>
    </section>

    {{-- ─── At-risk feed ─── --}}
    <template x-if="lens==='atrisk'">
        <section class="space-y-3">
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                <div class="kpi"><p class="kpi-label">Open cases</p><p class="kpi-value tabular-nums" x-text="totals.open_ack ?? '—'"></p><p class="text-[11px] text-muted-foreground mt-1">in your area</p></div>
                <div class="kpi"><p class="kpi-label">Missed deadlines</p><p class="kpi-value tabular-nums text-critical" x-text="totals.breached ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">past their target</p></div>
                <div class="kpi"><p class="kpi-label">Closing in</p><p class="kpi-value tabular-nums text-warning" x-text="totals.at_risk ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">&gt; 75% of deadline used</p></div>
                <div class="kpi"><p class="kpi-label">Last refreshed</p><p class="kpi-value text-[12px]" x-text="humanTime(meta.computed_at)"></p><p class="text-[11px] text-muted-foreground mt-1">reminders fire once / 24h</p></div>
            </div>

            <div class="card">
                <div class="card-content !p-0">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-2 p-3 sm:p-4 border-b">
                        <select class="select w-auto !h-8 text-xs" x-model="filters.risk_level" @change="loadData()">
                            <option value="">Any risk</option><option>CRITICAL</option><option>HIGH</option><option>MEDIUM</option><option>LOW</option>
                        </select>
                        <select class="select w-auto !h-8 text-xs" x-model="filters.routed_to_level" @change="loadData()">
                            <option value="">Any level</option><option>DISTRICT</option><option>PHEOC</option><option>NATIONAL</option>
                        </select>
                        <label class="flex items-center gap-2 text-[12px]">
                            <input type="checkbox" x-model="filters.only_breached" @change="loadData()">
                            <span>Only breached</span>
                        </label>
                        <div class="flex-1"></div>
                        <button class="btn btn-outline btn-xs" @click="loadData()">Refresh</button>
                    </div>
                    <div class="table-wrap !rounded-none !border-0">
                        <table class="table">
                            <thead class="table-head"><tr>
                                <th class="table-head-th">Case</th>
                                <th class="table-head-th hidden md:table-cell" title="DETECT phase">Spot it</th>
                                <th class="table-head-th hidden md:table-cell" title="NOTIFY phase">Notify</th>
                                <th class="table-head-th hidden md:table-cell" title="RESPOND phase">Respond</th>
                                <th class="table-head-th text-right">Status</th>
                            </tr></thead>
                            <tbody class="table-body">
                                <template x-if="loading"><tr><td colspan="5" class="table-cell text-center py-8 text-muted-foreground text-sm">Computing…</td></tr></template>
                                <template x-if="!loading && rows.length===0"><tr><td colspan="5" class="table-cell"><div class="empty-state"><p class="text-sm">All clear — no at-risk alerts in scope.</p></div></td></tr></template>
                                <template x-for="row in rows" :key="row.id">
                                    <tr class="table-row" :class="row.any_breached ? 'bg-critical/5' : ''">
                                        <td class="table-cell"><div class="flex flex-col">
                                            <span class="text-[12.5px] font-semibold flex items-center gap-1">
                                                <span class="font-mono text-[10.5px] text-muted-foreground" x-text="row.alert_code"></span>
                                                <span class="badge" :class="riskBadge(row.risk_level)" x-text="row.risk_level"></span>
                                            </span>
                                            <span class="text-[11.5px] truncate" x-text="row.alert_title"></span>
                                            <span class="text-[10.5px] text-muted-foreground"><span x-text="row.poe_code"></span> · <span x-text="row.district_code"></span></span>
                                        </div></td>
                                        <template x-for="phase in ['DETECT','NOTIFY','RESPOND']" :key="phase">
                                            <td class="table-cell hidden md:table-cell">
                                                <template x-if="row.phases[phase]?.percent !== null">
                                                    <div>
                                                        <div class="flex items-center justify-between text-[10.5px]">
                                                            <span x-text="row.phases[phase].elapsed_h + 'h'"></span>
                                                            <span class="text-muted-foreground" x-text="'/'+ row.phases[phase].target_h + 'h'"></span>
                                                        </div>
                                                        <div class="h-1 mt-1 rounded-full bg-muted overflow-hidden">
                                                            <span class="block h-full" :style="`width:${row.phases[phase].percent}%`" :class="row.phases[phase].breached ? 'bg-critical' : (row.phases[phase].percent>75 ? 'bg-warning' : 'bg-success')"></span>
                                                        </div>
                                                    </div>
                                                </template>
                                                <template x-if="row.phases[phase]?.percent === null"><span class="text-[10px] text-muted-foreground">n/a (acked)</span></template>
                                            </td>
                                        </template>
                                        <td class="table-cell text-right">
                                            <span x-show="row.any_breached" class="badge badge-critical" title="Deadline missed">Missed</span>
                                            <span x-show="!row.any_breached && row.any_at_risk" class="badge badge-warning" title="More than 75% of the deadline used">Closing in</span>
                                            <button class="btn btn-outline btn-xs ml-1" @click="openRcaWiz(row)" x-show="row.any_breached" title="Record why this was missed">+ Why?</button>
                                            <a class="btn btn-ghost btn-xs ml-1" :href="'/admin/alerts/'+row.id+'/case-file'">Open dossier</a>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </template>

    {{-- ─── Reports lens ─── --}}
    <template x-if="lens==='reports'">
        <section class="space-y-3">
            <div class="card">
                <div class="card-content !p-0">
                    <div class="p-3 sm:p-4 border-b flex flex-wrap gap-2">
                        <div class="tabs-list">
                            <template x-for="t in [{key:'OPEN',label:'Open'},{key:'IN_PROGRESS',label:'In progress'},{key:'RESOLVED',label:'Resolved'},{key:'CANCELLED',label:'Cancelled'},{key:'ALL',label:'All'}]" :key="t.key">
                                <button class="tabs-trigger" :data-state="reportFilters.status===t.key?'active':'inactive'" @click="reportFilters.status=t.key; loadReports()">
                                    <span x-text="t.label"></span>
                                    <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="reportTabs[t.key.toLowerCase()] ?? 0"></span>
                                </button>
                            </template>
                        </div>
                        <div class="flex-1"></div>
                        <select class="select w-auto !h-8 text-xs" x-model="reportFilters.phase" @change="loadReports()">
                            <option value="">Any phase</option><option>DETECT</option><option>NOTIFY</option><option>RESPOND</option>
                        </select>
                    </div>
                    <div class="table-wrap !rounded-none !border-0">
                        <table class="table">
                            <thead class="table-head"><tr>
                                <th class="table-head-th">Phase / Breach</th>
                                <th class="table-head-th hidden md:table-cell">Alert</th>
                                <th class="table-head-th hidden lg:table-cell">Owner</th>
                                <th class="table-head-th hidden md:table-cell">Root cause</th>
                                <th class="table-head-th text-right">Status</th>
                            </tr></thead>
                            <tbody class="table-body">
                                <template x-if="reportLoading"><tr><td colspan="5" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading…</td></tr></template>
                                <template x-if="!reportLoading && reports.length===0"><tr><td colspan="5" class="table-cell"><div class="empty-state"><p class="text-sm">No breach reports filed.</p></div></td></tr></template>
                                <template x-for="row in reports" :key="row.id">
                                    <tr class="table-row">
                                        <td class="table-cell"><div class="flex flex-col">
                                            <span class="text-[12.5px] font-semibold flex items-center gap-1">
                                                <span class="badge badge-critical" x-text="row.phase"></span>
                                                <span>+<span x-text="row.breach_minutes"></span>m</span>
                                            </span>
                                            <span class="text-[10.5px] text-muted-foreground" x-text="humanTime(row.created_at)"></span>
                                        </div></td>
                                        <td class="table-cell hidden md:table-cell text-[11.5px]">
                                            <a class="font-mono text-brand hover:underline" :href="'/admin/alerts?focus='+row.alert_id" x-text="row.alert_code"></a>
                                            <div class="text-muted-foreground truncate max-w-[180px]" x-text="row.alert_title"></div>
                                        </td>
                                        <td class="table-cell hidden lg:table-cell text-[11.5px]" x-text="row.owner_name || '—'"></td>
                                        <td class="table-cell hidden md:table-cell text-[11.5px]">
                                            <span class="badge badge-outline" x-text="row.root_cause_category"></span>
                                            <p class="text-[11px] text-muted-foreground truncate max-w-[220px]" x-text="row.root_cause_text"></p>
                                        </td>
                                        <td class="table-cell text-right">
                                            <span class="badge" :class="rcaBadge(row.status)" x-text="row.status"></span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    </template>

    {{-- ─── Aggregate lens ─── --}}
    <template x-if="lens==='aggregate'">
        <section class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                <template x-for="phase in ['DETECT','NOTIFY','RESPOND']" :key="phase">
                    <div class="card">
                        <div class="card-content !p-3">
                            <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground" x-text="phase"></p>
                            <div class="flex items-baseline gap-2 mt-1">
                                <span class="text-[24px] font-bold tabular-nums" x-text="agg.per_phase?.[phase]?.breached ?? 0"></span>
                                <span class="text-[11px] text-muted-foreground">breached / <span x-text="agg.per_phase?.[phase]?.open ?? 0"></span> open</span>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                <div class="card">
                    <div class="card-header"><h3 class="card-title text-[13px]">By risk</h3></div>
                    <div class="card-content !pt-0">
                        <ul class="space-y-1.5 text-[12px]">
                            <template x-for="risk in ['CRITICAL','HIGH','MEDIUM','LOW']" :key="risk">
                                <li class="flex items-center gap-2">
                                    <span class="badge" :class="riskBadge(risk)" x-text="risk"></span>
                                    <span class="text-critical font-bold tabular-nums" x-text="agg.per_risk?.[risk]?.breached ?? 0"></span>
                                    <span class="text-muted-foreground">/ <span x-text="agg.per_risk?.[risk]?.open ?? 0"></span> open</span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h3 class="card-title text-[13px]">By district</h3></div>
                    <div class="card-content !pt-0 max-h-72 overflow-auto">
                        <ul class="space-y-1.5 text-[12px]">
                            <template x-for="[district, c] in Object.entries(agg.per_district || {})" :key="district">
                                <li class="flex items-center gap-2">
                                    <span x-text="district || 'unknown'"></span>
                                    <div class="flex-1"></div>
                                    <span class="text-critical font-bold tabular-nums" x-text="c.breached"></span>
                                    <span class="text-muted-foreground">/ <span x-text="c.open"></span></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h3 class="card-title text-[13px]">Root causes (filed)</h3></div>
                    <div class="card-content !pt-0">
                        <ul class="space-y-1 text-[12px]">
                            <template x-for="[cat, c] in Object.entries(agg.per_root_cause || {})" :key="cat">
                                <li class="flex items-center gap-2">
                                    <span class="badge badge-outline" x-text="cat"></span>
                                    <div class="flex-1"></div>
                                    <span class="font-bold tabular-nums" x-text="c"></span>
                                </li>
                            </template>
                            <li x-show="Object.keys(agg.per_root_cause||{}).length===0" class="text-muted-foreground">No reports filed yet.</li>
                        </ul>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header"><h3 class="card-title text-[13px]">Reports by status</h3></div>
                    <div class="card-content !pt-0">
                        <ul class="space-y-1 text-[12px]">
                            <template x-for="[status, c] in Object.entries(agg.reports_by_status || {})" :key="status">
                                <li class="flex items-center gap-2">
                                    <span class="badge" :class="rcaBadge(status)" x-text="status"></span>
                                    <div class="flex-1"></div>
                                    <span class="font-bold tabular-nums" x-text="c"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    </template>

    {{-- ─── RCA wizard ─── --}}
    <template x-if="rcaWiz.open">
        <div class="fixed inset-0 z-[55] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="rcaWiz.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="rcaWiz.open=false"></div>
            <div class="relative w-full max-w-lg card !rounded-xl !shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold">Why was this deadline missed?</h3>
                <p class="text-[12px] text-muted-foreground mt-1">For case <span class="font-mono" x-text="rcaWiz.alert?.alert_code"></span> — your note becomes part of the case history.</p>
                <div class="grid grid-cols-2 gap-2 mt-3">
                    <div>
                        <label class="label">Which phase missed <span class="text-critical">*</span></label>
                        <select class="select mt-1.5" x-model="rcaWiz.form.phase">
                            <option value="">Select…</option>
                            <option value="DETECT">Spot it</option>
                            <option value="NOTIFY">Notify</option>
                            <option value="RESPOND">Respond</option>
                        </select>
                    </div>
                    <div>
                        <label class="label">Target (hours) <span class="text-critical">*</span></label>
                        <input type="number" min="1" class="input mt-1.5" x-model.number="rcaWiz.form.target_hours">
                    </div>
                </div>
                <label class="label mt-3">Elapsed (hours) <span class="text-critical">*</span></label>
                <input type="number" step="0.1" min="0" class="input mt-1.5" x-model.number="rcaWiz.form.elapsed_hours">
                <label class="label mt-3">What was the underlying reason? <span class="text-critical">*</span></label>
                <select class="select mt-1.5" x-model="rcaWiz.form.root_cause_category">
                    <option value="">Select…</option>
                    <option value="RESOURCE_GAP">No responder available</option>
                    <option value="STAFFING">Staffing gap</option>
                    <option value="SYSTEM_OUTAGE">System or network outage</option>
                    <option value="COMMUNICATION">Communication gap</option>
                    <option value="POLICY_GAP">Policy or procedure gap</option>
                    <option value="EXTERNAL_DEPENDENCY">Awaiting external information</option>
                    <option value="WORKLOAD">Workload — too many cases at once</option>
                    <option value="TRAINING_GAP">Training gap</option>
                    <option value="OTHER">Other (explain below)</option>
                </select>
                <label class="label mt-3">Tell us what actually happened <span class="text-critical">*</span></label>
                <textarea class="input mt-1.5" rows="3" x-model="rcaWiz.form.root_cause_text" placeholder="One or two sentences."></textarea>
                <label class="label mt-3">What will be done so this doesn't recur? <span class="text-critical">*</span></label>
                <textarea class="input mt-1.5" rows="3" x-model="rcaWiz.form.mitigation_plan" placeholder="A short plan — who, by when."></textarea>
                <div class="rounded-md bg-blue-50 border border-blue-200 px-3 py-2.5 text-[11.5px] text-blue-900 mt-3">
                    <p>Filing this does not change the case state. The case stays open until the actual milestone is met. A reminder will go out at most once every 24 hours per recipient until then.</p>
                </div>
                <div class="flex justify-end gap-2 mt-4">
                    <button class="btn btn-outline btn-sm" @click="rcaWiz.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" :disabled="!rcaReady || rcaWiz.submitting" @click="performRca()">
                        <span x-show="!rcaWiz.submitting">Save reason</span><span x-show="rcaWiz.submitting">…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <div class="fixed inset-x-0 bottom-6 z-[70] flex justify-center px-3 pointer-events-none" x-show="opToast.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="opToast.kind==='success'?'toast-success':'toast-destructive'">
            <div><p class="toast-title" x-text="opToast.title"></p><p class="toast-description" x-text="opToast.body"></p></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function slaBoard(){
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()});
    const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null&&v!==false&&v!==0&&v!=='0').map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');

    return {
        lens:'atrisk',
        filters:{risk_level:'', routed_to_level:'', only_breached:false},
        rows:[], loading:false, totals:{}, meta:{},
        reportFilters:{status:'OPEN', phase:''},
        reports:[], reportLoading:false, reportTabs:{},
        agg:{},
        rcaWiz:{open:false, submitting:false, alert:null, form:{phase:'', target_hours:0, elapsed_hours:0, root_cause_category:'', root_cause_text:'', mitigation_plan:''}},
        opToast:{open:false,kind:'success',title:'',body:'',t:null},

        get rcaReady(){
            const f = this.rcaWiz.form;
            return f.phase && f.target_hours>0 && f.elapsed_hours>=0 && f.root_cause_category && f.root_cause_text.length>=3 && f.mitigation_plan.length>=3;
        },

        async boot(){
            await this.loadData();
            window.addEventListener('alert-coach:wizard', e => {
                if (!e?.detail || e.detail.view !== 'sla') return;
                this.handleWizard(e.detail.code);
            });
        },

        handleWizard(code){
            switch (code) {
                case 'COMING_UP':  this.lens='atrisk';   this.filters.only_breached=false; this.loadData();    break;
                case 'MISSED':     this.lens='atrisk';   this.filters.only_breached=true;  this.loadData();    break;
                case 'RECORD_WHY': this.lens='atrisk';   this.filters.only_breached=true;  this.loadData();    break;
                case 'AGGREGATE':  this.lens='aggregate'; this.loadAggregate(); break;
            }
        },

        async loadData(){
            this.loading=true;
            try{
                const r=await fetch('/admin/alerts/sla/data?'+qs(this.filters),{headers:{'Accept':'application/json'}});
                const j=await r.json();
                if(j.success){
                    this.rows = j.data.rows; this.totals = j.meta?.totals || {}; this.meta = j.meta || {};
                    if(window.Alpine && Alpine.store('pageMeta')){ Alpine.store('pageMeta').rows=this.totals.breached; Alpine.store('pageMeta').kind='alert-sla'; }
                }
            } finally { this.loading=false; }
        },
        async loadReports(){
            this.reportLoading=true;
            try{
                const r=await fetch('/admin/alerts/sla/reports?'+qs(this.reportFilters),{headers:{'Accept':'application/json'}});
                const j=await r.json();
                if(j.success){ this.reports = j.data.rows; this.reportTabs = j.meta?.tabs || {}; }
            } finally { this.reportLoading=false; }
        },
        async loadAggregate(){
            try{
                const r=await fetch('/admin/alerts/sla/aggregate',{headers:{'Accept':'application/json'}});
                const j=await r.json(); if(j.success) this.agg = j.data;
            } catch(e){}
        },

        openRcaWiz(row){
            const breached = Object.entries(row.phases || {}).filter(([_,p])=>p?.breached).map(([k,p])=>({phase:k, ...p}))[0];
            this.rcaWiz = {open:true, submitting:false, alert:row, form:{
                phase: breached?.phase || '',
                target_hours: breached?.target_h || 0,
                elapsed_hours: breached?.elapsed_h || 0,
                root_cause_category: '', root_cause_text: '', mitigation_plan: ''
            }};
        },
        async performRca(){
            this.rcaWiz.submitting=true;
            try{
                const r=await fetch('/admin/alerts/'+this.rcaWiz.alert.id+'/breach-reports',{method:'POST',headers:headersJson(),body:JSON.stringify(this.rcaWiz.form)});
                const j=await r.json();
                if(j.success){ this.toast('success','RCA filed','Breach root-cause logged.'); this.rcaWiz.open=false; await this.loadData(); }
                else this.toast('error','Failed', j.message);
            } catch(e){ this.toast('error','Network', e.message); } finally{ this.rcaWiz.submitting=false; }
        },

        riskBadge(r){ return ({CRITICAL:'badge-critical',HIGH:'badge-high',MEDIUM:'badge-medium',LOW:'badge-low'})[r] || 'badge-outline'; },
        rcaBadge(s){ return ({OPEN:'badge-warning',IN_PROGRESS:'badge-info',RESOLVED:'badge-success',CANCELLED:'badge-outline'})[s] || 'badge-outline'; },
        humanTime(t){ if(!t) return '—'; try{ return new Date(t).toLocaleString(); }catch(e){ return t; } },
        toast(kind,title,body){ this.opToast={open:true,kind,title,body,t:null}; clearTimeout(this.opToast.t); this.opToast.t=setTimeout(()=>{this.opToast.open=false;},3500); },
    };
}
</script>
@endpush
