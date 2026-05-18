{{-- ============================================================================
  Admin · Governance · Reminders & Retry  (gov-reminders)
  ----------------------------------------------------------------------------
  Future-facing counterpart to Delivery Audit. What WILL fire / is suppressed
  now / is queued for retry.

    1. KPI strip   (overdue · due-24h · blockers · retry · suppressions)
    2. 24-hour horizon timeline   (hourly bar with closure-blockers overlay)
    3. Pressure by action_code    (stacked overdue vs due-24h)
    4. Retry pyramid              (per retry_count bucket 0..3 + exhausted)
    5. Suppression state table    (template × active-now · window dial)
    6. Contact freshness heatmap  (level × 24h/7d/stale/never)
    7. Tabs — Followups · Retry queue · Suppressions
============================================================================ --}}
@extends('admin.layout')

@section('crumb', 'Governance')
@section('title', $page_title)

@php
    /** @var array $coach */
    $coach = $coach ?? \App\Support\Governance\CoachManifest::forView('reminders');
@endphp

@section('content')

{{-- v4 Governance coach overlay — sibling scope. --}}
@include('admin.governance._partials.coach-overlay', ['coach' => $coach, 'viewKey' => 'reminders'])

<div x-data="remindersPage()" x-init="boot()" class="space-y-5">

    <section class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Governance · Forward horizon</p>
            <h2 class="display-md mt-1">Reminders &amp; Retry</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-xl">
                Scheduled follow-up reminders · 15 per-template suppression windows ·
                retry-failed cron (every 15 min) · <span class="font-mono">FOLLOWUP_DUE</span>
                / <span class="font-mono">FOLLOWUP_OVERDUE</span>.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="btn btn-brand btn-sm" @click="refreshAll()">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span class="hidden sm:inline">Refresh</span>
            </button>
        </div>
    </section>

    {{-- ── KPI strip ─────────────────────────────────────────────────── --}}
    <section class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
        <div class="kpi kpi-glow">
            <p class="kpi-label">Overdue followups</p>
            <p class="kpi-value tabular-nums text-critical" x-text="summary ? summary.followups.overdue : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">due in the past · action required</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Due in 24h</p>
            <p class="kpi-value tabular-nums" x-text="summary ? summary.followups.due_24h : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">
                <span x-text="summary ? summary.followups.pending : '—'"></span> pending total
            </p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Closure blockers</p>
            <p class="kpi-value tabular-nums text-warning" x-text="summary ? summary.followups.blocks_closure : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">alerts cannot close until cleared</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Retry queue</p>
            <div class="flex items-baseline gap-3 mt-1">
                <p class="kpi-value tabular-nums" x-text="summary ? summary.retry.total : '—'"></p>
                <span class="text-muted-foreground">·</span>
                <p class="kpi-value tabular-nums text-muted-foreground" x-text="summary ? summary.retry.exhausted : '—'"></p>
            </div>
            <p class="text-[11px] text-muted-foreground mt-1">retryable · exhausted (≥ 4)</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Active suppressions</p>
            <p class="kpi-value tabular-nums" x-text="summary ? summary.suppressions.reduce((a,b)=>a+b.n,0) : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">
                <span x-text="summary ? summary.template_windows.length : '—'"></span> templates configured
            </p>
        </div>
    </section>

    {{-- ── 24h horizon + pressure + retry pyramid ────────────────────── --}}
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="card xl:col-span-2">
            <div class="card-header !pb-2">
                <p class="card-title">Next 24 hours · due horizon</p>
                <p class="card-description">Scheduled followups by hour · red slice = closure-blocking actions.</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading.summary"><div class="skeleton h-44 w-full"></div></template>
                <template x-if="!loading.summary && summary">
                    <div>
                        <svg :viewBox="'0 0 ' + horizonW + ' 170'" preserveAspectRatio="none"
                             class="w-full h-44" role="img" aria-label="24h followup horizon">
                            <template x-for="y in [0.25, 0.5, 0.75]" :key="'hz-gl-' + y">
                                <line :x1="0" :x2="horizonW" :y1="170 - (170 * y)" :y2="170 - (170 * y)"
                                      stroke="hsl(var(--border))" stroke-width="0.5" stroke-dasharray="2 3"/>
                            </template>
                            <template x-for="(row, i) in summary.horizon_24h" :key="'hz-' + i">
                                <g>
                                    <rect :x="i * (horizonW / 24)" :y="170 - barH(row.n, horizonMax)"
                                          :width="(horizonW / 24) - 2"
                                          :height="barH(row.n, horizonMax)"
                                          :fill="'hsl(var(--brand)/0.65)'" rx="2"/>
                                    <rect :x="i * (horizonW / 24)" :y="170 - barH(row.blockers, horizonMax)"
                                          :width="(horizonW / 24) - 2"
                                          :height="barH(row.blockers, horizonMax)"
                                          :fill="'hsl(var(--critical))'" rx="2"/>
                                </g>
                            </template>
                        </svg>
                        <div class="flex items-center justify-between mt-1 text-[10.5px] font-mono text-muted-foreground tabular-nums">
                            <span>now</span><span>+6h</span><span>+12h</span><span>+18h</span><span>+24h</span>
                        </div>
                        <div class="flex items-center gap-4 mt-2 text-[11px] text-muted-foreground">
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-brand"></span> Due</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-critical"></span> Blocks closure</span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="card">
            <div class="card-header !pb-2">
                <p class="card-title">Retry pyramid</p>
                <p class="card-description">Failed sends by retry_count · 4 = exhausted.</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading.summary"><div class="skeleton h-36 w-full"></div></template>
                <template x-if="!loading.summary && summary">
                    <ul class="space-y-2">
                        <template x-for="row in summary.retry.buckets" :key="'rp-' + row.retry_count">
                            <li>
                                <div class="flex justify-between text-[11.5px] mb-1">
                                    <span class="font-mono">retry #<span x-text="row.retry_count"></span></span>
                                    <span class="tabular-nums text-muted-foreground" x-text="row.n"></span>
                                </div>
                                <div class="h-2 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full rounded-full"
                                         :style="'width:' + pctOfMax(row.n, retryMax()) + '%; background:' + retryColor(row.retry_count)"></div>
                                </div>
                            </li>
                        </template>
                        <li class="pt-1 border-t mt-1">
                            <div class="flex justify-between text-[11.5px] mb-1">
                                <span class="font-mono text-muted-foreground">exhausted (≥4)</span>
                                <span class="tabular-nums text-muted-foreground" x-text="summary.retry.exhausted"></span>
                            </div>
                            <div class="h-2 rounded-full bg-muted overflow-hidden">
                                <div class="h-full rounded-full bg-muted-foreground/40"
                                     :style="'width:' + pctOfMax(summary.retry.exhausted, Math.max(retryMax(), summary.retry.exhausted)) + '%'"></div>
                            </div>
                        </li>
                        <li>
                            <div class="flex justify-between text-[11.5px] mb-1">
                                <span class="font-mono text-info">queued · will send</span>
                                <span class="tabular-nums text-muted-foreground" x-text="summary.retry.queued"></span>
                            </div>
                            <div class="h-2 rounded-full bg-muted overflow-hidden">
                                <div class="h-full rounded-full bg-info"
                                     :style="'width:' + pctOfMax(summary.retry.queued, Math.max(retryMax(), summary.retry.queued, 1)) + '%'"></div>
                            </div>
                        </li>
                    </ul>
                </template>
            </div>
        </div>
    </section>

    {{-- ── Pressure by action_code --}}
    <section class="card">
        <div class="card-header !pb-2">
            <p class="card-title">Pressure by action code</p>
            <p class="card-description">Open followups ranked by overdue first, then due-in-24h.</p>
        </div>
        <div class="card-content !pt-0">
            <template x-if="loading.summary"><div class="skeleton h-28 w-full"></div></template>
            <template x-if="!loading.summary && summary && summary.pressure.length">
                <ul class="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-2">
                    <template x-for="row in summary.pressure" :key="'pr-' + row.action_code">
                        <li>
                            <button type="button" class="w-full text-left group"
                                    @click="setFilterAction(row.action_code)"
                                    :aria-label="'Filter followups by ' + row.action_code">
                                <div class="flex justify-between text-[11.5px] mb-1">
                                    <span class="font-mono truncate group-hover:text-brand-ink" x-text="row.action_code"></span>
                                    <span class="tabular-nums text-muted-foreground">
                                        <span class="text-critical font-semibold" x-text="row.overdue"></span>
                                        <span class="mx-1">·</span>
                                        <span x-text="row.due_24h"></span>
                                    </span>
                                </div>
                                <div class="h-2 rounded-full bg-muted overflow-hidden flex">
                                    <div class="h-full bg-critical"
                                         :style="'width:' + stackPct(row.overdue, Math.max(row.total, 1)) + '%'"></div>
                                    <div class="h-full bg-warning"
                                         :style="'width:' + stackPct(row.due_24h, Math.max(row.total, 1)) + '%'"></div>
                                    <div class="h-full bg-brand/70"
                                         :style="'width:' + stackPct(row.total - row.overdue - row.due_24h, Math.max(row.total, 1)) + '%'"></div>
                                </div>
                            </button>
                        </li>
                    </template>
                </ul>
            </template>
            <template x-if="!loading.summary && summary && !summary.pressure.length">
                <div class="empty-state !py-8">
                    <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                    <p class="text-sm font-medium">No open followups across any action code.</p>
                </div>
            </template>
        </div>
    </section>

    {{-- ── Contact freshness + suppression window dial ───────────────── --}}
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        <div class="card xl:col-span-2">
            <div class="card-header !pb-2">
                <p class="card-title">Contact freshness</p>
                <p class="card-description">Per-level <span class="font-mono">last_notified_at</span> bucket — 24h (green) · 7d (brand) · stale (warning) · never (muted).</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading.summary"><div class="skeleton h-40 w-full"></div></template>
                <template x-if="!loading.summary && summary && summary.freshness.length">
                    <ul class="space-y-2.5">
                        <template x-for="row in summary.freshness" :key="'fr-' + row.level">
                            <li>
                                <div class="flex justify-between text-[11.5px] mb-1">
                                    <span class="font-mono" x-text="row.level"></span>
                                    <span class="tabular-nums text-muted-foreground"
                                          x-text="'total ' + row.total"></span>
                                </div>
                                <div class="h-3 rounded-full bg-muted overflow-hidden flex">
                                    <div class="h-full bg-success"  :style="'width:' + stackPct(row.fresh_24h, row.total) + '%'" :title="row.fresh_24h + ' fresh 24h'"></div>
                                    <div class="h-full bg-brand"    :style="'width:' + stackPct(row.fresh_7d,  row.total) + '%'" :title="row.fresh_7d + ' within 7d'"></div>
                                    <div class="h-full bg-warning"  :style="'width:' + stackPct(row.stale,     row.total) + '%'" :title="row.stale + ' stale'"></div>
                                    <div class="h-full bg-muted-foreground/30" :style="'width:' + stackPct(row.never, row.total) + '%'" :title="row.never + ' never'"></div>
                                </div>
                                <div class="flex flex-wrap gap-x-3 gap-y-0.5 mt-1 text-[10.5px] text-muted-foreground tabular-nums">
                                    <span>24h · <span x-text="row.fresh_24h"></span></span>
                                    <span>7d · <span x-text="row.fresh_7d"></span></span>
                                    <span class="text-warning">stale · <span x-text="row.stale"></span></span>
                                    <span>never · <span x-text="row.never"></span></span>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </div>

        <div class="card">
            <div class="card-header !pb-2">
                <p class="card-title">Suppression windows</p>
                <p class="card-description">Per-template anti-spam cooldown (minutes).</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading.summary"><div class="skeleton h-40 w-full"></div></template>
                <template x-if="!loading.summary && summary">
                    <ul class="space-y-1.5">
                        <template x-for="row in summary.template_windows" :key="'tw-' + row.template_code">
                            <li>
                                <div class="flex justify-between text-[11.5px] mb-1">
                                    <span class="font-mono truncate pr-3" x-text="row.template_code"></span>
                                    <span class="tabular-nums text-muted-foreground">
                                        <span x-text="row.window_min"></span><span class="ml-0.5">m</span>
                                    </span>
                                </div>
                                <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full rounded-full bg-info"
                                         :style="'width:' + pctOfMax(row.window_min, 1440) + '%'"></div>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </div>
    </section>

    {{-- ── Tabs: Followups · Retry · Suppressions ─────────────────────── --}}
    <section class="card">
        <div class="card-content !p-0">
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto">
                        <template x-for="t in tabs" :key="t.key">
                            <button type="button" class="tabs-trigger flex-1 sm:flex-none"
                                    :data-state="activeTab === t.key ? 'active' : 'inactive'"
                                    @click="setTab(t.key)">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="tabCount(t.key)"></span>
                            </button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <template x-if="activeTab === 'followups'">
                        <div class="flex flex-wrap items-center gap-2">
                            <label for="gov-rem-filter-status" class="sr-only">Followup lens</label>
                            <select id="gov-rem-filter-status" class="select w-auto !h-8 text-xs"
                                    x-model="filters.status" @change="loadFollowups()">
                                <option value="PENDING">Open (pending)</option>
                                <option value="OVERDUE">Overdue</option>
                                <option value="DUE_24H">Due in 24h</option>
                                <option value="BLOCKERS">Closure blockers</option>
                                <option value="ALL">All (incl. closed)</option>
                            </select>
                            <div class="relative w-full sm:w-64">
                                <svg class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 21l-4.35-4.35M11 19a8 8 0 110-16 8 8 0 010 16z"/></svg>
                                <label for="gov-rem-search" class="sr-only">Search followups</label>
                                <input id="gov-rem-search" type="search" class="input pl-8"
                                       placeholder="Search alert, action, notes…"
                                       x-model.debounce.300ms="filters.q" @input="loadFollowups()">
                            </div>
                        </div>
                    </template>
                    <template x-if="activeTab === 'suppressions'">
                        <div class="flex items-center gap-2">
                            <label for="gov-rem-supp-active" class="label text-[11.5px]">
                                <input id="gov-rem-supp-active" type="checkbox" class="mr-1"
                                       x-model="suppActiveOnly"> Active only
                            </label>
                        </div>
                    </template>
                </div>
                <template x-if="activeTab === 'followups' && filters.action_code">
                    <div class="-mt-1">
                        <span class="chip chip-on">
                            action: <span class="font-mono" x-text="filters.action_code"></span>
                            <button type="button" class="ml-1" @click="filters.action_code=''; loadFollowups()" aria-label="Clear action filter">×</button>
                        </span>
                    </div>
                </template>
            </div>

            {{-- Followups tab --}}
            <div x-show="activeTab === 'followups'" x-cloak>
                <div class="table-wrap !rounded-none !border-0 border-t-0">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">Due</th>
                                <th class="table-head-th">Action</th>
                                <th class="table-head-th hidden md:table-cell">Alert</th>
                                <th class="table-head-th hidden lg:table-cell">Scope</th>
                                <th class="table-head-th">Flags</th>
                            </tr>
                        </thead>
                        <tbody class="table-body">
                            <template x-if="loading.followups">
                                <tr><td colspan="5" class="table-cell text-center py-12">
                                    <div class="inline-flex items-center gap-2 text-muted-foreground text-sm">
                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-18 0"/></svg>
                                        Loading followups…
                                    </div>
                                </td></tr>
                            </template>
                            <template x-if="!loading.followups && followups.rows.length === 0">
                                <tr><td colspan="5" class="table-cell">
                                    <div class="empty-state">
                                        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                        <p class="text-sm font-medium">No followups match this lens.</p>
                                    </div>
                                </td></tr>
                            </template>
                            <template x-for="row in followups.rows" :key="'fu-' + row.id">
                                <tr class="table-row">
                                    <td class="table-cell">
                                        <div class="text-[12px] font-mono tabular-nums"
                                             :class="row.overdue ? 'text-critical' : 'text-foreground'"
                                             x-text="fmtTime(row.due_at)"></div>
                                        <div class="text-[10.5px] text-muted-foreground" x-text="row.status"></div>
                                    </td>
                                    <td class="table-cell">
                                        <div class="text-[12.5px] font-medium truncate max-w-[18rem]" x-text="row.action_label"></div>
                                        <div class="text-[11px] text-muted-foreground font-mono" x-text="row.action_code"></div>
                                    </td>
                                    <td class="table-cell hidden md:table-cell text-[11.5px] font-mono"
                                        x-text="row.alert_code || (row.alert_id ? '#' + row.alert_id : '—')"></td>
                                    <td class="table-cell hidden lg:table-cell text-[11.5px] font-mono"
                                        x-text="row.poe_code || row.district_code || '—'"></td>
                                    <td class="table-cell">
                                        <div class="flex flex-wrap gap-1">
                                            <span x-show="row.overdue" class="badge badge-critical">OVERDUE</span>
                                            <span x-show="row.blocks_closure" class="badge badge-warning">BLOCKS</span>
                                            <span x-show="row.assigned_to_role" class="badge badge-outline font-mono" x-text="row.assigned_to_role"></span>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between p-3 border-t text-[11.5px] text-muted-foreground">
                    <span x-text="followups.total + ' rows · page ' + followups.page + ' / ' + Math.max(followups.pages, 1)"></span>
                    <div class="flex gap-1.5">
                        <button type="button" class="btn btn-outline btn-xs"
                                :disabled="followups.page <= 1" @click="gotoFollowupsPage(followups.page - 1)">Prev</button>
                        <button type="button" class="btn btn-outline btn-xs"
                                :disabled="followups.page >= followups.pages" @click="gotoFollowupsPage(followups.page + 1)">Next</button>
                    </div>
                </div>
            </div>

            {{-- Retry queue tab --}}
            <div x-show="activeTab === 'retry'" x-cloak>
                <div class="table-wrap !rounded-none !border-0 border-t-0">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">Template</th>
                                <th class="table-head-th">Recipient</th>
                                <th class="table-head-th hidden md:table-cell">Last error</th>
                                <th class="table-head-th text-right">Retries</th>
                                <th class="table-head-th hidden md:table-cell">Failed at</th>
                            </tr>
                        </thead>
                        <tbody class="table-body">
                            <template x-if="loading.retry">
                                <tr><td colspan="5" class="table-cell text-center py-12">
                                    <div class="inline-flex items-center gap-2 text-muted-foreground text-sm">
                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-18 0"/></svg>
                                        Loading retry queue…
                                    </div>
                                </td></tr>
                            </template>
                            <template x-if="!loading.retry && retryRows.length === 0">
                                <tr><td colspan="5" class="table-cell">
                                    <div class="empty-state">
                                        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                        <p class="text-sm font-medium">Retry queue is empty.</p>
                                    </div>
                                </td></tr>
                            </template>
                            <template x-for="row in retryRows" :key="'rt-' + row.id">
                                <tr class="table-row">
                                    <td class="table-cell">
                                        <div class="font-mono text-[12px]" x-text="row.template_code"></div>
                                        <div class="text-[10.5px] text-muted-foreground font-mono" x-text="row.channel"></div>
                                    </td>
                                    <td class="table-cell">
                                        <div class="text-[12.5px] font-medium truncate max-w-[14rem]" x-text="row.recipient || '—'"></div>
                                        <div class="text-[10.5px] text-muted-foreground truncate max-w-[14rem]"
                                             x-text="row.level || (row.poe_code || row.district_code || '')"></div>
                                    </td>
                                    <td class="table-cell hidden md:table-cell text-[11px] text-critical truncate max-w-[22rem]"
                                        x-text="row.error_message || '—'"></td>
                                    <td class="table-cell text-right">
                                        <div class="inline-flex items-center gap-1">
                                            <div class="h-1.5 w-16 rounded-full bg-muted overflow-hidden">
                                                <div class="h-full rounded-full bg-warning"
                                                     :style="'width:' + (row.retry_count / 3 * 100) + '%'"></div>
                                            </div>
                                            <span class="tabular-nums text-[11.5px] text-muted-foreground" x-text="row.retry_count + '/4'"></span>
                                        </div>
                                    </td>
                                    <td class="table-cell hidden md:table-cell font-mono text-[11px] text-muted-foreground" x-text="fmtTime(row.failed_at)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Suppressions tab --}}
            <div x-show="activeTab === 'suppressions'" x-cloak>
                <div class="table-wrap !rounded-none !border-0 border-t-0">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">Template</th>
                                <th class="table-head-th hidden md:table-cell">Entity</th>
                                <th class="table-head-th">Contact</th>
                                <th class="table-head-th">Last sent</th>
                                <th class="table-head-th">Window</th>
                            </tr>
                        </thead>
                        <tbody class="table-body">
                            <template x-if="loading.supp">
                                <tr><td colspan="5" class="table-cell text-center py-12">
                                    <div class="inline-flex items-center gap-2 text-muted-foreground text-sm">
                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-18 0"/></svg>
                                        Loading suppressions…
                                    </div>
                                </td></tr>
                            </template>
                            <template x-if="!loading.supp && visibleSuppRows().length === 0">
                                <tr><td colspan="5" class="table-cell">
                                    <div class="empty-state">
                                        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                                        <p class="text-sm font-medium">No suppression rows match this lens.</p>
                                    </div>
                                </td></tr>
                            </template>
                            <template x-for="row in visibleSuppRows()" :key="'sp-' + row.id">
                                <tr class="table-row">
                                    <td class="table-cell font-mono text-[11.5px]" x-text="row.template_code"></td>
                                    <td class="table-cell hidden md:table-cell text-[11.5px] font-mono"
                                        x-text="(row.related_entity_type || '—') + (row.related_entity_id ? '#' + row.related_entity_id : '')"></td>
                                    <td class="table-cell">
                                        <div class="text-[12.5px] font-medium truncate max-w-[14rem]" x-text="row.contact_name || ('contact#' + row.contact_id)"></div>
                                        <div class="text-[10.5px] text-muted-foreground font-mono" x-text="row.contact_level || ''"></div>
                                    </td>
                                    <td class="table-cell font-mono text-[11px] text-muted-foreground" x-text="fmtTime(row.last_sent_at)"></td>
                                    <td class="table-cell">
                                        <div class="flex items-center gap-2">
                                            <div class="h-1.5 w-20 rounded-full bg-muted overflow-hidden">
                                                <div class="h-full rounded-full"
                                                     :class="row.is_active ? 'bg-warning' : 'bg-success'"
                                                     :style="'width:' + (row.is_active ? (100 - suppProgress(row)) : 100) + '%'"></div>
                                            </div>
                                            <span class="text-[11px] tabular-nums text-muted-foreground"
                                                  x-text="row.is_active ? (row.minutes_remaining + 'm left') : 'clear · ' + row.window_min + 'm'"></span>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</div>

