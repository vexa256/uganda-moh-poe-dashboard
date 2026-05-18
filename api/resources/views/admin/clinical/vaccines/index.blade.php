{{-- Admin · Clinical Library · Vaccines (clin-vaccines) — read-only reference --}}
@extends('admin.layout')
@section('crumb', 'Clinical Library')
@section('title', $pageTitle)

@section('content')
<div x-data="clinVaccines()" x-init="boot()" class="space-y-5">

    <section class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Clinical Library · Reference browser (read-only)</p>
            <h2 class="display-md mt-1">Vaccines</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                What counts as a valid vaccination record in this system, and what
                difference it makes to a traveller's score at the border.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready"><span class="status-dot status-dot-live"></span><span x-text="kpis.vaccine_count + ' vaccines'"></span></span>
            @include('admin.clinical._coach', ['sectionKey' => 'clin-vaccines'])
            <button type="button" class="btn btn-ghost btn-sm" @click="exportCsv()">Export CSV</button>
        </div>
    </section>

    <template x-if="ready">
        <section class="grid grid-cols-2 gap-3 sm:grid-cols-3">
            <div class="kpi"><p class="kpi-label">Vaccines on file</p><p class="kpi-value tabular-nums" x-text="kpis.vaccine_count"></p><p class="text-[11px] text-muted-foreground mt-1">Discovered live</p></div>
            <div class="kpi"><p class="kpi-label">Engine-config rows</p><p class="kpi-value tabular-nums" x-text="kpis.engine_row_count"></p><p class="text-[11px] text-muted-foreground mt-1">Vaccine-tagged config</p></div>
            <div class="kpi"><p class="kpi-label">Submission columns</p><p class="kpi-value tabular-nums" x-text="kpis.column_group_count"></p><p class="text-[11px] text-muted-foreground mt-1">Aggregated-template stances</p></div>
        </section>
    </template>

    {{-- Reality-of-data notice — surfaces honestly when both sources are empty --}}
    <template x-if="ready && kpis.vaccine_count === 0">
        <section class="card border-warning">
            <div class="card-content">
                <h3 class="text-[13px] font-semibold text-warning">No vaccine data on file yet</h3>
                <p class="mt-1 text-[12px] text-muted-foreground" x-text="realityNote"></p>
                <p class="mt-2 text-[12px]">There is no first-class <span class="font-mono">ref_vaccines</span> table in this schema. The view is built from <span class="font-mono">ref_engine_config</span> rows whose key mentions a vaccine name and from <span class="font-mono">aggregated_template_columns</span> whose key encodes a vaccine stance. Until either source has rows, this view stays honest about being empty rather than fabricating a vaccine list.</p>
            </div>
        </section>
    </template>

    <template x-if="ready && kpis.vaccine_count > 0">
        <section class="card">
            <div class="card-content !p-0">
                <nav class="flex flex-wrap gap-0 border-b" role="tablist">
                    <template x-for="t in tabs" :key="t.key">
                        <button type="button" class="px-4 py-3 text-[12.5px] font-medium border-b-2 transition-colors"
                                :class="tab === t.key ? 'border-brand text-brand' : 'border-transparent text-muted-foreground hover:text-foreground'"
                                @click="tab = t.key"><span x-text="t.label"></span></button>
                    </template>
                </nav>

                <div x-show="tab === 'all'" class="p-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <template x-for="vx in inventory" :key="vx.vaccine_key">
                            <div class="card">
                                <div class="card-content">
                                    <h3 class="text-[13px] font-semibold" x-text="vx.display_name"></h3>
                                    <p class="text-[11px] text-muted-foreground" x-text="'Source: ' + vx.source"></p>
                                    <p class="mt-2 text-[11.5px]"><span x-text="vx.column_count"></span> submission columns · <span x-text="vx.engine_rows"></span> engine rows</p>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="tab === 'rules'" class="p-4">
                    <p class="text-[12px] text-muted-foreground mb-3">Engine-config rows tagged for vaccines. Each row is a tunable the engine reads at scoring time.</p>
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead><tr><th>Config key</th><th>Description</th><th>Section</th><th>Version</th><th>Active</th></tr></thead>
                            <tbody>
                                <template x-for="r in engineRows" :key="r.config_key">
                                    <tr>
                                        <td class="font-mono text-[12px]" x-text="r.config_key"></td>
                                        <td class="text-[12px]" x-text="r.description || '—'"></td>
                                        <td class="text-[12px]" x-text="r.section || '—'"></td>
                                        <td class="text-[11px] text-muted-foreground" x-text="r.version || '—'"></td>
                                        <td><span class="badge" :class="r.is_active ? 'badge-success' : 'badge-muted'" x-text="r.is_active ? 'active' : 'inactive'"></span></td>
                                    </tr>
                                </template>
                                <template x-if="engineRows.length === 0">
                                    <tr><td colspan="5" class="text-[12px] text-muted-foreground italic py-4 text-center">No vaccine-tagged engine-config rows on file.</td></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div x-show="tab === 'links'" class="p-4">
                    <p class="text-[12px] text-muted-foreground mb-3">Heuristic match between vaccines and diseases. Confirm with the clinical team — this is a name-based match, not a clinical authority.</p>
                    <ul class="space-y-1">
                        <template x-for="(l, idx) in diseaseLinks" :key="idx">
                            <li class="flex items-center justify-between text-[12.5px]">
                                <span><span class="font-semibold" x-text="l.vaccine_name"></span><span class="text-muted-foreground" x-text="' → ' + l.disease_name"></span></span>
                                <span class="badge" :class="l.tier.code === 1 ? 'badge-critical' : (l.tier.code === 2 ? 'badge-warning' : 'badge-muted')" x-text="l.tier.short"></span>
                            </li>
                        </template>
                        <template x-if="!diseaseLinks || diseaseLinks.length === 0">
                            <li class="text-[12px] text-muted-foreground italic">No name-based vaccine-to-disease links found.</li>
                        </template>
                    </ul>
                </div>

                <div x-show="tab === 'methodology'" class="p-4 text-[12.5px] leading-relaxed space-y-2">
                    <p>Vaccination changes scoring when a traveller can prove a recognised, valid vaccination certificate. The platform recognises a vaccine when the engine has at least one config row that mentions the vaccine name; the certificate is valid when it satisfies the engine's per-vaccine validity rule.</p>
                    <p>This view derives its inventory from <span class="font-mono">ref_engine_config</span> + <span class="font-mono">aggregated_template_columns</span>. There is no first-class vaccine reference table in this schema; the inventory grows naturally as either source gains rows.</p>
                </div>
            </div>
        </section>
    </template>
