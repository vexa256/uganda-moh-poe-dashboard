@extends('admin.layout')

@section('crumb', 'Aggregated Reports')
@section('title', 'Reports Engine')

@php
    /** @var array $scope */
@endphp

@push('head')
{{-- Chart.js — animation off via global config in script body --}}
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
@endpush

@section('content')
<div x-data="idsrReports()"
     x-init="boot()"
     x-effect="window.adminLock.set('idsr-reports', detail.open)"
     class="space-y-5">

    {{-- ── KPI strip (national across all templates) ──────────────── --}}
    <section class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-3">
        <div class="kpi kpi-glow"><p class="kpi-label">Templates</p><p class="kpi-value tabular-nums" x-text="summary.templates_in_scope ?? '—'"></p><p class="text-[11px] text-muted-foreground mt-1"><span x-text="summary.templates_with_data ?? 0"></span> with data</p></div>
        <div class="kpi"><p class="kpi-label">Submissions</p><p class="kpi-value tabular-nums" x-text="formatNum(summary.total_submissions)"></p><p class="text-[11px] text-muted-foreground mt-1">in scope</p></div>
        <div class="kpi"><p class="kpi-label">Screened</p><p class="kpi-value tabular-nums" x-text="formatNum(summary.total_screened)"></p><p class="text-[11px] text-muted-foreground mt-1">across templates</p></div>
        <div class="kpi"><p class="kpi-label">Symptomatic</p><p class="kpi-value tabular-nums text-warning" x-text="formatNum(summary.total_symptomatic)"></p><p class="text-[11px] text-muted-foreground mt-1" x-text="symptomaticPct + '% of screened'"></p></div>
        <div class="kpi"><p class="kpi-label">PoEs</p><p class="kpi-value tabular-nums" x-text="summary.distinct_poes ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">contributing</p></div>
        <div class="kpi"><p class="kpi-label">Active reports</p><p class="kpi-value tabular-nums text-success" x-text="summary.distinct_templates ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">templates with data</p></div>
        <div class="kpi"><p class="kpi-label">Scope</p><p class="kpi-value text-[12px] truncate" x-text="summary.scope_label || 'Loading…'"></p><p class="text-[11px] text-muted-foreground mt-1">role-filtered</p></div>
    </section>

    {{-- ── Template gallery ───────────────────────────────────────── --}}
    <section class="card">
        <div class="card-content !p-0">
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div>
                        <h2 class="text-[14px] font-semibold">Templates</h2>
                        <p class="help-text">Click a card to open the full dynamic report. Cards without data are surfaced too — so empty templates aren't invisible.</p>
                    </div>
                    <div class="flex-1"></div>
                    <input type="search" class="input w-full sm:w-64 text-xs" placeholder="Search by name / code…" x-model.debounce.250ms="filters.q">
                    <select class="select w-full sm:w-40 text-xs" x-model="filters.statusFilter">
                        <option value="">Any status</option>
                        <option value="PUBLISHED">Published</option>
                        <option value="DRAFT">Draft</option>
                        <option value="RETIRED">Retired</option>
                    </select>
                    <label class="flex items-center gap-2 text-[12px]">
                        <input type="checkbox" x-model="filters.onlyWithData">
                        <span>Only with data</span>
                    </label>
                </div>
            </div>

            <div class="p-4 sm:p-5">
                <template x-if="loading.list">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <template x-for="i in 6" :key="i">
                            <div class="skeleton h-[148px]"></div>
                        </template>
                    </div>
                </template>
                <template x-if="!loading.list && filteredTemplates.length === 0">
                    <div class="empty-state py-14">
                        <p class="text-sm text-muted-foreground">No templates match the current filter.</p>
                    </div>
                </template>
                <template x-if="!loading.list && filteredTemplates.length > 0">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <template x-for="t in filteredTemplates" :key="t.id">
                            <button type="button"
                                    class="text-left rounded-xl border bg-card p-4 hover:shadow-elevation-3 transition-shadow group relative overflow-hidden"
                                    @click="openReport(t.id)">
                                <span class="absolute inset-y-0 left-0 w-1.5" :style="'background:' + (t.colour || '#10B981')"></span>
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="badge" :class="statusBadge(t.status)" x-text="statusLabel(t.status)"></span>
                                            <span class="badge badge-outline" x-text="freqLabel(t.reporting_frequency)"></span>
                                            <span class="badge badge-brand" x-show="t.is_default">default</span>
                                        </div>
                                        <h3 class="text-[13.5px] font-semibold truncate" x-text="t.template_name"></h3>
                                        <p class="text-[10.5px] font-mono text-muted-foreground mt-0.5 truncate" x-text="t.template_code + ' · v' + t.version"></p>
                                    </div>
                                    <div class="text-right shrink-0">
                                        <p class="text-2xl font-bold tabular-nums leading-none" :class="t.has_data ? 'text-foreground' : 'text-muted-foreground/40'" x-text="formatNum(t.submissions)"></p>
                                        <p class="text-[10px] text-muted-foreground mt-1">submissions</p>
                                    </div>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mt-3 pt-3 border-t border-border/60 text-[11px]">
                                    <div><p class="text-muted-foreground">Screened</p><p class="font-semibold tabular-nums" x-text="formatNum(t.screened)"></p></div>
                                    <div><p class="text-muted-foreground">PoEs</p><p class="font-semibold tabular-nums" x-text="t.poes_reporting"></p></div>
                                    <div><p class="text-muted-foreground">Columns</p><p class="font-semibold tabular-nums" x-text="t.columns_enabled + '/' + t.columns_total"></p></div>
                                </div>
                                <p class="text-[10.5px] text-muted-foreground mt-2 truncate" x-show="t.has_data" x-text="'Latest: ' + t.latest_rel"></p>
                                <p class="text-[10.5px] text-muted-foreground mt-2" x-show="!t.has_data">No submissions yet.</p>
                            </button>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════════════════
         DETAIL SHEET — full dynamic report for one template
         ══════════════════════════════════════════════════════════════ --}}
    <template x-if="detail.open">
        <div class="fixed inset-0 z-50 flex" role="dialog" aria-modal="true" @keydown.escape.window="closeDetail()">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="closeDetail()"></div>
            <div class="relative ml-auto w-full lg:max-w-[1180px] bg-background border-l shadow-elevation-5 flex flex-col h-full" @click.stop>

                <header class="flex items-center gap-3 px-5 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg shrink-0" :style="'background:' + ((detail.report?.template?.colour || '#10B981') + '22') + '; color:' + (detail.report?.template?.colour || '#10B981')">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="eyebrow flex items-center gap-1.5">
                            <span class="font-mono" x-text="detail.report?.template?.template_code || '…'"></span>
                            <span class="badge badge-outline" x-text="detail.report?.template?.reporting_frequency || ''"></span>
                            <span class="badge" :class="statusBadge(detail.report?.template?.status)" x-text="statusLabel(detail.report?.template?.status)"></span>
                        </p>
                        <h2 class="text-[14px] font-bold truncate" x-text="detail.report?.template?.template_name || 'Loading…'"></h2>
                    </div>
                    <button class="btn btn-outline btn-xs" @click="downloadCsv()" :disabled="!detail.report">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        CSV
                    </button>
                    <button class="btn btn-ghost btn-icon-xs" @click="closeDetail()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>

                {{-- Tabs --}}
                <div class="border-b">
                    <div class="tabs-list w-full overflow-x-auto px-2">
                        <template x-for="t in detailTabs" :key="t.key">
                            <button class="tabs-trigger flex-shrink-0" :data-state="detail.tab === t.key ? 'active' : 'inactive'" @click="setDetailTab(t.key)">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-show="t.badge" x-text="t.badge"></span>
                            </button>
                        </template>
                    </div>
                </div>

                {{-- Tab body --}}
                <div class="flex-1 overflow-y-auto px-5 py-5 space-y-5">
                    <template x-if="detail.loading">
                        <div class="space-y-4">
                            <div class="skeleton h-24 w-full"></div>
                            <div class="skeleton h-48 w-full"></div>
                            <div class="grid grid-cols-2 gap-3"><div class="skeleton h-32"></div><div class="skeleton h-32"></div></div>
                        </div>
                    </template>

                    {{-- ── OVERVIEW ────────────────────────────────────── --}}
                    <template x-if="!detail.loading && detail.tab === 'overview' && detail.report">
                        <div class="space-y-5">
                            <section class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                                <div class="kpi"><p class="kpi-label">Submissions</p><p class="kpi-value tabular-nums" x-text="formatNum(detail.report.summary.submissions)"></p></div>
                                <div class="kpi"><p class="kpi-label">PoEs</p><p class="kpi-value tabular-nums" x-text="detail.report.summary.poes_reporting"></p></div>
                                <div class="kpi"><p class="kpi-label">Districts</p><p class="kpi-value tabular-nums" x-text="detail.report.summary.districts_reporting"></p></div>
                                <div class="kpi"><p class="kpi-label">Period span</p><p class="kpi-value text-[15px]" x-text="detail.report.summary.period_span_days + ' days'"></p></div>
                                <div class="kpi"><p class="kpi-label">Latest period</p><p class="kpi-value text-[15px] truncate" x-text="formatDate(detail.report.summary.latest_period) || '—'"></p></div>
                                <div class="kpi"><p class="kpi-label">Versions</p><p class="kpi-value text-[15px]" x-text="(detail.report.summary.template_versions || []).join(', ') || '—'"></p></div>
                            </section>

                            <section>
                                <h3 class="text-[13px] font-semibold mb-2">Core totals</h3>
                                <p class="help-text mb-3">Fixed per-submission columns. Aggregated across every in-scope row.</p>
                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                    <template x-for="c in (detail.report.core_columns || [])" :key="c.column_key">
                                        <div class="card">
                                            <div class="card-content !p-4 space-y-3">
                                                <div class="flex items-center justify-between">
                                                    <div>
                                                        <p class="eyebrow" x-text="c.category"></p>
                                                        <p class="text-[14px] font-semibold" x-text="c.column_label"></p>
                                                    </div>
                                                    <div class="text-right">
                                                        <p class="text-2xl font-bold tabular-nums" x-text="formatNum(c.summary?.sum)"></p>
                                                        <p class="text-[10.5px] text-muted-foreground">total · <span class="font-mono" x-text="c.aggregation_fn"></span></p>
                                                    </div>
                                                </div>
                                                <div class="grid grid-cols-4 gap-2 text-[11px]">
                                                    <div><p class="text-muted-foreground">avg</p><p class="font-semibold tabular-nums" x-text="formatNum(c.summary?.avg)"></p></div>
                                                    <div><p class="text-muted-foreground">min</p><p class="font-semibold tabular-nums" x-text="formatNum(c.summary?.min)"></p></div>
                                                    <div><p class="text-muted-foreground">max</p><p class="font-semibold tabular-nums" x-text="formatNum(c.summary?.max)"></p></div>
                                                    <div><p class="text-muted-foreground">stdev</p><p class="font-semibold tabular-nums" x-text="formatNum(c.summary?.stdev)"></p></div>
                                                </div>
                                                <div :id="'core-spark-' + c.column_key" class="h-20"></div>
                                                <div class="flex items-center gap-2 text-[10.5px]">
                                                    <span class="badge" :class="trendBadge(c.trend?.direction)">
                                                        <span x-text="trendArrow(c.trend?.direction)"></span>
                                                        <span x-text="c.trend?.direction"></span>
                                                    </span>
                                                    <span class="text-muted-foreground" x-text="c.trend?.change_pct ? (c.trend.change_pct > 0 ? '+' : '') + c.trend.change_pct + '% Δ' : ''"></span>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </section>
                        </div>
                    </template>

                    {{-- ── COLUMNS (DYNAMIC) ──────────────────────────── --}}
                    <template x-if="!detail.loading && detail.tab === 'columns' && detail.report">
                        <div class="space-y-5">
                            <template x-if="(detail.report.columns || []).length === 0">
                                <div class="empty-state py-10"><p class="text-sm text-muted-foreground">This template has no custom columns. Use the Studio to add some.</p></div>
                            </template>
                            <template x-for="col in (detail.report.columns || [])" :key="col.column_key">
                                <div class="card">
                                    <div class="card-content !p-4 space-y-4">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="min-w-0">
                                                <p class="eyebrow flex items-center gap-1.5">
                                                    <span x-text="col.category"></span>
                                                    <span class="badge badge-outline" x-text="col.data_type"></span>
                                                    <span class="badge badge-secondary" x-show="col.is_required">required</span>
                                                    <span class="badge badge-brand" x-show="col.is_core">core</span>
                                                </p>
                                                <h4 class="text-[14px] font-semibold" x-text="col.column_label"></h4>
                                                <p class="text-[10.5px] font-mono text-muted-foreground" x-text="col.column_key + ' · agg=' + col.aggregation_fn"></p>
                                                <p class="text-[11.5px] text-muted-foreground mt-1" x-show="col.help_text" x-text="col.help_text"></p>
                                            </div>
                                            <div class="text-right text-[11px]">
                                                <p class="text-muted-foreground">Fill rate</p>
                                                <p class="text-[16px] font-bold tabular-nums" x-text="col.fill_rate_pct + '%'"></p>
                                                <p class="text-muted-foreground" x-text="col.response_count + ' of ' + (detail.report.summary.submissions ?? 0)"></p>
                                            </div>
                                        </div>

                                        {{-- Numeric (INTEGER / DECIMAL / PERCENT) --}}
                                        <template x-if="col.kind === 'numeric'">
                                            <div class="space-y-3">
                                                <div class="grid grid-cols-2 sm:grid-cols-7 gap-2 text-[11px]">
                                                    <div><p class="text-muted-foreground">sum</p><p class="font-bold tabular-nums" x-text="formatNum(col.summary?.sum)"></p></div>
                                                    <div><p class="text-muted-foreground">avg</p><p class="font-bold tabular-nums" x-text="formatNum(col.summary?.avg)"></p></div>
                                                    <div><p class="text-muted-foreground">median</p><p class="font-bold tabular-nums" x-text="formatNum(col.summary?.median)"></p></div>
                                                    <div><p class="text-muted-foreground">min</p><p class="font-bold tabular-nums" x-text="formatNum(col.summary?.min)"></p></div>
                                                    <div><p class="text-muted-foreground">max</p><p class="font-bold tabular-nums" x-text="formatNum(col.summary?.max)"></p></div>
                                                    <div><p class="text-muted-foreground">stdev</p><p class="font-bold tabular-nums" x-text="formatNum(col.summary?.stdev)"></p></div>
                                                    <div><p class="text-muted-foreground">trend</p><p class="font-bold" :class="trendTextClass(col.trend?.direction)"><span x-text="trendArrow(col.trend?.direction)"></span> <span x-text="(col.trend?.change_pct ?? 0) + '%'"></span></p></div>
                                                </div>
                                                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                                    <div><p class="text-[11.5px] font-semibold mb-1">Trend over time</p><div :id="'col-line-' + col.column_key" class="h-44"></div></div>
                                                    <div><p class="text-[11.5px] font-semibold mb-1">Top PoEs</p><div :id="'col-poe-' + col.column_key" class="h-44"></div></div>
                                                </div>
                                                <template x-if="(col.outliers || []).length > 0">
                                                    <div>
                                                        <p class="text-[11.5px] font-semibold mb-1">Outliers (|z| ≥ 3)</p>
                                                        <div class="table-wrap">
                                                            <table class="table">
                                                                <thead class="table-head"><tr>
                                                                    <th class="table-head-th">PoE</th><th class="table-head-th">Period</th>
                                                                    <th class="table-head-th text-right">Value</th><th class="table-head-th text-right">z</th>
                                                                </tr></thead>
                                                                <tbody class="table-body">
                                                                    <template x-for="o in col.outliers" :key="o.submission_id">
                                                                        <tr class="table-row">
                                                                            <td class="table-cell text-[11.5px]"><span class="font-semibold" x-text="o.poe_code"></span><span class="text-muted-foreground block" x-text="o.district_code"></span></td>
                                                                            <td class="table-cell text-[11.5px]" x-text="o.period_label"></td>
                                                                            <td class="table-cell text-right tabular-nums" x-text="formatNum(o.value)"></td>
                                                                            <td class="table-cell text-right">
                                                                                <span class="badge" :class="o.direction === 'high' ? 'badge-danger' : 'badge-info'">
                                                                                    <span x-text="(o.zscore > 0 ? '+' : '') + o.zscore"></span>
                                                                                </span>
                                                                            </td>
                                                                        </tr>
                                                                    </template>
                                                                </tbody>
                                                            </table>
                                                        </div>
                                                    </div>
                                                </template>
                                            </div>
                                        </template>

                                        {{-- Boolean --}}
                                        <template x-if="col.kind === 'boolean'">
                                            <div class="space-y-3">
                                                <div class="grid grid-cols-3 gap-3">
                                                    <div class="card !shadow-none border-success/30 bg-success-soft/50"><div class="card-content !p-3"><p class="eyebrow">Yes</p><p class="text-2xl font-bold tabular-nums text-success" x-text="col.yes"></p></div></div>
                                                    <div class="card !shadow-none border-muted-foreground/20"><div class="card-content !p-3"><p class="eyebrow">No</p><p class="text-2xl font-bold tabular-nums" x-text="col.no"></p></div></div>
                                                    <div class="card !shadow-none border-info/30 bg-info-soft/50"><div class="card-content !p-3"><p class="eyebrow">Yes %</p><p class="text-2xl font-bold tabular-nums text-info" x-text="col.yes_pct + '%'"></p></div></div>
                                                </div>
                                                <div :id="'col-bool-' + col.column_key" class="h-44"></div>
                                            </div>
                                        </template>

                                        {{-- Select --}}
                                        <template x-if="col.kind === 'select'">
                                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                                <div>
                                                    <p class="text-[11.5px] font-semibold mb-1">Distribution (<span x-text="col.option_count"></span> options · <span x-text="col.total"></span> picks)</p>
                                                    <div :id="'col-pie-' + col.column_key" class="h-56"></div>
                                                </div>
                                                <div>
                                                    <p class="text-[11.5px] font-semibold mb-1">Top picks</p>
                                                    <div class="table-wrap">
                                                        <table class="table">
                                                            <thead class="table-head"><tr><th class="table-head-th">Option</th><th class="table-head-th text-right">Count</th><th class="table-head-th">Share</th></tr></thead>
                                                            <tbody class="table-body">
                                                                <template x-for="d in col.distribution" :key="d.label">
                                                                    <tr class="table-row">
                                                                        <td class="table-cell text-[12px]" x-text="d.label"></td>
                                                                        <td class="table-cell text-right tabular-nums" x-text="d.count"></td>
                                                                        <td class="table-cell">
                                                                            <div class="progress progress-sm"><div class="progress-bar" :style="'width:' + ((d.count / Math.max(1, col.total)) * 100) + '%'"></div></div>
                                                                        </td>
                                                                    </tr>
                                                                </template>
                                                            </tbody>
                                                        </table>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>

                                        {{-- Date --}}
                                        <template x-if="col.kind === 'date'">
                                            <div class="space-y-3">
                                                <div class="grid grid-cols-3 gap-3 text-[11.5px]">
                                                    <div><p class="text-muted-foreground">count</p><p class="font-bold" x-text="col.count"></p></div>
                                                    <div><p class="text-muted-foreground">earliest</p><p class="font-bold" x-text="col.min_date || '—'"></p></div>
                                                    <div><p class="text-muted-foreground">latest</p><p class="font-bold" x-text="col.max_date || '—'"></p></div>
                                                </div>
                                                <div :id="'col-date-' + col.column_key" class="h-44"></div>
                                            </div>
                                        </template>

                                        {{-- Text --}}
                                        <template x-if="col.kind === 'text'">
                                            <div class="space-y-3">
                                                <div class="grid grid-cols-3 gap-3 text-[11.5px]">
                                                    <div><p class="text-muted-foreground">responses</p><p class="font-bold" x-text="col.total"></p></div>
                                                    <div><p class="text-muted-foreground">unique</p><p class="font-bold" x-text="col.unique_values"></p></div>
                                                    <div><p class="text-muted-foreground">avg length</p><p class="font-bold" x-text="col.avg_length + ' chars'"></p></div>
                                                </div>
                                                <div class="table-wrap">
                                                    <table class="table">
                                                        <thead class="table-head"><tr><th class="table-head-th">Top responses</th><th class="table-head-th text-right">Count</th></tr></thead>
                                                        <tbody class="table-body">
                                                            <template x-for="t in (col.top_values || [])" :key="t.value">
                                                                <tr class="table-row">
                                                                    <td class="table-cell text-[12px] truncate max-w-md" x-text="t.value"></td>
                                                                    <td class="table-cell text-right tabular-nums" x-text="t.count"></td>
                                                                </tr>
                                                            </template>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                        </template>

                                        <template x-if="!col.kind || col.kind === 'unknown'">
                                            <p class="text-sm text-muted-foreground">Unknown data type — no chart available.</p>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- ── GEOGRAPHY ──────────────────────────────────── --}}
                    <template x-if="!detail.loading && detail.tab === 'geography' && detail.report">
                        <div class="space-y-5">
                            <template x-for="c in (detail.report.core_columns || [])" :key="'geo-' + c.column_key">
                                <div class="card">
                                    <div class="card-content !p-4 space-y-3">
                                        <p class="text-[13px] font-semibold" x-text="c.column_label + ' — geographic breakdown'"></p>
                                        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                                            <div>
                                                <p class="text-[11.5px] font-semibold mb-1">By PoE (top 30)</p>
                                                <div :id="'geo-poe-' + c.column_key" class="h-64"></div>
                                            </div>
                                            <div>
                                                <p class="text-[11.5px] font-semibold mb-1">By district</p>
                                                <div :id="'geo-dist-' + c.column_key" class="h-64"></div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- ── COVERAGE ───────────────────────────────────── --}}
                    <template x-if="!detail.loading && detail.tab === 'coverage' && detail.report">
                        <div class="space-y-4">
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div class="kpi"><p class="kpi-label">Avg coverage</p><p class="kpi-value tabular-nums" x-text="(detail.report.coverage?.overall_coverage_pct ?? 0) + '%'"></p></div>
                                <div class="kpi"><p class="kpi-label">Expected PoEs</p><p class="kpi-value tabular-nums" x-text="detail.report.coverage?.expected_count ?? 0"></p></div>
                                <div class="kpi"><p class="kpi-label">Latest reported</p><p class="kpi-value tabular-nums" x-text="(detail.report.coverage?.latest_period?.reported ?? 0) + '/' + (detail.report.coverage?.latest_period?.expected ?? 0)"></p></div>
                                <div class="kpi"><p class="kpi-label">Latest period</p><p class="kpi-value text-[15px]" x-text="detail.report.coverage?.latest_period?.bucket || '—'"></p></div>
                            </div>
                            <div>
                                <p class="text-[11.5px] font-semibold mb-1">Coverage timeline</p>
                                <div id="coverage-timeline" class="h-48"></div>
                            </div>
                            <div>
                                <p class="text-[11.5px] font-semibold mb-1">Per-period detail</p>
                                <div class="table-wrap">
                                    <table class="table">
                                        <thead class="table-head"><tr>
                                            <th class="table-head-th">Period</th>
                                            <th class="table-head-th text-right">Reported</th>
                                            <th class="table-head-th text-right">Expected</th>
                                            <th class="table-head-th">Coverage</th>
                                            <th class="table-head-th">Missing PoEs</th>
                                        </tr></thead>
                                        <tbody class="table-body">
                                            <template x-if="!(detail.report.coverage?.periods || []).length"><tr><td colspan="5" class="table-cell text-center py-6 text-muted-foreground text-xs">No periods yet.</td></tr></template>
                                            <template x-for="p in (detail.report.coverage?.periods || [])" :key="p.bucket">
                                                <tr class="table-row">
                                                    <td class="table-cell font-mono text-[11px]" x-text="p.bucket"></td>
                                                    <td class="table-cell text-right tabular-nums" x-text="p.reported"></td>
                                                    <td class="table-cell text-right tabular-nums" x-text="p.expected"></td>
                                                    <td class="table-cell">
                                                        <div class="flex items-center gap-2">
                                                            <div class="progress progress-sm flex-1"><div class="progress-bar" :style="'width:' + p.coverage_pct + '%; background:' + (p.coverage_pct >= 80 ? 'hsl(var(--success))' : p.coverage_pct >= 50 ? 'hsl(var(--warning))' : 'hsl(var(--critical))')"></div></div>
                                                            <span class="text-[11px] tabular-nums" x-text="p.coverage_pct + '%'"></span>
                                                        </div>
                                                    </td>
                                                    <td class="table-cell text-[11px] text-muted-foreground truncate max-w-xs" :title="(p.missing_poes || []).join(', ')" x-text="(p.missing_poes || []).slice(0, 5).join(', ') + ((p.missing_poes || []).length > 5 ? ' +' + ((p.missing_poes || []).length - 5) : '')"></td>
                                                </tr>
                                            </template>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- ── ANOMALIES ──────────────────────────────────── --}}
                    <template x-if="!detail.loading && detail.tab === 'anomalies' && detail.report">
                        <div class="space-y-3">
                            <template x-if="(detail.report.anomalies || []).length === 0">
                                <div class="alert alert-success">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg>
                                    <div><p class="alert-title">No anomalies detected</p><p class="alert-description">Every submission's gender + symptom totals reconcile against total_screened.</p></div>
                                </div>
                            </template>
                            <template x-if="(detail.report.anomalies || []).length > 0">
                                <div>
                                    <div class="alert alert-warning mb-3">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                        <div><p class="alert-title"><span x-text="(detail.report.anomalies || []).length"></span> submission(s) with reconciliation issues</p><p class="alert-description">Numbers below should equal total_screened. Where they don't, the row is surfaced here for follow-up.</p></div>
                                    </div>
                                    <div class="table-wrap">
                                        <table class="table">
                                            <thead class="table-head"><tr>
                                                <th class="table-head-th">PoE</th>
                                                <th class="table-head-th">Period</th>
                                                <th class="table-head-th text-right">Screened</th>
                                                <th class="table-head-th text-right">Gender Σ</th>
                                                <th class="table-head-th text-right">Symp Σ</th>
                                                <th class="table-head-th">Issues</th>
                                            </tr></thead>
                                            <tbody class="table-body">
                                                <template x-for="a in detail.report.anomalies" :key="a.submission_id">
                                                    <tr class="table-row">
                                                        <td class="table-cell"><span class="font-semibold" x-text="a.poe_code"></span><span class="text-muted-foreground block text-[10.5px]" x-text="a.district_code"></span></td>
                                                        <td class="table-cell text-[11.5px]" x-text="a.period_label"></td>
                                                        <td class="table-cell text-right tabular-nums" x-text="a.screened"></td>
                                                        <td class="table-cell text-right tabular-nums" :class="a.gender_sum !== a.screened ? 'text-critical font-semibold' : ''" x-text="a.gender_sum"></td>
                                                        <td class="table-cell text-right tabular-nums" :class="a.symp_sum !== a.screened ? 'text-critical font-semibold' : ''" x-text="a.symp_sum"></td>
                                                        <td class="table-cell">
                                                            <template x-for="i in a.issues" :key="i">
                                                                <span class="badge badge-warning mr-1 mt-0.5" x-text="i"></span>
                                                            </template>
                                                        </td>
                                                    </tr>
                                                </template>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </template>
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
window.__IDSR_REPORTS__ = {
    scope: @json($scope ?? []),
    csrf:  @json(csrf_token()),
    routes: {
        list:     @json(url('/admin/aggregated/reports/data')),
        template: @json(url('/admin/aggregated/reports/template')),    // + /{id}
        export:   @json(url('/admin/aggregated/reports/template')),    // + /{id}/export
    },
};

