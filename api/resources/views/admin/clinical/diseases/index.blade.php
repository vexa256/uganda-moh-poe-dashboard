{{-- Admin · Clinical Library · Diseases (clin-diseases) — read-only reference console --}}
@extends('admin.layout')
@section('crumb', 'Clinical Library')
@section('title', $pageTitle)

@section('content')
<div x-data="clinDiseases()" x-init="boot()" class="space-y-5">

    {{-- ── Header strip ────────────────────────────────────────────────── --}}
    <section class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <p class="eyebrow">Clinical Library · Reference browser (read-only)</p>
            <h2 class="display-md mt-1">Diseases</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-2xl">
                Every disease the platform watches for. The list, weights, and tiers
                are discovered live from the reference data — nothing on this page is
                hardcoded.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready">
                <span class="status-dot status-dot-live"></span>
                <span x-text="kpis.total + ' diseases on file'"></span>
            </span>
            @include('admin.clinical._coach', ['sectionKey' => 'clin-diseases'])
            <button type="button" class="btn btn-ghost btn-sm" @click="exportCsv()">Export CSV</button>
        </div>
    </section>

    {{-- ── KPI strip — discovered live ─────────────────────────────────── --}}
    <template x-if="ready">
        <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div class="kpi"><p class="kpi-label">Total diseases</p><p class="kpi-value tabular-nums" x-text="kpis.total"></p><p class="text-[11px] text-muted-foreground mt-1"><span x-text="kpis.active"></span> active</p></div>
            <div class="kpi"><p class="kpi-label">Tier 1</p><p class="kpi-value tabular-nums text-critical" x-text="(kpis.tiers['Tier 1'] || 0)"></p><p class="text-[11px] text-muted-foreground mt-1">Always notifiable</p></div>
            <div class="kpi"><p class="kpi-label">Tier 2</p><p class="kpi-value tabular-nums text-warning" x-text="(kpis.tiers['Tier 2'] || 0)"></p><p class="text-[11px] text-muted-foreground mt-1">Annex 2 conditional</p></div>
            <div class="kpi"><p class="kpi-label">Tier 3</p><p class="kpi-value tabular-nums" x-text="(kpis.tiers['Tier 3'] || 0)"></p><p class="text-[11px] text-muted-foreground mt-1">National surveillance</p></div>
            <div class="kpi"><p class="kpi-label">With hallmark</p><p class="kpi-value tabular-nums" x-text="kpis.with_hallmark"></p><p class="text-[11px] text-muted-foreground mt-1">Required signal</p></div>
            <div class="kpi"><p class="kpi-label">Highest score cap</p><p class="kpi-value tabular-nums" x-text="kpis.max_score_cap"></p><p class="text-[11px] text-muted-foreground mt-1">Out of 100</p></div>
        </section>
    </template>

    {{-- ── Tabs ────────────────────────────────────────────────────────── --}}
    <template x-if="ready">
        <section class="card">
            <div class="card-content !p-0">
                <nav class="flex flex-wrap gap-0 border-b" role="tablist" aria-label="Disease tabs">
                    <template x-for="t in tabs" :key="t.key">
                        <button type="button"
                                :id="'tab-' + t.key"
                                class="px-4 py-3 text-[12.5px] font-medium border-b-2 transition-colors"
                                :class="tab === t.key ? 'border-brand text-brand' : 'border-transparent text-muted-foreground hover:text-foreground'"
                                :aria-selected="tab === t.key ? 'true' : 'false'"
                                role="tab"
                                @click="tab = t.key">
                            <span x-text="t.label"></span>
                            <span class="ml-1 text-[10px] text-muted-foreground" x-text="'(' + countFor(t.key) + ')'"></span>
                        </button>
                    </template>
                </nav>

                {{-- ── PANEL: All Diseases ─────────────────────────────── --}}
                <div x-show="tab === 'all'" role="tabpanel" class="p-4">
                    <div class="mb-3 flex flex-wrap items-center gap-2">
                        <input type="search" class="input input-sm w-64"
                               placeholder="Search disease, code, syndrome…"
                               x-model.debounce.250ms="search">
                        <select class="select select-sm" x-model="tierFilter">
                            <option value="">All tiers</option>
                            <template x-for="t in tiersInUse" :key="t">
                                <option :value="t" x-text="'Tier ' + t"></option>
                            </template>
                        </select>
                        <label class="flex items-center gap-1.5 text-[12px]">
                            <input type="checkbox" x-model="hallmarkOnly"> Has hallmark
                        </label>
                        <label class="flex items-center gap-1.5 text-[12px]">
                            <input type="checkbox" x-model="activeOnly" checked> Active only
                        </label>
                        <span class="ml-auto text-[11px] text-muted-foreground">
                            Showing <span x-text="filtered().length"></span> of <span x-text="rows.length"></span>
                        </span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Disease</th>
                                    <th>Tier</th>
                                    <th>Syndrome</th>
                                    <th>Hallmark</th>
                                    <th>Score cap</th>
                                    <th>Endemic countries</th>
                                    <th class="text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template x-for="r in filtered()" :key="r.id">
                                    <tr>
                                        <td>
                                            <button type="button" class="text-left hover:underline"
                                                    @click="openDossier(r.id)">
                                                <span class="font-semibold" x-text="r.display_name"></span>
                                            </button>
                                            <p class="text-[11px] text-muted-foreground" x-text="r.disease_code"></p>
                                        </td>
                                        <td>
                                            <span class="badge"
                                                  :class="r.tier_badge_class"
                                                  :title="r.tier.consequence"
                                                  x-text="r.tier.short"></span>
                                        </td>
                                        <td class="text-[12px]" x-text="r.syndrome.label"></td>
                                        <td>
                                            <template x-if="r.hallmark_required && r.hallmark_required.required.length">
                                                <span class="badge badge-info"
                                                      :title="'Required signals: ' + r.hallmark_required.required.map(x => x.display_name).join(', ')">
                                                    Required
                                                </span>
                                            </template>
                                            <template x-if="!r.hallmark_required || !r.hallmark_required.required.length">
                                                <span class="text-[11px] text-muted-foreground">No hallmark</span>
                                            </template>
                                        </td>
                                        <td class="tabular-nums" x-text="r.score_cap_estimated + ' / 100'"></td>
                                        <td class="tabular-nums" x-text="r.endemic_country_count"></td>
                                        <td class="text-right">
                                            <button class="btn btn-ghost btn-xs"
                                                    @click="openDossier(r.id)">Open dossier</button>
                                        </td>
                                    </tr>
                                </template>
                                <template x-if="filtered().length === 0">
                                    <tr><td colspan="7" class="text-center text-[12px] text-muted-foreground py-6">No diseases match the current filters.</td></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- ── PANEL: By Tier ──────────────────────────────────── --}}
                <div x-show="tab === 'by_tier'" role="tabpanel" class="p-4">
                    <div class="mb-3 flex items-center gap-2">
                        @include('admin.clinical._interpretation_modal', [
                            'chartId'    => 'clin-diseases.tier-distribution',
                            'title'      => 'How many diseases sit in each tier',
                            'how'        => 'Each row is a tier. The number is the count of diseases the platform tracks at that tier today, discovered live from the reference table.',
                            'informative'=> 'A roughly stable distribution across tiers — Tier 1 should be small (always-notifiable diseases are rare), Tier 2 the largest (most diseases qualify on threshold), Tier 3 a tail.',
                            'concerning' => 'A sudden swing — a disease that moved between tiers — or a disease the team expected to see in Tier 1 absent from the count.',
                            'whatToDo'   => 'Raise it with the clinical team via the change-request channel. This view cannot edit the tier.',
                            'cantTell'   => 'It does not tell you which specific diseases moved or why; click any tier to drill into the disease list.',
                            'source'     => 'ref_diseases.ihr_tier — the integer tier column on each disease row.',
                        ])
                    </div>
                    <template x-for="t in tiersInUse" :key="t">
                        <div class="mb-4">
                            <h3 class="text-[13px] font-semibold mb-1">
                                <span x-text="'Tier ' + t"></span>
                                <span class="ml-1 text-[11px] text-muted-foreground" x-text="'(' + (kpis.tiers['Tier '+t] || 0) + ' diseases)'"></span>
                            </h3>
                            <p class="text-[11.5px] text-muted-foreground mb-2"
                               x-text="tierConsequence(t)"></p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-2">
                                <template x-for="r in rows.filter(x => x.tier_int === t)" :key="r.id">
                                    <button type="button"
                                            class="text-left rounded-md border border-border bg-background px-3 py-2 hover:bg-accent"
                                            @click="openDossier(r.id)">
                                        <p class="font-semibold text-[13px]" x-text="r.display_name"></p>
                                        <p class="text-[11px] text-muted-foreground" x-text="r.syndrome.label + ' · cap ' + r.score_cap_estimated"></p>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- ── PANEL: By Action Band ───────────────────────────── --}}
                <div x-show="tab === 'by_band'" role="tabpanel" class="p-4">
                    <div class="mb-3 flex items-center gap-2">
                        @include('admin.clinical._interpretation_modal', [
                            'chartId'    => 'clin-diseases.action-band',
                            'title'      => 'How many diseases can drive each action at the border',
                            'how'        => 'Each band is the highest action a disease can drive based on its estimated maximum score. A disease in HIGH can drive an immediate referral; a disease in NONE will never trigger any action from score alone.',
                            'informative'=> 'Most Tier 1 diseases sit in HIGH or MEDIUM. Tier 3 diseases concentrate in LOW or NONE. The action band tracks tier loosely but is a more honest signal of operational impact.',
                            'concerning' => 'A Tier 1 disease in NONE — that means the platform would never flag it from score alone. Or a Tier 3 disease in HIGH — that may be over-weighted relative to its clinical priority.',
                            'whatToDo'   => 'Raise with the clinical team. The score cap is computed from symptom and exposure weights and the largest endemic boost; if the band feels wrong, the underlying weights may need review.',
                            'cantTell'   => 'It does not tell you the actual score a real traveller would get — only the maximum the disease can deterministically reach.',
                            'source'     => 'Estimated by the server-side simulator from ref_diseases.symptom_weights + .exposure_weights + max endemic bonus + engine boosts.',
                        ])
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                        <template x-for="band in ['HIGH','MEDIUM','LOW','NONE']" :key="band">
                            <div class="card">
                                <div class="card-content">
                                    <div class="flex items-center justify-between mb-2">
                                        <h3 class="text-[12px] font-semibold uppercase" x-text="band"></h3>
                                        <span class="badge badge-soft tabular-nums" x-text="byActionBand[band] || 0"></span>
                                    </div>
                                    <ul class="space-y-1">
                                        <template x-for="r in rows.filter(x => actionBandKey(x.score_cap_estimated) === band).slice(0, 8)" :key="r.id">
                                            <li class="text-[12px]">
                                                <button type="button" class="hover:underline" @click="openDossier(r.id)" x-text="r.display_name"></button>
                                                <span class="text-muted-foreground" x-text="' · cap ' + r.score_cap_estimated"></span>
                                            </li>
                                        </template>
                                    </ul>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- ── PANEL: Worked Examples ──────────────────────────── --}}
                <div x-show="tab === 'worked'" role="tabpanel" class="p-4">
                    <p class="text-[12px] text-muted-foreground mb-3">
                        <strong>Worked examples are simulations.</strong> The result is computed from the
                        same reference data the mobile reads, but the live engine is on the device — this
                        page does not call it.
                    </p>
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div class="card">
                            <div class="card-content space-y-2">
                                <h3 class="text-[13px] font-semibold">Set up the simulation</h3>
                                <label class="text-[11px] block">Disease
                                    <select class="select select-sm w-full" x-model="sim.disease_id" @change="runSimulation()">
                                        <option value="">— pick a disease —</option>
                                        <template x-for="r in rows" :key="r.id">
                                            <option :value="r.id" x-text="r.display_name"></option>
                                        </template>
                                    </select>
                                </label>
                                <label class="text-[11px] block">Arrival country (ISO code)
                                    <input class="input input-sm w-full" maxlength="3" x-model="sim.country" @change="runSimulation()" placeholder="e.g. ZM, UG, CD">
                                </label>
                                <label class="text-[11px] block">Present symptoms (comma-separated codes)
                                    <input class="input input-sm w-full" x-model="sim.present" @change="runSimulation()" placeholder="fever, high_fever, rash_vesicular_pustular">
                                </label>
                                <label class="text-[11px] block">Reported exposures (comma-separated)
                                    <input class="input input-sm w-full" x-model="sim.exposures" @change="runSimulation()" placeholder="close_contact_case">
                                </label>
                            </div>
                        </div>
                        <div class="card">
                            <div class="card-content">
                                <h3 class="text-[13px] font-semibold">Simulation result</h3>
                                <template x-if="sim.result">
                                    <div class="space-y-2">
                                        <p class="text-[12px] text-muted-foreground" x-text="sim.result.engine_note"></p>
                                        <div class="flex items-baseline gap-2">
                                            <span class="text-2xl font-bold tabular-nums" x-text="sim.result.final_score"></span>
                                            <span class="text-[11px] text-muted-foreground"> / 100</span>
                                            <span class="badge ml-2" :class="sim.result.action_band.badge" x-text="sim.result.action_band.label"></span>
                                        </div>
                                        <p class="text-[12px]" x-text="sim.result.plain_summary"></p>
                                        <details class="text-[11.5px]">
                                            <summary class="cursor-pointer text-brand">Show breakdown</summary>
                                            <ul class="mt-1 space-y-0.5">
                                                <template x-for="(v, k) in sim.result.breakdown" :key="k">
                                                    <li class="tabular-nums"><span class="text-muted-foreground" x-text="k + ': '"></span><span x-text="v"></span></li>
                                                </template>
                                            </ul>
                                            <p class="mt-2 text-muted-foreground">Components NOT modelled in this simulator:</p>
                                            <ul class="mt-1 list-disc pl-5">
                                                <template x-for="(v, k) in (sim.result.omitted_components || {})" :key="k">
                                                    <li><span class="font-semibold" x-text="k + ': '"></span><span x-text="v"></span></li>
                                                </template>
                                            </ul>
                                        </details>
                                    </div>
                                </template>
                                <template x-if="!sim.result">
                                    <p class="text-[11px] text-muted-foreground">Pick a disease above to run a simulation.</p>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- ── PANEL: Recently Updated ─────────────────────────── --}}
                <div x-show="tab === 'recent'" role="tabpanel" class="p-4">
                    <p class="text-[12px] text-muted-foreground mb-3">Diseases whose reference rows have changed most recently.</p>
                    <ol class="space-y-1 list-decimal pl-5">
                        <template x-for="r in recent" :key="r.id">
                            <li>
                                <button class="hover:underline text-[13px]" @click="openDossier(r.id)" x-text="r.display_name"></button>
                                <span class="text-[11px] text-muted-foreground" x-text="' · updated ' + r.updated_at"></span>
                            </li>
                        </template>
                        <template x-if="!recent || recent.length === 0">
                            <li class="text-[11px] text-muted-foreground">No diseases have a recorded update timestamp.</li>
                        </template>
                    </ol>
                </div>

                {{-- ── PANEL: Methodology ──────────────────────────────── --}}
                <div x-show="tab === 'methodology'" role="tabpanel" class="p-4 text-[12.5px] leading-relaxed space-y-3">
                    <p>The scoring engine reads each disease’s reference row and computes a per-traveller score from the symptoms the officer recorded, the exposures they reported, and the country they arrived from. The score determines what action is taken at the point of entry.</p>
                    <p>The five additive components — symptom weights, exposure weights, the absent-hallmark penalty, the endemic-country bonus, and the engine boost map — are all surfaced on every disease dossier.</p>
                    <details>
                        <summary class="cursor-pointer text-brand">Show technical detail</summary>
                        <pre class="mt-2 rounded bg-muted/30 p-2 text-[11px] overflow-x-auto whitespace-pre-wrap">final_score = symptom_score + exposure_score + outbreak_bonus + absent_hallmark_penalty + override_boost  (clamped 0..100)

