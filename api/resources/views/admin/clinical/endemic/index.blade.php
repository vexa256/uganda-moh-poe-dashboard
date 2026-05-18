{{-- Admin · Clinical Library · Endemic Map (clin-endemic) — read-only reference --}}
@extends('admin.layout')
@section('crumb', 'Clinical Library')
@section('title', $pageTitle)

@section('content')
<div x-data="clinEndemic()" x-init="boot()" class="space-y-5">

    <section class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Clinical Library · Reference browser (read-only)</p>
            <h2 class="display-md mt-1">Endemic Map</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                For every disease, which countries are flagged for it — and what that
                means for a traveller's score at the border.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready"><span class="status-dot status-dot-live"></span><span x-text="kpis.total + ' mappings'"></span></span>
            @include('admin.clinical._coach', ['sectionKey' => 'clin-endemic'])
            <button type="button" class="btn btn-ghost btn-sm" @click="exportCsv()">Export CSV</button>
        </div>
    </section>

    <template x-if="ready">
        <section class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="kpi"><p class="kpi-label">Total mappings</p><p class="kpi-value tabular-nums" x-text="kpis.total"></p><p class="text-[11px] text-muted-foreground mt-1">Country × disease pairs</p></div>
            <div class="kpi"><p class="kpi-label">Active outbreaks</p><p class="kpi-value tabular-nums text-critical" x-text="kpis.active_outbreaks"></p><p class="text-[11px] text-muted-foreground mt-1">Currently OUTBREAK_ACTIVE</p></div>
            <div class="kpi"><p class="kpi-label">Diseases</p><p class="kpi-value tabular-nums" x-text="kpis.distinct_diseases"></p><p class="text-[11px] text-muted-foreground mt-1">With at least one mapping</p></div>
            <div class="kpi"><p class="kpi-label">Countries</p><p class="kpi-value tabular-nums" x-text="kpis.distinct_countries"></p><p class="text-[11px] text-muted-foreground mt-1">With at least one mapping</p></div>
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

                <div x-show="tab === 'by_disease'" class="p-4">
                    @include('admin.clinical._interpretation_modal', [
                        'chartId' => 'clin-endemic.by-disease',
                        'title' => 'Diseases ranked by outbreak pressure',
                        'how' => 'Each row is a disease, sorted by the number of countries currently flagged as active outbreak or recent outbreak — the two levels that drive the largest endemic boost.',
                        'informative' => 'A handful of diseases dominate the top of the list, reflecting the ongoing outbreaks WHO is tracking. Most diseases sit lower with steady endemic mappings.',
                        'concerning' => 'A disease at the top of the list with no recent verification timestamps; a sudden jump in active-outbreak count without a documented source.',
                        'whatToDo' => 'Raise with the clinical team. Endemic mappings should follow WHO outbreak surveillance updates.',
                        'cantTell' => 'It does not tell you the absolute number of cases in each country — only the platform’s flag.',
                        'source' => 'ref_endemic_countries grouped by disease and endemicity level.',
                    ])
                    <div class="overflow-x-auto mt-3">
                        <table class="table table-sm">
                            <thead><tr><th>Disease</th><th>Tier</th><th class="text-right">Active</th><th class="text-right">Recent</th><th class="text-right">Endemic</th><th class="text-right">Sporadic</th></tr></thead>
                            <tbody>
                                <template x-for="r in byDisease" :key="r.disease_code">
                                    <tr>
                                        <td class="font-semibold text-[12.5px]" x-text="r.disease_name"></td>
                                        <td><span class="badge" :class="r.tier.code === 1 ? 'badge-critical' : (r.tier.code === 2 ? 'badge-warning' : 'badge-muted')" x-text="r.tier.short"></span></td>
                                        <td class="text-right tabular-nums text-critical" x-text="r.active"></td>
                                        <td class="text-right tabular-nums text-warning" x-text="r.recent"></td>
                                        <td class="text-right tabular-nums" x-text="r.endemic"></td>
                                        <td class="text-right tabular-nums text-muted-foreground" x-text="r.sporadic"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div x-show="tab === 'by_country'" class="p-4">
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead><tr><th>Country</th><th class="text-right">Total mappings</th><th class="text-right">Active outbreaks</th></tr></thead>
                            <tbody>
                                <template x-for="r in byCountry" :key="r.country_code">
                                    <tr>
                                        <td class="text-[12.5px]"><span class="font-semibold" x-text="r.country_name"></span><span class="text-[10px] text-muted-foreground ml-2" x-text="r.country_code"></span></td>
                                        <td class="text-right tabular-nums" x-text="r.count"></td>
                                        <td class="text-right tabular-nums text-critical" x-text="r.active"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div x-show="tab === 'active'" class="p-4">
                    <p class="text-[12px] text-muted-foreground mb-3">Country-disease pairs currently flagged as active outbreak — these drive the largest endemic boost on a traveller's score.</p>
                    <div class="flex flex-wrap gap-1">
                        <template x-for="o in activeOutbreaks" :key="o.id">
                            <span class="badge badge-critical" :title="o.level.consequence + ' · since ' + (o.since_year || 'unknown')">
                                <span x-text="o.country_name"></span>
                                <span class="ml-1" x-text="' · ' + o.disease_name"></span>
                            </span>
                        </template>
                        <template x-if="!activeOutbreaks || activeOutbreaks.length === 0">
                            <span class="text-[12px] text-muted-foreground italic">No active outbreaks currently flagged.</span>
                        </template>
                    </div>
                </div>

                <div x-show="tab === 'recent'" class="p-4">
                    <p class="text-[12px] text-muted-foreground mb-3">Mappings whose endemicity level was updated most recently.</p>
                    <ol class="space-y-1">
                        <template x-for="r in recent" :key="r.id">
                            <li class="text-[12.5px]"><span class="font-semibold" x-text="r.disease_name"></span><span class="text-muted-foreground" x-text="' · ' + r.country_name + ' · ' + r.level.label + ' · ' + r.updated_at"></span></li>
                        </template>
                        <template x-if="!recent || recent.length === 0">
                            <li class="text-[11px] text-muted-foreground">No recent updates recorded.</li>
                        </template>
                    </ol>
                </div>

                <div x-show="tab === 'methodology'" class="p-4 text-[12.5px] leading-relaxed space-y-2">
                    <p>Endemic mappings flag a country as a known source of a disease. A traveller arriving from a flagged country gets an additional score boost for that disease at the border.</p>
                    <p>Endemic boost values per level: active outbreak +15, recent outbreak +10, endemic +7, sporadic +3, imported-only +0.</p>
                </div>
            </div>
        </section>
    </template>
