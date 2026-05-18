@extends('admin.layout')

@section('crumb', 'Aggregated Reports')
@section('title', 'Submissions Intelligence')

@php
    /** @var array $scope */
    /** @var array $meta  */
@endphp

@section('content')
<div x-data="idsrIntel()"
     x-init="boot()"
     x-effect="window.adminLock.set('idsr-intel', detail.open)"
     class="space-y-5">

    {{-- ── National KPI strip — premium snapshot ────────────────── --}}
    <section class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
        <div class="kpi kpi-glow"><p class="kpi-label">Screened</p><p class="kpi-value tabular-nums" x-text="formatNum(rollups.national?.screened)"></p><p class="text-[11px] text-muted-foreground mt-1" x-text="(rollups.national?.poes_reporting ?? 0) + ' POEs reporting'"></p></div>
        <div class="kpi"><p class="kpi-label">Symptomatic</p><p class="kpi-value tabular-nums text-warning" x-text="formatNum(rollups.national?.symptomatic)"></p><p class="text-[11px] text-muted-foreground mt-1" x-text="(rollups.national?.symptomatic_pct ?? 0) + '% of screened'"></p></div>
        <div class="kpi"><p class="kpi-label">Submissions</p><p class="kpi-value tabular-nums" x-text="formatNum(rollups.national?.submissions)"></p><p class="text-[11px] text-muted-foreground mt-1" x-text="(rollups.national?.templates_used ?? 0) + ' templates used'"></p></div>
        <div class="kpi"><p class="kpi-label">Districts</p><p class="kpi-value tabular-nums" x-text="rollups.national?.districts_reporting ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">reporting</p></div>
        <div class="kpi"><p class="kpi-label">Male</p><p class="kpi-value tabular-nums" x-text="formatNum(rollups.national?.male)"></p><p class="text-[11px] text-muted-foreground mt-1">of screened</p></div>
        <div class="kpi"><p class="kpi-label">Female</p><p class="kpi-value tabular-nums" x-text="formatNum(rollups.national?.female)"></p><p class="text-[11px] text-muted-foreground mt-1">of screened</p></div>
    </section>

    {{-- ── Tabbed console ───────────────────────────────────────── --}}
    <section class="card">
        <div class="card-content !p-0">

            {{-- Tab switcher + shared filters --}}
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto">
                        <template x-for="t in tabs" :key="t.key">
                            <button class="tabs-trigger flex-1 sm:flex-none" :data-state="tab === t.key ? 'active' : 'inactive'" @click="switchTab(t.key)">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-show="t.key === 'browse'" x-text="pagination.total ?? 0"></span>
                                <span class="badge badge-warning ml-1 px-1.5 py-0 text-[9.5px]" x-show="t.key === 'late' && lateReporters.missing?.length" x-text="lateReporters.missing.length"></span>
                            </button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <button class="btn btn-outline btn-sm" @click="filtersOpen = !filtersOpen" :class="filtersOpen ? 'btn-soft-brand' : ''">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/></svg>
                        Filters <span class="badge badge-outline ml-1 text-[9.5px]" x-show="activeFilterCount > 0" x-text="activeFilterCount"></span>
                    </button>
                </div>

                {{-- Progressive filters (collapsed until user opens) --}}
                <div x-show="filtersOpen" x-collapse>
                    <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-2">
                        <div>
                            <label class="help-text">From</label>
                            <input type="date" class="input !h-8 text-xs mt-0.5" x-model="filters.date_from" @change="onFilterChange()">
                        </div>
                        <div>
                            <label class="help-text">To</label>
                            <input type="date" class="input !h-8 text-xs mt-0.5" x-model="filters.date_to" @change="onFilterChange()">
                        </div>
                        <div>
                            <label class="help-text">Template</label>
                            <select class="select !h-8 text-xs mt-0.5" x-model="filters.template_id" @change="onFilterChange()">
                                <option value="">Any</option>
                                <template x-for="t in meta.templates" :key="t.id"><option :value="t.id" x-text="t.template_name"></option></template>
                            </select>
                        </div>
                        <div>
                            <label class="help-text">District</label>
                            <select class="select !h-8 text-xs mt-0.5" x-model="filters.district_code" @change="onFilterChange()">
                                <option value="">Any</option>
                                <template x-for="d in meta.districts" :key="d.district_code"><option :value="d.district_code" x-text="d.district_name"></option></template>
                            </select>
                        </div>
                        <div>
                            <label class="help-text">PoE</label>
                            <select class="select !h-8 text-xs mt-0.5" x-model="filters.poe_code" @change="onFilterChange()">
                                <option value="">Any</option>
                                <template x-for="p in meta.poes" :key="p.poe_code"><option :value="p.poe_code" x-text="p.poe_name"></option></template>
                            </select>
                        </div>
                        <div>
                            <label class="help-text">Sync</label>
                            <select class="select !h-8 text-xs mt-0.5" x-model="filters.sync_status" @change="onFilterChange()">
                                <option value="">Any</option>
                                <template x-for="s in meta.sync_statuses" :key="s"><option :value="s" x-text="s"></option></template>
                            </select>
                        </div>
                        <div class="col-span-2 sm:col-span-3 lg:col-span-5">
                            <label class="help-text">Search</label>
                            <input type="search" class="input !h-8 text-xs mt-0.5" placeholder="notes, POE code, district, template code…" x-model.debounce.300ms="filters.q" @input="onFilterChange()">
                        </div>
                        <div class="flex items-end">
                            <button class="btn btn-ghost btn-xs" @click="resetFilters()">Reset</button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- ══════════════════════════════════════════════════════════
                 TAB: BROWSE
                 ══════════════════════════════════════════════════════════ --}}
            <template x-if="tab === 'browse'">
                <div>
                    <div class="table-wrap !rounded-none !border-0">
                        <table class="table">
                            <thead class="table-head"><tr>
                                <th class="table-head-th">Period</th>
                                <th class="table-head-th">Location</th>
                                <th class="table-head-th text-right">Screened</th>
                                <th class="table-head-th hidden md:table-cell text-right">Symptomatic</th>
                                <th class="table-head-th hidden lg:table-cell">Template</th>
                                <th class="table-head-th hidden lg:table-cell">Submitter</th>
                                <th class="table-head-th text-right">Sync</th>
                            </tr></thead>
                            <tbody class="table-body">
                                <template x-if="loading.browse"><tr><td colspan="7" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading submissions…</td></tr></template>
                                <template x-if="!loading.browse && browseRows.length === 0"><tr><td colspan="7" class="table-cell"><div class="empty-state py-10"><p class="text-sm text-muted-foreground">No submissions match the current filters.</p></div></td></tr></template>
                                <template x-for="r in browseRows" :key="r.id">
                                    <tr class="table-row cursor-pointer" @click="openDetail(r.id)">
                                        <td class="table-cell">
                                            <div class="font-semibold text-[12.5px]" x-text="r.period_label"></div>
                                            <div class="text-[10.5px] text-muted-foreground" x-text="r.created_rel"></div>
                                        </td>
                                        <td class="table-cell text-[11.5px]">
                                            <div class="font-semibold" x-text="r.poe_code"></div>
                                            <div class="text-muted-foreground" x-text="r.district_code"></div>
                                        </td>
                                        <td class="table-cell text-right tabular-nums" x-text="formatNum(r.total_screened)"></td>
                                        <td class="table-cell hidden md:table-cell text-right tabular-nums" x-text="formatNum(r.total_symptomatic)"></td>
                                        <td class="table-cell hidden lg:table-cell text-[11.5px]">
                                            <div x-text="r.template_name || '—'"></div>
                                            <div class="text-muted-foreground font-mono text-[10.5px]" x-text="r.template_code + ' · v' + r.template_version"></div>
                                        </td>
                                        <td class="table-cell hidden lg:table-cell text-[11.5px]" x-text="r.submitted_by_name || '—'"></td>
                                        <td class="table-cell text-right">
                                            <span class="badge" :class="syncBadge(r.sync_status)" x-text="syncLabel(r.sync_status)"></span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    {{-- Pagination --}}
                    <div class="p-3 flex items-center justify-between text-[11.5px] text-muted-foreground border-t" x-show="pagination.pages > 1">
                        <div>Page <span class="font-semibold" x-text="pagination.page"></span> of <span x-text="pagination.pages"></span> · <span x-text="pagination.total"></span> row(s)</div>
                        <div class="flex gap-1">
                            <button class="btn btn-outline btn-xs" @click="pagination.page=1; loadBrowse()" :disabled="pagination.page <= 1">«</button>
                            <button class="btn btn-outline btn-xs" @click="pagination.page--; loadBrowse()" :disabled="pagination.page <= 1">‹</button>
                            <button class="btn btn-outline btn-xs" @click="pagination.page++; loadBrowse()" :disabled="pagination.page >= pagination.pages">›</button>
                            <button class="btn btn-outline btn-xs" @click="pagination.page=pagination.pages; loadBrowse()" :disabled="pagination.page >= pagination.pages">»</button>
                        </div>
                    </div>
                </div>
            </template>

            {{-- ══════════════════════════════════════════════════════════
                 TAB: ROLLUPS
                 ══════════════════════════════════════════════════════════ --}}
            <template x-if="tab === 'rollups'">
                <div class="p-4 sm:p-5 space-y-5">
                    {{-- Monthly trend --}}
                    <div>
                        <h3 class="text-[13px] font-semibold mb-2">Monthly trend</h3>
                        <template x-if="loading.rollups"><p class="text-[12px] text-muted-foreground">Loading…</p></template>
                        <template x-if="!loading.rollups && rollups.monthly?.length === 0"><div class="empty-state py-8"><p class="text-sm text-muted-foreground">No data in this scope.</p></div></template>
                        <template x-if="!loading.rollups && rollups.monthly?.length">
                            <div>
                                {{-- Inline bar chart. Screened = full-height brand track,
                                     Symptomatic = amber strip drawn AT THE TOP of the
                                     screened bar (never occluding the full screened
                                     height). Hover shows exact counts. --}}
                                <div class="flex items-end gap-2 h-[170px] border-b border-border/60">
                                    <template x-for="row in rollups.monthly" :key="row.month">
                                        <div class="flex-1 flex flex-col items-center justify-end gap-0.5 h-full group" :title="'Screened ' + formatNum(row.screened) + ' · Symptomatic ' + formatNum(row.symptomatic)">
                                            <span class="text-[10px] font-semibold tabular-nums text-foreground/80 opacity-0 group-hover:opacity-100 transition-opacity" x-text="formatNum(row.screened)"></span>
                                            <div class="w-full rounded-t bg-brand/80 relative" :style="'height: ' + Math.max(row.screened > 0 ? 3 : 0, (row.screened / maxMonthly) * 150) + 'px'">
                                                <div class="absolute inset-x-0 top-0 bg-warning rounded-t" :style="'height: ' + Math.min(100, row.screened > 0 ? (row.symptomatic / row.screened) * 100 : 0) + '%'" :title="'symptomatic ' + formatNum(row.symptomatic)"></div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                                <div class="grid gap-2 mt-1" :style="'grid-template-columns: repeat(' + rollups.monthly.length + ', minmax(0,1fr))'">
                                    <template x-for="row in rollups.monthly" :key="'lbl-' + row.month">
                                        <span class="text-[9.5px] text-muted-foreground font-mono text-center truncate" x-text="row.month"></span>
                                    </template>
                                </div>
                                <div class="flex items-center gap-3 text-[10.5px] text-muted-foreground mt-2">
                                    <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded bg-brand/80"></span> Screened (full bar)</span>
                                    <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded bg-warning"></span> Symptomatic (% of screened, top-band)</span>
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        {{-- POE rollup --}}
                        <div>
                            <h3 class="text-[13px] font-semibold mb-2">Per PoE <span class="text-muted-foreground font-normal">(top 50)</span></h3>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead class="table-head"><tr>
                                        <th class="table-head-th">PoE</th>
                                        <th class="table-head-th text-right">Screened</th>
                                        <th class="table-head-th text-right">Symptomatic</th>
                                        <th class="table-head-th text-right">Subs</th>
                                    </tr></thead>
                                    <tbody class="table-body">
                                        <template x-if="!rollups.poe?.length"><tr><td colspan="4" class="table-cell text-center py-6 text-muted-foreground text-xs">No data</td></tr></template>
                                        <template x-for="row in rollups.poe" :key="row.poe">
                                            <tr class="table-row">
                                                <td class="table-cell"><span class="font-semibold" x-text="row.poe"></span><span class="text-muted-foreground block text-[10.5px]" x-text="row.district"></span></td>
                                                <td class="table-cell text-right tabular-nums" x-text="formatNum(row.screened)"></td>
                                                <td class="table-cell text-right tabular-nums" x-text="formatNum(row.symptomatic)"></td>
                                                <td class="table-cell text-right tabular-nums" x-text="row.submissions"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        {{-- District rollup --}}
                        <div>
                            <h3 class="text-[13px] font-semibold mb-2">Per district</h3>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead class="table-head"><tr>
                                        <th class="table-head-th">District</th>
                                        <th class="table-head-th text-right">Screened</th>
                                        <th class="table-head-th text-right">Symptomatic</th>
                                        <th class="table-head-th text-right">POEs</th>
                                        <th class="table-head-th text-right">Subs</th>
                                    </tr></thead>
                                    <tbody class="table-body">
                                        <template x-if="!rollups.district?.length"><tr><td colspan="5" class="table-cell text-center py-6 text-muted-foreground text-xs">No data</td></tr></template>
                                        <template x-for="row in rollups.district" :key="row.district">
                                            <tr class="table-row">
                                                <td class="table-cell font-semibold" x-text="row.district"></td>
                                                <td class="table-cell text-right tabular-nums" x-text="formatNum(row.screened)"></td>
                                                <td class="table-cell text-right tabular-nums" x-text="formatNum(row.symptomatic)"></td>
                                                <td class="table-cell text-right tabular-nums" x-text="row.poes"></td>
                                                <td class="table-cell text-right tabular-nums" x-text="row.submissions"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    {{-- Province rollup --}}
                    <template x-if="rollups.province?.length">
                        <div>
                            <h3 class="text-[13px] font-semibold mb-2">Per province (PHEOC)</h3>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead class="table-head"><tr>
                                        <th class="table-head-th">Province</th>
                                        <th class="table-head-th text-right">Screened</th>
                                        <th class="table-head-th text-right">Symptomatic</th>
                                        <th class="table-head-th text-right">Districts</th>
                                        <th class="table-head-th text-right">Subs</th>
                                    </tr></thead>
                                    <tbody class="table-body">
                                        <template x-for="row in rollups.province" :key="row.province">
                                            <tr class="table-row">
                                                <td class="table-cell font-semibold" x-text="row.province"></td>
                                                <td class="table-cell text-right tabular-nums" x-text="formatNum(row.screened)"></td>
                                                <td class="table-cell text-right tabular-nums" x-text="formatNum(row.symptomatic)"></td>
                                                <td class="table-cell text-right tabular-nums" x-text="row.districts"></td>
                                                <td class="table-cell text-right tabular-nums" x-text="row.submissions"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- ══════════════════════════════════════════════════════════
                 TAB: LATE REPORTERS (gap detector)
                 ══════════════════════════════════════════════════════════ --}}
            <template x-if="tab === 'late'">
                <div class="p-4 sm:p-5 space-y-4">
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                        <div class="kpi"><p class="kpi-label">Template</p><p class="kpi-value text-[15px] truncate" x-text="lateReporters.template?.template_name || '—'"></p><p class="text-[11px] text-muted-foreground mt-1" x-text="lateReporters.template?.reporting_frequency || ''"></p></div>
                        <div class="kpi"><p class="kpi-label">Period</p><p class="kpi-value text-[15px]" x-text="lateReporters.period?.label || '—'"></p></div>
                        <div class="kpi"><p class="kpi-label">Reporting</p><p class="kpi-value tabular-nums text-success" x-text="lateReporters.reported?.length ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1" x-text="'of ' + (lateReporters.expected_count ?? 0) + ' POEs'"></p></div>
                        <div class="kpi"><p class="kpi-label">Coverage</p><p class="kpi-value tabular-nums" x-text="(lateReporters.coverage_pct ?? 0) + '%'"></p><div class="progress progress-sm mt-2"><div class="progress-bar" :style="'width:' + (lateReporters.coverage_pct ?? 0) + '%'"></div></div></div>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <div>
                            <h3 class="text-[13px] font-semibold mb-2 flex items-center gap-2">
                                <span class="status-dot status-dot-danger"></span>
                                Missing <span class="text-muted-foreground font-normal">(<span x-text="lateReporters.missing?.length || 0"></span>)</span>
                            </h3>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead class="table-head"><tr>
                                        <th class="table-head-th">PoE</th>
                                        <th class="table-head-th">District</th>
                                    </tr></thead>
                                    <tbody class="table-body">
                                        <template x-if="loading.late"><tr><td colspan="2" class="table-cell text-center py-6 text-muted-foreground text-xs">Loading…</td></tr></template>
                                        <template x-if="!loading.late && !lateReporters.missing?.length"><tr><td colspan="2" class="table-cell text-center py-6 text-success text-xs">All POEs reported.</td></tr></template>
                                        <template x-for="poe in (lateReporters.missing || [])" :key="poe.poe_code">
                                            <tr class="table-row">
                                                <td class="table-cell"><span class="font-semibold" x-text="poe.poe_name"></span><span class="text-muted-foreground font-mono block text-[10.5px]" x-text="poe.poe_code"></span></td>
                                                <td class="table-cell text-[11.5px]" x-text="poe.district_code"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div>
                            <h3 class="text-[13px] font-semibold mb-2 flex items-center gap-2">
                                <span class="status-dot status-dot-live"></span>
                                Reported <span class="text-muted-foreground font-normal">(<span x-text="lateReporters.reported?.length || 0"></span>)</span>
                            </h3>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead class="table-head"><tr>
                                        <th class="table-head-th">PoE</th>
                                        <th class="table-head-th text-right">Subs</th>
                                        <th class="table-head-th hidden sm:table-cell">Last received</th>
                                    </tr></thead>
                                    <tbody class="table-body">
                                        <template x-if="!lateReporters.reported?.length"><tr><td colspan="3" class="table-cell text-center py-6 text-muted-foreground text-xs">No reports yet this period.</td></tr></template>
                                        <template x-for="poe in (lateReporters.reported || [])" :key="poe.poe_code">
                                            <tr class="table-row">
                                                <td class="table-cell"><span class="font-semibold" x-text="poe.poe_name"></span><span class="text-muted-foreground block text-[10.5px]" x-text="poe.district_code"></span></td>
                                                <td class="table-cell text-right tabular-nums" x-text="poe.count"></td>
                                                <td class="table-cell hidden sm:table-cell text-[11px] text-muted-foreground" x-text="poe.last_received || '—'"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            {{-- ══════════════════════════════════════════════════════════
                 TAB: EXPORT
                 ══════════════════════════════════════════════════════════ --}}
            <template x-if="tab === 'export'">
                <div class="p-4 sm:p-5 space-y-4">
                    <div class="alert alert-info">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <div>
                            <p class="alert-title">Scope-aware CSV export</p>
                            <p class="alert-description">Export respects the filters above and your jurisdictional scope. Capped at 5,000 rows per call — narrow the date range for larger queries.</p>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-[12px]">
                        <div><p class="eyebrow">From</p><p class="font-mono" x-text="filters.date_from || '—'"></p></div>
                        <div><p class="eyebrow">To</p><p class="font-mono" x-text="filters.date_to || '—'"></p></div>
                        <div><p class="eyebrow">Template</p><p class="font-mono" x-text="templateLabel(filters.template_id) || 'Any'"></p></div>
                        <div><p class="eyebrow">PoE</p><p class="font-mono" x-text="filters.poe_code || 'Any'"></p></div>
                    </div>
                    <div class="flex gap-2">
                        <button class="btn btn-brand" @click="downloadCsv()">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Download CSV
                        </button>
                        <button class="btn btn-outline" @click="tab='browse'">Preview rows</button>
                    </div>
                </div>
            </template>
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════════════════
         SUBMISSION DETAIL SHEET
         ══════════════════════════════════════════════════════════════ --}}
    <template x-if="detail.open">
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="detail.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="detail.open=false"></div>
            <div class="relative w-full sm:max-w-2xl bg-background border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-5 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="eyebrow">
                            <span x-text="detail.sub?.template_code || '—'"></span>
                            <span class="badge ml-1" :class="syncBadge(detail.sub?.sync_status)" x-text="syncLabel(detail.sub?.sync_status)"></span>
                        </p>
                        <h2 class="text-[14px] font-bold truncate" x-text="detail.sub?.template_name || 'Submission'"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="detail.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-5 py-5 space-y-4">
                    <template x-if="detail.loading"><p class="text-sm text-muted-foreground">Loading…</p></template>
                    <template x-if="!detail.loading && detail.sub">
                        <div class="space-y-4">
                            <div class="grid grid-cols-2 gap-3 text-[12px]">
                                <div><p class="eyebrow">Period</p><p x-text="detail.sub.period_label"></p></div>
                                <div><p class="eyebrow">Received</p><p class="text-muted-foreground" x-text="detail.sub.created_rel"></p></div>
                                <div><p class="eyebrow">PoE</p><p x-text="detail.sub.poe_code"></p></div>
                                <div><p class="eyebrow">District</p><p x-text="detail.sub.district_code"></p></div>
                                <div><p class="eyebrow">Province</p><p x-text="detail.sub.province_code || '—'"></p></div>
                                <div><p class="eyebrow">Submitter</p><p x-text="detail.sub.submitted_by_name || '—'"></p></div>
                                <div><p class="eyebrow">Device</p><p class="font-mono text-[11px]" x-text="(detail.sub.platform || '—') + ' · ' + (detail.sub.app_version || '—')"></p></div>
                                <div><p class="eyebrow">Sync attempts</p><p class="tabular-nums" x-text="detail.sub.sync_attempt_count"></p></div>
                            </div>
                            <template x-if="detail.sub.last_sync_error">
                                <div class="alert alert-critical">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z"/></svg>
                                    <div><p class="alert-title">Last sync error</p><p class="alert-description break-all" x-text="detail.sub.last_sync_error"></p></div>
                                </div>
                            </template>
                            <template x-if="detail.sub.notes">
                                <div>
                                    <p class="eyebrow">Notes</p>
                                    <p class="text-[12.5px] text-muted-foreground mt-1" x-text="detail.sub.notes"></p>
                                </div>
                            </template>

                            <section>
                                <h3 class="text-[13px] font-semibold mb-2">Fixed totals</h3>
                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2">
                                    <div class="card"><div class="card-content !p-3"><p class="eyebrow">Screened</p><p class="text-lg font-bold tabular-nums" x-text="formatNum(detail.sub.total_screened)"></p></div></div>
                                    <div class="card"><div class="card-content !p-3"><p class="eyebrow">Male</p><p class="text-lg font-bold tabular-nums" x-text="formatNum(detail.sub.total_male)"></p></div></div>
                                    <div class="card"><div class="card-content !p-3"><p class="eyebrow">Female</p><p class="text-lg font-bold tabular-nums" x-text="formatNum(detail.sub.total_female)"></p></div></div>
                                    <div class="card"><div class="card-content !p-3"><p class="eyebrow">Symptomatic</p><p class="text-lg font-bold tabular-nums text-warning" x-text="formatNum(detail.sub.total_symptomatic)"></p></div></div>
                                </div>
                            </section>

                            <section>
                                <h3 class="text-[13px] font-semibold mb-2">Template values <span class="text-muted-foreground font-normal">(<span x-text="detail.values.length"></span>)</span></h3>
                                <template x-if="!detail.values.length">
                                    <div class="empty-state py-8"><p class="text-sm text-muted-foreground">No per-column values persisted.</p></div>
                                </template>
                                <template x-if="detail.values.length">
                                    <div class="table-wrap">
                                        <table class="table">
                                            <thead class="table-head"><tr>
                                                <th class="table-head-th">Column</th>
                                                <th class="table-head-th hidden sm:table-cell">Type</th>
                                                <th class="table-head-th text-right">Value</th>
                                            </tr></thead>
                                            <tbody class="table-body">
                                                <template x-for="v in detail.values" :key="v.id">
                                                    <tr class="table-row">
                                                        <td class="table-cell"><span class="font-semibold text-[12px]" x-text="v.column_label"></span><span class="text-muted-foreground font-mono block text-[10.5px]" x-text="v.column_key"></span></td>
                                                        <td class="table-cell hidden sm:table-cell text-[11.5px]"><span class="badge badge-outline" x-text="v.data_type"></span></td>
                                                        <td class="table-cell text-right tabular-nums" x-text="displayValue(v)"></td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </template>
                            </section>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>

    <div class="fixed inset-x-0 bottom-6 z-[70] flex justify-center pointer-events-none px-4" x-show="flash.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="flash.variant === 'danger' ? 'toast-destructive' : (flash.variant === 'warning' ? 'toast-warning' : 'toast-success')">
            <div><p class="toast-title" x-text="flash.title"></p><p class="toast-description" x-text="flash.body"></p></div>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.__IDSR_INTEL__ = {
    meta:  @json($meta ?? []),
    scope: @json($scope ?? []),
    csrf:  @json(csrf_token()),
    routes: {
        data:          @json(url('/admin/aggregated/submissions/data')),
        rollups:       @json(url('/admin/aggregated/submissions/rollups')),
        late:          @json(url('/admin/aggregated/submissions/late-reporters')),
        export:        @json(url('/admin/aggregated/submissions/export')),
        show:          @json(url('/admin/aggregated/submissions')),
    },
};

