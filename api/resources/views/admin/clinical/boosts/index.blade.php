{{-- Admin · Clinical Library · Scoring Rules (clin-boosts) — read-only reference --}}
@extends('admin.layout')
@section('crumb', 'Clinical Library')
@section('title', $pageTitle)

@section('content')
<div x-data="clinBoosts()" x-init="boot()" class="space-y-5">

    <section class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Clinical Library · Reference browser (read-only)</p>
            <h2 class="display-md mt-1">Scoring Rules</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                Boost rules and tunables layered on top of the standard symptom-and-exposure
                scoring. Read-only — clinical changes go through the governed workflow.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready"><span class="status-dot status-dot-live"></span><span x-text="kpis.total + ' rules'"></span></span>
            @include('admin.clinical._coach', ['sectionKey' => 'clin-boosts'])
            <button type="button" class="btn btn-ghost btn-sm" @click="exportCsv()">Export CSV</button>
        </div>
    </section>

    <template x-if="ready">
        <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="kpi"><p class="kpi-label">Total rules</p><p class="kpi-value tabular-nums" x-text="kpis.total"></p><p class="text-[11px] text-muted-foreground mt-1"><span x-text="kpis.active"></span> active</p></div>
            <div class="kpi"><p class="kpi-label">Disease boosts</p><p class="kpi-value tabular-nums" x-text="kpis.distinct_disease_boosts"></p><p class="text-[11px] text-muted-foreground mt-1">Distinct diseases boosted</p></div>
            <div class="kpi"><p class="kpi-label">Largest boost</p><p class="kpi-value tabular-nums text-warning" x-text="kpis.largest_boost"></p><p class="text-[11px] text-muted-foreground mt-1">Single largest value</p></div>
            <div class="kpi"><p class="kpi-label">Sections</p><p class="kpi-value tabular-nums" x-text="(sectionsInUse || []).length"></p><p class="text-[11px] text-muted-foreground mt-1">Engine sections in use</p></div>
        </section>
    </template>

    <template x-if="ready">
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
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <input type="search" class="input input-sm w-64" placeholder="Search…" x-model.debounce.250ms="search">
                        <select class="select select-sm" x-model="sectionFilter">
                            <option value="">All sections</option>
                            <template x-for="s in sectionsInUse" :key="s"><option :value="s" x-text="s"></option></template>
                        </select>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead><tr><th>Rule</th><th>Section</th><th>Plain summary</th><th>Preview</th><th>Shape</th><th>Version</th><th>Active</th></tr></thead>
                            <tbody>
                                <template x-for="r in filtered()" :key="r.id">
                                    <tr>
                                        <td>
                                            <p class="font-semibold text-[12.5px]" x-text="r.config_key"></p>
                                            <p class="text-[11px] text-muted-foreground" x-text="r.description || '—'"></p>
                                        </td>
                                        <td class="text-[12px]" x-text="r.section || '—'"></td>
                                        <td class="text-[12px]" x-text="r.plain_summary"></td>
                                        <td class="text-[11px] font-mono max-w-[260px] truncate" :title="r.preview" x-text="r.preview"></td>
                                        <td><span class="badge badge-soft text-[10px]" x-text="r.shape"></span></td>
                                        <td class="text-[11px] text-muted-foreground" x-text="r.version || '—'"></td>
                                        <td><span class="badge" :class="r.is_active ? 'badge-success' : 'badge-muted'" x-text="r.is_active ? 'active' : 'inactive'"></span></td>
                                    </tr>
                                </template>
                                <template x-if="filtered().length === 0">
                                    <tr><td colspan="7" class="text-center text-[12px] text-muted-foreground py-6">No rules match the current filters.</td></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div x-show="tab === 'top'" class="p-4">
                    @include('admin.clinical._interpretation_modal', [
                        'chartId' => 'clin-boosts.top-disease-boosts',
                        'title' => 'Diseases that get the largest scoring boost',
                        'how' => 'Each row is a (disease, boost-value) pair. Bar length represents the boost magnitude. Boosts are added on top of the standard symptom-and-exposure score.',
                        'informative' => 'Tier 1 high-consequence pathogens dominate the top of the list. Boost values cluster in well-defined bands.',
                        'concerning' => 'A boost so large that the disease would trigger HIGH action with weak signals; a Tier 3 disease near the top of the list.',
                        'whatToDo' => 'Raise with the clinical team. The boost values are stored in ref_engine_config.',
                        'cantTell' => 'It does not tell you which traveller profiles trigger the boost — that is run by the live engine on the mobile.',
                        'source' => 'ref_engine_config rows whose value is a disease-keyed numeric map.',
                    ])
                    <ol class="space-y-1 mt-3">
                        <template x-for="b in topBoosts" :key="b.disease_code + '|' + b.config_key">
                            <li class="flex items-center justify-between text-[12.5px]">
                                <span><span class="font-semibold" x-text="b.disease_name"></span><span class="text-[10px] text-muted-foreground ml-2" x-text="b.config_key"></span></span>
                                <span class="tabular-nums"><span class="font-mono mr-1" x-text="(b.boost > 0 ? '+' : '') + b.boost"></span><span class="text-[10px] text-muted-foreground" x-text="b.strength.label"></span></span>
                            </li>
                        </template>
                    </ol>
                </div>

                <div x-show="tab === 'caps'" class="p-4">
                    @include('admin.clinical._interpretation_modal', [
                        'chartId' => 'clin-boosts.score-caps',
                        'title' => 'Per-disease score cap',
                        'how' => 'Each row is a disease and the highest score it can deterministically reach. Computed from symptom + exposure + endemic + engine-boost upper bounds, clamped to 100.',
                        'informative' => 'Tier 1 diseases have caps that can comfortably cross the HIGH band (≥55). Tier 3 diseases often top out below the MEDIUM band.',
                        'concerning' => 'A Tier 1 disease whose cap is below 55 — it can never drive an immediate referral from score alone. A Tier 3 disease whose cap is in HIGH — may be over-weighted.',
                        'whatToDo' => 'Raise with the clinical team. Caps are computed; underlying weights live in ref_diseases.',
                        'cantTell' => 'It does not tell you the typical real-world score — only the worst-case maximum.',
                        'source' => 'Computed by the server-side simulator from ref_diseases JSON columns + the largest endemic bonus + the engine boost map.',
                    ])
                    <table class="table table-sm mt-3">
                        <thead><tr><th>Disease</th><th>Tier</th><th>Cap</th><th>Highest action band</th></tr></thead>
                        <tbody>
                            <template x-for="c in scoreCaps" :key="c.disease_code">
                                <tr>
                                    <td class="font-semibold text-[12.5px]" x-text="c.display_name"></td>
                                    <td><span class="badge" :class="c.tier.code === 1 ? 'badge-critical' : (c.tier.code === 2 ? 'badge-warning' : 'badge-muted')" x-text="c.tier.short"></span></td>
                                    <td class="tabular-nums" x-text="c.cap + ' / 100'"></td>
                                    <td><span class="badge" :class="c.action_band.badge" x-text="c.action_band.label"></span></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>

                <div x-show="tab === 'methodology'" class="p-4 text-[12.5px] leading-relaxed space-y-2">
                    <p>The scoring engine reads ref_engine_config rows and applies them on top of the standard symptom and exposure scoring. Different rows have different shapes:</p>
                    <ul class="list-disc pl-5 space-y-1">
                        <li><strong>disease_boost_map</strong> — adds a baseline boost to every listed disease.</li>
                        <li><strong>scalar</strong> — a single global tunable.</li>
                        <li><strong>object</strong> — a structured tunable; see the value preview for shape.</li>
                        <li><strong>string</strong> — a configuration label or selector.</li>
                    </ul>
                </div>
            </div>
        </section>
    </template>
