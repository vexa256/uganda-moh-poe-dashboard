@extends('admin.layout')

@section('crumb', 'Geography')
@section('title', 'Districts')

@section('content')
<div x-data="districtsRegistry()" x-init="boot()" x-effect="window.adminLock.set('page', wizard?.open || sheet?.open || confirm?.open)" class="space-y-5">

    <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="kpi"><p class="kpi-label">Active</p><p class="kpi-value tabular-nums" x-text="tabCounts.active ?? '—'"></p></div>
        <div class="kpi"><p class="kpi-label">Retired</p><p class="kpi-value tabular-nums text-muted-foreground" x-text="tabCounts.retired ?? '—'"></p></div>
        <div class="kpi"><p class="kpi-label">Bundle Version</p><p class="kpi-value tabular-nums" x-text="bundleVersion ? 'v' + bundleVersion : '—'"></p></div>
        <div class="kpi"><p class="kpi-label">Provinces</p><p class="kpi-value tabular-nums" x-text="meta.provinces?.length ?? '—'"></p></div>
    </section>

    <section class="card">
        <div class="card-content !p-0">
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto">
                        <template x-for="t in [{key:'active',label:'Active'},{key:'retired',label:'Retired'},{key:'all',label:'All'}]" :key="t.key">
                            <button class="tabs-trigger flex-1 sm:flex-none" :data-state="filters.status===t.key?'active':'inactive'" @click="setStatusTab(t.key)">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="tabCounts[t.key] ?? 0"></span>
                            </button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <input type="search" class="input w-full sm:w-72" placeholder="Search…" x-model.debounce.300ms="filters.q" @input="loadData()">
                    <button class="btn btn-brand btn-sm" @click="openCreate()">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14m-7-7h14"/></svg>
                        New District
                    </button>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <select class="select w-auto !h-8 text-xs" x-model="filters.province_id" @change="loadData()">
                        <option value="0">All provinces</option>
                        <template x-for="p in meta.provinces" :key="p.id"><option :value="p.id" x-text="p.name"></option></template>
                    </select>
                    <button class="btn btn-ghost btn-xs" @click="resetFilters()" x-show="filters.province_id != 0 || filters.q !== ''">Clear</button>
                </div>
            </div>

            <div class="table-wrap !rounded-none !border-0">
                <table class="table">
                    <thead class="table-head"><tr>
                        <th class="table-head-th">District</th>
                        <th class="table-head-th hidden md:table-cell">Province</th>
                        <th class="table-head-th hidden lg:table-cell">Code · Stem</th>
                        <th class="table-head-th text-center">PoEs</th>
                        <th class="table-head-th text-right">Actions</th>
                    </tr></thead>
                    <tbody class="table-body">
                        <template x-if="loading"><tr><td colspan="5" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading…</td></tr></template>
                        <template x-if="!loading && rows.length===0"><tr><td colspan="5" class="table-cell"><div class="empty-state"><p class="text-sm">No districts match.</p></div></td></tr></template>
                        <template x-for="row in rows" :key="row.id">
                            <tr class="table-row">
                                <td class="table-cell"><div class="flex flex-col">
                                    <span class="text-[12.5px] font-semibold" x-text="row.name"></span>
                                    <span class="text-[10.5px] text-muted-foreground md:hidden" x-text="row.province_name"></span>
                                </div></td>
                                <td class="table-cell hidden md:table-cell text-[12px]" x-text="row.province_name"></td>
                                <td class="table-cell hidden lg:table-cell"><span class="font-mono text-[10.5px]" x-text="row.code"></span> · <span class="text-muted-foreground text-[11px]" x-text="row.name_raw"></span></td>
                                <td class="table-cell text-center tabular-nums" x-text="row.poe_count"></td>
                                <td class="table-cell text-right">
                                    <div class="inline-flex gap-1 justify-end">
                                        <button class="btn btn-ghost btn-xs" @click="openDetail(row.id)" title="View"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg></button>
                                        <button class="btn btn-ghost btn-xs" @click="openEdit(row.id)" title="Edit" x-show="!row.is_retired"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></button>
                                        <button class="btn btn-ghost btn-xs text-critical" @click="confirmRetire(row)" title="Retire" x-show="!row.is_retired"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7"/></svg></button>
                                        <button class="btn btn-ghost btn-xs text-success" @click="restoreRow(row)" title="Restore" x-show="row.is_retired"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1015.66-6.34L21 8M21 3v5h-5"/></svg></button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- Detail sheet (bulletproof modal pattern) --}}
    <template x-if="sheet.open">
        <div class="fixed inset-0 z-50 flex justify-end"
             role="dialog" aria-modal="true"
             @keydown.escape.window="sheet.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="sheet.open=false"></div>
            <aside class="relative h-full w-[92vw] max-w-[420px] bg-background border-l shadow-elevation-5 flex flex-col p-5 sm:p-6 overflow-y-auto" @click.stop>
            <header class="flex items-start gap-3 pb-3 border-b">
                <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand text-white shrink-0"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 17v-2a4 4 0 00-4-4H3"/></svg></div>
                <div class="min-w-0 flex-1">
                    <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">District</p>
                    <h2 class="text-[15px] font-bold leading-tight" x-text="sheet.data?.name || 'Loading…'"></h2>
                </div>
                <button class="btn btn-ghost btn-icon-xs" @click="sheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
            </header>
            <template x-if="sheet.data"><div class="space-y-3 mt-3">
                <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-[12px]">
                    <dt class="text-muted-foreground">name</dt><dd class="font-semibold" x-text="sheet.data.name"></dd>
                    <dt class="text-muted-foreground">name_raw</dt><dd x-text="sheet.data.name_raw"></dd>
                    <dt class="text-muted-foreground">code</dt><dd class="font-mono" x-text="sheet.data.code"></dd>
                    <dt class="text-muted-foreground">province_id</dt><dd class="font-mono" x-text="sheet.data.province_id"></dd>
                    <dt class="text-muted-foreground">order</dt><dd class="font-mono tabular-nums" x-text="sheet.data.display_order"></dd>
                    <dt class="text-muted-foreground">active</dt><dd x-text="sheet.data.is_active ? 'yes' : 'no'"></dd>
                </dl>
                <footer class="flex items-center gap-2 pt-3 border-t">
                    <button class="btn btn-outline btn-sm" @click="sheet.open=false">Close</button><div class="flex-1"></div>
                    <button class="btn btn-brand btn-sm" @click="openEdit(sheet.data.id); sheet.open=false" x-show="!sheet.data.is_retired">Edit</button>
                </footer>
            </div></template>
        </aside></div></template>

    {{-- Wizard 3-step: Identity → Parent → Review (bulletproof modal pattern) --}}
    <template x-if="wizard.open">
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
             role="dialog" aria-modal="true"
             @keydown.escape.window="wizard.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="wizard.open=false"></div>
            <div class="relative w-full sm:max-w-xl bg-card border-t sm:border sm:rounded-xl shadow-elevation-5 max-h-[92vh] flex flex-col" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14m-7-7h14"/></svg></div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground" x-text="wizard.mode==='edit'?'Edit District':'New District'"></p>
                        <h2 class="text-[14px] font-bold truncate" x-text="wizard.form.name || '—'"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="wizard.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex items-center gap-1.5 px-4 sm:px-6 py-3 border-b bg-muted/20">
                    <template x-for="s in [1,2,3]" :key="s">
                        <div class="flex-1 flex items-center gap-1.5">
                            <div class="grid place-items-center h-6 w-6 rounded-full text-[11px] font-bold" :class="wizard.step===s?'bg-brand text-white':wizard.step>s?'bg-success-soft text-success':'bg-muted text-muted-foreground'"><span x-text="s"></span></div>
                            <span class="text-[11px]" x-text="['Identity','Parent','Review'][s-1]"></span>
                            <div class="flex-1 h-px bg-border" x-show="s<3"></div>
                        </div>
                    </template>
                </div>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-4">
                    <template x-if="wizard.step===1">
                        <div class="space-y-4">
                            <div>
                                <label class="label">District name <span class="text-critical">*</span></label>
                                <input type="text" class="input mt-1.5" x-model="wizard.form.name" @input="autoNameRaw()" placeholder='e.g. "Chililabombwe District"'>
                                <p class="help-text mt-1.5">Full canonical name with " District" suffix.</p>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="label">Stem (name_raw)</label>
                                    <input type="text" class="input mt-1.5" x-model="wizard.form.name_raw" placeholder="auto from name">
                                </div>
                                <div>
                                    <label class="label">Code (slug)</label>
                                    <input type="text" class="input mt-1.5" x-model="wizard.form.code" placeholder="auto from name">
                                </div>
                            </div>
                        </div>
                    </template>
                    <template x-if="wizard.step===2">
                        <div class="space-y-4">
                            <div>
                                <label class="label">Provincial PHEOC <span class="text-critical">*</span></label>
                                <select class="select mt-1.5" x-model="wizard.form.province_id">
                                    <option value="">Select province…</option>
                                    <template x-for="p in meta.provinces" :key="p.id"><option :value="p.id" x-text="p.name"></option></template>
                                </select>
                            </div>
                            <div>
                                <label class="label">Display order</label>
                                <input type="number" class="input mt-1.5" x-model="wizard.form.display_order">
                            </div>
                        </div>
                    </template>
                    <template x-if="wizard.step===3">
                        <div class="space-y-3">
                            <div class="table-wrap"><table class="table"><tbody class="table-body">
                                <tr class="table-row"><td class="table-cell text-muted-foreground w-1/3">name</td><td class="table-cell font-semibold" x-text="wizard.form.name"></td></tr>
                                <tr class="table-row"><td class="table-cell text-muted-foreground">name_raw</td><td class="table-cell" x-text="wizard.form.name_raw"></td></tr>
                                <tr class="table-row"><td class="table-cell text-muted-foreground">code</td><td class="table-cell font-mono" x-text="wizard.form.code || 'auto'"></td></tr>
                                <tr class="table-row"><td class="table-cell text-muted-foreground">province</td><td class="table-cell" x-text="meta.provinces.find(p=>p.id==wizard.form.province_id)?.name || '—'"></td></tr>
                            </tbody></table></div>
                            <div class="alert alert-info"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg><div><p class="alert-title">On save</p><p class="alert-description">Bundle bumps to v<span class="font-mono" x-text="bundleVersion+1"></span>. Renames cascade to PoEs in this district.</p></div></div>
                        </div>
                    </template>
                </div>
                <footer class="flex items-center gap-2 px-4 sm:px-6 py-3 border-t">
                    <button class="btn btn-outline btn-sm" @click="prevStep()" :disabled="wizard.step===1">Back</button><div class="flex-1"></div>
                    <button class="btn btn-ghost btn-sm" @click="wizard.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" @click="nextStep()" :disabled="!stepValid(wizard.step)" x-show="wizard.step<3">Next</button>
                    <button class="btn btn-brand btn-sm" @click="save()" :disabled="wizard.submitting" x-show="wizard.step===3">
                        <span x-show="!wizard.submitting" x-text="wizard.mode==='edit'?'Save changes':'Create'"></span>
                        <span x-show="wizard.submitting">Saving…</span>
                    </button>
                </footer>
            </div>
        </div></template>

    {{-- Confirm dialog (bulletproof modal pattern) --}}
    <template x-if="confirm.open">
        <div class="fixed inset-0 z-[55] flex items-center justify-center p-4"
             role="dialog" aria-modal="true" @keydown.escape.window="confirm.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="confirm.open=false"></div>
            <div class="relative w-full max-w-sm bg-card border rounded-xl shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold text-critical">Retire district?</h3>
                <p class="text-[12.5px] text-muted-foreground leading-relaxed mt-1.5">
                    <span class="font-semibold text-foreground" x-text="confirm.row?.name"></span> · blocked if active PoEs / hospitals exist.
                </p>
                <div class="flex justify-end gap-2 mt-5">
                    <button class="btn btn-outline btn-sm" @click="confirm.open=false">Cancel</button>
                    <button class="btn btn-destructive btn-sm" @click="performRetire()" :disabled="confirm.busy">
                        <span x-show="!confirm.busy">Retire</span>
                        <span x-show="confirm.busy">Retiring…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <div class="fixed inset-x-0 bottom-6 z-[60] flex justify-center px-3 pointer-events-none" x-show="opToast.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="opToast.kind==='success'?'toast-success':opToast.kind==='error'?'toast-destructive':'toast-warning'">
            <div><p class="toast-title" x-text="opToast.title"></p><p class="toast-description" x-text="opToast.body"></p></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function districtsRegistry(){
        // Closure-scoped — must precede `return` (Alpine calls us with `this`=globalThis).
        const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
        const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()});
        const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null&&v!==0&&v!=='0').map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');
        const blank=()=>({name:'',name_raw:'',code:'',province_id:'',display_order:'',is_active:true});
        return {
            meta:{provinces:[]}, filters:{status:'active',province_id:0,q:''}, rows:[], total:0, loading:false,
            tabCounts:{active:0,retired:0,all:0}, bundleVersion:null,
            sheet:{open:false,data:null}, wizard:{open:false,mode:'create',step:1,form:blank(),submitting:false,editingId:null},
            confirm:{open:false,row:null,busy:false}, opToast:{open:false,kind:'success',title:'',body:'',t:null},
            blank,
            async boot(){ await Promise.all([this.loadMeta(),this.loadData()]); },
            async loadMeta(){ const r=await fetch('/admin/geo/districts/meta'); const j=await r.json(); if(j.success) this.meta=j.data; },
            async loadData(){ this.loading=true;
                try{ const r=await fetch('/admin/geo/districts/data?'+qs(this.filters)); const j=await r.json();
                    if(j.success){
                        this.rows=j.data.rows; this.total=j.data.total; this.tabCounts=j.meta.tabs; this.bundleVersion=j.meta.version;
                        Alpine.store('pageMeta').rows    = this.filters.status==='active'?j.meta.tabs.active:this.filters.status==='retired'?j.meta.tabs.retired:j.meta.tabs.all;
                        Alpine.store('pageMeta').version = j.meta.version;
                        Alpine.store('pageMeta').kind    = 'districts';
                    }
                } finally{ this.loading=false; } },
            setStatusTab(k){ this.filters.status=k; this.loadData(); },
            resetFilters(){ this.filters={status:this.filters.status,province_id:0,q:''}; this.loadData(); },
            autoNameRaw(){ const n=this.wizard.form.name; if(!this.wizard._touchedRaw) this.wizard.form.name_raw=n.replace(/\s+District\s*$/i,''); },
            async openDetail(id){ this.sheet.open=true; this.sheet.data=null;
                const r=await fetch('/admin/geo/districts/'+id); const j=await r.json(); if(j.success) this.sheet.data=j.data; },
            openCreate(){ this.wizard.open=true; this.wizard.mode='create'; this.wizard.step=1; this.wizard.editingId=null; this.wizard.form=blank(); this.wizard._touchedRaw=false; },
            async openEdit(id){ this.wizard.open=true; this.wizard.mode='edit'; this.wizard.step=1; this.wizard.editingId=id;
                const r=await fetch('/admin/geo/districts/'+id); const j=await r.json();
                if(j.success){ const d=j.data; this.wizard.form={name:d.name,name_raw:d.name_raw,code:d.code,province_id:d.province_id,display_order:d.display_order,is_active:d.is_active}; this.wizard._touchedRaw=true; } },
            stepValid(s){ const f=this.wizard.form; if(s===1) return f.name && f.name.trim().length>=2; if(s===2) return f.province_id; return true; },
            nextStep(){ if(this.stepValid(this.wizard.step) && this.wizard.step<3) this.wizard.step++; },
            prevStep(){ if(this.wizard.step>1) this.wizard.step--; },
            async save(){ this.wizard.submitting=true;
                const f=this.wizard.form;
                const body={name:f.name,name_raw:f.name_raw||undefined,code:f.code||undefined,province_id:parseInt(f.province_id),is_active:!!f.is_active};
                if(f.display_order!=='') body.display_order=parseInt(f.display_order);
                try{ const url=this.wizard.mode==='edit'?'/admin/geo/districts/'+this.wizard.editingId:'/admin/geo/districts';
                    const r=await fetch(url,{method:this.wizard.mode==='edit'?'PATCH':'POST',headers:headersJson(),body:JSON.stringify(body)});
                    const j=await r.json();
                    if(!r.ok||!j.success){ this.toast('error','Save failed',j.message); this.wizard.submitting=false; return; }
                    this.toast('success',this.wizard.mode==='edit'?'Updated':'Created','Bundle now v'+(j.meta?.version??'?'));
                    this.wizard.open=false; await this.loadData();
                } catch(e){ this.toast('error','Network error',e.message); } finally{ this.wizard.submitting=false; } },
            confirmRetire(row){ this.confirm={open:true,row,busy:false}; },
            async performRetire(){ if(!this.confirm.row) return; this.confirm.busy=true;
                try{ const r=await fetch('/admin/geo/districts/'+this.confirm.row.id,{method:'DELETE',headers:headersJson()});
                    const j=await r.json();
                    if(j.success){ this.toast('success','Retired','Bundle now v'+(j.meta?.version??'?')); this.confirm.open=false; await this.loadData(); }
                    else this.toast('error','Retire failed',j.message);
                } finally{ this.confirm.busy=false; } },
            async restoreRow(row){ const r=await fetch('/admin/geo/districts/'+row.id+'/restore',{method:'POST',headers:headersJson()});
                const j=await r.json(); if(j.success){ this.toast('success','Restored','Bundle now v'+(j.meta?.version??'?')); await this.loadData(); }
                else this.toast('error','Restore failed',j.message); },
            toast(kind,title,body){ this.opToast={open:true,kind,title,body,t:null}; clearTimeout(this.opToast.t);
                this.opToast.t=setTimeout(()=>{this.opToast.open=false;},3000); },
        };
    }
</script>
@endpush