// Disable Chart.js animation (theme directive: no animations).
document.addEventListener('DOMContentLoaded', () => {
    if (window.Chart) { window.Chart.defaults.animation = false; window.Chart.defaults.font.family = 'Inter, sans-serif'; }
});

// Colour-blind safe palette aligned with theme --viz-* tokens
const VIZ = ['hsl(199 89% 48%)','hsl(160 84% 39%)','hsl(38 92% 50%)','hsl(262 83% 58%)','hsl(350 82% 51%)','hsl(27 96% 61%)','hsl(173 58% 39%)','hsl(292 47% 51%)'];

function idsrReports() {
    const C = window.__IDSR_REPORTS__;

    return {
        loading: { list: false },
        templates: [],
        summary: {},
        filters: { q: '', statusFilter: '', onlyWithData: false },

        detail: { open: false, id: null, loading: false, report: null, tab: 'overview' },
        chartInstances: {},                  // chart_id → Chart instance (so we can destroy on tab switch)
        flash: { open: false, variant: 'success', title: '', body: '', timer: null },

        get filteredTemplates() {
            const q = (this.filters.q || '').toLowerCase().trim();
            return (this.templates || []).filter(t => {
                if (this.filters.statusFilter && t.status !== this.filters.statusFilter) return false;
                if (this.filters.onlyWithData && !t.has_data) return false;
                if (!q) return true;
                return (t.template_name || '').toLowerCase().includes(q)
                    || (t.template_code || '').toLowerCase().includes(q)
                    || (t.description || '').toLowerCase().includes(q);
            });
        },
        get symptomaticPct() {
            const s = Number(this.summary?.total_screened || 0);
            const x = Number(this.summary?.total_symptomatic || 0);
            return s > 0 ? Math.round((x / s) * 1000) / 10 : 0;
        },
        get detailTabs() {
            const r = this.detail.report;
            return [
                { key: 'overview',   label: 'Overview',   badge: '' },
                { key: 'columns',    label: 'Columns',    badge: r ? (r.columns || []).length : '' },
                { key: 'geography',  label: 'Geography',  badge: '' },
                { key: 'coverage',   label: 'Coverage',   badge: r ? (r.coverage?.periods || []).length : '' },
                { key: 'anomalies',  label: 'Anomalies',  badge: r ? (r.anomalies || []).length : '' },
            ];
        },

        async boot() { await this.loadList(); },

        async jsonGet(url) {
            const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
            const ct = (res.headers.get('content-type') || '').toLowerCase();
            if (!ct.includes('application/json')) {
                if (res.status === 401 || res.status === 419) throw new Error('Session expired — reload the page.');
                throw new Error('Non-JSON response (HTTP ' + res.status + ').');
            }
            const body = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(body?.message || ('HTTP ' + res.status));
            return body;
        },

        setPageMeta(rows) {
            try { if (window.Alpine && Alpine.store) { const s = Alpine.store('pageMeta'); if (s) s.rows = rows; } } catch (_) {}
        },

        async loadList() {
            this.loading.list = true;
            try {
                const body = await this.jsonGet(C.routes.list);
                this.templates = body?.data?.templates || [];
                this.summary   = body?.data?.summary   || {};
                this.setPageMeta(this.templates.length);
            } catch (e) {
                this.toast('danger', 'Load failed', e.message);
                this.templates = []; this.summary = {};
            } finally { this.loading.list = false; }
        },

        async openReport(id) {
            this.detail = { open: true, id, loading: true, report: null, tab: 'overview' };
            this.destroyAllCharts();
            try {
                const body = await this.jsonGet(C.routes.template + '/' + id);
                this.detail.report = body?.data || null;
                // Defer chart rendering until DOM is committed.
                this.$nextTick(() => this.renderActiveTabCharts());
            } catch (e) {
                this.toast('danger', 'Report failed', e.message);
                this.detail.report = null;
            } finally { this.detail.loading = false; }
        },

        setDetailTab(key) {
            this.detail.tab = key;
            this.destroyAllCharts();
            this.$nextTick(() => this.renderActiveTabCharts());
        },

        closeDetail() {
            this.destroyAllCharts();
            this.detail.open = false;
        },

        renderActiveTabCharts() {
            const r = this.detail.report; if (!r) return;
            if (this.detail.tab === 'overview') {
                (r.core_columns || []).forEach(c => this.renderSparkLine('core-spark-' + c.column_key, c.time_series));
            } else if (this.detail.tab === 'columns') {
                (r.columns || []).forEach(c => {
                    if (c.kind === 'numeric') {
                        this.renderLineChart('col-line-' + c.column_key, c.time_series, c.column_label);
                        this.renderBarChart('col-poe-' + c.column_key, c.by_poe || [], 'PoE');
                    } else if (c.kind === 'boolean') {
                        this.renderBoolStacked('col-bool-' + c.column_key, c.time_series || []);
                    } else if (c.kind === 'select') {
                        this.renderPie('col-pie-' + c.column_key, c.distribution || []);
                    } else if (c.kind === 'date') {
                        this.renderHistogramBar('col-date-' + c.column_key, c.histogram || []);
                    }
                });
            } else if (this.detail.tab === 'geography') {
                (r.core_columns || []).forEach(c => {
                    this.renderBarChart('geo-poe-' + c.column_key, c.by_poe || [], 'PoE');
                    this.renderBarChart('geo-dist-' + c.column_key, c.by_district || [], 'District');
                });
            } else if (this.detail.tab === 'coverage') {
                this.renderCoverageTimeline('coverage-timeline', r.coverage?.periods || []);
            }
        },

        // ─── Chart factories ────────────────────────────────────────
        ensureCanvas(elId) {
            const host = document.getElementById(elId);
            if (!host) return null;
            host.innerHTML = '';
            const canvas = document.createElement('canvas');
            host.appendChild(canvas);
            return canvas;
        },
        register(elId, chart) {
            if (this.chartInstances[elId]) try { this.chartInstances[elId].destroy(); } catch (_) {}
            this.chartInstances[elId] = chart;
        },
        destroyAllCharts() {
            Object.values(this.chartInstances).forEach(c => { try { c.destroy(); } catch (_) {} });
            this.chartInstances = {};
        },

        renderSparkLine(elId, ts) {
            const cv = this.ensureCanvas(elId); if (!cv || !window.Chart) return;
            const labels = (ts || []).map(p => p.bucket);
            const data   = (ts || []).map(p => Number(p.value || 0));
            const ch = new Chart(cv, {
                type: 'line',
                data: { labels, datasets: [{ data, borderColor: VIZ[1], backgroundColor: 'hsl(160 84% 39% / 0.15)', fill: true, tension: 0.25, pointRadius: 0, borderWidth: 2 }] },
                options: { plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } }, scales: { x: { display: false }, y: { display: false } }, maintainAspectRatio: false },
            });
            this.register(elId, ch);
        },
        renderLineChart(elId, ts, label) {
            const cv = this.ensureCanvas(elId); if (!cv || !window.Chart) return;
            const labels = (ts || []).map(p => p.bucket);
            const data   = (ts || []).map(p => Number(p.value || 0));
            const ch = new Chart(cv, {
                type: 'line',
                data: { labels, datasets: [{ label, data, borderColor: VIZ[0], backgroundColor: 'hsl(199 89% 48% / 0.15)', fill: true, tension: 0.25, pointRadius: 3, borderWidth: 2 }] },
                options: { plugins: { legend: { display: false } }, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } },
            });
            this.register(elId, ch);
        },
        renderBarChart(elId, rows, label) {
            const cv = this.ensureCanvas(elId); if (!cv || !window.Chart) return;
            const labels = (rows || []).slice(0, 12).map(r => r.label || r.poe || r.district || '—');
            const data   = (rows || []).slice(0, 12).map(r => Number(r.value || 0));
            const ch = new Chart(cv, {
                type: 'bar',
                data: { labels, datasets: [{ label, data, backgroundColor: VIZ[1], borderColor: VIZ[1] }] },
                options: { plugins: { legend: { display: false } }, indexAxis: 'y', maintainAspectRatio: false, scales: { x: { beginAtZero: true } } },
            });
            this.register(elId, ch);
        },
        renderBoolStacked(elId, ts) {
            const cv = this.ensureCanvas(elId); if (!cv || !window.Chart) return;
            const labels = (ts || []).map(p => p.bucket);
            const ch = new Chart(cv, {
                type: 'bar',
                data: { labels, datasets: [
                    { label: 'Yes', data: (ts || []).map(p => p.yes), backgroundColor: VIZ[1] },
                    { label: 'No',  data: (ts || []).map(p => p.no),  backgroundColor: 'hsl(0 0% 70%)' },
                ] },
                options: { plugins: { legend: { display: true } }, maintainAspectRatio: false, scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } },
            });
            this.register(elId, ch);
        },
        renderPie(elId, dist) {
            const cv = this.ensureCanvas(elId); if (!cv || !window.Chart) return;
            const labels = (dist || []).map(d => d.label);
            const data   = (dist || []).map(d => d.count);
            const ch = new Chart(cv, {
                type: 'doughnut',
                data: { labels, datasets: [{ data, backgroundColor: labels.map((_, i) => VIZ[i % VIZ.length]) }] },
                options: { plugins: { legend: { position: 'right' } }, maintainAspectRatio: false, cutout: '55%' },
            });
            this.register(elId, ch);
        },
        renderHistogramBar(elId, hist) {
            const cv = this.ensureCanvas(elId); if (!cv || !window.Chart) return;
            const labels = (hist || []).map(p => p.bucket);
            const data   = (hist || []).map(p => p.count);
            const ch = new Chart(cv, {
                type: 'bar',
                data: { labels, datasets: [{ data, backgroundColor: VIZ[3] }] },
                options: { plugins: { legend: { display: false } }, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } },
            });
            this.register(elId, ch);
        },
        renderCoverageTimeline(elId, periods) {
            const cv = this.ensureCanvas(elId); if (!cv || !window.Chart) return;
            const labels = (periods || []).map(p => p.bucket);
            const data   = (periods || []).map(p => p.coverage_pct);
            const ch = new Chart(cv, {
                type: 'bar',
                data: { labels, datasets: [{ data, backgroundColor: data.map(v => v >= 80 ? 'hsl(142 71% 45%)' : v >= 50 ? 'hsl(38 92% 50%)' : 'hsl(0 84% 60%)') }] },
                options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => c.parsed.y + '% coverage' } } }, maintainAspectRatio: false, scales: { y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } } } },
            });
            this.register(elId, ch);
        },

        downloadCsv() {
            if (!this.detail.id) return;
            const a = document.createElement('a');
            a.href = C.routes.export + '/' + this.detail.id + '/export';
            a.download = '';
            a.rel = 'noopener';
            document.body.appendChild(a); a.click(); setTimeout(() => a.remove(), 0);
            this.toast('success', 'Export started', 'CSV download will begin shortly.');
        },

        // ─── small helpers ──────────────────────────────────────────
        formatNum(n) {
            if (n === null || n === undefined) return '—';
            const v = Number(n);
            if (!Number.isFinite(v)) return String(n);
            if (Math.abs(v) >= 1000) return v.toLocaleString();
            return Math.round(v * 100) / 100;
        },
        formatDate(s) { return s ? String(s).substring(0, 10) : ''; },
        statusBadge(s) {
            switch ((s || '').toUpperCase()) {
                case 'PUBLISHED': return 'badge-success';
                case 'DRAFT':     return 'badge-warning';
                case 'RETIRED':   return 'badge-outline';
                default:          return 'badge-outline';
            }
        },
        statusLabel(s) {
            const map = { PUBLISHED: 'Published', DRAFT: 'Draft', RETIRED: 'Retired', ARCHIVED: 'Archived' };
            return map[(s || '').toUpperCase()] || (s || '');
        },
        freqLabel(s) {
            const map = { DAILY: 'Daily', WEEKLY: 'Weekly', MONTHLY: 'Monthly', QUARTERLY: 'Quarterly', AD_HOC: 'Ad-hoc', EVENT: 'Event' };
            return map[(s || '').toUpperCase()] || (s || '');
        },
        trendBadge(d) {
            return { rising: 'badge-success', falling: 'badge-danger', stable: 'badge-outline', insufficient_data: 'badge-secondary' }[d] || 'badge-outline';
        },
        trendArrow(d) {
            return { rising: '↗', falling: '↘', stable: '→', insufficient_data: '·' }[d] || '·';
        },
        trendTextClass(d) {
            return { rising: 'text-success', falling: 'text-critical', stable: 'text-muted-foreground', insufficient_data: 'text-muted-foreground' }[d] || '';
        },
        toast(variant, title, body) {
            const prev = this.flash?.timer;
            if (prev) clearTimeout(prev);
            this.flash = { open: true, variant, title, body, timer: null };
            this.flash.timer = setTimeout(() => { this.flash.open = false; }, 2800);
        },
    };
}
</script>
@endpush
@endsection