</div>

@push('scripts')
<script>
    function clinBoosts() {
        return {
            ready:false, tab:'all',
            tabs:[
                {key:'all',         label:'All Boosts'},
                {key:'top',         label:'Top Disease Boosts'},
                {key:'caps',        label:'Score-Cap Reference'},
                {key:'methodology', label:'Methodology'},
            ],
            rows:[], topBoosts:[], scoreCaps:[], sectionsInUse:[],
            kpis:{total:0,active:0,distinct_disease_boosts:0,largest_boost:0},
            search:'', sectionFilter:'',
            async boot() {
                try {
                    const r = await fetch('{{ route('admin.clinical.boosts.data') }}', {headers:{Accept:'application/json'}});
                    const j = await r.json();
                    if (j.success) {
                        this.rows = j.data.rows; this.topBoosts = j.data.top_boosts;
                        this.scoreCaps = j.data.score_caps; this.sectionsInUse = j.data.sections_in_use;
                        this.kpis = j.data.kpis;
                    }
                } catch (e) { console.error(e); }
                this.ready = true;
            },
            filtered() {
                let r = this.rows;
                if (this.sectionFilter) r = r.filter(x => x.section === this.sectionFilter);
                if (this.search) {
                    const q = this.search.toLowerCase();
                    r = r.filter(x => (x.config_key||'').toLowerCase().includes(q) || (x.description||'').toLowerCase().includes(q));
                }
                return r;
            },
            exportCsv() {
                const headers = ['Rule','Section','Plain summary','Preview','Shape','Version','Active'];
                const rows = this.filtered().map(r => [r.config_key, r.section || '', r.plain_summary, r.preview, r.shape, r.version || '', r.is_active?'Yes':'No']);
                const csv = [headers, ...rows].map(row => row.map(v => '"' + String(v).replace(/"/g,'""') + '"').join(',')).join('\r\n');
                const blob = new Blob([csv], {type:'text/csv;charset=utf-8'});
                const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'clin-boosts.csv'; a.click(); URL.revokeObjectURL(a.href);
            },
        };
    }
</script>
@endpush
@endsection