function idsrIntel() {
    const C = window.__IDSR_INTEL__;
    return {
        tabs: [
            { key: 'browse',  label: 'Browse' },
            { key: 'rollups', label: 'Rollups' },
            { key: 'late',    label: 'Late reporters' },
            { key: 'export',  label: 'Export' },
        ],
        tab: 'browse',
        filtersOpen: false,
        meta: C.meta,

        filters: {
            date_from: '', date_to: '', template_id: '', district_code: '',
            poe_code: '', province_code: '', sync_status: '', q: '',
        },

        loading: { browse: false, rollups: false, late: false },
        browseRows: [],
        pagination: { page: 1, pages: 1, per_page: 25, total: 0 },

        rollups: { monthly: [], poe: [], district: [], province: [], national: null },
        lateReporters: { template: null, period: null, reported: [], missing: [], coverage_pct: 0, expected_count: 0 },

        detail: { open: false, id: null, loading: false, sub: null, values: [] },
        flash: { open: false, variant: 'success', title: '', body: '', timer: null },

        get activeFilterCount() {
            return Object.values(this.filters).filter(v => v !== '' && v !== null && v !== undefined).length;
        },
        get maxMonthly() {
            const rows = this.rollups.monthly || [];
            return rows.reduce((m, r) => Math.max(m, r.screened || 0), 0) || 1;
        },

        async boot() {
            await Promise.all([this.loadBrowse(), this.loadRollups()]);
        },

        switchTab(k) {
            this.tab = k;
            if (k === 'late' && !this.lateReporters.template && !this.loading.late) this.loadLate();
        },

        onFilterChange() {
            this.pagination.page = 1;
            if (this.tab === 'browse')  this.loadBrowse();
            if (this.tab === 'rollups') this.loadRollups();
            if (this.tab === 'late')    this.loadLate();
        },

        resetFilters() {
            this.filters = {
                date_from: '', date_to: '', template_id: '', district_code: '',
                poe_code: '', province_code: '', sync_status: '', q: '',
            };
            this.onFilterChange();
        },

        buildQS(extra = {}) {
            const qs = new URLSearchParams();
            for (const [k, v] of Object.entries({ ...this.filters, ...extra })) {
                if (v !== '' && v !== null && v !== undefined) qs.set(k, v);
            }
            return qs.toString();
        },

        setPageMeta(rows) {
            try {
                if (window.Alpine && Alpine.store) {
                    const s = Alpine.store('pageMeta');
                    if (s) s.rows = rows;
                }
            } catch (_) { /* no-op */ }
        },

        async jsonGet(url) {
            const res = await fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            const ct = (res.headers.get('content-type') || '').toLowerCase();
            if (!ct.includes('application/json')) {
                if (res.status === 401 || res.status === 419) throw new Error('Session expired — reload the page.');
                throw new Error('Non-JSON response (HTTP ' + res.status + ').');
            }
            const body = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(body?.message || ('HTTP ' + res.status));
            return body;
        },

        async loadBrowse() {
            this.loading.browse = true;
            try {
                const qs = this.buildQS({ page: this.pagination.page, per_page: this.pagination.per_page });
                const body = await this.jsonGet(C.routes.data + '?' + qs);
                this.browseRows = body?.data?.items || [];
                this.pagination = {
                    page: body?.data?.page || 1,
                    pages: body?.data?.pages || 1,
                    per_page: body?.data?.per_page || 25,
                    total: body?.data?.total || 0,
                };
                this.setPageMeta(this.pagination.total);
            } catch (e) {
                this.browseRows = [];
                this.toast('danger', 'Load failed', e.message || 'Could not load submissions.');
            } finally {
                this.loading.browse = false;
            }
        },

        async loadRollups() {
            this.loading.rollups = true;
            try {
                const qs = this.buildQS();
                const body = await this.jsonGet(C.routes.rollups + '?' + qs);
                this.rollups = body?.data || this.rollups;
            } catch (e) {
                this.toast('danger', 'Rollups failed', e.message || 'Could not load rollups.');
            } finally {
                this.loading.rollups = false;
            }
        },

        async loadLate() {
            this.loading.late = true;
            try {
                const qs = this.buildQS();
                const body = await this.jsonGet(C.routes.late + '?' + qs);
                this.lateReporters = body?.data || this.lateReporters;
            } catch (e) {
                this.toast('danger', 'Gap detector failed', e.message || 'Could not load late reporters.');
            } finally {
                this.loading.late = false;
            }
        },

        async openDetail(id) {
            this.detail = { open: true, id, loading: true, sub: null, values: [] };
            try {
                const body = await this.jsonGet(C.routes.show + '/' + id);
                this.detail.sub    = body?.data?.submission || null;
                this.detail.values = body?.data?.values     || [];
            } catch (e) {
                this.detail.sub = null;
                this.toast('danger', 'Load failed', e.message);
            } finally {
                this.detail.loading = false;
            }
        },

        downloadCsv() {
            const qs = this.buildQS();
            const href = C.routes.export + (qs ? '?' + qs : '');
            // Hidden anchor triggers a genuine download without navigating away.
            const a = document.createElement('a');
            a.href = href;
            a.download = '';
            a.rel = 'noopener';
            document.body.appendChild(a);
            a.click();
            setTimeout(() => a.remove(), 0);
            this.toast('success', 'Export started', 'CSV download will begin shortly.');
        },

        toast(variant, title, body) {
            const prev = this.flash?.timer;
            if (prev) clearTimeout(prev);
            this.flash = { open: true, variant, title, body, timer: null };
            this.flash.timer = setTimeout(() => { this.flash.open = false; }, 2800);
        },

        // helpers
        formatNum(n) {
            if (n === null || n === undefined) return '—';
            const v = Number(n);
            return Number.isFinite(v) ? v.toLocaleString() : String(n);
        },
        syncBadge(s) {
            switch ((s || '').toUpperCase()) {
                case 'SYNCED':   return 'badge-success';
                case 'UNSYNCED': return 'badge-warning';
                case 'FAILED':   return 'badge-danger';
                default:         return 'badge-outline';
            }
        },
        // Mirrors src/services/plainLabels.js syncLabel.
        syncLabel(s) {
            const map = { SYNCED: 'Uploaded', UNSYNCED: 'Waiting to upload', PENDING: 'Waiting to upload', FAILED: 'Upload failed', QUARANTINED: 'Stuck — contact support', UNKNOWN: 'Status unknown' };
            return map[(s || '').toUpperCase()] || (s || '');
        },
        templateLabel(id) {
            if (!id) return '';
            const t = (this.meta.templates || []).find(x => String(x.id) === String(id));
            return t ? t.template_name : id;
        },
        displayValue(v) {
            // BOOLEAN columns are stored in value_numeric (0/1) because the
            // schema has no value_boolean column — render them as Yes/No so
            // the admin sees the same plain language a screener sees on the
            // mobile app, not "1.00".
            if ((v.data_type || '').toUpperCase() === 'BOOLEAN') {
                if (v.value_numeric === null || v.value_numeric === undefined) return '—';
                return Number(v.value_numeric) === 1 ? 'Yes' : 'No';
            }
            if (v.value_numeric !== null && v.value_numeric !== undefined) return this.formatNum(v.value_numeric);
            if (v.value_text !== null && v.value_text !== '') return v.value_text;
            if (v.value_json) return JSON.stringify(v.value_json);
            return '—';
        },
    };
}
</script>
@endpush
@endsection