Action bands: ≥55 HIGH (immediate referral) · ≥35 MEDIUM (secondary screening) · ≥15 LOW (watchful) · &lt;15 NONE</pre>
                    </details>
                </div>
            </div>
        </section>
    </template>

    {{-- ── Disease dossier sheet ──────────────────────────────────────── --}}
    <div x-cloak x-show="dossier.open"
         x-transition.opacity
         class="fixed inset-0 z-[60] bg-black/40"
         @click="closeDossier()" aria-hidden="true"></div>
    <aside x-cloak x-show="dossier.open"
           x-transition:enter="transition transform duration-150"
           x-transition:enter-start="translate-x-full"
           x-transition:enter-end="translate-x-0"
           class="fixed inset-y-0 right-0 z-[61] w-full max-w-[640px] bg-background border-l border-border shadow-2xl flex flex-col"
           role="dialog" aria-modal="true">
        <header class="border-b border-border p-4 flex items-start justify-between">
            <div>
                <p class="eyebrow">Disease dossier</p>
                <h3 class="text-[16px] font-semibold mt-0.5" x-text="dossier.data?.display_name || '—'"></h3>
                <p class="text-[11px] text-muted-foreground mt-0.5" x-text="dossier.data?.disease_code"></p>
            </div>
            <button class="btn btn-ghost btn-sm" @click="closeDossier()">Close</button>
        </header>
        <div class="flex-1 overflow-y-auto p-4 text-[12.5px] leading-relaxed">
            <template x-if="dossier.data">
                <div class="space-y-4">
                    <section>
                        <p>
                            <span class="badge" :class="dossier.data.tier ? translatorBadge(dossier.data.tier.code) : 'badge-soft'" x-text="dossier.data.tier?.short"></span>
                            <span class="ml-2 text-[11px] text-muted-foreground" x-text="dossier.data.syndrome?.label"></span>
                        </p>
                        <p class="mt-1 text-[12px] text-muted-foreground" x-text="dossier.data.tier?.consequence"></p>
                    </section>

                    <section>
                        <h4 class="text-[12px] font-semibold mb-1">Hallmark requirement</h4>
                        <p class="text-[12px]" x-text="dossier.data.hallmark?.plain"></p>
                        <template x-if="dossier.data.hallmark && dossier.data.hallmark.required.length">
                            <ul class="mt-1 list-disc pl-5">
                                <template x-for="h in dossier.data.hallmark.required" :key="h.code">
                                    <li><span class="font-semibold" x-text="h.display_name"></span></li>
                                </template>
                            </ul>
                        </template>
                    </section>

                    <section>
                        <h4 class="text-[12px] font-semibold mb-1">Symptom weights</h4>
                        <ul class="space-y-0.5">
                            <template x-for="w in (dossier.data.symptom_weights || []).slice(0, 12)" :key="w.code">
                                <li class="flex items-center justify-between text-[12px]">
                                    <span x-text="w.display_name"></span>
                                    <span class="tabular-nums">
                                        <span class="font-mono mr-1" x-text="(w.weight > 0 ? '+' : '') + w.weight"></span>
                                        <span class="text-[10px] text-muted-foreground" x-text="w.strength.label"></span>
                                    </span>
                                </li>
                            </template>
                        </ul>
                    </section>

                    <section>
                        <h4 class="text-[12px] font-semibold mb-1">Exposure weights</h4>
                        <ul class="space-y-0.5">
                            <template x-for="w in (dossier.data.exposure_weights || []).slice(0, 8)" :key="w.code">
                                <li class="flex items-center justify-between text-[12px]">
                                    <span x-text="w.display_name"></span>
                                    <span class="tabular-nums">
                                        <span class="font-mono mr-1" x-text="(w.weight > 0 ? '+' : '') + w.weight"></span>
                                        <span class="text-[10px] text-muted-foreground" x-text="w.strength.label"></span>
                                    </span>
                                </li>
                            </template>
                        </ul>
                    </section>

                    <section>
                        <h4 class="text-[12px] font-semibold mb-1">Endemic countries
                            <span class="ml-1 text-[11px] text-muted-foreground" x-text="'(' + (dossier.data.endemic_countries?.length || 0) + ')'"></span>
                        </h4>
                        <div class="flex flex-wrap gap-1">
                            <template x-for="e in (dossier.data.endemic_countries || []).slice(0, 30)" :key="e.country_code">
                                <span class="badge badge-soft" :title="e.level.consequence">
                                    <span x-text="e.country_name"></span>
                                    <span class="ml-1 text-[10px]" x-text="e.level.label"></span>
                                </span>
                            </template>
                        </div>
                    </section>

                    <section>
                        <h4 class="text-[12px] font-semibold mb-1">Score cap (estimated)</h4>
                        <p class="text-[12px]"><span class="tabular-nums font-bold" x-text="dossier.data.score_cap_estimated"></span> / 100 — the highest score this disease can deterministically reach.</p>
                    </section>
                </div>
            </template>
        </div>
    </aside>
