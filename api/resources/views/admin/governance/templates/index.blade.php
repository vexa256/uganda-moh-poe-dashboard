{{-- Admin · Governance · Notification Templates (gov-templates) --}}
@extends('admin.layout')
@section('crumb', 'Governance')
@section('title', $page_title)

@php
    /** @var array $coach */
    $coach = $coach ?? \App\Support\Governance\CoachManifest::forView('templates');
@endphp

@section('content')

{{-- v4 Governance coach overlay — sibling scope. --}}
@include('admin.governance._partials.coach-overlay', ['coach' => $coach, 'viewKey' => 'templates'])

<div x-data="templatesPage()" x-init="boot()" class="space-y-5">

    <section class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Governance · Notification catalogue</p>
            <h2 class="display-md mt-1">Notification Templates</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-xl">
                15 Mustache-variable templates · per-template suppression windows · 30-day usage stats ·
                <span class="font-mono">is_ai_enhanced</span> (currently inert).
            </p>
        </div>
        <button type="button" class="btn btn-brand btn-sm" @click="loadData()">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
            <span class="hidden sm:inline">Refresh</span>
        </button>
    </section>

    {{-- KPI strip --}}
    <section class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="kpi kpi-glow">
            <p class="kpi-label">Templates</p>
            <div class="flex items-baseline gap-3 mt-1">
                <p class="kpi-value tabular-nums" x-text="rows.length || '—'"></p>
                <span class="text-muted-foreground">·</span>
                <p class="kpi-value tabular-nums text-muted-foreground" x-text="byActive.active || 0"></p>
            </div>
            <p class="text-[11px] text-muted-foreground mt-1">total · active</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Sent · 30 days</p>
            <p class="kpi-value tabular-nums" x-text="totals.sent"></p>
            <p class="text-[11px] text-muted-foreground mt-1">across all templates</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Failed · 30 days</p>
            <p class="kpi-value tabular-nums text-critical" x-text="totals.failed"></p>
            <p class="text-[11px] text-muted-foreground mt-1">
                <span x-text="totals.successPct + '%'"></span> attempt success
            </p>
        </div>
        <div class="kpi">
            <p class="kpi-label">AI-enhanced tag</p>
            <div class="flex items-baseline gap-3 mt-1">
                <p class="kpi-value tabular-nums text-info" x-text="byAi.ai || 0"></p>
                <span class="text-muted-foreground">·</span>
                <p class="kpi-value tabular-nums text-muted-foreground" x-text="byAi.plain || 0"></p>
            </div>
            <p class="text-[11px] text-muted-foreground mt-1">inert flag · see §B.5</p>
        </div>
    </section>

    {{-- Usage ranking + suppression window dial --}}
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="card xl:col-span-2">
            <div class="card-header !pb-2">
                <p class="card-title">30-day usage</p>
                <p class="card-description">Stacked sent / failed / skipped per template · click to preview.</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading"><div class="skeleton h-40 w-full"></div></template>
                <template x-if="!loading && rows.length">
                    <ul class="space-y-1.5">
                        <template x-for="row in sortedByUsage()" :key="'u-' + row.id">
                            <li>
                                <button type="button" class="w-full text-left group" @click="openDetail(row)">
                                    <div class="flex justify-between text-[11.5px] mb-1">
                                        <span class="font-mono group-hover:text-brand-ink truncate" x-text="row.template_code"></span>
                                        <span class="tabular-nums text-muted-foreground">
                                            <span x-text="row.usage_30d"></span>
                                            <span class="ml-1" x-text="row.success_pct + '%'"></span>
                                        </span>
                                    </div>
                                    <div class="h-2 rounded-full bg-muted overflow-hidden flex">
                                        <div class="h-full bg-brand"    :style="'width:' + pctOf(row.sent_30d, maxUsage()) + '%'"></div>
                                        <div class="h-full bg-critical" :style="'width:' + pctOf(row.failed_30d, maxUsage()) + '%'"></div>
                                        <div class="h-full bg-warning"  :style="'width:' + pctOf(row.skipped_30d, maxUsage()) + '%'"></div>
                                    </div>
                                </button>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </div>

        <div class="card">
            <div class="card-header !pb-2">
                <p class="card-title">Suppression windows</p>
                <p class="card-description">Minutes between same-triple sends.</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading"><div class="skeleton h-36 w-full"></div></template>
                <template x-if="!loading && rows.length">
                    <ul class="space-y-1.5">
                        <template x-for="row in sortedByWindow()" :key="'w-' + row.id">
                            <li>
                                <div class="flex justify-between text-[11.5px] mb-1">
                                    <span class="font-mono truncate pr-3" x-text="row.template_code"></span>
                                    <span class="tabular-nums text-muted-foreground"><span x-text="row.suppression_min"></span>m</span>
                                </div>
                                <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full rounded-full bg-info"
                                         :style="'width:' + pctOf(row.suppression_min, 1440) + '%'"></div>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </div>
    </section>

    {{-- Template table --}}
    <section class="card">
        <div class="card-content !p-0">
            <div class="p-4 sm:p-5 border-b flex flex-col sm:flex-row gap-3 sm:items-center">
                <div class="tabs-list">
                    <template x-for="t in tabs" :key="t.key">
                        <button type="button" class="tabs-trigger"
                                :data-state="activeTab === t.key ? 'active' : 'inactive'"
                                @click="activeTab = t.key">
                            <span x-text="t.label"></span>
                            <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="tabCount(t.key)"></span>
                        </button>
                    </template>
                </div>
                <div class="flex-1"></div>
                <div class="relative w-full sm:w-72">
                    <svg class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 21l-4.35-4.35M11 19a8 8 0 110-16 8 8 0 010 16z"/></svg>
                    <label for="gov-tpl-search" class="sr-only">Search templates</label>
                    <input id="gov-tpl-search" type="search" class="input pl-8"
                           placeholder="Search template code, subject, var…"
                           x-model.debounce.200ms="search">
                </div>
            </div>
            <div class="table-wrap !rounded-none !border-0 border-t-0">
                <table class="table">
                    <thead class="table-head">
                        <tr>
                            <th class="table-head-th">Template</th>
                            <th class="table-head-th hidden md:table-cell">Subject</th>
                            <th class="table-head-th">Window</th>
                            <th class="table-head-th text-right">Usage 30d</th>
                            <th class="table-head-th">Flags</th>
                        </tr>
                    </thead>
                    <tbody class="table-body">
                        <template x-if="loading">
                            <tr><td colspan="5" class="table-cell text-center py-12">
                                <div class="inline-flex items-center gap-2 text-muted-foreground text-sm">
                                    <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-18 0"/></svg>
                                    Loading templates…
                                </div>
                            </td></tr>
                        </template>
                        <template x-if="!loading && visibleRows().length === 0">
                            <tr><td colspan="5" class="table-cell">
                                <div class="empty-state">
                                    <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5"/></svg>
                                    <p class="text-sm font-medium">No templates match.</p>
                                </div>
                            </td></tr>
                        </template>
                        <template x-for="row in visibleRows()" :key="row.id">
                            <tr class="table-row cursor-pointer" @click="openDetail(row)">
                                <td class="table-cell">
                                    <div class="font-mono text-[12.5px]" x-text="row.template_code"></div>
                                    <div class="text-[10.5px] text-muted-foreground" x-text="row.channel + ' · ' + row.vars.length + ' vars'"></div>
                                </td>
                                <td class="table-cell hidden md:table-cell">
                                    <div class="text-[12.5px] truncate max-w-[24rem]" x-text="row.subject_template"></div>
                                </td>
                                <td class="table-cell">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-12 rounded-full bg-muted overflow-hidden">
                                            <div class="h-full rounded-full bg-info" :style="'width:' + pctOf(row.suppression_min, 1440) + '%'"></div>
                                        </div>
                                        <span class="tabular-nums text-[11.5px] text-muted-foreground" x-text="row.suppression_min + 'm'"></span>
                                    </div>
                                </td>
                                <td class="table-cell text-right">
                                    <div class="tabular-nums text-[12.5px]" x-text="row.usage_30d"></div>
                                    <div class="text-[10.5px] text-muted-foreground" x-text="row.success_pct + '% ok'"></div>
                                </td>
                                <td class="table-cell">
                                    <div class="flex flex-wrap gap-1">
                                        <span class="badge" :class="row.is_active ? 'badge-success' : 'badge-outline'" x-text="row.is_active ? 'ACTIVE' : 'OFF'"></span>
                                        <span x-show="row.is_ai_enhanced" class="badge badge-info">AI</span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- Detail + preview drawer --}}
    <template x-if="detail.open">
        <div class="fixed inset-0 z-50 flex"
             x-effect="window.adminLock.set('gov-tpl-detail', detail.open)"
             role="dialog" aria-modal="true" aria-label="Template preview">
            <div class="absolute inset-0 bg-black/45" @click="closeDetail()"></div>
            <aside class="relative ml-auto h-full w-full max-w-2xl bg-background border-l shadow-elevation-5 overflow-y-auto" @click.stop>
                <header class="flex items-center justify-between p-4 border-b">
                    <div class="min-w-0">
                        <p class="eyebrow">Template</p>
                        <p class="font-mono text-[13px] truncate" x-text="detail.row?.template_code"></p>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="btn btn-outline btn-xs" @click="toggleActive()"
                                :disabled="detail.submitting">
                            <span x-text="detail.row?.is_active ? 'Deactivate' : 'Activate'"></span>
                        </button>
                        <button type="button" class="btn btn-ghost btn-icon-xs" @click="closeDetail()" aria-label="Close">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </header>
                <div class="p-4 space-y-4 text-[12.5px]">
                    <div class="tabs-list w-full">
                        <template x-for="t in ['Preview','Source','Stats']" :key="'dt-' + t">
                            <button type="button" class="tabs-trigger flex-1"
                                    :data-state="detail.pane === t ? 'active' : 'inactive'"
                                    @click="detail.pane = t"
                                    x-text="t"></button>
                        </template>
                    </div>

                    <div x-show="detail.pane === 'Preview'">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Subject (rendered)</p>
                        <p class="card !p-3 text-[13px] font-semibold break-words" x-text="preview.subject || '—'"></p>
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mt-3 mb-1">Body (rendered · HTML stripped)</p>
                        <pre class="card !p-3 text-[12px] whitespace-pre-wrap break-words max-h-80 overflow-auto" x-text="stripTags(preview.body_html || '')"></pre>
                    </div>

                    <div x-show="detail.pane === 'Source'">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Subject template</p>
                        <pre class="card !p-3 text-[11.5px] font-mono whitespace-pre-wrap break-words" x-text="detail.row?.subject_template"></pre>
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mt-3 mb-1">Body (HTML)</p>
                        <pre class="card !p-3 text-[11px] font-mono whitespace-pre-wrap break-words max-h-72 overflow-auto" x-text="detail.row?.body_html_template"></pre>
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mt-3 mb-1">Variables</p>
                        <div class="flex flex-wrap gap-1">
                            <template x-for="v in detail.row?.vars || []" :key="'v-' + v">
                                <span class="badge badge-outline font-mono" x-text="v"></span>
                            </template>
                        </div>
                    </div>

                    <div x-show="detail.pane === 'Stats'" class="space-y-3">
                        <dl class="grid grid-cols-[8rem_1fr] gap-y-2">
                            <dt class="text-muted-foreground">Channel</dt>          <dd class="font-mono" x-text="detail.row?.channel"></dd>
                            <dt class="text-muted-foreground">Active</dt>           <dd x-text="detail.row?.is_active ? 'yes' : 'no'"></dd>
                            <dt class="text-muted-foreground">AI-enhanced</dt>      <dd x-text="detail.row?.is_ai_enhanced ? 'yes (inert)' : 'no'"></dd>
                            <dt class="text-muted-foreground">Suppression</dt>      <dd class="tabular-nums" x-text="(detail.row?.suppression_min || 0) + ' min'"></dd>
                            <dt class="text-muted-foreground">Subject length</dt>   <dd class="tabular-nums" x-text="detail.row?.subject_len + ' chars'"></dd>
                            <dt class="text-muted-foreground">Body length</dt>      <dd class="tabular-nums" x-text="detail.row?.body_len + ' chars'"></dd>
                            <dt class="text-muted-foreground">Usage · 30d</dt>      <dd class="tabular-nums" x-text="detail.row?.usage_30d"></dd>
                            <dt class="text-muted-foreground">Sent · 30d</dt>       <dd class="tabular-nums text-success" x-text="detail.row?.sent_30d"></dd>
                            <dt class="text-muted-foreground">Failed · 30d</dt>     <dd class="tabular-nums text-critical" x-text="detail.row?.failed_30d"></dd>
                            <dt class="text-muted-foreground">Success rate</dt>     <dd class="tabular-nums" x-text="(detail.row?.success_pct || 0) + '%'"></dd>
                            <dt class="text-muted-foreground">Last used</dt>        <dd class="font-mono" x-text="detail.row?.last_used || '—'"></dd>
                            <dt class="text-muted-foreground">Applicable levels</dt><dd class="font-mono" x-text="(detail.row?.applicable_levels || []).join(' · ') || '—'"></dd>
                        </dl>
                    </div>
                </div>
            </aside>
        </div>
    </template>
