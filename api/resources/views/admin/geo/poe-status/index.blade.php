@extends('admin.layout')

@section('crumb', 'PoE Ops')
@section('title', 'Open / Closed Status')

@section('content')
<div x-data="poeStatus()" x-init="boot()" x-effect="window.adminLock.set('page', form?.open)" class="space-y-5">

    <section class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <div class="kpi"><p class="kpi-label">Open</p><p class="kpi-value tabular-nums text-success" x-text="tabCounts.open ?? '—'"></p></div>
        <div class="kpi"><p class="kpi-label">Reduced hrs</p><p class="kpi-value tabular-nums text-warning" x-text="tabCounts.reduced_hours ?? '—'"></p></div>
        <div class="kpi"><p class="kpi-label">Closed</p><p class="kpi-value tabular-nums text-critical" x-text="tabCounts.closed ?? '—'"></p></div>
        <div class="kpi"><p class="kpi-label">Emergency</p><p class="kpi-value tabular-nums text-critical" x-text="tabCounts.emergency_closed ?? '—'"></p></div>
        <div class="kpi"><p class="kpi-label">Maintenance</p><p class="kpi-value tabular-nums text-info" x-text="tabCounts.maintenance ?? '—'"></p></div>
    </section>

    <section class="card">
        <div class="card-content !p-0">
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto flex-wrap">
                        <template x-for="t in [{key:'all',label:'All'},{key:'OPEN',label:'Open'},{key:'CLOSED',label:'Closed'},{key:'REDUCED_HOURS',label:'Reduced'},{key:'EMERGENCY_CLOSED',label:'Emergency'},{key:'MAINTENANCE',label:'Maintenance'}]" :key="t.key">
                            <button class="tabs-trigger" :data-state="filters.status===t.key?'active':'inactive'" @click="filters.status=t.key; loadData()" x-text="t.label"></button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <button class="btn btn-brand btn-sm" @click="openSet()">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14m-7-7h14"/></svg>
                        Set Status
                    </button>
                </div>
            </div>

            <div class="table-wrap !rounded-none !border-0">
                <table class="table">
                    <thead class="table-head"><tr>
                        <th class="table-head-th">PoE</th>
                        <th class="table-head-th">Status</th>
                        <th class="table-head-th hidden md:table-cell">Since</th>
                        <th class="table-head-th hidden lg:table-cell">Reason</th>
                    </tr></thead>
                    <tbody class="table-body">
                        <template x-if="loading"><tr><td colspan="4" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading…</td></tr></template>
                        <template x-if="!loading && current.length===0"><tr><td colspan="4" class="table-cell"><div class="empty-state"><p class="text-sm">No PoEs in this status.</p></div></td></tr></template>
                        <template x-for="row in current" :key="row.poe_code">
                            <tr class="table-row cursor-pointer hover:bg-muted/40" @click="openSet(row)">
                                <td class="table-cell"><div class="flex flex-col">
                                    <span class="text-[12.5px] font-semibold" x-text="row.poe_name"></span>
                                    <span class="text-[10.5px] text-muted-foreground" x-text="row.district + ' · ' + row.province"></span>
                                </div></td>
                                <td class="table-cell">
                                    <span class="badge" :class="badgeClass(row.status)" x-text="row.status.replace('_',' ').toLowerCase()"></span>
                                </td>
                                <td class="table-cell hidden md:table-cell text-[11.5px]"><span x-text="row.since || 'always'"></span><span x-show="row.days_in_status" class="text-muted-foreground"> · <span x-text="row.days_in_status"></span>d</span></td>
                                <td class="table-cell hidden lg:table-cell text-[11.5px] text-muted-foreground truncate max-w-xs" x-text="row.reason || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- Recent log --}}
    <section class="card">
        <header class="card-header pb-3 border-b"><h2 class="card-title">Recent activity</h2></header>
        <div class="card-content !p-0">
            <ul class="divide-y">
                <template x-if="log.length===0"><li class="px-5 py-4 text-[12px] text-muted-foreground">No status changes recorded.</li></template>
                <template x-for="e in log" :key="e.id">
                    <li class="px-5 py-3 flex items-center gap-3">
                        <span class="badge" :class="badgeClass(e.status)" x-text="e.status.replace('_',' ').toLowerCase()"></span>
                        <span class="text-[12.5px] font-semibold flex-1 truncate" x-text="e.poe_code"></span>
                        <span class="text-[10.5px] text-muted-foreground tabular-nums" x-text="e.started_at"></span>
                    </li>
                </template>
            </ul>
        </div>
    </section>

    {{-- Set status modal --}}
    <template x-if="form.open">
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
             role="dialog" aria-modal="true" @keydown.escape.window="form.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="form.open=false"></div>
            <div class="relative w-full sm:max-w-md bg-card border-t sm:border sm:rounded-xl shadow-elevation-5 max-h-[92vh] flex flex-col" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
                    <div class="min-w-0 flex-1"><p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Set PoE status</p>
                        <h2 class="text-[14px] font-bold truncate" x-text="form.data.poe_name || form.data.poe_code || '—'"></h2></div>
                    <button class="btn btn-ghost btn-icon-xs" @click="form.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-3">
                    <div x-show="!form.data.poe_code">
                        <label class="label">PoE <span class="text-critical">*</span></label>
                        <select class="select mt-1.5" x-model="form.data.poe_code">
                            <option value="">Select…</option>
                            <template x-for="p in meta.poes" :key="p.poe_code"><option :value="p.poe_code" x-text="p.poe_name + ' · ' + p.district"></option></template>
                        </select>
                    </div>
                    <div>
                        <label class="label">Status <span class="text-critical">*</span></label>
                        <select class="select mt-1.5" x-model="form.data.status">
                            <template x-for="s in meta.statuses" :key="s"><option :value="s" x-text="s.replace('_',' ').toLowerCase()"></option></template>
                        </select>
                    </div>
                    <div>
                        <label class="label">Reason</label>
                        <textarea class="textarea mt-1.5" rows="3" x-model="form.data.reason" placeholder="Holiday closure · Strike · Infrastructure works …"></textarea>
                    </div>
                    <div>
                        <label class="label">Effective from</label>
                        <input type="datetime-local" class="input mt-1.5" x-model="form.data.started_at">
                    </div>
                </div>
                <footer class="flex items-center gap-2 px-4 sm:px-6 py-3 border-t">
                    <div class="flex-1"></div>
                    <button class="btn btn-ghost btn-sm" @click="form.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" @click="save()" :disabled="form.submitting || !form.data.poe_code || !form.data.status">
                        <span x-show="!form.submitting">Save status</span>
                        <span x-show="form.submitting">Saving…</span>
                    </button>
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
    function poeStatus(){
        const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
        const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()});
        const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');
        const blank=()=>({poe_code:'',poe_name:'',status:'OPEN',reason:'',started_at:new Date().toISOString().slice(0,16)});
        return {
            meta:{statuses:[],poes:[]}, filters:{status:'all'}, current:[], log:[], loading:false, tabCounts:{},
            form:{open:false,submitting:false,data:blank()}, opToast:{open:false,kind:'success',title:'',body:'',t:null},
            badgeClass(s){
                return ({OPEN:'badge-success',CLOSED:'badge-critical',REDUCED_HOURS:'badge-warning',EMERGENCY_CLOSED:'badge-critical',MAINTENANCE:'badge-info'})[s]||'badge-outline';
            },
            async boot(){ await Promise.all([this.loadMeta(),this.loadData()]); },
            async loadMeta(){ const r=await fetch('/admin/poe/status/meta'); const j=await r.json(); if(j.success) this.meta=j.data; },
            async loadData(){ this.loading=true;
                try{ const r=await fetch('/admin/poe/status/data?'+qs(this.filters)); const j=await r.json();
                    if(j.success){ this.current=j.data.current; this.log=j.data.log; this.tabCounts=j.meta.tabs;
                        Alpine.store('pageMeta').rows=this.tabCounts.open ?? null;
                        Alpine.store('pageMeta').version=null; Alpine.store('pageMeta').kind='poe-status';
                    }
                } finally{ this.loading=false; } },
            openSet(row){ this.form={open:true,submitting:false,data:row?{...blank(),poe_code:row.poe_code,poe_name:row.poe_name,status:row.status}:{...blank()}}; },
            async save(){ this.form.submitting=true;
                try{ const r=await fetch('/admin/poe/status',{method:'POST',headers:headersJson(),body:JSON.stringify(this.form.data)});
                    const j=await r.json();
                    if(!r.ok||!j.success){ this.toast('error','Save failed',j.message); this.form.submitting=false; return; }
                    this.toast('success','Status updated','PoE status set.'); this.form.open=false; await this.loadData();
                } catch(e){ this.toast('error','Network',e.message); } finally{ this.form.submitting=false; } },
            toast(kind,title,body){ this.opToast={open:true,kind,title,body,t:null}; clearTimeout(this.opToast.t); this.opToast.t=setTimeout(()=>{this.opToast.open=false;},3000); },
        };
    }
</script>
@endpush
