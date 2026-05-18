@extends('admin.layout')

@section('crumb', 'Alert Lifecycle')
@section('title', 'Case follow-ups')

@section('content')
<div class="space-y-5">

    {{-- Coach + wizard launcher (shared partial) --}}
    @include('admin.alerts._partials.coach', [
        'viewKey'   => 'followups',
        'viewTitle' => 'Case follow-ups',
        'oneLiner'  => 'For each case, the steps that need to happen, who is responsible, and what is left to close it.',
        'why'       => 'Every alert opens with a small set of follow-up steps a responder must work through. This screen shows those steps grouped by case so you can see which one is closest to done and which is stuck.',
        'youDo'     => 'Pick a case. Mark the steps you have done. If a step is blocked, mark it stuck and write what you need. Steps marked "must be done before closing" hold the case open.',
        'connects'  => 'A case here is the same case as in Alerts (the hub) and Case Room. Closing a case is gated by the steps with "must be done before closing" finished.',
        'glossary'  => [
            ['term'=>'Case',         'plain'=>'A single suspected illness in one traveller. The system also calls it an "alert".', 'technical'=>'Row in the alerts table; alert_code is its public identifier.'],
            ['term'=>'Follow-up step','plain'=>'One thing that has to happen as part of working a case (for example: take a sample, isolate the traveller, notify a contact).', 'technical'=>'Row in alert_followups, action_code identifies the step.'],
            ['term'=>'Blocks closing','plain'=>'A step the case cannot be closed without. While it is open or stuck, the case stays open.', 'technical'=>'alert_followups.blocks_closure = 1.'],
            ['term'=>'Stuck',        'plain'=>'A step you tried but cannot finish — usually because someone else has to do something first.', 'technical'=>'status = BLOCKED.'],
            ['term'=>'Skipped',      'plain'=>'A step that does not apply to this case (for example, the contact list is irrelevant if there were no contacts).', 'technical'=>'status = NOT_APPLICABLE.'],
            ['term'=>'Overdue',      'plain'=>'A step whose deadline has passed and is still not done. The case stays open and the responsible person is reminded once a day until it is done.', 'technical'=>'due_at < now AND status IN (PENDING, IN_PROGRESS, BLOCKED).'],
        ],
        'wizardOptions' => [
            ['code'=>'BY_CASE',      'label'=>'See everything that needs doing on a case', 'help'=>'Pick a case and view its open steps in one place.', 'glyph'=>'⚏', 'tone'=>'bg-blue-50 text-blue-700'],
            ['code'=>'MARK_DONE',    'label'=>'Mark a step as done',                       'help'=>'Find a step you have completed and tick it off.',         'glyph'=>'✓', 'tone'=>'bg-emerald-50 text-emerald-700'],
            ['code'=>'BLOCKERS',     'label'=>'Show what is blocking a case from closing','help'=>'Only the steps that hold cases open.',                    'glyph'=>'!', 'tone'=>'bg-amber-50 text-amber-700'],
            ['code'=>'WALK',         'label'=>'Walk me through one case',                 'help'=>'Open the case dossier — every step, every comment, every event.', 'glyph'=>'→', 'tone'=>'bg-violet-50 text-violet-700'],
            ['code'=>'MINE_RECENT',  'label'=>'Find a case I worked on recently',         'help'=>'Filter to your own completions in the last 14 days.',      'glyph'=>'⌚', 'tone'=>'bg-slate-100 text-slate-700'],
        ],
        'charts' => [
            [
                'key'        => 'progress_ring',
                'title'      => 'Per-case progress ring',
                'shows'      => 'How many of a case\'s follow-up steps are done versus open.',
                'read'       => 'A full green ring means every step is done or skipped. The wedge shows how much is left. The red sliver is steps that are stuck or overdue.',
                'good'       => 'Cases close their rings within their deadlines.',
                'concerning' => 'A case that has sat at the same wedge for days, or a case with a red sliver and a missed deadline.',
                'do'         => 'Click the case to see which steps are still open. If they are stuck, find out what each one is waiting for.',
                'cant'       => 'It cannot tell you whether a step took the right action — only whether someone marked it done.',
                'source'     => 'alert_followups for one alert, grouped by status, for cases you can see.',
            ],
        ],
    ])

    <div
        x-data="followupsHub({
            endpoints: {
                data:     '{{ route('admin.alerts.followups.data') }}',
                meta:     '{{ route('admin.alerts.followups.meta') }}',
                update:   '{{ url('/admin/alerts/followups') }}',
                wizardOf:   function (id) { return '{{ url('/admin/alerts') }}/' + id + '/wizard'; },
                casefileOf: function (id) { return '{{ url('/admin/alerts') }}/' + id + '/case-file'; },
            }
        })"
        x-init="boot()"
        class="space-y-3"
    >

        {{-- KPI STRIP --}}
        <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="kpi kpi-glow">
                <p class="kpi-label">Not started</p>
                <p class="kpi-value tabular-nums" x-text="tabCounts.pending ?? '—'"></p>
                <p class="text-[11px] text-muted-foreground mt-1">need to begin</p>
            </div>
            <div class="kpi">
                <p class="kpi-label">In progress</p>
                <p class="kpi-value tabular-nums text-info" x-text="tabCounts.in_progress ?? 0"></p>
                <p class="text-[11px] text-muted-foreground mt-1">being worked on</p>
            </div>
            <div class="kpi">
                <p class="kpi-label">Past deadline</p>
                <p class="kpi-value tabular-nums text-destructive" x-text="tabCounts.overdue ?? 0"></p>
                <p class="text-[11px] text-muted-foreground mt-1">deadline already passed</p>
            </div>
            <div class="kpi">
                <p class="kpi-label">Stuck</p>
                <p class="kpi-value tabular-nums text-warning" x-text="tabCounts.blocked ?? 0"></p>
                <p class="text-[11px] text-muted-foreground mt-1">need help to move</p>
            </div>
        </section>

        {{-- LENS TOGGLE: By case (primary) · By step --}}
        <section class="card">
            <div class="card-content !p-0">
                <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3 min-w-0">
                        <div class="tabs-list w-full sm:w-auto">
                            <button class="tabs-trigger flex-1 sm:flex-none"
                                    :data-state="lens==='by_case'?'active':'inactive'"
                                    @click="lens='by_case'; loadData()">
                                <span>By case</span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="cases.length"></span>
                            </button>
                            <button class="tabs-trigger flex-1 sm:flex-none"
                                    :data-state="lens==='by_step'?'active':'inactive'"
                                    @click="lens='by_step'; loadData()">By step</button>
                            <button class="tabs-trigger flex-1 sm:flex-none"
                                    :data-state="lens==='blocked'?'active':'inactive'"
                                    @click="lens='blocked'; loadData()">Blocking closure
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="tabCounts.blocking_closure ?? 0"></span>
                            </button>
                        </div>
                        <div class="flex-1"></div>
                        <input type="search" class="input w-full sm:w-72" placeholder="Search by case or place…"
                               x-model.debounce.300ms="filters.q" @input="loadData()">
                    </div>
                    <div class="flex flex-wrap gap-2" x-show="lens==='by_step'">
                        <template x-for="t in stepTabs" :key="t.key">
                            <button class="chip"
                                    :class="filters.status === t.key ? 'chip-on' : 'chip-off'"
                                    @click="filters.status = t.key; loadData()">
                                <span x-text="t.label"></span>
                                <span class="ml-1 opacity-70" x-text="tabCounts[t.key] ?? 0"></span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- ─── BY CASE LENS ─── --}}
                <template x-if="lens==='by_case'">
                    <div>
                        <div class="divide-y" x-show="!loading && cases.length > 0">
                            <template x-for="c in cases" :key="c.alert_id">
                                <div class="px-4 sm:px-5 py-4 hover:bg-muted/30 transition">
                                    <div class="flex items-start gap-3 min-w-0">
                                        {{-- Per-case progress ring --}}
                                        <button type="button"
                                                class="shrink-0 relative grid place-items-center w-12 h-12 rounded-full"
                                                @click="window.alertCoach.followups.interp('progress_ring')"
                                                :title="`${c.done_count}/${c.total_count} steps done`">
                                            <svg viewBox="0 0 36 36" class="w-12 h-12 -rotate-90">
                                                <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="rgba(15,23,42,0.08)" stroke-width="3"/>
                                                <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="#10b981" stroke-width="3"
                                                        :stroke-dasharray="`${ringSlice(c.done_pct)} 100`"
                                                        stroke-dashoffset="0"/>
                                                <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="#f43f5e" stroke-width="3"
                                                        :stroke-dasharray="`${ringSlice(c.alert_pct)} 100`"
                                                        :stroke-dashoffset="`${-ringSlice(c.done_pct)}`"
                                                        x-show="c.alert_pct > 0"/>
                                            </svg>
                                            <span class="absolute text-[10px] font-bold tabular-nums text-slate-700"
                                                  x-text="`${c.done_count}/${c.total_count}`"></span>
                                        </button>

                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-start gap-2 flex-wrap min-w-0">
                                                <span class="font-medium text-[14px] leading-snug break-words">
                                                    <span x-text="c.traveller_name || 'Unnamed traveller'"></span>
                                                    <span class="font-normal text-slate-500" x-text="`(${c.classification || 'Under review'})`"></span>
                                                </span>
                                                <span x-show="c.blocking_count > 0"
                                                      class="inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-semibold bg-amber-100 text-amber-700"
                                                      x-text="`${c.blocking_count} step(s) hold this open`"></span>
                                                <span x-show="c.overdue_count > 0"
                                                      class="inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-semibold bg-rose-100 text-rose-700"
                                                      x-text="`${c.overdue_count} past deadline`"></span>
                                            </div>
                                            <p class="mt-1 text-[12px] text-muted-foreground" x-text="caseSubtitle(c)"></p>

                                            {{-- Step pills grouped by category --}}
                                            <div class="mt-3 space-y-2">
                                                <template x-for="grp in groupSteps(c.followups)" :key="grp.key">
                                                    <div>
                                                        <p class="text-[10px] uppercase tracking-[0.1em] text-slate-500 font-semibold mb-1" x-text="grp.label"></p>
                                                        <div class="flex flex-wrap gap-1.5">
                                                            <template x-for="f in grp.items" :key="f.id">
                                                                <button type="button"
                                                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-medium border"
                                                                        :class="stepPillClass(f)"
                                                                        :title="`${f.action_label} — ${f.human?.status_label || f.status}`"
                                                                        @click="openStep(f, c)">
                                                                    <span x-text="stepGlyph(f)"></span>
                                                                    <span class="truncate max-w-[180px]" x-text="f.human?.title || f.action_label"></span>
                                                                </button>
                                                            </template>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </div>

                                        <div class="text-right shrink-0 hidden sm:flex flex-col gap-1.5">
                                            <a class="btn btn-outline btn-xs" :href="endpoints.casefileOf(c.alert_id)">Open dossier</a>
                                            <a class="btn btn-ghost btn-xs" :href="endpoints.wizardOf(c.alert_id)">Walk through</a>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="px-6 py-12 text-center" x-show="!loading && cases.length === 0">
                            <p class="text-sm text-muted-foreground">Nothing here right now.</p>
                            <p class="mt-1 text-xs text-muted-foreground">When a case opens, its steps will appear under it.</p>
                        </div>
                        <div class="px-6 py-12 text-center" x-show="loading">
                            <p class="text-sm text-muted-foreground">Loading…</p>
                        </div>
                    </div>
                </template>

                {{-- ─── BY STEP LENS (legacy flat list, kept for power users) ─── --}}
                <template x-if="lens==='by_step' || lens==='blocked'">
                    <div>
                        <div class="divide-y" x-show="!loading && rows.length > 0">
                            <template x-for="row in rows" :key="row.id">
                                <button type="button"
                                        class="w-full flex items-start gap-3 px-4 sm:px-5 py-3.5 text-left hover:bg-muted/40 transition"
                                        @click="openStep(row, null)">
                                    <span class="mt-1 inline-flex w-6 h-6 items-center justify-center rounded-full text-[11px] font-bold flex-shrink-0"
                                          :class="stepPillClass(row, true)"
                                          x-text="stepGlyph(row)"></span>
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2 flex-wrap">
                                            <span class="font-medium text-[14px] leading-tight" x-text="row.human?.title || row.action_label"></span>
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-semibold"
                                                  :class="statusPillClass(row)"
                                                  x-text="row.human?.status_label || row.status"></span>
                                            <span x-show="row.blocks_closure" class="inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-semibold bg-blue-50 text-blue-700">Must be done before closing</span>
                                        </div>
                                        <p class="mt-1 text-[12.5px] text-muted-foreground" x-text="row.human?.short || ''"></p>
                                        <p class="mt-1 text-[11.5px] text-muted-foreground" x-text="rowSubtitle(row)"></p>
                                    </div>
                                    <div class="text-right shrink-0 min-w-[100px]">
                                        <p class="text-[11.5px] tabular-nums font-medium"
                                           :class="row.is_overdue ? 'text-rose-700' : 'text-muted-foreground'"
                                           x-text="row.human?.due_human || ''"></p>
                                        <p class="text-[11px] text-muted-foreground mt-1" x-show="row.completed_by_name"
                                           x-text="`done by ${row.completed_by_name}`"></p>
                                    </div>
                                </button>
                            </template>
                        </div>
                        <div class="px-6 py-12 text-center" x-show="!loading && rows.length === 0">
                            <p class="text-sm text-muted-foreground">Nothing here right now.</p>
                            <p class="mt-1 text-xs text-muted-foreground">When new cases open, the steps to do appear here.</p>
                        </div>
                        <div class="px-6 py-12 text-center" x-show="loading">
                            <p class="text-sm text-muted-foreground">Loading…</p>
                        </div>
                    </div>
                </template>

            </div>
        </section>

        {{-- ─── STEP DETAIL + STATUS-CHANGE SHEET ─── --}}
        <div x-show="step.open" x-cloak
             class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-md flex items-end sm:items-center justify-center"
             @keydown.escape.window="step.open = false">
            <div class="bg-white w-full h-full sm:h-auto sm:max-h-[90vh] sm:max-w-xl sm:rounded-3xl shadow-2xl flex flex-col overflow-hidden"
                 @click.away="step.open = false">
                <header class="px-5 sm:px-7 pt-5 pb-3 shrink-0 border-b border-slate-100">
                    <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-500 font-semibold" x-text="step.case ? (step.case.traveller_name || 'Step') : 'Step'"></p>
                    <h3 class="mt-1 text-base sm:text-lg font-bold text-slate-900" x-text="step.row?.human?.title || step.row?.action_label"></h3>
                    <p class="text-[12px] text-slate-500 mt-1" x-text="step.row?.human?.short"></p>
                </header>
                <div class="overflow-y-auto px-5 sm:px-7 py-4 grow space-y-4">
                    <div class="rounded-xl bg-slate-50 border border-slate-200 px-3 py-2.5 text-[12px] text-slate-700">
                        <p><span class="font-semibold">Case:</span> <a class="underline" x-show="step.row" :href="step.row ? endpoints.casefileOf(step.row.alert_id) : '#'" x-text="step.row?.alert_code || ''"></a> · <span x-text="step.row?.alert_title"></span></p>
                        <p class="mt-1"><span class="font-semibold">Currently:</span> <span x-text="step.row?.human?.status_label || step.row?.status"></span><span x-show="step.row?.blocks_closure"> · holds the case open</span></p>
                        <p class="mt-1" x-show="step.row?.human?.due_human"><span class="font-semibold">Deadline:</span> <span x-text="step.row?.human?.due_human"></span></p>
                    </div>

                    <div>
                        <p class="text-[11px] uppercase tracking-[0.1em] text-slate-500 font-semibold mb-2">Set the status</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <template x-for="opt in statusOptions" :key="opt.code">
                                <button type="button"
                                        class="text-left rounded-xl border border-slate-200 px-3 py-2.5 hover:border-slate-400 hover:shadow-sm transition"
                                        :class="step.draft.status === opt.code ? 'ring-2 ring-emerald-500 border-emerald-300 bg-emerald-50/40' : ''"
                                        @click="step.draft.status = opt.code">
                                    <p class="text-[12.5px] font-semibold" x-text="opt.label"></p>
                                    <p class="text-[11.5px] text-slate-500 mt-0.5" x-text="opt.help"></p>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div x-show="step.draft.status === 'BLOCKED' || step.draft.status === 'NOT_APPLICABLE'">
                        <p class="text-[11px] uppercase tracking-[0.1em] text-slate-500 font-semibold mb-1">Why? <span class="text-rose-600">required</span></p>
                        <textarea class="input" rows="3" maxlength="500" x-model="step.draft.notes" placeholder="One sentence so the next person knows what's happening."></textarea>
                    </div>
                    <div x-show="step.draft.status === 'COMPLETED'">
                        <p class="text-[11px] uppercase tracking-[0.1em] text-slate-500 font-semibold mb-1">Note (optional)</p>
                        <textarea class="input" rows="2" maxlength="500" x-model="step.draft.notes" placeholder="Optional — what you did, where the evidence is."></textarea>
                    </div>

                    <div class="rounded-xl bg-blue-50 border border-blue-200 px-3 py-2.5 text-[11.5px] text-blue-900" x-show="step.draft.status && step.draft.status !== step.row?.status">
                        <p><span class="font-semibold">Before you confirm:</span> <span x-text="confirmExplain()"></span></p>
                        <p class="mt-1" x-show="willTriggerReminder()">A reminder for this step will go out at most once every 24 hours until it is done. We don't fill your inbox.</p>
                    </div>
                </div>
                <footer class="px-5 sm:px-7 py-3 border-t border-slate-100 shrink-0 flex items-center gap-2">
                    <button type="button" class="btn btn-ghost btn-sm" @click="step.open = false">Cancel</button>
                    <div class="flex-1"></div>
                    <a class="btn btn-outline btn-sm" x-show="step.row" :href="step.row ? endpoints.wizardOf(step.row.alert_id) : '#'">Walk through case</a>
                    <button type="button" class="btn btn-brand btn-sm"
                            :disabled="!stepReady() || step.submitting"
                            @click="commitStep()">
                        <span x-show="!step.submitting">Save</span>
                        <span x-show="step.submitting">Saving…</span>
                    </button>
                </footer>
            </div>
        </div>

        {{-- POST-COMMIT SUMMARY --}}
        <div x-show="summary.open" x-cloak
             class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-md flex items-end sm:items-center justify-center"
             @keydown.escape.window="summary.open = false">
            <div class="bg-white w-full sm:max-w-md sm:rounded-3xl shadow-2xl overflow-hidden" @click.away="summary.open = false">
                <header class="px-5 sm:px-6 pt-5 pb-3 border-b border-slate-100">
                    <p class="text-[10.5px] uppercase tracking-[0.12em] text-emerald-700 font-semibold">Saved</p>
                    <h3 class="mt-1 text-base font-bold text-slate-900" x-text="summary.title"></h3>
                </header>
                <div class="px-5 sm:px-6 py-4 text-[13px] text-slate-700 space-y-2">
                    <p x-text="summary.changed"></p>
                    <p class="text-[11.5px] text-slate-500" x-text="summary.next"></p>
                </div>
                <footer class="px-5 sm:px-6 py-3 border-t border-slate-100 flex justify-end gap-2">
                    <button type="button" class="btn btn-outline btn-sm" @click="summary.open = false">Close</button>
                    <a class="btn btn-brand btn-sm" :href="summary.dossierUrl">Open dossier</a>
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
function followupsHub(opts) {
    // ─────────────────────────────────────────────────────────────
    // PLAIN-LANGUAGE STEP CATEGORIES
    // The 14 RTSL follow-up items collapse into four buckets so the user
    // sees a small, named structure instead of fourteen identical pills.
    // We map by action_code prefix; anything unknown falls into "Other".
    // Versioned for review — `v1, domain sign-off pending`.
    const STEP_CATEGORIES = [
        { key:'isolate',  label:'Care for the traveller',
          codes:['ISOLATE','CLINICAL_REVIEW','REFER_FACILITY','SAMPLE_TAKEN'] },
        { key:'notify',   label:'Tell the right people',
          codes:['NOTIFY_DISTRICT','NOTIFY_PHEOC','NOTIFY_NATIONAL','NOTIFY_WHO'] },
        { key:'investigate','label':'Investigate the case',
          codes:['CONTACT_LIST','TRIP_HISTORY','EXPOSURE_REVIEW','LAB_CONFIRMATION'] },
        { key:'close',    label:'Wrap up and record',
          codes:['EVIDENCE_PACK','RTSL_FORM','RTSL_SUBMIT','OUTCOME_RECORDED'] },
    ];
    function classifyCode(code){
        const c = String(code||'').toUpperCase();
        for (const g of STEP_CATEGORIES) if (g.codes.some(p => c.startsWith(p) || c === p)) return g;
        return { key:'other', label:'Other steps' };
    }

    return {
        endpoints: opts.endpoints,
        lens: 'by_case',
        rows: [], cases: [], meta: null, tabCounts: {}, loading: true,
        filters: { status: 'pending', q: '' },

        stepTabs: [
            { key: 'pending',     label: 'Not started' },
            { key: 'in_progress', label: 'In progress' },
            { key: 'overdue',     label: 'Past deadline' },
            { key: 'blocked',     label: 'Stuck' },
            { key: 'completed',   label: 'Done' },
            { key: 'na',          label: 'Skipped' },
            { key: 'all',         label: 'All' },
        ],

        statusOptions: [
            { code:'IN_PROGRESS',    label:'I\'m working on it',     help:'Mark this as picked up. The system stops nudging the team until you finish.' },
            { code:'COMPLETED',      label:'It\'s done',             help:'Mark this step finished. If it was holding the case open, that block clears.' },
            { code:'BLOCKED',        label:'I\'m stuck',             help:'You tried but cannot finish. Tell us why so the next person knows.' },
            { code:'NOT_APPLICABLE', label:'Doesn\'t apply here',    help:'This step is not relevant to this case.' },
            { code:'PENDING',        label:'Hand it back',           help:'Return to "not started" so someone else can pick it up.' },
        ],

        step:    { open:false, row:null, case:null, draft:{ status:'', notes:'' }, submitting:false },
        summary: { open:false, title:'', changed:'', next:'', dossierUrl:'#' },
        toast:   { show:false, message:'', tone:'ok', timer:null },

        async boot() {
            await this.loadMeta();
            await this.loadData();
            window.addEventListener('alert-coach:wizard', e => {
                if (!e?.detail || e.detail.view !== 'followups') return;
                this.handleWizard(e.detail.code);
            });
        },

        csrfToken() { return document.querySelector('meta[name="csrf-token"]').content; },

        showToast(message, tone='ok', ms=2500) {
            clearTimeout(this.toast.timer);
            this.toast.message = message; this.toast.tone = tone; this.toast.show = true;
            this.toast.timer = setTimeout(() => this.toast.show = false, ms);
        },

        async fetchJson(url, init={}) {
            try {
                const res = await fetch(url, {
                    credentials: 'same-origin', ...init,
                    headers: { 'Accept':'application/json', 'X-Requested-With':'XMLHttpRequest', 'X-CSRF-TOKEN':this.csrfToken(), ...(init.headers||{}) },
                });
                const body = await res.json().catch(() => ({}));
                if (!res.ok || body.success === false) {
                    this.showToast(body?.error?.human || body?.message || 'Something went wrong.', 'error');
                    return null;
                }
                return body;
            } catch (e) { this.showToast('Lost connection. Try again.', 'error'); return null; }
        },

        async loadMeta() { const body = await this.fetchJson(this.endpoints.meta); if (body) this.meta = body.data; },

        async loadData() {
            this.loading = true;
            const params = new URLSearchParams();
            // For by_case lens we pull "all" and group client-side — the server
            // already caps at 500 rows in scope. For by_step we honour the tab.
            if (this.lens === 'by_case') params.set('status', 'all');
            else if (this.lens === 'blocked') { params.set('status','all'); params.set('blocks_closure', '1'); }
            else                          params.set('status', this.filters.status);
            if (this.filters.q) params.set('q', this.filters.q);

            const body = await this.fetchJson(`${this.endpoints.data}?${params.toString()}`);
            this.loading = false;
            if (!body) return;

            const all = body.data.rows || [];
            this.tabCounts = (body.meta && body.meta.tabs) || {};

            if (this.lens === 'by_case') {
                this.cases = this.groupByCase(all);
                this.rows  = [];
            } else if (this.lens === 'blocked') {
                this.rows  = all.filter(r => r.blocks_closure && !['COMPLETED','NOT_APPLICABLE'].includes(r.status));
                this.cases = [];
                this.tabCounts.blocking_closure = this.rows.length;
            } else {
                this.rows  = all;
                this.cases = [];
            }
        },

        groupByCase(rows) {
            const byId = new Map();
            for (const r of rows) {
                if (!byId.has(r.alert_id)) {
                    byId.set(r.alert_id, {
                        alert_id: r.alert_id,
                        alert_code: r.alert_code,
                        // The followups API does not return traveller name or
                        // disease classification directly. Fall back to the
                        // alert_title (already computed by the API to be a
                        // human phrase) and render the alert_code as the
                        // identifier when no name exists.
                        traveller_name: r.alert_title || ('Case ' + r.alert_code),
                        classification: r.human?.alert_status_label || 'Under review',
                        district_code: r.district_code, poe_code: r.poe_code,
                        routed_to: r.human?.alert_routed_to || null,
                        followups: [],
                    });
                }
                byId.get(r.alert_id).followups.push(r);
            }
            const cases = Array.from(byId.values()).map(c => {
                const total     = c.followups.length;
                const done      = c.followups.filter(f => ['COMPLETED','NOT_APPLICABLE'].includes(f.status)).length;
                const alertish  = c.followups.filter(f => f.is_overdue || f.status === 'BLOCKED').length;
                const blocking  = c.followups.filter(f => f.blocks_closure && !['COMPLETED','NOT_APPLICABLE'].includes(f.status)).length;
                const overdue   = c.followups.filter(f => f.is_overdue).length;
                return {
                    ...c,
                    total_count: total, done_count: done,
                    blocking_count: blocking, overdue_count: overdue,
                    done_pct:  total ? Math.round((done / total) * 100) : 0,
                    alert_pct: total ? Math.round((alertish / total) * 100) : 0,
                };
            });
            // Sort: most blocking first, then most overdue, then most pending.
            cases.sort((a,b) => (b.blocking_count - a.blocking_count) || (b.overdue_count - a.overdue_count) || (b.total_count - b.done_count - (a.total_count - a.done_count)));
            return cases;
        },

        groupSteps(steps) {
            const byKey = new Map();
            for (const f of steps) {
                const g = classifyCode(f.action_code);
                if (!byKey.has(g.key)) byKey.set(g.key, { key:g.key, label:g.label, items: [] });
                byKey.get(g.key).items.push(f);
            }
            // Stable order: care, notify, investigate, close, other
            const order = ['isolate','notify','investigate','close','other'];
            return Array.from(byKey.values()).sort((a,b) => order.indexOf(a.key) - order.indexOf(b.key));
        },

        ringSlice(pct) { return Math.max(0, Math.min(100, Number(pct) || 0)); },

        stepGlyph(f) {
            if (f.status === 'COMPLETED')      return '✓';
            if (f.status === 'NOT_APPLICABLE') return '−';
            if (f.status === 'BLOCKED')        return '!';
            if (f.is_overdue)                  return '!';
            if (f.status === 'IN_PROGRESS')    return '◐';
            return '○';
        },
        stepPillClass(f, big=false) {
            const tone = f.is_overdue ? 'urgent' :
                f.status === 'BLOCKED' ? 'urgent' :
                f.status === 'COMPLETED' ? 'done' :
                f.status === 'NOT_APPLICABLE' ? 'skipped' :
                f.status === 'IN_PROGRESS' ? 'watch' : 'info';
            if (big) {
                return ({
                    urgent:  'bg-rose-100 text-rose-700',
                    watch:   'bg-amber-100 text-amber-700',
                    done:    'bg-emerald-100 text-emerald-700',
                    skipped: 'bg-slate-100 text-slate-500',
                    info:    'bg-slate-100 text-slate-600',
                })[tone];
            }
            return ({
                urgent:  'bg-rose-50 text-rose-700 border-rose-200',
                watch:   'bg-amber-50 text-amber-700 border-amber-200',
                done:    'bg-emerald-50 text-emerald-700 border-emerald-200',
                skipped: 'bg-slate-50 text-slate-500 border-slate-200',
                info:    'bg-white text-slate-700 border-slate-200',
            })[tone];
        },
        statusPillClass(f) {
            return ({
                urgent:  'bg-rose-100 text-rose-700',
                watch:   'bg-amber-100 text-amber-700',
                done:    'bg-emerald-100 text-emerald-700',
                skipped: 'bg-slate-100 text-slate-500',
                info:    'bg-slate-100 text-slate-600',
            })[f.human?.status_tone] || 'bg-slate-100 text-slate-600';
        },

        rowSubtitle(row) {
            const parts = [];
            if (row.alert_title)   parts.push(`Case: ${row.alert_title}`);
            if (row.poe_code)      parts.push(row.poe_code);
            if (row.district_code) parts.push(row.district_code);
            if (row.human?.alert_routed_to) parts.push('with ' + row.human.alert_routed_to);
            return parts.join(' · ');
        },
        caseSubtitle(c) {
            const parts = [];
            if (c.poe_code)      parts.push(c.poe_code);
            if (c.district_code) parts.push(c.district_code);
            if (c.routed_to)     parts.push('with ' + c.routed_to);
            return parts.join(' · ') || 'In your area';
        },

        // ── Step open / save / commit ──
        openStep(row, caseRef) {
            this.step = { open:true, row, case: caseRef, draft:{ status: row.status, notes: row.notes || '' }, submitting: false };
        },
        stepReady() {
            const d = this.step.draft;
            if (!d.status) return false;
            if ((d.status === 'BLOCKED' || d.status === 'NOT_APPLICABLE') && (!d.notes || d.notes.trim().length < 3)) return false;
            return d.status !== this.step.row?.status || (d.notes || '') !== (this.step.row?.notes || '');
        },
        confirmExplain() {
            const d = this.step.draft.status;
            const f = this.step.row;
            switch (d) {
                case 'COMPLETED':
                    return f?.blocks_closure
                        ? 'This step holds the case open. Marking it done removes that block.'
                        : 'The team will see this step is finished.';
                case 'BLOCKED':       return 'The case stays open and the team is reminded once a day until you can resume.';
                case 'NOT_APPLICABLE':return 'The step is dismissed for this case. If it was blocking closure, that block clears.';
                case 'IN_PROGRESS':   return 'You\'re marking this picked up. Reminders pause while you work on it.';
                case 'PENDING':       return 'The step returns to "not started" so anyone can take it.';
            }
            return '';
        },
        willTriggerReminder() {
            const d = this.step.draft.status;
            return d === 'BLOCKED' || d === 'PENDING';
        },
        async commitStep() {
            const id  = this.step.row.id;
            const url = `${this.endpoints.update}/${id}`;
            this.step.submitting = true;
            const before = this.step.row.status;

            // Optimistic update
            const idx  = this.rows.findIndex(r => r.id === id);
            const optimistic = { ...this.step.row, status: this.step.draft.status, notes: this.step.draft.notes || this.step.row.notes };
            if (idx >= 0) this.rows.splice(idx, 1, optimistic);
            this.cases = this.cases.map(c => ({ ...c, followups: c.followups.map(f => f.id === id ? optimistic : f) }));

            const body = await this.fetchJson(url, {
                method: 'PATCH',
                headers: { 'Content-Type':'application/json' },
                body: JSON.stringify({ status: this.step.draft.status, notes: this.step.draft.notes || null }),
            });

            this.step.submitting = false;
            if (!body) {
                // Roll back optimistic update.
                if (idx >= 0) this.rows.splice(idx, 1, this.step.row);
                this.cases = this.cases.map(c => ({ ...c, followups: c.followups.map(f => f.id === id ? this.step.row : f) }));
                this.showToast('Could not save. Try again.', 'error');
                return;
            }

            this.step.open = false;
            this.summary = {
                open: true,
                title: `${this.step.row?.human?.title || this.step.row?.action_label}: saved`,
                changed: `Status: ${before} → ${this.step.draft.status}.${this.step.draft.notes ? ' Note recorded.' : ''}`,
                next: this.step.draft.status === 'COMPLETED' && this.step.row?.blocks_closure
                    ? 'This step was holding the case open — that block is now cleared. If everything else is done, the case can be closed.'
                    : (this.step.draft.status === 'BLOCKED'
                        ? 'The case stays open. Whoever needs to unblock you will be reminded once every 24 hours until this is resolved.'
                        : 'No further action required for this step.'),
                dossierUrl: this.endpoints.casefileOf(this.step.row.alert_id),
            };
            await this.loadData();
        },

        // ── "What would you like to do?" wizard handlers ──
        handleWizard(code) {
            switch (code) {
                case 'BY_CASE':     this.lens = 'by_case';                 this.loadData(); break;
                case 'MARK_DONE':   this.lens = 'by_step'; this.filters.status = 'in_progress'; this.loadData(); break;
                case 'BLOCKERS':    this.lens = 'blocked';                 this.loadData(); break;
                case 'WALK':
                    if (this.cases[0]) window.location.href = this.endpoints.casefileOf(this.cases[0].alert_id);
                    else this.showToast('No cases to walk through.', 'error');
                    break;
                case 'MINE_RECENT': this.lens = 'by_step'; this.filters.status = 'completed'; this.loadData(); break;
            }
        },
    };
}
</script>
@endsection
