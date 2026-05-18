@extends('admin.layout')

@section('crumb', 'Geography')
@section('title', 'Countries')

@section('content')
<div x-data="countriesView()" x-init="boot()" x-effect="window.adminLock.set('page', edit?.open)" class="space-y-5">

    <div class="alert alert-info">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <div><p class="alert-title">Single-tenant per install</p>
        <p class="alert-description">Per dashboard.txt §D.5, this build is locked to one country (Uganda). The row below is editable for ISO codes, display order, and dataset metadata only — no add/delete from this surface.</p></div>
    </div>

    <template x-if="loading">
        <div class="card"><div class="card-content"><div class="skeleton h-32 w-full"></div></div></div>
    </template>

    <template x-for="row in rows" :key="row.country_code">
        <section class="card">
            <header class="card-header pb-3 border-b">
                <div class="flex items-center gap-3">
                    <div class="grid place-items-center h-10 w-10 rounded-lg bg-brand text-white font-bold text-sm" x-text="row.iso_alpha2 || '??'"></div>
                    <div class="min-w-0 flex-1">
                        <h2 class="card-title" x-text="row.name"></h2>
                        <p class="text-[11.5px] text-muted-foreground">
                            <span class="kbd" x-text="row.country_code"></span>
                            · ISO-2 <span class="font-mono" x-text="row.iso_alpha2 || '—'"></span>
                            · ISO-3 <span class="font-mono" x-text="row.iso_alpha3 || '—'"></span>
                        </p>
                    </div>
                    <button class="btn btn-brand btn-sm" @click="openEdit(row)">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        Edit
                    </button>
                </div>
            </header>

            <div class="card-content">
                <div x-data="{ tab: 'overview' }">
                    <div class="tabs-list w-full sm:w-auto">
                        <button class="tabs-trigger flex-1 sm:flex-none" :data-state="tab==='overview'?'active':'inactive'" @click="tab='overview'">Overview</button>
                        <button class="tabs-trigger flex-1 sm:flex-none" :data-state="tab==='hierarchy'?'active':'inactive'" @click="tab='hierarchy'">Hierarchy</button>
                        <button class="tabs-trigger flex-1 sm:flex-none" :data-state="tab==='metadata'?'active':'inactive'" @click="tab='metadata'">Dataset metadata</button>
                    </div>

                    <div class="tabs-content" x-show="tab==='overview'">
                        <dl class="grid grid-cols-2 sm:grid-cols-4 gap-x-4 gap-y-1.5 text-[12px]">
                            <dt class="text-muted-foreground">active</dt><dd x-text="row.is_active ? 'yes' : 'no'"></dd>
                            <dt class="text-muted-foreground">order</dt><dd class="font-mono tabular-nums" x-text="row.display_order"></dd>
                            <dt class="text-muted-foreground">created</dt><dd class="font-mono text-[11px]" x-text="row.created_at"></dd>
                            <dt class="text-muted-foreground">updated</dt><dd class="font-mono text-[11px]" x-text="row.updated_at"></dd>
                        </dl>
                    </div>

                    <div class="tabs-content" x-show="tab==='hierarchy'">
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                            <div class="kpi"><p class="kpi-label">Provinces</p><p class="kpi-value tabular-nums" x-text="row.province_count"></p></div>
                            <div class="kpi"><p class="kpi-label">Districts</p><p class="kpi-value tabular-nums" x-text="row.district_count"></p></div>
                            <div class="kpi"><p class="kpi-label">PoEs</p><p class="kpi-value tabular-nums" x-text="row.poe_count"></p></div>
                            <div class="kpi"><p class="kpi-label">Hospitals</p><p class="kpi-value tabular-nums" x-text="row.hospital_count"></p></div>
                        </div>
                        <p class="mt-3 text-[11.5px] text-muted-foreground">Bundle version: <span class="font-mono" x-text="'v' + row.bundle_version"></span> · published to <span class="kbd">/api/poes/bundle</span></p>
                    </div>

                    <div class="tabs-content" x-show="tab==='metadata'">
                        <pre class="text-[11px] bg-muted/40 border rounded-lg p-3 overflow-x-auto font-mono leading-relaxed" x-text="JSON.stringify(row.metadata_json, null, 2)"></pre>
                    </div>
                </div>
            </div>
        </section>
    </template>

    {{-- Edit modal (bulletproof modal pattern) --}}
    <template x-if="edit.open">
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
             role="dialog" aria-modal="true"
             @keydown.escape.window="edit.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="edit.open=false"></div>
            <div class="relative w-full sm:max-w-lg bg-card border-t sm:border sm:rounded-xl shadow-elevation-5 max-h-[92vh] flex flex-col" @click.stop>
                    <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                        <div class="min-w-0 flex-1">
                            <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Edit country</p>
                            <h2 class="text-[14px] font-bold truncate" x-text="edit.form.name"></h2>
                        </div>
                        <button class="btn btn-ghost btn-icon-xs" @click="edit.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                    </header>
                    <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-3">
                        <div>
                            <label class="label">Display name</label>
                            <input type="text" class="input mt-1.5" x-model="edit.form.name">
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div><label class="label">ISO alpha-2</label><input type="text" maxlength="2" class="input mt-1.5 font-mono uppercase" x-model="edit.form.iso_alpha2"></div>
                            <div><label class="label">ISO alpha-3</label><input type="text" maxlength="3" class="input mt-1.5 font-mono uppercase" x-model="edit.form.iso_alpha3"></div>
                        </div>
                        <div>
                            <label class="label">Display order</label>
                            <input type="number" class="input mt-1.5" x-model="edit.form.display_order">
                        </div>
                        <p class="help-text">Country code (<span class="kbd" x-text="edit.code"></span>) is immutable — it is the FK joined by ref_provinces / ref_districts / ref_poes / ref_hospitals.</p>
                    </div>
                    <footer class="flex items-center gap-2 px-4 sm:px-6 py-3 border-t">
                        <div class="flex-1"></div>
                        <button class="btn btn-ghost btn-sm" @click="edit.open=false">Cancel</button>
                        <button class="btn btn-brand btn-sm" @click="save()" :disabled="edit.submitting">
                            <span x-show="!edit.submitting">Save changes</span>
                            <span x-show="edit.submitting">Saving…</span>
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
    function countriesView(){
        const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
        const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf()});
        return {
            rows:[], loading:false, edit:{open:false,code:'',form:{},submitting:false},
            opToast:{open:false,kind:'success',title:'',body:'',t:null},
            async boot(){ await this.loadData(); },
            async loadData(){ this.loading=true;
                try{ const r=await fetch('/admin/geo/countries/data'); const j=await r.json();
                    if(j.success){
                        this.rows=j.data.rows;
                        Alpine.store('pageMeta').rows    = j.data.rows.length;
                        Alpine.store('pageMeta').version = j.data.rows[0]?.bundle_version ?? null;
                        Alpine.store('pageMeta').kind    = 'countries';
                    }
                } finally{ this.loading=false; } },
            openEdit(row){ this.edit={open:true,code:row.country_code,form:{name:row.name,iso_alpha2:row.iso_alpha2||'',iso_alpha3:row.iso_alpha3||'',display_order:row.display_order},submitting:false}; },
            async save(){ this.edit.submitting=true;
                try{ const r=await fetch('/admin/geo/countries/'+encodeURIComponent(this.edit.code),{method:'PATCH',headers:headersJson(),body:JSON.stringify(this.edit.form)});
                    const j=await r.json();
                    if(!r.ok||!j.success){ this.toast('error','Save failed',j.message); this.edit.submitting=false; return; }
                    this.toast('success','Updated','Bundle now v'+(j.meta?.version??'?'));
                    this.edit.open=false; await this.loadData();
                } catch(e){ this.toast('error','Network error',e.message); } finally{ this.edit.submitting=false; } },
            toast(kind,title,body){ this.opToast={open:true,kind,title,body,t:null}; clearTimeout(this.opToast.t);
                this.opToast.t=setTimeout(()=>{this.opToast.open=false;},3000); },
        };
    }
</script>
@endpush