@push('scripts')
<script>
    const GOV_REM_URLS = {
        summary:      @json(route('admin.governance.reminders.summary')),
        followups:    @json(route('admin.governance.reminders.followups')),
        retry:        @json(route('admin.governance.reminders.retry')),
        suppressions: @json(route('admin.governance.reminders.suppressions')),
    };

    function remindersPage() {
        return {
            activeTab: 'followups',
            tabs: [
                { key: 'followups',    label: 'Followups'    },
                { key: 'retry',        label: 'Retry queue'  },
                { key: 'suppressions', label: 'Suppressions' },
            ],
            loading:   { summary: true, followups: true, retry: false, supp: false },
            summary:   null,
            followups: { rows: [], page: 1, per_page: 50, total: 0, pages: 0 },
            retryRows: [],
            suppRows:  [],
            suppActiveOnly: true,
            filters:   { q: '', status: 'PENDING', action_code: '', poe_code: '' },
            horizonW: 600,
            horizonMax: 1,

            boot() {
                this.refreshAll();
            },

            refreshAll() {
                this.loadSummary();
                this.loadFollowups();
                if (this.activeTab === 'retry')        this.loadRetry();
                if (this.activeTab === 'suppressions') this.loadSuppressions();
            },

            setTab(k) {
                this.activeTab = k;
                if (k === 'retry' && this.retryRows.length === 0)       this.loadRetry();
                if (k === 'suppressions' && this.suppRows.length === 0) this.loadSuppressions();
            },

            async loadSummary() {
                this.loading.summary = true;
                try {
                    const r = await fetch(GOV_REM_URLS.summary, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) {
                        this.summary = j.data;
                        this.horizonW = 600;
                        this.horizonMax = Math.max(1, ...this.summary.horizon_24h.map(p => p.n));
                    }
                } catch (e) {}
                this.loading.summary = false;
            },

            async loadFollowups() {
                this.loading.followups = true;
                try {
                    const p = new URLSearchParams({
                        page: String(this.followups.page),
                        per_page: String(this.followups.per_page),
                        status: this.filters.status || 'PENDING',
                    });
                    if (this.filters.q)           p.set('q', this.filters.q);
                    if (this.filters.action_code) p.set('action_code', this.filters.action_code);
                    if (this.filters.poe_code)    p.set('poe_code', this.filters.poe_code);
                    const r = await fetch(GOV_REM_URLS.followups + '?' + p.toString(), { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) {
                        this.followups.rows     = j.data.rows;
                        this.followups.page     = j.data.page;
                        this.followups.per_page = j.data.per_page;
                        this.followups.total    = j.data.total;
                        this.followups.pages    = j.data.pages;
                    }
                } catch (e) {}
                this.loading.followups = false;
            },

            async loadRetry() {
                this.loading.retry = true;
                try {
                    const r = await fetch(GOV_REM_URLS.retry, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.retryRows = j.data.rows;
                } catch (e) {}
                this.loading.retry = false;
            },

            async loadSuppressions() {
                this.loading.supp = true;
                try {
                    const r = await fetch(GOV_REM_URLS.suppressions, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.suppRows = j.data.rows;
                } catch (e) {}
                this.loading.supp = false;
            },

            visibleSuppRows() {
                return this.suppActiveOnly ? this.suppRows.filter(r => r.is_active) : this.suppRows;
            },

            gotoFollowupsPage(p) {
                if (p < 1 || p > this.followups.pages) return;
                this.followups.page = p; this.loadFollowups();
            },

            setFilterAction(code) {
                this.filters.action_code = code;
                this.filters.status = 'PENDING';
                this.activeTab = 'followups';
                this.followups.page = 1;
                this.loadFollowups();
            },

            tabCount(k) {
                if (!this.summary) return 0;
                if (k === 'followups')    return this.summary.followups.pending;
                if (k === 'retry')        return this.summary.retry.total;
                if (k === 'suppressions') return this.summary.suppressions.reduce((a, b) => a + b.n, 0);
                return 0;
            },

            retryMax() {
                if (!this.summary || !this.summary.retry.buckets.length) return 1;
                return Math.max(1, ...this.summary.retry.buckets.map(r => r.n));
            },
            retryColor(retry) {
                return ['hsl(var(--brand))', 'hsl(var(--info))', 'hsl(var(--warning))', 'hsl(var(--critical))'][retry] || 'hsl(var(--muted-foreground))';
            },

            suppProgress(row) {
                if (!row.window_min) return 0;
                const elapsed = row.window_min - row.minutes_remaining;
                return Math.max(0, Math.min(100, elapsed / row.window_min * 100));
            },

            barH(v, max) {
                return Math.max(0, Math.min(170, (v / Math.max(max, 1)) * 165));
            },

            stackPct(part, whole) { return !whole || whole <= 0 ? 0 : Math.max(0, Math.min(100, part / whole * 100)); },
            pctOfMax(v, max)      { return max > 0 ? Math.round(v / max * 100) : 0; },

            fmtTime(s) {
                if (!s) return '—';
                try { return new Date(s.replace(' ', 'T')).toISOString().replace('T', ' ').slice(0, 16); }
                catch { return s; }
            },
        };
    }
</script>
@endpush
@endsection
