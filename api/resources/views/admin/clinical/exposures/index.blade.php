{{-- Admin · Clinical Library · Exposures (clin-exposures) — read-only reference --}}
@extends('admin.layout')
@section('crumb', 'Clinical Library')
@section('title', $pageTitle)

@section('content')
<div x-data="clinExposures()" x-init="boot()" class="space-y-5">

    <section class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Clinical Library · Reference browser (read-only)</p>
            <h2 class="display-md mt-1">Exposures</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                What raises a traveller's risk and how the operator-facing exposure
                names map to the engine's internal codes.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="kpis.total + ' exposures on file'"></span>
            </span>
            @include('admin.clinical._coach', ['sectionKey' => 'clin-exposures'])
            <button type="button" class="btn btn-ghost btn-sm" @click="exportCsv()">Export CSV</button>
        </div>
    </section>

    <template x-if="ready">
        <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="kpi"><p class="kpi-label">Total exposures</p><p class="kpi-value tabular-nums" x-text="kpis.total"></p><p class="text-[11px] text-muted-foreground mt-1"><span x-text="kpis.active"></span> active</p></div>
            <div class="kpi"><p class="kpi-label">High-risk</p><p class="kpi-value tabular-nums text-warning" x-text="kpis.high_risk"></p><p class="text-[11px] text-muted-foreground mt-1">High-risk on their own</p></div>
            <div class="kpi"><p class="kpi-label">Engine codes</p><p class="kpi-value tabular-nums" x-text="kpis.distinct_engine_codes"></p><p class="text-[11px] text-muted-foreground mt-1">Distinct engine targets</p></div>
            <div class="kpi"><p class="kpi-label">Categories</p><p class="kpi-value tabular-nums" x-text="Object.keys(kpis.categories || {}).length"></p><p class="text-[11px] text-muted-foreground mt-1">Categories in use</p></div>
        </section>
    </template>

    <template x-if="ready">
        <section class="card">
            <div class="card-content !p-0">
                <nav class="flex flex-wrap gap-0 border-b" role="tablist">
                    <template x-for="t in tabs" :key="t.key">
                        <button type="button" class="px-4 py-3 text-[12.5px] font-medium border-b-2 transition-colors"
                                :class="tab === t.key ? 'border-brand text-brand' : 'border-transparent text-muted-foreground hover:text-foreground'"
                                @click="tab = t.key">
                            <span x-text="t.label"></span>
                        </button>
                    </template>
                </nav>

                <div x-show="tab === 'all'" class="p-4">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <input type="search" class="input input-sm w-64" placeholder="Search…" x-model.debounce.250ms="search">
                        <label class="flex items-center gap-1.5 text-[12px]"><input type="checkbox" x-model="highRiskOnly"> High-risk</label>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead><tr><th>Exposure</th><th>Operator prompt</th><th>Response</th><th>Engine codes</th><th>Diseases</th><th>Strongest link</th><th>Flags</th></tr></thead>
                            <tbody>
                                <template x-for="r in filtered()" :key="r.id">
                                    <tr>
                                        <td>
                                            <button type="button" class="text-left hover:underline" @click="openDossier(r.id)">
                                                <span class="font-semibold" x-text="r.display_name"></span>
                                            </button>
                                            <p class="text-[11px] text-muted-foreground" x-text="r.exposure_code"></p>
                                        </td>
                                        <td class="text-[12px] max-w-[260px] truncate" x-text="r.prompt_text || '—'" :title="r.prompt_text"></td>
                                        <td class="text-[11px]" x-text="r.response_label"></td>
                                        <td class="text-[11px]">
                                            <template x-for="ec in (r.engine_codes || []).slice(0, 3)" :key="ec.engine_code">
                                                <span class="badge badge-soft mr-1 mb-0.5" x-text="ec.engine_code"></span>
                                            </template>
                                            <template x-if="(r.engine_codes || []).length > 3"><span class="text-[10px] text-muted-foreground">+<span x-text="r.engine_codes.length - 3"></span> more</span></template>
                                        </td>
                                        <td class="tabular-nums" x-text="r.disease_count"></td>
                                        <td class="text-[12px]"><template x-if="r.strongest_link"><span x-text="r.strongest_link.display_name + ' (' + (r.strongest_link.weight > 0 ? '+' : '') + r.strongest_link.weight + ')'"></span></template></td>
                                        <td class="text-[11px]"><template x-if="r.is_high_risk"><span class="badge badge-warning">High-risk</span></template></td>
                                    </tr>
                                </template>
                                <template x-if="filtered().length === 0">
                                    <tr><td colspan="7" class="text-center text-[12px] text-muted-foreground py-6">No exposures match the current filters.</td></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div x-show="tab === 'mappings'" class="p-4">
                    @include('admin.clinical._interpretation_modal', [
                        'chartId' => 'clin-exposures.engine-mappings',
                        'title' => 'How operator-facing exposures map to engine codes',
                        'how' => 'Each row maps an exposure code (what the operator sees as a question) to the engine code (what the scoring engine actually consumes). Priority breaks ties when a single exposure maps to several engine codes.',
                        'informative' => 'Most exposures have a single engine code with priority 1. Multi-code mappings exist when an exposure is ambiguous and the engine needs to consider it against several disease pathways.',
                        'concerning' => 'Two mappings with the same priority — the engine has to pick one and the choice is data-order-dependent. Or an exposure with no mapping at all — the platform never sees its signal.',
                        'whatToDo' => 'Raise with the clinical team. The mapping table is owned by the scoring engine team.',
                        'cantTell' => 'It does not tell you which diseases each engine code drives — open the disease dossier for that.',
                        'source' => 'ref_exposure_mappings — one row per (exposure_code, engine_code) pair.',
                    ])
                    <div class="overflow-x-auto mt-3">
                        <table class="table table-sm">
                            <thead><tr><th>Exposure code</th><th>Engine code</th><th>Priority</th></tr></thead>
                            <tbody>
                                <template x-for="m in mappings" :key="m.exposure_code + '|' + m.engine_code">
                                    <tr><td class="text-[12px] font-mono" x-text="m.exposure_code"></td><td class="text-[12px] font-mono" x-text="m.engine_code"></td><td class="tabular-nums" x-text="m.priority"></td></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div x-show="tab === 'strongest'" class="p-4">
                    <ol class="space-y-1">
                        <template x-for="r in topByStrongest()" :key="r.id">
                            <li class="flex items-center justify-between text-[12.5px]">
                                <span><button class="hover:underline font-semibold" @click="openDossier(r.id)" x-text="r.display_name"></button>
                                <span class="text-muted-foreground" x-text="' → ' + (r.strongest_link ? r.strongest_link.display_name : '—')"></span></span>
                                <span class="tabular-nums" x-text="r.strongest_link ? (r.strongest_link.weight > 0 ? '+' : '') + r.strongest_link.weight : '—'"></span>
                            </li>
                        </template>
                    </ol>
                </div>

                <div x-show="tab === 'methodology'" class="p-4 text-[12.5px] leading-relaxed space-y-2">
                    <p>Exposures are situations a traveller has been in (sick contacts, animal contact, food handling, lab work, travel from outbreak area). Each has an operator-facing prompt and one or more engine codes the scoring engine can read.</p>
                    <p>The platform asks the operator a plain-language question; the answer (Yes / No / multi-select / numeric / text) is mapped to one or more engine codes via the mappings table.</p>
                </div>
            </div>
        </section>
    </template>

    <div x-cloak x-show="dossier.open" class="fixed inset-0 z-[60] bg-black/40" @click="dossier.open=false"></div>
    <aside x-cloak x-show="dossier.open" class="fixed inset-y-0 right-0 z-[61] w-full max-w-[560px] bg-background border-l border-border shadow-2xl flex flex-col">
        <header class="border-b border-border p-4 flex items-start justify-between">
            <div>
                <p class="eyebrow">Exposure dossier</p>
                <h3 class="text-[16px] font-semibold mt-0.5" x-text="dossier.data?.display_name || '—'"></h3>
                <p class="text-[11px] text-muted-foreground" x-text="dossier.data?.exposure_code"></p>
            </div>
            <button class="btn btn-ghost btn-sm" @click="dossier.open=false">Close</button>
        </header>
        <div class="flex-1 overflow-y-auto p-4 text-[12.5px]">
            <template x-if="dossier.data">
                <div class="space-y-4">
                    <p><span class="text-muted-foreground">Operator prompt:</span> <span x-text="dossier.data.prompt_text || '—'"></span></p>
                    <p><span class="text-muted-foreground">Response shape:</span> <span x-text="dossier.data.response_label"></span></p>
                    <div>
                        <h4 class="text-[12px] font-semibold mb-1">Engine codes</h4>
                        <ul class="space-y-0.5">
                            <template x-for="e in (dossier.data.engine_codes || [])" :key="e.engine_code">
                                <li class="flex items-center justify-between"><span class="font-mono" x-text="e.engine_code"></span><span class="text-[10px] text-muted-foreground" x-text="'priority ' + e.priority"></span></li>
                            </template>
                        </ul>
                    </div>
                    <div>
                        <h4 class="text-[12px] font-semibold mb-1">Linked diseases</h4>
                        <ul class="space-y-0.5">
                            <template x-for="l in (dossier.data.linked_diseases || [])" :key="l.disease_code">
                                <li class="flex items-center justify-between">
                                    <span><span class="badge mr-1" :class="l.tier.code === 1 ? 'badge-critical' : (l.tier.code === 2 ? 'badge-warning' : 'badge-muted')" x-text="l.tier.short"></span><span x-text="l.display_name"></span></span>
                                    <span class="tabular-nums"><span class="font-mono mr-1" x-text="(l.weight > 0 ? '+' : '') + l.weight"></span><span class="text-[10px] text-muted-foreground" x-text="l.strength.label"></span></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
            </template>
        </div>
    </aside>
