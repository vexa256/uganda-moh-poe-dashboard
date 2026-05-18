{{-- ============================================================================
  Admin · Geo · PoE Registry
  ----------------------------------------------------------------------------
  Premium · mobile-first · tabs · slim premium table · step-wizard create/edit.
  Pulls every dataset over JSON from the controller endpoints; never embeds
  data in the rendered HTML so refresh cycles are independent of page render.
  Theme primitives only (admin.partials.theme — no inline @apply).
============================================================================ --}}
@extends('admin.layout')

@section('crumb', 'PoEs')
@section('title', 'Points of Entry')

@section('content')
<div x-data="poeRegistry()" x-init="boot()" x-effect="window.adminLock.set('page', wizard?.open || sheet?.open || confirm?.open)" class="space-y-5">

    {{-- ── KPI strip ───────────────────────────────────────────────── --}}
    <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="kpi kpi-glow">
            <p class="kpi-label">Active</p>
            <p class="kpi-value tabular-nums" x-text="tabCounts.active ?? '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">live in the bundle</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Retired</p>
            <p class="kpi-value tabular-nums text-muted-foreground" x-text="tabCounts.retired ?? '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">restorable</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Bundle</p>
            <p class="kpi-value tabular-nums" x-text="bundleVersion ? 'v' + bundleVersion : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">bumps on save</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Scope</p>
            <p class="kpi-value">Uganda</p>
            <p class="text-[11px] text-muted-foreground mt-1">single tenant</p>
        </div>
    </section>

    {{-- ── Toolbar (tabs · search · new) ─────────────────────────────── --}}
    <section class="card">
        <div class="card-content !p-0">

            {{-- Tabs row --}}
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto">
                        <template x-for="t in [
                                {key:'active',  label:'Active'},
                                {key:'retired', label:'Retired'},
                                {key:'all',     label:'All'}
                            ]" :key="t.key">
                            <button type="button"
                                    class="tabs-trigger flex-1 sm:flex-none"
                                    :data-state="filters.status === t.key ? 'active' : 'inactive'"
                                    @click="setStatusTab(t.key)">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]"
                                      x-text="tabCounts[t.key] ?? 0"></span>
                            </button>
                        </template>
                    </div>

                    <div class="flex-1"></div>

                    <div class="relative w-full sm:w-72">
                        <svg class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 21l-4.35-4.35M11 19a8 8 0 110-16 8 8 0 010 16z"/></svg>
                        <input type="search"
                               class="input pl-8"
                               placeholder="Search name, code, district, border…"
                               x-model.debounce.300ms="filters.q"
                               @input="loadData()">
                    </div>

                    <button type="button" class="btn btn-brand btn-sm" @click="openCreate()">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14m-7-7h14"/></svg>
                        New PoE
                    </button>
                </div>

                {{-- Filter chips row --}}
                <div class="flex flex-wrap items-center gap-2 -mt-1">
                    <select class="select w-auto !h-8 text-xs" x-model="filters.province_id" @change="onProvinceFilter()">
                        <option value="0">All regions</option>
                        <template x-for="p in meta.provinces" :key="p.id">
                            <option :value="p.id" x-text="p.name"></option>
                        </template>
                    </select>
                    <select class="select w-auto !h-8 text-xs" x-model="filters.district_id" @change="loadData()" :disabled="districtsFiltered.length === 0">
                        <option value="0">All districts</option>
                        <template x-for="d in districtsFiltered" :key="d.id">
                            <option :value="d.id" x-text="d.name"></option>
                        </template>
                    </select>
                    <select class="select w-auto !h-8 text-xs" x-model="filters.poe_type" @change="loadData()">
                        <option value="">Any type</option>
                        <template x-for="t in meta.poe_types" :key="t">
                            <option :value="t" x-text="t"></option>
                        </template>
                    </select>
                    <select class="select w-auto !h-8 text-xs" x-model="filters.transport_mode" @change="loadData()">
                        <option value="">Any transport</option>
                        <template x-for="m in meta.transport_modes" :key="m">
                            <option :value="m" x-text="m"></option>
                        </template>
                    </select>
                    <select class="select w-auto !h-8 text-xs" x-model="filters.border_country" @change="loadData()">
                        <option value="">Any border</option>
                        <template x-for="b in meta.neighbours" :key="b">
                            <option :value="b" x-text="b"></option>
                        </template>
                    </select>
                    <button type="button" class="btn btn-ghost btn-xs"
                            x-show="hasActiveFilter()" @click="resetFilters()">
                        Clear filters
                    </button>
                </div>
            </div>

            {{-- ── Slim premium table ──────────────────────────────── --}}
            <div class="table-wrap !rounded-none !border-0 border-t-0">
                <table class="table">
                    <thead class="table-head">
                        <tr>
                            <th class="table-head-th">PoE</th>
                            <th class="table-head-th hidden md:table-cell">Type</th>
                            <th class="table-head-th hidden md:table-cell">Region / District</th>
                            <th class="table-head-th hidden lg:table-cell">Border</th>
                            <th class="table-head-th">Flags</th>
                            <th class="table-head-th text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="table-body">
                        <template x-if="loading">
                            <tr><td colspan="6" class="table-cell text-center py-12">
                                <div class="inline-flex items-center gap-2 text-muted-foreground text-sm">
                                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-18 0"/></svg>
                                    Loading PoEs…
                                </div>
                            </td></tr>
                        </template>
                        <template x-if="!loading && rows.length === 0">
                            <tr><td colspan="6" class="table-cell">
                                <div class="empty-state">
                                    <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10"/></svg>
                                    <p class="text-sm font-medium">No PoEs match these filters.</p>
                                    <button type="button" class="btn btn-outline btn-sm" @click="resetFilters()">Clear filters</button>
                                </div>
                            </td></tr>
                        </template>
                        <template x-for="row in rows" :key="row.id">
                            <tr class="table-row">
                                <td class="table-cell">
                                    <div class="flex flex-col min-w-0">
                                        <span class="text-[12.5px] font-semibold text-foreground truncate" x-text="row.poe_name"></span>
                                        <span class="text-[10.5px] font-mono text-muted-foreground" x-text="row.external_id"></span>
                                    </div>
                                </td>
                                <td class="table-cell hidden md:table-cell">
                                    <span class="badge badge-outline" x-text="row.poe_type"></span>
                                    <span class="text-[10.5px] text-muted-foreground ml-1" x-text="row.transport_mode"></span>
                                </td>
                                <td class="table-cell hidden md:table-cell">
                                    <div class="flex flex-col min-w-0">
                                        <span class="text-[12px] truncate" x-text="row.admin_level_1"></span>
                                        <span class="text-[10.5px] text-muted-foreground truncate" x-text="row.district"></span>
                                    </div>
                                </td>
                                <td class="table-cell hidden lg:table-cell">
                                    <span class="text-[12px]" x-text="row.border_country || '—'"></span>
                                </td>
                                <td class="table-cell">
                                    <div class="flex flex-wrap gap-1">
                                        <span x-show="row.is_major_entry"      class="badge badge-info" title="Major entry">M</span>
                                        <span x-show="row.is_recommended_osbp" class="badge badge-brand" title="OSBP">OSBP</span>
                                        <span x-show="row.is_national_level"   class="badge badge-warning" title="National level">NAT</span>
                                        <span x-show="row.is_retired"          class="badge badge-soon" title="Retired">retired</span>
                                    </div>
                                </td>
                                <td class="table-cell text-right">
                                    <div class="inline-flex gap-1 justify-end">
                                        <button type="button" class="btn btn-ghost btn-xs" @click="openDetail(row.id)" title="View">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </button>
                                        <button type="button" class="btn btn-ghost btn-xs" @click="openEdit(row.id)" title="Edit" x-show="!row.is_retired">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </button>
                                        <button type="button" class="btn btn-ghost btn-xs text-critical" @click="confirmRetire(row)" title="Retire" x-show="!row.is_retired">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                                        </button>
                                        <button type="button" class="btn btn-ghost btn-xs text-success" @click="restoreRow(row)" title="Restore" x-show="row.is_retired">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 12a9 9 0 1015.66-6.34L21 8M21 3v5h-5"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- Pagination footer --}}
            <div class="flex flex-col sm:flex-row items-center gap-2 px-4 sm:px-5 py-3 border-t text-[12px] text-muted-foreground">
                <span>
                    Showing <span class="font-semibold text-foreground" x-text="rows.length"></span>
                    of <span class="font-semibold text-foreground" x-text="total"></span> PoEs
                </span>
                <div class="flex-1"></div>
                <div class="inline-flex items-center gap-1">
                    <button type="button" class="btn btn-outline btn-xs" @click="prevPage()" :disabled="page <= 1">Prev</button>
                    <span class="px-2 tabular-nums">page <span x-text="page"></span></span>
                    <button type="button" class="btn btn-outline btn-xs" @click="nextPage()" :disabled="page * perPage >= total">Next</button>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Detail sheet (right) ───────────────────────────────────── --}}
    @include('admin.geo.poes._detail-sheet')

    {{-- ── Step wizard (modal) ────────────────────────────────────── --}}
    @include('admin.geo.poes._wizard')

    {{-- ── Confirm-retire dialog (bulletproof modal pattern) ───────── --}}
    <template x-if="confirm.open">
        <div class="fixed inset-0 z-[55] flex items-center justify-center p-4"
             role="dialog" aria-modal="true" aria-labelledby="confirm-retire-title"
             @keydown.escape.window="confirm.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="confirm.open=false"></div>
            <div class="relative w-full max-w-sm bg-card border rounded-xl shadow-elevation-5 p-5" @click.stop>
                <h3 id="confirm-retire-title" class="text-[14px] font-bold text-critical">Retire this PoE?</h3>
                <p class="text-[12.5px] text-muted-foreground leading-relaxed mt-1.5">
                    <span class="font-semibold text-foreground" x-text="confirm.row?.poe_name"></span>
                    will be soft-deleted. The mobile bundle drops it on next refresh; restore brings it back.
                </p>
                <div class="flex justify-end gap-2 mt-5">
                    <button type="button" class="btn btn-outline btn-sm" @click="confirm.open=false">Cancel</button>
                    <button type="button" class="btn btn-destructive btn-sm" @click="performRetire()" :disabled="confirm.busy">
                        <span x-show="!confirm.busy">Retire PoE</span>
                        <span x-show="confirm.busy">Retiring…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    {{-- ── Toast (success notifications) ───────────────────────────── --}}
    <div class="fixed inset-x-0 bottom-6 z-[60] flex justify-center px-3 pointer-events-none"
         x-show="opToast.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md"
             :class="opToast.kind === 'success' ? 'toast-success'
                   : opToast.kind === 'error'   ? 'toast-destructive'
                   :                              'toast-warning'">
            <div class="flex items-start gap-2">
                <svg class="h-4 w-4 shrink-0 mt-0.5"
                     :class="opToast.kind === 'success' ? 'text-success'
                           : opToast.kind === 'error'   ? 'text-white'
                           :                              'text-warning'"
                     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M5 13l4 4L19 7"/>
                </svg>
                <div class="min-w-0">
                    <p class="toast-title" x-text="opToast.title"></p>
                    <p class="toast-description" x-text="opToast.body"></p>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function poeRegistry () {
        // Closure-scoped helpers — must be declared BEFORE the returned
        // object literal because Alpine calls poeRegistry() with `this`
        // bound to the global, so `this.blankForm()` would throw.
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
        const headersJson = () => ({
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
        });
        const qs = (obj) => Object.entries(obj)
            .filter(([_, v]) => v !== '' && v !== null && v !== undefined && v !== 0 && v !== '0')
            .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v))
            .join('&');
        const blankForm = () => ({
            poe_name: '', province_id: '', district_id: '',
            poe_type: 'land_border', transport_mode: 'land',
            border_country: '',
            is_major_entry: false, is_recommended_osbp: false, is_national_level: false,
            critical_details: '',
            source_url: '', source_origin: '',
            poe_code: '', external_id: '',
            display_order: '', latitude: '', longitude: '',
            is_active: true,
        });

        return {
            // ── meta dropdowns ─────────────────────────────────────────
            meta: { provinces: [], districts: [], poe_types: [], transport_modes: [], neighbours: [], known_osbps: [], source_origins: {}, source_urls: {}, rules: {} },

            // ── filters / pagination ───────────────────────────────────
            filters: { country: 'Uganda', province_id: 0, district_id: 0, poe_type: '', transport_mode: '', border_country: '', q: '', status: 'active' },
            page: 1,
            perPage: 50,
            total: 0,
            rows: [],
            loading: false,
            tabCounts: { active: 0, retired: 0, all: 0 },
            bundleVersion: null,

            // ── detail sheet ───────────────────────────────────────────
            sheet: { open: false, busy: false, data: null },

            // ── wizard ─────────────────────────────────────────────────
            wizard: {
                open: false, mode: 'create', step: 1, totalSteps: 5,
                form: blankForm(),
                suggestions: null, dupes: [],
                submitting: false, errors: {},
                editingId: null,
            },

            // ── confirm dialog ─────────────────────────────────────────
            confirm: { open: false, row: null, busy: false },

            // ── toast ──────────────────────────────────────────────────
            opToast: { open: false, kind: 'success', title: '', body: '', t: null },

            // ────────────────────────────────────────────────────────────
            //  BOOT
            // ────────────────────────────────────────────────────────────
            async boot () {
                await Promise.all([this.loadMeta(), this.loadData()]);
            },

            // Expose blankForm so methods (openCreate, openEdit reset, …) can call it.
            blankForm,

            // ────────────────────────────────────────────────────────────
            //  COMPUTED
            // ────────────────────────────────────────────────────────────
            get districtsFiltered () {
                const pid = parseInt(this.filters.province_id || 0);
                if (!pid) { return this.meta.districts; }
                return (this.meta.districts || []).filter(d => d.province_id === pid);
            },
            get districtsForWizardForm () {
                const pid = parseInt(this.wizard.form.province_id || 0);
                if (!pid) { return []; }
                return (this.meta.districts || []).filter(d => d.province_id === pid);
            },

            hasActiveFilter () {
                return this.filters.province_id != 0 || this.filters.district_id != 0
                    || this.filters.poe_type !== '' || this.filters.transport_mode !== ''
                    || this.filters.border_country !== '' || this.filters.q !== '';
            },

            // ────────────────────────────────────────────────────────────
            //  DATA
            // ────────────────────────────────────────────────────────────
            async loadMeta () {
                const r = await fetch('/admin/geo/poes/meta?country=' + encodeURIComponent(this.filters.country));
                const j = await r.json();
                if (j.success) { this.meta = j.data; }
            },

            async loadData () {
                this.loading = true;
                const url = '/admin/geo/poes/data?' + qs({
                    country:        this.filters.country,
                    province_id:    this.filters.province_id,
                    district_id:    this.filters.district_id,
                    poe_type:       this.filters.poe_type,
                    transport_mode: this.filters.transport_mode,
                    border_country: this.filters.border_country,
                    q:              this.filters.q,
                    status:         this.filters.status,
                    page:           this.page,
                    per_page:       this.perPage,
                });
                try {
                    const r = await fetch(url);
                    const j = await r.json();
                    if (j.success) {
                        this.rows          = j.data.rows;
                        this.total         = j.data.total;
                        this.tabCounts     = j.meta.tabs;
                        this.bundleVersion = j.meta.version;
                        // Live page meta → topbar
                        Alpine.store('pageMeta').rows    = this.filters.status === 'active'  ? j.meta.tabs.active
                                                        : this.filters.status === 'retired' ? j.meta.tabs.retired
                                                        : j.meta.tabs.all;
                        Alpine.store('pageMeta').version = j.meta.version;
                        Alpine.store('pageMeta').kind    = 'poes';
                    }
                } finally {
                    this.loading = false;
                }
            },

            setStatusTab (key) { this.filters.status = key; this.page = 1; this.loadData(); },
            onProvinceFilter () { this.filters.district_id = 0; this.loadData(); },
            resetFilters () {
                this.filters = { country: 'Uganda', province_id: 0, district_id: 0, poe_type: '', transport_mode: '', border_country: '', q: '', status: this.filters.status };
                this.page = 1; this.loadData();
            },
            prevPage () { if (this.page > 1) { this.page--; this.loadData(); } },
            nextPage () { if (this.page * this.perPage < this.total) { this.page++; this.loadData(); } },

            // ────────────────────────────────────────────────────────────
            //  DETAIL SHEET
            // ────────────────────────────────────────────────────────────
            async openDetail (id) {
                this.sheet.open = true; this.sheet.busy = true; this.sheet.data = null;
                try {
                    const r = await fetch('/admin/geo/poes/' + id);
                    const j = await r.json();
                    if (j.success) { this.sheet.data = j.data; }
                } finally { this.sheet.busy = false; }
            },

            // ────────────────────────────────────────────────────────────
            //  WIZARD
            // ────────────────────────────────────────────────────────────
            openCreate () {
                this.wizard.open = true; this.wizard.mode = 'create';
                this.wizard.step = 1;    this.wizard.editingId = null;
                this.wizard.form = blankForm();
                this.wizard.suggestions = null; this.wizard.dupes = []; this.wizard.errors = {};
                this.wizard._userTouched = {};
            },

            async openEdit (id) {
                this.wizard.open = true; this.wizard.mode = 'edit';
                this.wizard.step = 1;    this.wizard.editingId = id;
                this.wizard.form = blankForm();
                this.wizard._userTouched = {};
                this.wizard.suggestions = null; this.wizard.dupes = []; this.wizard.errors = {};
                const r = await fetch('/admin/geo/poes/' + id);
                const j = await r.json();
                if (j.success) {
                    const d = j.data;
                    this.wizard.form = {
                        poe_name:           d.poe_name,
                        province_id:        d.province_id,
                        district_id:        d.district_id,
                        poe_type:           d.poe_type,
                        transport_mode:     d.transport_mode,
                        border_country:     d.border_country || '',
                        is_major_entry:     !!d.is_major_entry,
                        is_recommended_osbp:!!d.is_recommended_osbp,
                        is_national_level:  !!d.is_national_level,
                        critical_details:   (d.payload && d.payload.critical_details) || '',
                        source_url:         (d.payload && d.payload.source_url) || '',
                        source_origin:      (d.payload && d.payload.source_origin) || '',
                        poe_code:           d.poe_code,
                        external_id:        d.external_id,
                        display_order:      d.display_order,
                        latitude:           d.latitude || '',
                        longitude:          d.longitude || '',
                        is_active:          d.is_active,
                    };
                    await this.runSuggest();
                }
            },

            // Debounced auto-derive whenever the user changes name / province / district.
            _suggestT: null,
            scheduleSuggest () {
                clearTimeout(this._suggestT);
                this._suggestT = setTimeout(() => this.runSuggest(), 250);
            },

            async runSuggest () {
                const f = this.wizard.form;
                if (!f.poe_name) { this.wizard.suggestions = null; this.wizard.dupes = []; return; }
                const [sR, dR] = await Promise.all([
                    fetch('/admin/geo/poes/suggest', { method: 'POST', headers: headersJson(), body: JSON.stringify({
                        poe_name: f.poe_name, province_id: f.province_id || 0, district_id: f.district_id || 0,
                    })}),
                    fetch('/admin/geo/poes/dupe-check', { method: 'POST', headers: headersJson(), body: JSON.stringify({ poe_name: f.poe_name })}),
                ]);
                const sJ = await sR.json(); const dJ = await dR.json();
                if (sJ.success) {
                    this.wizard.suggestions = sJ.data.derived;
                    // Auto-apply derivations the user hasn't manually overridden.
                    if (!this.wizard._userTouched?.poe_type)        f.poe_type        = sJ.data.derived.poe_type;
                    if (!this.wizard._userTouched?.transport_mode)  f.transport_mode  = sJ.data.derived.transport_mode;
                    if (!this.wizard._userTouched?.border_country)  f.border_country  = sJ.data.derived.border_country || '';
                    if (!this.wizard._userTouched?.source_url)      f.source_url      = sJ.data.derived.source_url;
                    if (!this.wizard._userTouched?.source_origin)   f.source_origin   = sJ.data.derived.source_origin;
                    if (!this.wizard._userTouched?.critical_details && !f.critical_details) {
                        f.critical_details = sJ.data.derived.critical_details_template;
                    }
                }
                if (dJ.success) {
                    // Filter out the row we're editing from dupes.
                    this.wizard.dupes = (dJ.data.candidates || []).filter(c => c.id !== this.wizard.editingId);
                }
            },

            markTouched (field) {
                this.wizard._userTouched = this.wizard._userTouched || {};
                this.wizard._userTouched[field] = true;
            },

            onWizardProvinceChange () {
                this.wizard.form.district_id = '';
                this.runSuggest();
            },

            stepValid (step) {
                const f = this.wizard.form;
                if (step === 1) return f.poe_name && f.poe_name.trim().length >= 2;
                if (step === 2) return f.province_id && f.district_id && (f.poe_type !== 'land_border' || f.border_country);
                if (step === 3) return true;
                if (step === 4) return true;
                if (step === 5) return true;
                return false;
            },
            allValid () { return [1,2,3,4].every(s => this.stepValid(s)); },

            nextStep () { if (this.stepValid(this.wizard.step) && this.wizard.step < this.wizard.totalSteps) { this.wizard.step++; } },
            prevStep () { if (this.wizard.step > 1) { this.wizard.step--; } },

            async save () {
                if (!this.allValid()) { this.toast('error', 'Wizard incomplete', 'Fill the required fields in each step.'); return; }
                this.wizard.submitting = true; this.wizard.errors = {};
                const f = this.wizard.form;
                const body = {
                    poe_name: f.poe_name, province_id: parseInt(f.province_id), district_id: parseInt(f.district_id),
                    poe_type: f.poe_type, border_country: f.border_country || null,
                    is_major_entry: !!f.is_major_entry, is_recommended_osbp: !!f.is_recommended_osbp, is_national_level: !!f.is_national_level,
                    critical_details: f.critical_details || '',
                    source_url: f.source_url || '', source_origin: f.source_origin || '',
                    poe_code: f.poe_code || '', is_active: !!f.is_active,
                };
                if (f.latitude !== '')  body.latitude  = parseFloat(f.latitude);
                if (f.longitude !== '') body.longitude = parseFloat(f.longitude);
                if (f.display_order !== '') body.display_order = parseInt(f.display_order);
                if (this.wizard.mode === 'edit' && f.transport_mode) { body.transport_mode = f.transport_mode; }

                try {
                    const url = this.wizard.mode === 'edit'
                        ? '/admin/geo/poes/' + this.wizard.editingId
                        : '/admin/geo/poes';
                    const method = this.wizard.mode === 'edit' ? 'PATCH' : 'POST';
                    const r = await fetch(url, { method, headers: headersJson(), body: JSON.stringify(body) });
                    const j = await r.json();
                    if (!r.ok || !j.success) {
                        this.toast('error', 'Save failed', j.message + (j.error?.allowed ? ' · allowed: ' + j.error.allowed.join(', ') : ''));
                        this.wizard.submitting = false; return;
                    }
                    const v = (j.meta && j.meta.version) || this.bundleVersion;
                    this.toast('success', this.wizard.mode === 'edit' ? 'PoE updated' : 'PoE created',
                        'Bundle now v' + v + ' · mobile clients refresh on next sync.');
                    this.wizard.open = false;
                    await this.loadData();
                } catch (e) {
                    this.toast('error', 'Network error', e.message || String(e));
                } finally {
                    this.wizard.submitting = false;
                }
            },

            // ────────────────────────────────────────────────────────────
            //  RETIRE / RESTORE
            // ────────────────────────────────────────────────────────────
            confirmRetire (row) { this.confirm = { open: true, row, busy: false }; },
            async performRetire () {
                if (!this.confirm.row) return;
                this.confirm.busy = true;
                try {
                    const r = await fetch('/admin/geo/poes/' + this.confirm.row.id, { method: 'DELETE', headers: headersJson() });
                    const j = await r.json();
                    if (j.success) {
                        this.toast('success', 'PoE retired', 'Bundle now v' + (j.meta?.version ?? '?'));
                        this.confirm.open = false; await this.loadData();
                    } else {
                        this.toast('error', 'Retire failed', j.message);
                    }
                } finally { this.confirm.busy = false; }
            },
            async restoreRow (row) {
                const r = await fetch('/admin/geo/poes/' + row.id + '/restore', { method: 'POST', headers: headersJson() });
                const j = await r.json();
                if (j.success) {
                    this.toast('success', 'PoE restored', 'Bundle now v' + (j.meta?.version ?? '?'));
                    await this.loadData();
                } else {
                    this.toast('error', 'Restore failed', j.message);
                }
            },

            // ────────────────────────────────────────────────────────────
            //  TOAST
            // ────────────────────────────────────────────────────────────
            toast (kind, title, body) {
                this.opToast = { open: true, kind, title, body, t: null };
                clearTimeout(this.opToast.t);
                this.opToast.t = setTimeout(() => { this.opToast.open = false; }, 3200);
            },
        };
    }
</script>
@endpush