</div>

@push('scripts')
<script>
    const GOV_TPL_URLS = {
        data:        @json(route('admin.governance.templates.data')),
        previewBase: @json(url('/admin/governance/templates')),
    };

    function templatesPage() {
        return {
            loading: true,
            rows: [], byActive: { active: 0, inactive: 0 }, byAi: { ai: 0, plain: 0 },
            search: '',
            activeTab: 'all',
            tabs: [
                { key: 'all',      label: 'All'      },
                { key: 'active',   label: 'Active'   },
                { key: 'inactive', label: 'Inactive' },
                { key: 'ai',       label: 'AI'       },
            ],
            detail: { open: false, row: null, pane: 'Preview', submitting: false },
            preview: { subject: '', body_html: '', body_text: '' },

            async boot() { await this.loadData(); },

            async loadData() {
                this.loading = true;
                try {
                    const r = await fetch(GOV_TPL_URLS.data, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) {
                        this.rows = j.data.rows;
                        this.byActive = j.data.by_active;
                        this.byAi     = j.data.by_ai;
                    }
                } catch (e) {}
                this.loading = false;
            },

            visibleRows() {
                const q = this.search.trim().toLowerCase();
                return this.rows.filter(r => {
                    if (this.activeTab === 'active'   && !r.is_active) return false;
                    if (this.activeTab === 'inactive' && r.is_active)  return false;
                    if (this.activeTab === 'ai'       && !r.is_ai_enhanced) return false;
                    if (!q) return true;
                    return r.template_code.toLowerCase().includes(q)
                        || r.subject_template.toLowerCase().includes(q)
                        || r.vars.some(v => v.toLowerCase().includes(q));
                });
            },
            sortedByUsage()  { return [...this.rows].sort((a, b) => b.usage_30d - a.usage_30d); },
            sortedByWindow() { return [...this.rows].sort((a, b) => b.suppression_min - a.suppression_min); },
            maxUsage() { return Math.max(1, ...this.rows.map(r => r.usage_30d)); },
            pctOf(v, max) { return max > 0 ? Math.round(v / max * 100) : 0; },

            tabCount(k) {
                if (k === 'all')      return this.rows.length;
                if (k === 'active')   return this.byActive.active || 0;
                if (k === 'inactive') return this.byActive.inactive || 0;
                if (k === 'ai')       return this.byAi.ai || 0;
                return 0;
            },

            get totals() {
                const sent   = this.rows.reduce((a, r) => a + r.sent_30d,   0);
                const failed = this.rows.reduce((a, r) => a + r.failed_30d, 0);
                const total  = sent + failed;
                return {
                    sent, failed,
                    successPct: total > 0 ? Math.round(sent / total * 1000) / 10 : 0,
                };
            },

            async openDetail(row) {
                this.detail.row = row;
                this.detail.pane = 'Preview';
                this.detail.open = true;
                this.preview = { subject: '…', body_html: '…', body_text: '' };
                try {
                    const r = await fetch(GOV_TPL_URLS.previewBase + '/' + row.id + '/preview', { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.preview = { subject: j.data.subject, body_html: j.data.body_html, body_text: j.data.body_text };
                } catch (e) {}
            },
            closeDetail() { this.detail.open = false; this.detail.row = null; },

            async toggleActive() {
                if (!this.detail.row) return;
                this.detail.submitting = true;
                try {
                    const r = await fetch(GOV_TPL_URLS.previewBase + '/' + this.detail.row.id + '/toggle', {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        },
                        body: JSON.stringify({ is_active: !this.detail.row.is_active }),
                    });
                    if (r.ok) {
                        this.detail.row.is_active = !this.detail.row.is_active;
                        this.loadData();
                    }
                } catch (e) {}
                this.detail.submitting = false;
            },

            stripTags(html) {
                if (!html) return '';
                const d = document.createElement('div');
                d.innerHTML = html;
                return d.textContent || '';
            },
        };
    }
</script>
@endpush
@endsection