</div>

@push('scripts')
<script>
    function clinExposures() {
        return {
            ready:false, tab:'all',
            tabs:[
                {key:'all',         label:'All Exposures'},
                {key:'mappings',    label:'Engine-Code Mappings'},
                {key:'strongest',   label:'By Strongest Disease Link'},
                {key:'methodology', label:'Methodology'},
            ],
            rows:[], mappings:[], kpis:{total:0,active:0,high_risk:0,distinct_engine_codes:0,response_types:{},categories:{}},
            search:'', highRiskOnly:false,
            dossier:{open:false, data:null},
            async boot() {
                try {
                    const r = await fetch('{{ route('admin.clinical.exposures.data') }}', {headers:{Accept:'application/json'}});
                    const j = await r.json();
                    if (j.success) { this.rows = j.data.rows; this.mappings = j.data.mappings; this.kpis = j.data.kpis; }
                } catch (e) { console.error(e); }
                this.ready = true;
            },
            filtered() {
                let r = this.rows;
                if (this.highRiskOnly) r = r.filter(x => x.is_high_risk);
                if (this.search) {
                    const q = this.search.toLowerCase();
                    r = r.filter(x => (x.display_name||'').toLowerCase().includes(q) || (x.exposure_code||'').toLowerCase().includes(q) || (x.prompt_text||'').toLowerCase().includes(q));
                }
                return r;
            },
            topByStrongest() {
                return [...this.rows].sort((a,b) => {
                    const aw = a.strongest_link ? Math.abs(a.strongest_link.weight) : 0;
                    const bw = b.strongest_link ? Math.abs(b.strongest_link.weight) : 0;
                    return bw - aw;
                }).slice(0, 50);
            },
            async openDossier(id) {
                this.dossier.open = true; this.dossier.data = null;
                try {
                    const r = await fetch('{{ url('admin/clinical/exposures') }}/' + id, {headers:{Accept:'application/json'}});
                    const j = await r.json();
                    if (j.success) this.dossier.data = j.data;
                } catch (e) { console.error(e); }
            },
            exportCsv() {
                const headers = ['Exposure','Code','Operator prompt','Response','Engine codes','Diseases','High-risk','Active'];
                const rows = this.filtered().map(r => [r.display_name, r.exposure_code, r.prompt_text || '', r.response_label, (r.engine_codes||[]).map(e=>e.engine_code).join('; '), r.disease_count, r.is_high_risk?'Yes':'No', r.is_active?'Yes':'No']);
                const csv = [headers, ...rows].map(row => row.map(v => '"' + String(v).replace(/"/g,'""') + '"').join(',')).join('\r\n');
                const blob = new Blob([csv], {type:'text/csv;charset=utf-8'});
                const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'clin-exposures.csv'; a.click(); URL.revokeObjectURL(a.href);
            },
        };
    }
</script>
@endpush
@endsection