</div>

@push('scripts')
<script>
    function clinDiseases() {
        return {
            ready: false,
            tab: 'all',
            tabs: [
                { key: 'all',          label: 'All Diseases' },
                { key: 'by_tier',      label: 'By Tier' },
                { key: 'by_band',      label: 'By Action Band' },
                { key: 'worked',       label: 'Worked Examples' },
                { key: 'recent',       label: 'Recently Updated' },
                { key: 'methodology',  label: 'Methodology' },
            ],
            rows: [],
            kpis: { total: 0, active: 0, tiers: {}, syndromes: {}, with_hallmark: 0, max_score_cap: 0, min_score_cap: 0 },
            byActionBand: {},
            recent: [],
            tiersInUse: [],
            search: '',
            tierFilter: '',
            hallmarkOnly: false,
            activeOnly: true,
            dossier: { open: false, id: null, data: null },
            sim: { disease_id: '', country: '', present: '', exposures: '', result: null },

            async boot() {
                try {
                    const r = await fetch('{{ route('admin.clinical.diseases.data') }}', { headers: { 'Accept': 'application/json' } });
                    const j = await r.json();
                    if (j.success) {
                        this.rows         = j.data.rows;
                        this.kpis         = j.data.kpis;
                        this.byActionBand = j.data.by_action_band || {};
                        this.recent       = j.data.recent;
                        this.tiersInUse   = j.data.tiers_in_use;
                    }
                } catch (e) { console.error('clinDiseases boot', e); }
                this.ready = true;
            },

            countFor(key) {
                if (key === 'all') return this.rows.length;
                if (key === 'by_tier') return this.tiersInUse.length;
                if (key === 'by_band') return Object.values(this.byActionBand).reduce((a,b)=>a+b, 0);
                if (key === 'worked') return '';
                if (key === 'recent') return (this.recent || []).length;
                return '';
            },

            filtered() {
                let r = this.rows;
                if (this.activeOnly)   r = r.filter(x => x.is_active);
                if (this.tierFilter)   r = r.filter(x => x.tier_int === parseInt(this.tierFilter, 10));
                if (this.hallmarkOnly) r = r.filter(x => x.hallmark_required && x.hallmark_required.required.length);
                if (this.search) {
                    const q = this.search.toLowerCase();
                    r = r.filter(x =>
                        (x.display_name || '').toLowerCase().includes(q) ||
                        (x.disease_code || '').toLowerCase().includes(q) ||
                        ((x.syndrome && x.syndrome.label) || '').toLowerCase().includes(q)
                    );
                }
                return r;
            },

            actionBandKey(score) {
                if (score >= 55) return 'HIGH';
                if (score >= 35) return 'MEDIUM';
                if (score >= 15) return 'LOW';
                return 'NONE';
            },

            tierConsequence(t) {
                const m = {
                    1: 'Always reportable to WHO. Confirmed cases trigger immediate national notification.',
                    2: 'Reportable when WHO Annex 2 algorithm thresholds are met. Higher-than-baseline scores trigger national notification.',
                    3: 'Tracked nationally for surveillance. Cases feed the situational picture but do not by themselves trigger WHO notification.',
                };
                return m[t] || '';
            },

            translatorBadge(tier) {
                if (tier === 1) return 'badge-critical';
                if (tier === 2) return 'badge-warning';
                if (tier === 3) return 'badge-muted';
                return 'badge-soft';
            },

            async openDossier(id) {
                this.dossier.open = true;
                this.dossier.id = id;
                this.dossier.data = null;
                try {
                    const r = await fetch('{{ url('admin/clinical/diseases') }}/' + id, { headers: { 'Accept': 'application/json' } });
                    const j = await r.json();
                    if (j.success) this.dossier.data = j.data;
                } catch (e) { console.error('openDossier', e); }
            },

            closeDossier() { this.dossier.open = false; this.dossier.data = null; },

            async runSimulation() {
                if (! this.sim.disease_id) { this.sim.result = null; return; }
                const params = new URLSearchParams();
                this.sim.present.split(',').map(s => s.trim()).filter(Boolean).forEach(s => params.append('present[]', s));
                this.sim.exposures.split(',').map(s => s.trim()).filter(Boolean).forEach(s => params.append('exposures[]', s));
                if (this.sim.country) params.append('arrival_country', this.sim.country.toUpperCase());
                try {
                    const r = await fetch('{{ url('admin/clinical/diseases') }}/' + this.sim.disease_id + '/worked-example?' + params.toString(),
                                          { headers: { 'Accept': 'application/json' } });
                    const j = await r.json();
                    if (j.success) this.sim.result = j.data;
                } catch (e) { console.error('runSimulation', e); }
            },

            exportCsv() {
                const headers = ['Disease','Code','Tier','Syndrome','Hallmark','Score cap','Endemic countries','Active'];
                const rows = this.filtered().map(r => [
                    r.display_name, r.disease_code, 'Tier ' + r.tier_int, r.syndrome.label,
                    (r.hallmark_required && r.hallmark_required.required.length) ? 'Required' : 'No',
                    r.score_cap_estimated, r.endemic_country_count, r.is_active ? 'Yes' : 'No',
                ]);
                const csv = [headers, ...rows].map(row => row.map(v => '"' + String(v).replace(/"/g,'""') + '"').join(',')).join('\r\n');
                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'clin-diseases.csv';
                a.click();
                URL.revokeObjectURL(a.href);
            },
        };
    }
</script>
@endpush
@endsection
