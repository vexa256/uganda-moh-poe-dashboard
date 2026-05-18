@extends('admin.layout')

@section('crumb', 'Workforce')
@section('title', 'Training')

@section('content')
<div x-data="wfTraining()" x-init="boot()" x-effect="window.adminLock.set('page', wizard?.open || confirm?.open)" class="space-y-5">

    <section class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <div class="kpi kpi-glow"><p class="kpi-label">Valid</p><p class="kpi-value tabular-nums" x-text="tabCounts.valid ?? '—'"></p><p class="text-[11px] text-muted-foreground mt-1">in date</p></div>
        <div class="kpi"><p class="kpi-label">Expiring ≤60d</p><p class="kpi-value tabular-nums text-warning" x-text="tabCounts.expiring ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">refresher window</p></div>
        <div class="kpi"><p class="kpi-label">Expired</p><p class="kpi-value tabular-nums text-critical" x-text="tabCounts.expired ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">overdue</p></div>
        <div class="kpi"><p class="kpi-label">Revoked</p><p class="kpi-value tabular-nums text-muted-foreground" x-text="tabCounts.revoked ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">manual revoke</p></div>
        <div class="kpi"><p class="kpi-label">Total</p><p class="kpi-value tabular-nums" x-text="tabCounts.all ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">in your scope</p></div>
    </section>

    <section class="card">
        <div class="card-content !p-0">
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto">
                        <template x-for="t in [{key:'valid',label:'Valid'},{key:'expiring',label:'Expiring'},{key:'expired',label:'Expired'},{key:'revoked',label:'Revoked'},{key:'all',label:'All'}]" :key="t.key">
                            <button class="tabs-trigger flex-1 sm:flex-none" :data-state="filters.status===t.key?'active':'inactive'" @click="filters.status=t.key; loadData()">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="tabCounts[t.key] ?? 0"></span>
                            </button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <input type="search" class="input w-full sm:w-72" placeholder="Search title, code, certificate…" x-model.debounce.300ms="filters.q" @input="loadData()">
                    <button class="btn btn-brand btn-sm" @click="openCreate()">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14m-7-7h14"/></svg>
                        Record Training
                    </button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <select class="select w-auto !h-8 text-xs" x-model="filters.domain" @change="loadData()">
                        <option value="">Any domain</option>
                        <template x-for="d in meta.domains" :key="d"><option :value="d" x-text="d"></option></template>
                    </select>
                    <select class="select w-auto !h-8 text-xs" x-model.number="filters.user_id" @change="loadData()">
                        <option :value="0">Any user</option>
                        <template x-for="u in meta.users" :key="u.id"><option :value="u.id" x-text="u.full_name"></option></template>
                    </select>
                </div>
            </div>

            <div class="table-wrap !rounded-none !border-0">
                <table class="table">
                    <thead class="table-head"><tr>
                        <th class="table-head-th">Training</th>
                        <th class="table-head-th hidden md:table-cell">User</th>
                        <th class="table-head-th hidden lg:table-cell">Domain</th>
                        <th class="table-head-th hidden md:table-cell">Completed / Expires</th>
                        <th class="table-head-th text-right">Status</th>
                        <th class="table-head-th text-right">Actions</th>
                    </tr></thead>
                    <tbody class="table-body">
                        <template x-if="loading"><tr><td colspan="6" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading…</td></tr></template>
                        <template x-if="!loading && rows.length===0"><tr><td colspan="6" class="table-cell"><div class="empty-state"><p class="text-sm">No training records.</p><button class="btn btn-brand btn-sm" @click="openCreate()">Record the first</button></div></td></tr></template>
                        <template x-for="row in rows" :key="row.id">
                            <tr class="table-row">
                                <td class="table-cell"><div class="flex flex-col">
                                    <span class="text-[12.5px] font-semibold" x-text="row.training_title"></span>
                                    <span class="text-[10.5px] text-muted-foreground"><span class="font-mono" x-text="row.training_code"></span> · <span x-text="row.provider || 'no provider'"></span></span>
                                </div></td>
                                <td class="table-cell hidden md:table-cell text-[11.5px]">
                                    <div x-text="row.full_name || ('user #'+row.user_id)"></div>
                                    <div class="text-muted-foreground"><span x-show="row.role_key" class="badge badge-outline" x-text="row.role_key"></span></div>
                                </td>
                                <td class="table-cell hidden lg:table-cell"><span class="badge badge-outline" x-text="row.competency_domain"></span></td>
                                <td class="table-cell hidden md:table-cell text-[11px] text-muted-foreground">
                                    <span x-text="new Date(row.completed_on).toLocaleDateString()"></span>
                                    <span x-show="row.expires_on"> → <span x-text="new Date(row.expires_on).toLocaleDateString()"></span></span>
                                    <span x-show="row.days_to_expiry !== null && row.days_to_expiry >= 0 && row.days_to_expiry <= 60" class="badge badge-warning ml-1"><span x-text="row.days_to_expiry+'d'"></span></span>
                                    <span x-show="row.days_to_expiry !== null && row.days_to_expiry < 0" class="badge badge-critical ml-1">overdue</span>
                                </td>
                                <td class="table-cell text-right">
                                    <span class="badge" :class="{ 'badge-success': row.status==='VALID', 'badge-warning': row.status==='EXPIRING', 'badge-critical': row.status==='EXPIRED', 'badge-outline': row.status==='REVOKED'}" x-text="row.status"></span>
                                </td>
                                <td class="table-cell text-right">
                                    <div class="inline-flex gap-1 justify-end">
                                        <a x-show="row.evidence_url" :href="row.evidence_url" target="_blank" class="btn btn-ghost btn-xs" title="Evidence"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg></a>
                                        <button class="btn btn-ghost btn-xs" @click="openEdit(row)" title="Edit"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></button>
                                        <button class="btn btn-ghost btn-xs text-critical" @click="askDelete(row)" title="Remove"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a2 2 0 012-2h2a2 2 0 012 2v3"/></svg></button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- Wizard: User → Course → Dates & Status --}}
    <template x-if="wizard.open">
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true" @keydown.escape.window="wizard.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="wizard.open=false"></div>
            <div class="relative w-full sm:max-w-xl bg-card border-t sm:border sm:rounded-xl shadow-elevation-5 max-h-[92vh] flex flex-col" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 14l9-5-9-5-9 5 9 5z"/></svg></div>
                    <div class="min-w-0 flex-1"><p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground" x-text="wizard.mode==='edit'?'Edit Training':'Record Training'"></p>
                        <h2 class="text-[14px] font-bold truncate" x-text="wizard.form.training_title || '—'"></h2></div>
                    <button class="btn btn-ghost btn-icon-xs" @click="wizard.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex items-center gap-1.5 px-4 sm:px-6 py-3 border-b bg-muted/20">
                    <template x-for="s in [1,2,3]" :key="s">
                        <div class="flex-1 flex items-center gap-1.5">
                            <div class="grid place-items-center h-6 w-6 rounded-full text-[11px] font-bold" :class="wizard.step===s?'bg-brand text-white':wizard.step>s?'bg-success-soft text-success':'bg-muted text-muted-foreground'"><span x-text="s"></span></div>
                            <span class="text-[11px]" x-text="['User','Course','Dates & Evidence'][s-1]"></span>
                            <div class="flex-1 h-px bg-border" x-show="s<3"></div>
                        </div>
                    </template>
                </div>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-4">
                    <template x-if="wizard.step===1">
                        <div class="space-y-3">
                            <div>
                                <label class="label">User <span class="text-critical">*</span></label>
                                <select class="select mt-1.5" x-model.number="wizard.form.user_id" :disabled="wizard.mode==='edit'">
                                    <option :value="0">Select…</option>
                                    <template x-for="u in meta.users" :key="u.id"><option :value="u.id" x-text="u.full_name + ' · '+u.role_key"></option></template>
                                </select>
                            </div>
                        </div>
                    </template>
                    <template x-if="wizard.step===2">
                        <div class="space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div><label class="label">Course code <span class="text-critical">*</span></label><input type="text" class="input mt-1.5" x-model="wizard.form.training_code" placeholder="IHR_CORE"></div>
                                <div>
                                    <label class="label">Domain <span class="text-critical">*</span></label>
                                    <select class="select mt-1.5" x-model="wizard.form.competency_domain">
                                        <option value="">Select…</option>
                                        <template x-for="d in meta.domains" :key="d"><option :value="d" x-text="d"></option></template>
                                    </select>
                                </div>
                            </div>
                            <div><label class="label">Title <span class="text-critical">*</span></label><input type="text" class="input mt-1.5" x-model="wizard.form.training_title" placeholder="IHR Core Capacities — Annex 1A"></div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div><label class="label">Provider</label><input type="text" class="input mt-1.5" x-model="wizard.form.provider" placeholder="WHO AFRO / UNIPH / RTSL"></div>
                                <div><label class="label">Certificate №</label><input type="text" class="input mt-1.5" x-model="wizard.form.certificate_no"></div>
                            </div>
                        </div>
                    </template>
                    <template x-if="wizard.step===3">
                        <div class="space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div><label class="label">Completed on <span class="text-critical">*</span></label><input type="date" class="input mt-1.5" x-model="wizard.form.completed_on"></div>
                                <div><label class="label">Expires on (optional)</label><input type="date" class="input mt-1.5" x-model="wizard.form.expires_on"></div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div><label class="label">Score (0-100)</label><input type="number" min="0" max="100" class="input mt-1.5" x-model.number="wizard.form.score"></div>
                                <div>
                                    <label class="label">Status</label>
                                    <select class="select mt-1.5" x-model="wizard.form.status">
                                        <template x-for="s in meta.statuses" :key="s"><option :value="s" x-text="s"></option></template>
                                    </select>
                                </div>
                            </div>
                            <div><label class="label">Evidence URL</label><input type="url" class="input mt-1.5" x-model="wizard.form.evidence_url" placeholder="https://…"></div>
                            <div><label class="label">Notes</label><textarea class="input mt-1.5" rows="2" x-model="wizard.form.notes"></textarea></div>
                        </div>
                    </template>
                </div>
                <footer class="flex items-center gap-2 px-4 sm:px-6 py-3 border-t">
                    <button class="btn btn-outline btn-sm" @click="prevStep()" :disabled="wizard.step===1">Back</button>
                    <div class="flex-1"></div>
                    <button class="btn btn-ghost btn-sm" @click="wizard.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" @click="nextStep()" :disabled="!stepValid(wizard.step)" x-show="wizard.step<3">Next</button>
                    <button class="btn btn-brand btn-sm" @click="save()" :disabled="wizard.submitting" x-show="wizard.step===3">
                        <span x-show="!wizard.submitting" x-text="wizard.mode==='edit'?'Save changes':'Record'"></span>
                        <span x-show="wizard.submitting">Saving…</span>
                    </button>
                </footer>
            </div>
        </div>
    </template>

    <template x-if="confirm.open">
        <div class="fixed inset-0 z-[55] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="confirm.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="confirm.open=false"></div>
            <div class="relative w-full max-w-sm bg-card border rounded-xl shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold text-critical">Remove training record?</h3>
                <p class="text-[12.5px] text-muted-foreground mt-1.5"><span class="font-semibold text-foreground" x-text="confirm.row?.training_title"></span> will be removed from the ledger. The audit trail is preserved.</p>
                <div class="flex justify-end gap-2 mt-5">
                    <button class="btn btn-outline btn-sm" @click="confirm.open=false">Cancel</button>
                    <button class="btn btn-destructive btn-sm" @click="performDelete()">Remove</button>
                </div>
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
function wfTraining(){
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()});
    const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null&&v!==0&&v!=='0').map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');
    const blank=()=>({user_id:0,training_code:'',training_title:'',competency_domain:'',provider:'',certificate_no:'',completed_on:new Date().toISOString().slice(0,10),expires_on:'',score:null,status:'VALID',evidence_url:'',notes:''});
    return {
        meta:{domains:[],statuses:[],users:[]},
        filters:{status:'valid',domain:'',user_id:0,q:''}, rows:[], loading:false,
        tabCounts:{valid:0,expiring:0,expired:0,revoked:0,all:0},
        wizard:{open:false,mode:'create',step:1,form:blank(),submitting:false,editingId:null},
        confirm:{open:false,row:null}, opToast:{open:false,kind:'success',title:'',body:'',t:null},

        async boot(){ await Promise.all([this.loadMeta(), this.loadData()]); },
        async loadMeta(){ try{ const r=await fetch('/admin/workforce/training/meta',{headers:{'Accept':'application/json'}}); const j=await r.json(); if(j.success) this.meta=j.data; }catch(e){} },
        async loadData(){ this.loading=true;
            try{ const r=await fetch('/admin/workforce/training/data?'+qs(this.filters),{headers:{'Accept':'application/json'}}); const j=await r.json();
                if(j.success){ this.rows=j.data.rows; this.tabCounts=j.meta?.tabs||this.tabCounts;
                    if(window.Alpine && Alpine.store('pageMeta')){ Alpine.store('pageMeta').rows=this.tabCounts[this.filters.status]??this.tabCounts.all; Alpine.store('pageMeta').kind='wf-training'; }
                } else { this.toast('error','Load failed',j.message); }
            }catch(e){ this.toast('error','Network',e.message); } finally{ this.loading=false; } },
        openCreate(){ this.wizard={open:true,mode:'create',step:1,form:blank(),submitting:false,editingId:null}; },
        openEdit(row){ this.wizard={open:true,mode:'edit',step:1,form:{
            user_id:row.user_id,training_code:row.training_code,training_title:row.training_title,competency_domain:row.competency_domain,provider:row.provider||'',certificate_no:row.certificate_no||'',
            completed_on:row.completed_on?row.completed_on.slice(0,10):'',expires_on:row.expires_on?row.expires_on.slice(0,10):'',
            score:row.score,status:row.status_raw||row.status,evidence_url:row.evidence_url||'',notes:row.notes||''
        },submitting:false,editingId:row.id}; },
        stepValid(s){ const f=this.wizard.form; if(s===1) return f.user_id>0; if(s===2) return f.training_code && f.training_title && f.competency_domain; return !!f.completed_on; },
        nextStep(){ if(this.stepValid(this.wizard.step) && this.wizard.step<3) this.wizard.step++; },
        prevStep(){ if(this.wizard.step>1) this.wizard.step--; },
        async save(){ this.wizard.submitting=true;
            const f={...this.wizard.form}; if(f.score==='') f.score=null;
            const url=this.wizard.mode==='edit'?'/admin/workforce/training/'+this.wizard.editingId:'/admin/workforce/training';
            const method=this.wizard.mode==='edit'?'PATCH':'POST';
            try{ const r=await fetch(url,{method,headers:headersJson(),body:JSON.stringify(f)}); const j=await r.json();
                if(!r.ok||!j.success){ this.toast('error','Save failed',j.message||('HTTP '+r.status)); this.wizard.submitting=false; return; }
                this.toast('success',this.wizard.mode==='edit'?'Updated':'Recorded','Training saved.');
                this.wizard.open=false; await this.loadData();
            }catch(e){ this.toast('error','Network',e.message); } finally{ this.wizard.submitting=false; } },
        askDelete(row){ this.confirm={open:true,row}; },
        async performDelete(){ const id=this.confirm.row.id;
            try{ const r=await fetch('/admin/workforce/training/'+id,{method:'DELETE',headers:headersJson()}); const j=await r.json();
                if(j.success){ this.toast('success','Removed','Record gone.'); this.confirm.open=false; await this.loadData(); }
                else this.toast('error','Failed',j.message);
            }catch(e){ this.toast('error','Network',e.message); } },
        toast(kind,title,body){ this.opToast={open:true,kind,title,body,t:null}; clearTimeout(this.opToast.t); this.opToast.t=setTimeout(()=>{this.opToast.open=false;},3000); },
    };
}
</script>
@endpush