</div>

@push('scripts')
<script>
    function clinVaccines() {
        return {
            ready:false, tab:'all',
            tabs:[
                {key:'all',         label:'All Vaccines'},
                {key:'rules',       label:'Engine-Config Rows'},
                {key:'links',       label:'By Disease Link'},
                {key:'methodology', label:'Methodology'},
            ],
            inventory:[], engineRows:[], diseaseLinks:[],
            kpis:{vaccine_count:0,engine_row_count:0,column_group_count:0},
            realityNote: '',
            async boot() {
                try {
                    const r = await fetch('{{ route('admin.clinical.vaccines.data') }}', {headers:{Accept:'application/json'}});
                    const j = await r.json();
                    if (j.success) {
                        this.inventory = j.data.inventory; this.engineRows = j.data.engine_rows;
                        this.diseaseLinks = j.data.disease_links; this.kpis = j.data.kpis;
                        this.realityNote = j.data.reality_note;
                    }
                } catch (e) { console.error(e); }
                this.ready = true;
            },
            exportCsv() {
                const headers = ['Vaccine','Source','Submission columns','Engine rows'];
                const rows = this.inventory.map(v => [v.display_name, v.source, v.column_count, v.engine_rows]);
                const csv = [headers, ...rows].map(row => row.map(v => '"' + String(v).replace(/"/g,'""') + '"').join(',')).join('\r\n');
                const blob = new Blob([csv], {type:'text/csv;charset=utf-8'});
                const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'clin-vaccines.csv'; a.click(); URL.revokeObjectURL(a.href);
            },
        };
    }
</script>
@endpush
@endsection
