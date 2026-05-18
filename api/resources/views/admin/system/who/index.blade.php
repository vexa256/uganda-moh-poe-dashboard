{{-- Admin · System Health · WHO Connector (sys-who) — v4 honest placeholder --}}
@extends('admin.layout')

@section('crumb', 'System Health')
@section('title', $page_title)

@php
    /** @var array $coach */
    $coach = $coach ?? \App\Support\System\CoachManifest::forView('who');
@endphp

@section('content')
<div x-data="whoPage()" x-init="boot()"
     x-effect="window.adminLock?.set?.('page', coachSheet?.open || wizardSheet?.open || interpretSheet?.open)"
     class="flex flex-col gap-4 min-h-[calc(100vh-7rem)]">

    <script type="application/json" id="sys-who-coach">@json($coach)</script>

    {{-- ─── Coach intro strip ─────────────────────────────────────────── --}}
    <section class="rounded-2xl border bg-card shadow-sm">
        <button type="button" class="w-full text-left flex items-start gap-3 px-4 sm:px-5 py-3"
                @click="coachIntroOpen=!coachIntroOpen" :aria-expanded="coachIntroOpen">
            <span class="grid place-items-center h-8 w-8 rounded-lg bg-brand-soft text-brand-ink shrink-0 mt-0.5">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </span>
            <span class="flex-1 min-w-0">
                <span class="block text-[12.5px] font-semibold leading-tight" x-text="coach.view.title"></span>
                <span class="block text-[11.5px] text-muted-foreground mt-0.5 leading-snug" x-text="coach.view.header_intro"></span>
            </span>
            <span class="shrink-0 flex items-center gap-2">
                <button type="button" class="btn btn-outline btn-xs" @click.stop="wizardSheet.open=true">What do you want to do?</button>
                <button type="button" class="btn btn-ghost btn-icon-xs" @click.stop="coachSheet.open=true" title="Glossary &amp; full guide">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </button>
                <svg class="h-4 w-4 text-muted-foreground transition-transform" :class="coachIntroOpen?'rotate-180':''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
            </span>
        </button>
        <div x-show="coachIntroOpen" x-cloak x-collapse class="px-4 sm:px-5 pb-4 border-t pt-3 space-y-2.5 text-[12px]">
            <div>
                <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">What this page is</p>
                <p class="mt-0.5 leading-relaxed" x-text="coach.view.purpose"></p>
            </div>
        </div>
    </section>

    {{-- ─── Honest "Not yet connected" banner ──────────────────────── --}}
    <section class="rounded-2xl border-2 border-dashed border-warning/40 bg-warning-soft/40 p-5 flex flex-col sm:flex-row gap-4 items-start">
        <div class="grid place-items-center h-10 w-10 rounded-full bg-warning text-warning-ink shrink-0">
            <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01M5 19h14a2 2 0 001.84-2.75L13.84 4.24a2 2 0 00-3.68 0L3.16 16.25A2 2 0 005 19z"/></svg>
        </div>
        <div class="flex-1 min-w-0">
            <p class="text-[10px] font-semibold uppercase tracking-wider text-warning-ink/80">Status</p>
            <h2 class="text-[16px] font-bold leading-tight">Not yet connected</h2>
            <p class="text-[12px] mt-1.5" x-text="contract?.plain_status || ''"></p>
            <p class="text-[11.5px] mt-1.5 text-muted-foreground"><strong class="text-foreground">In the meantime:</strong> <span x-text="contract?.manual_fallback || ''"></span></p>
        </div>
        <div class="shrink-0">
            <button class="btn btn-brand btn-sm" :disabled="contract?.interest?.you_already_asked || notifyBusy" @click="askNotify()">
                <span x-show="!contract?.interest?.you_already_asked && !notifyBusy">Tell me when this is ready</span>
                <span x-show="contract?.interest?.you_already_asked && !notifyBusy">Already asked</span>
                <span x-show="notifyBusy">Recording…</span>
            </button>
            <p class="text-[10px] text-muted-foreground text-right mt-1">
                <span x-text="contract?.interest?.count ?? 0"></span> operator(s) waiting
            </p>
        </div>
    </section>

    {{-- ─── Tabs ──────────────────────────────────────────────────── --}}
    <section class="card flex-1 flex flex-col min-h-0">
        <div class="card-content !p-0 flex flex-col min-h-0">
            <div class="flex flex-col sm:flex-row sm:items-center gap-3 p-4 sm:p-5 border-b bg-card sticky top-0 z-20">
                <div class="tabs-list w-full sm:w-auto">
                    <template x-for="t in tabs" :key="t.key">
                        <button class="tabs-trigger flex-1 sm:flex-none" :data-state="activeTab===t.key?'active':'inactive'" @click="activeTab=t.key; pushUrl()">
                            <span x-text="t.label"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- ── Status tab — readiness checklist + interfaces + next actions ── --}}
            <div x-show="activeTab==='status'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-4">
                <div class="rounded-xl border p-4">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold">Readiness</p>
                        <button class="text-[10.5px] text-brand underline-offset-2 hover:underline" @click="openInterpret('readiness_ring')">How to read</button>
                    </div>
                    <div class="flex items-center gap-3">
                        <svg viewBox="0 0 64 64" class="h-16 w-16 -rotate-90 shrink-0">
                            <circle cx="32" cy="32" r="28" fill="none" stroke="rgb(229 231 235)" stroke-width="6"/>
                            <circle cx="32" cy="32" r="28" fill="none" stroke="rgb(34 197 94)" stroke-width="6" stroke-linecap="round" :stroke-dasharray="ringDash()" stroke-dashoffset="0"/>
                        </svg>
                        <div class="min-w-0">
                            <p class="text-[20px] font-bold tabular-nums leading-none"><span x-text="contract?.readiness_done ?? 0"></span> <span class="text-muted-foreground text-[14px]">/ <span x-text="contract?.readiness_total ?? 0"></span></span></p>
                            <p class="text-[10.5px] text-muted-foreground mt-1">items completed before the connector can be enabled</p>
                        </div>
                    </div>
                    <ul class="mt-3 divide-y rounded-lg border bg-card overflow-hidden">
                        <template x-for="item in (contract?.readiness || [])" :key="item.item">
                            <li class="px-3 py-2 flex items-start gap-2 text-[11.5px]">
                                <span class="grid place-items-center h-5 w-5 rounded-full shrink-0 mt-0.5" :class="item.done?'bg-success-soft text-success':'bg-muted text-muted-foreground'">
                                    <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" x-show="item.done"><path d="M5 13l4 4L19 7"/></svg>
                                </span>
                                <p class="flex-1" :class="item.done?'':'text-muted-foreground'"><span x-text="item.item"></span></p>
                                <span class="shrink-0 text-[10.5px]" :class="item.done?'text-success':'text-muted-foreground italic'" x-text="item.done?'Done':'Outstanding'"></span>
                            </li>
                        </template>
                    </ul>
                </div>

                <div class="rounded-xl border p-4">
                    <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold mb-2">What the link will exchange</p>
                    <ul class="divide-y rounded-lg overflow-hidden">
                        <template x-for="iface in (contract?.interfaces || [])" :key="iface.name">
                            <li class="px-3 py-2.5 flex items-start gap-2 text-[11.5px]">
                                <span class="badge text-[9.5px] shrink-0" :class="iface.direction==='OUTBOUND'?'badge-warning':'badge-outline'" x-text="iface.direction==='OUTBOUND'?'Outbound':'Inbound'"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold" x-text="iface.plain_name || iface.name"></p>
                                    <p class="text-[10.5px] text-muted-foreground mt-0.5"><strong>When:</strong> <span x-text="iface.trigger"></span></p>
                                    <p class="text-[10.5px] text-muted-foreground"><strong>Cadence:</strong> <span x-text="iface.sla_phrase"></span></p>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>

                <div class="rounded-xl border p-4">
                    <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold mb-2">What the engineering team will do next</p>
                    <ul class="list-disc pl-5 text-[11.5px] space-y-1">
                        <template x-for="(action, idx) in (contract?.next_actions || [])" :key="'na-'+idx">
                            <li x-text="action"></li>
                        </template>
                    </ul>
                </div>
            </div>

            {{-- ── Preview tab ── --}}
            <div x-show="activeTab==='preview'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-4">
                <div class="rounded-xl border-2 border-dashed border-muted-foreground/30 p-4">
                    <span class="badge text-[9.5px] badge-outline">Preview · not live</span>
                    <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold mt-2">When live: overall health</p>
                    <p class="text-[14px] mt-1" x-text="contract?.preview?.health_pill || ''"></p>
                </div>
                <div class="rounded-xl border-2 border-dashed border-muted-foreground/30 p-4">
                    <span class="badge text-[9.5px] badge-outline">Preview · not live</span>
                    <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold mt-2">When live: outbound notifications</p>
                    <p class="text-[12px] mt-1" x-text="contract?.preview?.recent_notifications || ''"></p>
                </div>
                <div class="rounded-xl border-2 border-dashed border-muted-foreground/30 p-4">
                    <span class="badge text-[9.5px] badge-outline">Preview · not live</span>
                    <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold mt-2">When live: inbound signals</p>
                    <p class="text-[12px] mt-1" x-text="contract?.preview?.incoming_signals || ''"></p>
                </div>
                <p class="text-[10.5px] italic text-muted-foreground">Everything on this tab is a deliberate placeholder. The shapes here are what will populate when the connector is enabled — they will not display real numbers until then.</p>
            </div>

            {{-- ── Methodology tab ── --}}
            <div x-show="activeTab==='method'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-6 space-y-4 text-[12px] leading-relaxed">
                <h3 class="text-[14px] font-bold">What the IHR is</h3>
                <p>The International Health Regulations (2005) is the global agreement under which countries notify each other of public-health events that may cross borders. The agreement names a designated officer in every country — the <em>National IHR Focal Point</em> — and a small set of WHO offices that receive the notifications.</p>
                <h3 class="text-[14px] font-bold mt-4">What this connector will do</h3>
                <p>When enabled, the connector will let the platform send IHR notifications to WHO automatically when an alert reaches the threshold, instead of relying on a person to do it manually. The connector will also pull in WHO-verified signals so they appear in the platform’s Signal Inbox for assessment.</p>
                <h3 class="text-[14px] font-bold mt-4">Why this is honest about not being live</h3>
                <p>Pretending a connector works when it does not is dangerous. Until every readiness item on the Status tab is ticked and the engineering team has cut the connector over, this screen will say "Not connected". The manual channel through the National IHR Focal Point remains the way IHR notifications are sent today.</p>
                <p class="text-[10.5px] text-muted-foreground italic mt-4">Audit prefix in <span class="font-mono">auth_events</span>: <span class="font-mono">WHO_EIS_*</span>.</p>
            </div>
        </div>
    </section>

    {{-- ─── Wizard sheet ──────────────────────────────────────────────── --}}
    <template x-if="wizardSheet.open">
        <div class="fixed inset-0 z-[58] flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="wizardSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="wizardSheet.open=false"></div>
            <div class="relative w-full sm:max-w-md bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="min-w-0 flex-1"><p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Wizard</p><h2 class="text-[14px] font-bold" x-text="coach.wizard?.launcher_label || 'What do you want to do?'"></h2></div>
                    <button class="btn btn-ghost btn-icon-xs" @click="wizardSheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-2.5 text-[12px]">
                    <template x-for="opt in (coach.wizard?.options || [])" :key="opt.id">
                        <button class="w-full text-left rounded-xl border p-3 hover:bg-muted/30 transition" @click="pickWizardOption(opt)">
                            <p class="font-semibold" x-text="opt.label"></p>
                            <p class="text-[11px] text-muted-foreground mt-0.5" x-text="opt.summary"></p>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Coach sheet ──────────────────────────────────────────────── --}}
    <template x-if="coachSheet.open">
        <div class="fixed inset-0 z-[58] flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="coachSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="coachSheet.open=false"></div>
            <div class="relative w-full sm:max-w-md bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="min-w-0 flex-1"><p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Guide</p><h2 class="text-[14px] font-bold" x-text="coach.view.title"></h2></div>
                    <button class="btn btn-ghost btn-icon-xs" @click="coachSheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-5 text-[12px]">
                    <section>
                        <h3 class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Words you may meet</h3>
                        <dl class="space-y-2">
                            <template x-for="g in coach.glossary" :key="g.term">
                                <div class="rounded-lg border p-2.5">
                                    <dt class="font-semibold" x-text="g.term"></dt>
                                    <dd class="text-[11px] text-muted-foreground mt-0.5" x-text="g.plain_english"></dd>
                                </div>
                            </template>
                        </dl>
                    </section>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Interpretation modal ─────────────────────────────────────── --}}
    <template x-if="interpretSheet.open">
        <div class="fixed inset-0 z-[59] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="interpretSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="interpretSheet.open=false"></div>
            <div class="relative w-full max-w-md bg-card border rounded-2xl shadow-elevation-5 p-5 max-h-[85vh] overflow-y-auto" @click.stop>
                <h3 class="text-[14px] font-bold" x-text="interpretSheet.chart?.title || 'Reading this'"></h3>
                <dl class="mt-3 space-y-3 text-[11.5px]">
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">What this shows</dt><dd x-text="interpretSheet.chart?.shows"></dd></div>
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">How to read it</dt><dd x-text="interpretSheet.chart?.how_to_read"></dd></div>
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">Healthy</dt><dd x-text="interpretSheet.chart?.healthy"></dd></div>
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">Concerning</dt><dd x-text="interpretSheet.chart?.concerning"></dd></div>
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">What to do</dt><dd x-text="interpretSheet.chart?.what_to_do"></dd></div>
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">What it cannot tell you</dt><dd x-text="interpretSheet.chart?.cannot_tell"></dd></div>
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">Where the data comes from</dt><dd x-text="interpretSheet.chart?.data_source"></dd></div>
                </dl>
                <div class="flex justify-end mt-4"><button class="btn btn-brand btn-sm" @click="interpretSheet.open=false">Got it</button></div>
            </div>
        </div>
    </template>

    {{-- ─── Post-action ──────────────────────────────────────────────── --}}
    <template x-if="postAction.open">
        <div class="fixed inset-0 z-[63] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="postAction.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="postAction.open=false"></div>
            <div class="relative w-full max-w-md bg-card border rounded-2xl shadow-elevation-5 p-5" @click.stop>
                <div class="flex items-start gap-3">
                    <div class="grid place-items-center h-10 w-10 rounded-full bg-success-soft text-success shrink-0"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg></div>
                    <div class="min-w-0 flex-1"><h3 class="text-[14px] font-bold" x-text="postAction.title"></h3><p class="text-[12px] text-muted-foreground mt-1" x-text="postAction.body"></p></div>
                </div>
                <p class="text-[11px] text-muted-foreground mt-3"><strong class="text-foreground">What is next:</strong> the engineering team sees the interest count. You will receive a notification when the connector is enabled.</p>
                <div class="flex justify-end mt-5"><button class="btn btn-brand btn-sm" @click="postAction.open=false">Close</button></div>
            </div>
        </div>
    </template>

    {{-- Toast --}}
    <div class="fixed inset-x-0 bottom-6 z-[70] flex justify-center px-3 pointer-events-none" x-show="opToast.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="opToast.kind==='success'?'toast-success':'toast-destructive'">
            <div><p class="toast-title" x-text="opToast.title"></p><p class="toast-description" x-text="opToast.body"></p></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function whoPage(){
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const idemKey=()=>{ if(crypto?.randomUUID) return crypto.randomUUID(); return 'k-'+Date.now()+'-'+Math.random().toString(36).slice(2); };
    const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf(),'Idempotency-Key':idemKey()});

    let coach = {};
    try{ coach = JSON.parse(document.getElementById('sys-who-coach')?.textContent || '{}') || {}; }catch(e){ coach = {}; }
    coach.glossary = coach.glossary || [];
    coach.charts   = coach.charts   || {};
    coach.wizard   = coach.wizard   || { options: [] };

    return {
        coach,
        coachIntroOpen:false,
        coachSheet:{open:false},
        wizardSheet:{open:false},
        interpretSheet:{open:false, chart:null},
        postAction:{open:false, title:'', body:''},
        opToast:{open:false, kind:'success', title:'', body:'', t:null},

        tabs: [
            {key:'status',  label:'Status'},
            {key:'preview', label:'Preview'},
            {key:'method',  label:'Methodology'},
        ],
        activeTab:'status',
        contract:null,
        notifyBusy:false,

        ringDash(){
            const total = this.contract?.readiness_total || 1;
            const done  = this.contract?.readiness_done  || 0;
            const circ  = 2 * Math.PI * 28;
            const fill  = (done / total) * circ;
            return `${fill.toFixed(2)} ${(circ - fill).toFixed(2)}`;
        },

        openInterpret(key){ const chart = this.coach.charts?.[key]; if(!chart) return; this.interpretSheet = {open:true, chart}; },
        pickWizardOption(opt){ this.wizardSheet.open=false; this.activeTab=opt.goto_tab||'status'; this.pushUrl(); this.toast('success', opt.label, opt.summary); },

        async boot(){
            this.readUrl();
            await this.load();
        },
        readUrl(){
            try{ const u = new URL(window.location.href); const t = u.searchParams.get('tab'); if(t) this.activeTab = t; }catch(e){}
        },
        pushUrl(){
            try{ const u = new URL(window.location.href); u.searchParams.set('tab', this.activeTab); window.history.replaceState(null,'',u.toString()); }catch(e){}
        },
        async load(){
            try{
                const r = await fetch('/admin/system/who/contract', {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok) this.contract = j.data;
            }catch(e){}
        },

        async askNotify(){
            this.notifyBusy = true;
            try{
                const r = await fetch('/admin/system/who/notify-me', {method:'POST', headers:headersJson(), body:JSON.stringify({})});
                const j = await r.json();
                if(j.ok){
                    this.postAction = {open:true, title: j.data?.already_asked?'Already recorded':'Your interest is recorded', body: j.data?.plain_summary || ''};
                    await this.load();
                } else {
                    this.toast('error','Could not record', j.message || 'Unknown error.');
                }
            }catch(e){ this.toast('error','Network', e.message); }
            finally{ this.notifyBusy = false; }
        },

        toast(kind, title, body){ this.opToast = {open:true, kind, title, body, t:null}; clearTimeout(this.opToast.t); this.opToast.t = setTimeout(()=>{ this.opToast.open=false; }, 4500); },
    };
}
</script>
@endpush