</div>

@push('scripts')
<script>
    function clinEndemic() {
        return {
            ready:false, tab:'by_disease',
            tabs:[
                {key:'by_disease',  label:'By Disease'},
                {key:'by_country',  label:'By Country'},
                {key:'active',      label:'Active Outbreaks'},
                {key:'recent',      label:'Recently Changed'},
                {key:'methodology', label:'Methodology'},
            ],
            rows:[], byDisease:[], byCountry:[], activeOutbreaks:[], recent:[],
            kpis:{total:0,active_outbreaks:0,distinct_diseases:0,distinct_countries:0,levels:{}},
            async boot() {
                try {
                    const r = await fetch('{{ route('admin.clinical.endemic.data') }}', {headers:{Accept:'application/json'}});
                    const j = await r.json();
                    if (j.success) {
                        this.rows = j.data.rows; this.byDisease = j.data.by_disease; this.byCountry = j.data.by_country;
                        this.activeOutbreaks = j.data.active_outbreaks; this.recent = j.data.recent; this.kpis = j.data.kpis;
                    }
                } catch (e) { console.error(e); }
                this.ready = true;
            },
            exportCsv() {
                const headers = ['Disease','Country','Endemicity','Since year','Source'];
                const rows = this.rows.map(r => [r.disease_name, r.country_name, r.level.label, r.since_year || '', r.source || '']);
                const csv = [headers, ...rows].map(row => row.map(v => '"' + String(v).replace(/"/g,'""') + '"').join(',')).join('\r\n');
                const blob = new Blob([csv], {type:'text/csv;charset=utf-8'});
                const a = document.createElement('a'); a.href = URL.createObjectURL(blob); a.download = 'clin-endemic.csv'; a.click(); URL.revokeObjectURL(a.href);
            },
        };
    }
</script>
@endpush
@endsection
