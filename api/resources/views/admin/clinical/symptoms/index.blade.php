{{-- Admin · Clinical Library · Symptoms (clin-symptoms) — read-only reference --}}
@extends('admin.layout')
@section('crumb', 'Clinical Library')
@section('title', $pageTitle)

@section('content')
<div x-data="clinSymptoms()" x-init="boot()" class="space-y-5">

    <section class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Clinical Library · Reference browser (read-only)</p>
            <h2 class="display-md mt-1">Symptoms</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                What each symptom contributes to which diseases — discovered live from
                the reference data, with strength labels and syndromic groups in plain
                language.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="kpis.total + ' symptoms on file'"></span>
            </span>
            @include('admin.clinical._coach', ['sectionKey' => 'clin-symptoms'])
            <button type="button" class="btn btn-ghost btn-sm" @click="exportCsv()">Export CSV</button>
        </div>
    </section>

    <template x-if="ready">
        <section class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-5">
            <div class="kpi"><p class="kpi-label">Total</p><p class="kpi-value tabular-nums" x-text="kpis.total"></p><p class="text-[11px] text-muted-foreground mt-1"><span x-text="kpis.active"></span> active</p></div>
            <div class="kpi"><p class="kpi-label">Red-flag</p><p class="kpi-value tabular-nums text-warning" x-text="kpis.red_flag"></p><p class="text-[11px] text-muted-foreground mt-1">Urgent on their own</p></div>
            <div class="kpi"><p class="kpi-label">Hallmark</p><p class="kpi-value tabular-nums text-info" x-text="kpis.hallmark"></p><p class="text-[11px] text-muted-foreground mt-1">Required for at least one disease</p></div>
            <div class="kpi"><p class="kpi-label">Categories</p><p class="kpi-value tabular-nums" x-text="Object.keys(kpis.categories || {}).length"></p><p class="text-[11px] text-muted-foreground mt-1">Clinical categories in use</p></div>
            <div class="kpi"><p class="kpi-label">Syndromic tags</p><p class="kpi-value tabular-nums" x-text="Object.keys(kpis.syndrome_tags || {}).length"></p><p class="text-[11px] text-muted-foreground mt-1">Distinct WHO groups</p></div>
        </section>
    </template>

    <template x-if="ready">
        <section class="card">
            <div class="card-content !p-0">
                <nav class="flex flex-wrap gap-0 border-b" role="tablist">
                    <template x-for="t in tabs" :key="t.key">
                        <button type="button"
                                class="px-4 py-3 text-[12.5px] font-medium border-b-2 transition-colors"
                                :class="tab === t.key ? 'border-brand text-brand' : 'border-transparent text-muted-foreground hover:text-foreground'"
                                role="tab" @click="tab = t.key">
                            <span x-text="t.label"></span>
                        </button>
                    </template>
                </nav>

                <div x-show="tab === 'all'" class="p-4">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <input type="search" class="input input-sm w-64" placeholder="Search symptom…" x-model.debounce.250ms="search">
                        <select class="select select-sm" x-model="categoryFilter">
                            <option value="">All categories</option>
                            <template x-for="c in categoriesInUse" :key="c"><option :value="c" x-text="c"></option></template>
                        </select>
                        <label class="flex items-center gap-1.5 text-[12px]"><input type="checkbox" x-model="redFlagOnly"> Red-flag</label>
                        <label class="flex items-center gap-1.5 text-[12px]"><input type="checkbox" x-model="hallmarkOnly"> Hallmark</label>
                        <span class="ml-auto text-[11px] text-muted-foreground">Showing <span x-text="filtered().length"></span> of <span x-text="rows.length"></span></span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead><tr><th>Symptom</th><th>Category</th><th>Sensitivity</th><th>Diseases</th><th>Strongest link</th><th>Flags</th></tr></thead>
                            <tbody>
                                <template x-for="r in filtered()" :key="r.id">
                                    <tr>
                                        <td>
                                            <button type="button" class="text-left hover:underline" @click="openDossier(r.id)">
                                                <span class="font-semibold" x-text="r.display_name"></span>
                                            </button>
                                            <p class="text-[11px] text-muted-foreground" x-text="r.symptom_code"></p>
                                        </td>
                                        <td class="text-[12px]" x-text="r.category || '—'"></td>
                                        <td class="text-[12px]" x-text="r.sensitivity.label"></td>
                                        <td class="tabular-nums" x-text="r.disease_count"></td>
                                        <td class="text-[12px]"><template x-if="r.strongest_link"><span x-text="r.strongest_link.display_name + ' (' + (r.strongest_link.weight > 0 ? '+' : '') + r.strongest_link.weight + ')'"></span></template></td>
                                        <td class="text-[11px]">
                                            <template x-if="r.is_red_flag"><span class="badge badge-warning mr-1">Red-flag</span></template>
                                            <template x-if="r.is_hallmark"><span class="badge badge-info">Hallmark</span></template>
                                        </td>
                                    </tr>
                                </template>
                                <template x-if="filtered().length === 0">
                                    <tr><td colspan="6" class="text-center text-[12px] text-muted-foreground py-6">No symptoms match the current filters.</td></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div x-show="tab === 'syndrome'" class="p-4">
                    @include('admin.clinical._interpretation_modal', [
                        'chartId' => 'clin-symptoms.syndromic-groups',
                        'title' => 'How symptoms group by WHO syndromic category',
                        'how' => 'Each chip is a syndromic group. The number is the count of symptoms tagged with that group. Symptoms can belong to more than one group.',
                        'informative' => 'Most symptoms cluster into 2-3 well-defined syndromic groups.',
                        'concerning' => 'A syndromic group with only one or two symptoms — may indicate a coding gap.',
                        'whatToDo' => 'Raise with the clinical team. The grouping comes from the syndrome_tags column.',
                        'cantTell' => 'It does not tell you which diseases the group points to; click into a symptom for that.',
                        'source' => 'ref_symptoms.syndrome_tags — the JSON array of tag strings on each symptom row.',
                    ])
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2 mt-3">
                        <template x-for="(count, label) in (kpis.syndrome_tags || {})" :key="label">
                            <div class="rounded border border-border bg-background p-2 flex items-center justify-between">
                                <span class="font-semibold text-[12.5px]" x-text="label"></span>
                                <span class="badge badge-soft tabular-nums" x-text="count"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div x-show="tab === 'strongest'" class="p-4">
                    <ol class="space-y-1">
                        <template x-for="r in topByStrongest()" :key="r.id">
                            <li class="flex items-center justify-between text-[12.5px]">
                                <span>
                                    <button class="hover:underline font-semibold" @click="openDossier(r.id)" x-text="r.display_name"></button>
                                    <span class="text-muted-foreground" x-text="' → ' + (r.strongest_link ? r.strongest_link.display_name : '—')"></span>
                                </span>
                                <span class="tabular-nums" x-text="r.strongest_link ? (r.strongest_link.weight > 0 ? '+' : '') + r.strongest_link.weight : '—'"></span>
                            </li>
                        </template>
                    </ol>
                </div>

                <div x-show="tab === 'combos'" class="p-4">
                    <p class="text-[12px] text-muted-foreground mb-3">Pick two or three symptoms (codes, comma-separated). The platform shows the diseases all of them point to, ranked by combined weight.</p>
                    <input class="input input-sm w-96" x-model="combo.codes" @change="runCombination()" placeholder="fever, high_fever, vomiting">
                    <div class="mt-3">
                        <template x-if="combo.matches.length === 0 && combo.queried">
                            <p class="text-[12px] text-muted-foreground italic">No diseases include all of those symptoms in their weights.</p>
                        </template>
                        <ul class="space-y-1">
                            <template x-for="m in combo.matches" :key="m.disease_code">
                                <li class="flex items-center justify-between text-[12.5px]">
                                    <span><span class="badge mr-2" :class="m.tier.code === 1 ? 'badge-critical' : (m.tier.code === 2 ? 'badge-warning' : 'badge-muted')" x-text="m.tier.short"></span><span class="font-semibold" x-text="m.display_name"></span></span>
                                    <span class="tabular-nums" x-text="(m.combined_weight > 0 ? '+' : '') + m.combined_weight + '  · ' + m.strength.label"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>

                <div x-show="tab === 'methodology'" class="p-4 text-[12.5px] leading-relaxed space-y-2">
                    <p>Each symptom carries a clinical sensitivity (how often the symptom is present in confirmed cases) and a syndromic-group set (which WHO syndromes the symptom contributes to).</p>
                    <p>The strength label on a symptom-disease link is derived from the disease’s symptom_weights JSON: ≥18 very strong, ≥12 strong, ≥6 moderate, > 0 weak; negative weights mean the symptom rules the disease out.</p>
                </div>
            </div>
        </section>
    </template>

    <div x-cloak x-show="dossier.open" class="fixed inset-0 z-[60] bg-black/40" @click="dossier.open=false"></div>
    <aside x-cloak x-show="dossier.open" class="fixed inset-y-0 right-0 z-[61] w-full max-w-[560px] bg-background border-l border-border shadow-2xl flex flex-col">
        <header class="border-b border-border p-4 flex items-start justify-between">
            <div>
                <p class="eyebrow">Symptom dossier</p>
                <h3 class="text-[16px] font-semibold mt-0.5" x-text="dossier.data?.display_name || '—'"></h3>
                <p class="text-[11px] text-muted-foreground" x-text="dossier.data?.symptom_code"></p>
            </div>
            <button class="btn btn-ghost btn-sm" @click="dossier.open=false">Close</button>
        </header>
        <div class="flex-1 overflow-y-auto p-4 text-[12.5px]">
            <template x-if="dossier.data">
                <div class="space-y-4">
                    <p><span class="text-muted-foreground">Category:</span> <span x-text="dossier.data.category || '—'"></span></p>
                    <p><span class="text-muted-foreground">Sensitivity:</span> <span x-text="dossier.data.sensitivity.label"></span></p>
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
    function clinSymptoms() {
        return {
            ready: false,
            tab: 'all',
            tabs: [
                {key: 'all',         label: 'All Symptoms'},
                {key: 'syndrome',    label: 'By Syndromic Group'},
                {key: 'strongest',   label: 'By Strongest Disease Link'},
                {key: 'combos',      label: 'Symptom Combinations'},
                {key: 'methodology', label: 'Methodology'},
            ],
            rows: [], kpis: {total:0, active:0, red_flag:0, hallmark:0, categories:{}, syndrome_tags:{}},
            categoriesInUse: [], syndromeTagsInUse: [],
            search: '', categoryFilter: '', redFlagOnly: false, hallmarkOnly: false,
            dossier: {open:false, data:null},
            combo: {codes:'', queried:false, matches:[]},
            async boot() {
                try {
                    const r = await fetch('{{ route('admin.clinical.symptoms.data') }}', {headers: {Accept:'application/json'}});
                    const j = await r.json();
                    if (j.success) {
                        this.rows = j.data.rows; this.kpis = j.data.kpis;
                        this.categoriesInUse = j.data.categories_in_use; this.syndromeTagsInUse = j.data.syndrome_tags_in_use;
                    }
                } catch (e) { console.error(e); }
                this.ready = true;
            },
            filtered() {
                let r = this.rows;
                if (this.categoryFilter) r = r.filter(x => x.category === this.categoryFilter);
                if (this.redFlagOnly)    r = r.filter(x => x.is_red_flag);
                if (this.hallmarkOnly)   r = r.filter(x => x.is_hallmark);
                if (this.search) {
                    const q = this.search.toLowerCase();
                    r = r.filter(x => (x.display_name||'').toLowerCase().includes(q) || (x.symptom_code||'').toLowerCase().includes(q));
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
                    const r = await fetch('{{ url('admin/clinical/symptoms') }}/' + id, {headers: {Accept:'application/json'}});
                    const j = await r.json();
                    if (j.success) this.dossier.data = j.data;
                } catch (e) { console.error(e); }
            },
            async runCombination() {
                const codes = this.combo.codes.split(',').map(s=>s.trim()).filter(Boolean);
                this.combo.queried = true;
                if (! codes.length) { this.combo.matches = []; return; }
                const params = new URLSearchParams();
                codes.forEach(c => params.append('codes[]', c));
                try {
                    const r = await fetch('{{ route('admin.clinical.symptoms.combinations') }}?' + params.toString(),
                                          {headers: {Accept:'application/json'}});
                    const j = await r.json();
                    if (j.success) this.combo.matches = j.data.matches || [];
                } catch (e) { console.error(e); }
            },
            exportCsv() {
                const headers = ['Symptom','Code','Category','Sensitivity','Disease count','Red-flag','Hallmark'];
                const rows = this.filtered().map(r => [r.display_name, r.symptom_code, r.category || '', r.sensitivity.label, r.disease_count, r.is_red_flag?'Yes':'No', r.is_hallmark?'Yes':'No']);
                const csv = [headers, ...rows].map(row => row.map(v => '"' + String(v).replace(/"/g,'""') + '"').join(',')).join('\r\n');
                const blob = new Blob([csv], {type:'text/csv;charset=utf-8'});
                const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'clin-symptoms.csv'; a.click(); URL.revokeObjectURL(a.href);
            },
        };
    }
</script>
@endpush
@endsection
