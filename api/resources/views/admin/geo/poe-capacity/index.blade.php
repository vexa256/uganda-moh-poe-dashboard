@extends('admin.layout')

@section('crumb', 'PoE Ops')
@section('title', 'Annex-1A Capacity')

@section('content')
<div x-data="poeCapacity()" x-init="boot()" x-effect="window.adminLock.set('page', wizard?.open)" class="space-y-5">

    <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="kpi"><p class="kpi-label">Total</p><p class="kpi-value tabular-nums" x-text="tabCounts.all ?? '—'"></p><p class="text-[11px] text-muted-foreground mt-1">all assessments</p></div>
        <div class="kpi"><p class="kpi-label">Drafts</p><p class="kpi-value tabular-nums text-warning" x-text="tabCounts.draft ?? '—'"></p></div>
        <div class="kpi"><p class="kpi-label">Submitted</p><p class="kpi-value tabular-nums text-info" x-text="tabCounts.submitted ?? '—'"></p></div>
        <div class="kpi kpi-glow"><p class="kpi-label">Reviewed</p><p class="kpi-value tabular-nums text-success" x-text="tabCounts.reviewed ?? '—'"></p><p class="text-[11px] text-muted-foreground mt-1">closed loop</p></div>
    </section>

    <section class="card">
        <div class="card-content !p-0">
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto flex-wrap">
                        <template x-for="t in [{key:'all',label:'All'},{key:'DRAFT',label:'Draft'},{key:'SUBMITTED',label:'Submitted'},{key:'REVIEWED',label:'Reviewed'},{key:'ARCHIVED',label:'Archived'}]" :key="t.key">
                            <button class="tabs-trigger" :data-state="filters.status===t.key?'active':'inactive'" @click="filters.status=t.key; loadData()" x-text="t.label"></button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <select class="select w-auto !h-9 text-xs" x-model="filters.poe_code" @change="loadData()">
                        <option value="">Any PoE</option>
                        <template x-for="p in meta.poes" :key="p.poe_code"><option :value="p.poe_code" x-text="p.poe_name"></option></template>
                    </select>
                    <button class="btn btn-brand btn-sm" @click="openCreate()">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14m-7-7h14"/></svg>
                        New Assessment
                    </button>
                </div>
            </div>

            <div class="table-wrap !rounded-none !border-0">
                <table class="table">
                    <thead class="table-head"><tr>
                        <th class="table-head-th">PoE</th>
                        <th class="table-head-th hidden md:table-cell">Date</th>
                        <th class="table-head-th">Status</th>
                        <th class="table-head-th text-center">Score</th>
                        <th class="table-head-th text-right">Actions</th>
                    </tr></thead>
                    <tbody class="table-body">
                        <template x-if="loading"><tr><td colspan="5" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading…</td></tr></template>
                        <template x-if="!loading && rows.length===0"><tr><td colspan="5" class="table-cell"><div class="empty-state"><p class="text-sm">No assessments yet.</p><button class="btn btn-brand btn-sm" @click="openCreate()">Start one</button></div></td></tr></template>
                        <template x-for="row in rows" :key="row.id">
                            <tr class="table-row">
                                <td class="table-cell"><span class="text-[12.5px] font-semibold" x-text="row.poe_code"></span></td>
                                <td class="table-cell hidden md:table-cell text-[11.5px]" x-text="row.assessment_date"></td>
                                <td class="table-cell"><span class="badge" :class="statusBadge(row.status)" x-text="row.status.toLowerCase()"></span></td>
                                <td class="table-cell text-center">
                                    <template x-if="row.overall_score !== null">
                                        <span class="font-bold tabular-nums text-[13px]" :class="scoreColor(row.overall_score)" x-text="row.overall_score + '%'"></span>
                                    </template>
                                    <template x-if="row.overall_score === null"><span class="text-muted-foreground text-[11px]">—</span></template>
                                </td>
                                <td class="table-cell text-right">
                                    <button class="btn btn-ghost btn-xs" @click="openView(row.id)">View</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- Wizard: PoE → Score 8 capacities → Notes → Submit --}}
    <template x-if="wizard.open">
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
             role="dialog" aria-modal="true" @keydown.escape.window="wizard.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="wizard.open=false"></div>
            <div class="relative w-full sm:max-w-2xl bg-card border-t sm:border sm:rounded-xl shadow-elevation-5 max-h-[92vh] flex flex-col" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/></svg></div>
                    <div class="min-w-0 flex-1"><p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground" x-text="wizard.mode==='view'?'View Assessment':wizard.mode==='edit'?'Edit Assessment':'New Annex-1A Assessment'"></p>
                        <h2 class="text-[14px] font-bold truncate"><span x-text="wizard.form.poe_code || 'Untitled'"></span> <span class="text-muted-foreground text-[11px]" x-show="wizard.computedScore !== null">· <span x-text="wizard.computedScore"></span>%</span></h2></div>
                    <button class="btn btn-ghost btn-icon-xs" @click="wizard.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex items-center gap-1.5 px-4 sm:px-6 py-3 border-b bg-muted/20">
                    <template x-for="s in [1,2,3]" :key="s">
                        <div class="flex-1 flex items-center gap-1.5">
                            <div class="grid place-items-center h-6 w-6 rounded-full text-[11px] font-bold" :class="wizard.step===s?'bg-brand text-white':wizard.step>s?'bg-success-soft text-success':'bg-muted text-muted-foreground'"><span x-text="s"></span></div>
                            <span class="text-[11px]" x-text="['Subject','Score 8 capacities','Narrative'][s-1]"></span>
                            <div class="flex-1 h-px bg-border" x-show="s<3"></div>
                        </div>
                    </template>
                </div>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-4">
                    <template x-if="wizard.step===1">
                        <div class="space-y-3">
                            <div>
                                <label class="label">PoE <span class="text-critical">*</span></label>
                                <select class="select mt-1.5" x-model="wizard.form.poe_code" :disabled="wizard.mode!=='create'">
                                    <option value="">Select…</option>
                                    <template x-for="p in meta.poes" :key="p.poe_code"><option :value="p.poe_code" x-text="p.poe_name + ' · ' + p.district"></option></template>
                                </select>
                            </div>
                            <div>
                                <label class="label">Assessment date</label>
                                <input type="date" class="input mt-1.5" x-model="wizard.form.assessment_date">
                            </div>
                        </div>
                    </template>
                    <template x-if="wizard.step===2">
                        <div class="space-y-3">
                            <p class="text-[11.5px] text-muted-foreground">WHO IHR-2005 Annex-1A core capacities. Score each 1 (absent) to 5 (fully meets).</p>
                            <template x-for="(cap, code) in meta.capacities" :key="code">
                                <div class="rounded-lg border bg-card p-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <div class="min-w-0">
                                            <p class="text-[12.5px] font-semibold" x-text="cap.label"></p>
                                            <p class="text-[11px] text-muted-foreground leading-snug" x-text="cap.detail"></p>
                                        </div>
                                        <div class="inline-flex gap-1 shrink-0">
                                            <template x-for="n in [1,2,3,4,5]" :key="n">
                                                <button type="button" @click="wizard.form.scores[code] = wizard.form.scores[code] || {}; wizard.form.scores[code].score = n"
                                                        class="h-7 w-7 rounded-md border text-[11px] font-bold tabular-nums"
                                                        :class="(wizard.form.scores[code]?.score)===n ? 'bg-brand text-white border-brand' : 'border-border bg-card hover:bg-muted/40'"
                                                        x-text="n"></button>
                                            </template>
                                        </div>
                                    </div>
                                    <details class="mt-2">
                                        <summary class="text-[11px] text-brand cursor-pointer">Evidence + gaps</summary>
                                        <textarea class="textarea mt-2 text-[11.5px]" rows="2" placeholder="Evidence…"
                                                  @input="wizard.form.scores[code] = wizard.form.scores[code] || {}; wizard.form.scores[code].evidence = $event.target.value"
                                                  :value="wizard.form.scores[code]?.evidence || ''"></textarea>
                                        <textarea class="textarea mt-1.5 text-[11.5px]" rows="2" placeholder="Gaps identified…"
                                                  @input="wizard.form.scores[code] = wizard.form.scores[code] || {}; wizard.form.scores[code].gap_notes = $event.target.value"
                                                  :value="wizard.form.scores[code]?.gap_notes || ''"></textarea>
                                    </details>
                                </div>
                            </template>
                            <div class="rounded-lg border border-brand/30 bg-brand-soft/40 p-3">
                                <div class="flex items-center justify-between">
                                    <div><p class="text-[10.5px] font-semibold uppercase tracking-wider text-brand-ink">Computed overall</p>
                                        <p class="text-[20px] font-bold text-brand-ink tabular-nums" x-text="wizard.computedScore !== null ? wizard.computedScore + '%' : '—'"></p></div>
                                    <span class="text-[11px] text-muted-foreground" x-text="scoredCount + '/' + Object.keys(meta.capacities||{}).length + ' scored'"></span>
                                </div>
                            </div>
                        </div>
                    </template>
                    <template x-if="wizard.step===3">
                        <div class="space-y-3">
                            <div><label class="label">Summary</label><textarea class="textarea mt-1.5" rows="2" x-model="wizard.form.summary"></textarea></div>
                            <div><label class="label">Gaps identified</label><textarea class="textarea mt-1.5" rows="3" x-model="wizard.form.gaps_identified"></textarea></div>
                            <div><label class="label">Action plan</label><textarea class="textarea mt-1.5" rows="3" x-model="wizard.form.action_plan"></textarea></div>
                        </div>
                    </template>
                </div>
                <footer class="flex items-center gap-2 px-4 sm:px-6 py-3 border-t">
                    <button class="btn btn-outline btn-sm" @click="wizard.step--" :disabled="wizard.step===1">Back</button>
                    <div class="flex-1"></div>
                    <button class="btn btn-ghost btn-sm" @click="wizard.open=false">Close</button>
                    <button class="btn btn-brand btn-sm" @click="wizard.step++" :disabled="!wizard.form.poe_code" x-show="wizard.step<3">Next</button>
                    <button class="btn btn-brand btn-sm" @click="save('DRAFT')"     :disabled="wizard.submitting" x-show="wizard.step===3 && wizard.mode!=='view'">Save draft</button>
                    <button class="btn btn-success btn-sm" @click="save('SUBMITTED')" :disabled="wizard.submitting" x-show="wizard.step===3 && wizard.mode!=='view'">Submit</button>
                </footer>
            </div>
        </div>
    </template>

    <div class="fixed inset-x-0 bottom-6 z-[60] flex justify-center px-3 pointer-events-none" x-show="opToast.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="opToast.kind==='success'?'toast-success':'toast-destructive'">
            <div><p class="toast-title" x-text="opToast.title"></p><p class="toast-description" x-text="opToast.body"></p></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function poeCapacity(){
        const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
        const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()});
        const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');
        const blank=()=>({poe_code:'',assessment_date:new Date().toISOString().slice(0,10),summary:'',gaps_identified:'',action_plan:'',scores:{}});
        return {
            meta:{statuses:[],poes:[],capacities:{}}, filters:{status:'all',poe_code:''}, rows:[], loading:false, tabCounts:{},
            wizard:{open:false,mode:'create',step:1,form:blank(),submitting:false,editingId:null},
            opToast:{open:false,kind:'success',title:'',body:'',t:null},
            statusBadge(s){ return ({DRAFT:'badge-warning',SUBMITTED:'badge-info',REVIEWED:'badge-success',ARCHIVED:'badge-soon'})[s]||'badge-outline'; },
            scoreColor(p){ if(p==null) return ''; if(p>=80) return 'text-success'; if(p>=60) return 'text-info'; if(p>=40) return 'text-warning'; return 'text-critical'; },
            get scoredCount(){ return Object.values(this.wizard.form.scores||{}).filter(s=>s && s.score>0).length; },
            get computedScore(){
                const scores = Object.values(this.wizard.form.scores||{}).filter(s=>s && s.score>0);
                if (scores.length === 0) return null;
                const avg = scores.reduce((a,s)=>a+s.score,0) / scores.length;
                return Math.round((avg-1)/4*100);
            },
            async boot(){ await Promise.all([this.loadMeta(),this.loadData()]); },
            async loadMeta(){ const r=await fetch('/admin/poe/capacity/meta'); const j=await r.json(); if(j.success) this.meta=j.data; },
            async loadData(){ this.loading=true;
                try{ const r=await fetch('/admin/poe/capacity/data?'+qs(this.filters)); const j=await r.json();
                    if(j.success){ this.rows=j.data.rows; this.tabCounts=j.meta.tabs;
                        Alpine.store('pageMeta').rows=this.tabCounts.all ?? null;
                        Alpine.store('pageMeta').version=null; Alpine.store('pageMeta').kind='poe-capacity';
                    }
                } finally{ this.loading=false; } },
            openCreate(){ this.wizard={open:true,mode:'create',step:1,form:blank(),submitting:false,editingId:null}; },
            async openView(id){ this.wizard={open:true,mode:'view',step:1,form:blank(),submitting:false,editingId:id};
                const r=await fetch('/admin/poe/capacity/'+id); const j=await r.json();
                if(j.success){ const d=j.data; const scores={}; (d.scores||[]).forEach(s=>scores[s.capacity_code]={score:s.score,evidence:s.evidence,gap_notes:s.gap_notes});
                    this.wizard.form={poe_code:d.poe_code,assessment_date:d.assessment_date,summary:d.summary||'',gaps_identified:d.gaps_identified||'',action_plan:d.action_plan||'',scores};
                    if (d.status === 'DRAFT') this.wizard.mode = 'edit';
                } },
            async save(targetStatus){ this.wizard.submitting=true;
                const body={...this.wizard.form};
                if(this.wizard.mode==='edit') body.status=targetStatus;
                try{ const url=this.wizard.mode==='create'?'/admin/poe/capacity':'/admin/poe/capacity/'+this.wizard.editingId;
                    const method=this.wizard.mode==='create'?'POST':'PATCH';
                    if(this.wizard.mode==='create' && targetStatus==='SUBMITTED'){
                        // create + then PATCH to SUBMITTED — simplest: create as DRAFT, then PATCH.
                    }
                    const r=await fetch(url,{method,headers:headersJson(),body:JSON.stringify(body)});
                    const j=await r.json();
                    if(!r.ok||!j.success){ this.toast('error','Save failed',j.message); this.wizard.submitting=false; return; }
                    if(this.wizard.mode==='create' && targetStatus==='SUBMITTED' && j.data.id){
                        await fetch('/admin/poe/capacity/'+j.data.id,{method:'PATCH',headers:headersJson(),body:JSON.stringify({status:'SUBMITTED'})});
                    }
                    this.toast('success','Saved',targetStatus==='SUBMITTED'?'Assessment submitted.':'Draft saved.');
                    this.wizard.open=false; await this.loadData();
                } catch(e){ this.toast('error','Network',e.message); } finally{ this.wizard.submitting=false; } },
            toast(kind,title,body){ this.opToast={open:true,kind,title,body,t:null}; clearTimeout(this.opToast.t); this.opToast.t=setTimeout(()=>{this.opToast.open=false;},3000); },
        };
    }
</script>
@endpush
