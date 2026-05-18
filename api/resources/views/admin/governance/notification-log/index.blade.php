{{-- ============================================================================
  Admin · Governance · Delivery Audit  (gov-notif-log)
  ----------------------------------------------------------------------------
  Per-recipient proof of delivery for every SENT / SKIPPED / FAILED / BOUNCED
  notification. Non-cluttered premium layout:

    1. KPI strip  (delivery ratio ring, failure ratio, queued depth, 24h)
    2. Viz grid   (hourly stacked area · status donut · scope · channel split)
    3. Template ranking + Failure reasons + 7×24 heatmap
    4. Tabs       (Feed · Failures-only quick lens)
    5. Detail drawer with full body preview + retry history

  Every chart is inline SVG against theme tokens. No JS chart library.
============================================================================ --}}
@extends('admin.layout')

@section('crumb', 'Governance')
@section('title', $page_title)

@php
    /** @var array $coach */
    $coach = $coach ?? \App\Support\Governance\CoachManifest::forView('notif-log');
@endphp

@section('content')

{{-- v4 Governance coach overlay — sibling scope, never collides with notifLogPage(). --}}
@include('admin.governance._partials.coach-overlay', ['coach' => $coach, 'viewKey' => 'notif-log'])

<div x-data="notifLogPage()" x-init="boot()" class="space-y-5">

    {{-- ── Section intro ─────────────────────────────────────────────── --}}
    <section class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Governance · Audit</p>
            <h2 class="display-md mt-1">Delivery Audit</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-xl">
                <span class="font-mono text-foreground">notification_log</span> · per-recipient delivery proof ·
                <span class="font-mono">SENT</span> / <span class="font-mono">FAILED</span> /
                <span class="font-mono">SKIPPED</span> / <span class="font-mono">BOUNCED</span> ·
                <span class="font-mono">last_error</span> capture.
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="tabs-list">
                <template x-for="opt in windowOptions" :key="opt.h">
                    <button type="button" class="tabs-trigger"
                            :data-state="windowHours === opt.h ? 'active' : 'inactive'"
                            @click="setWindow(opt.h)">
                        <span x-text="opt.label"></span>
                    </button>
                </template>
            </div>
            <a :href="exportHref()" class="btn btn-outline btn-sm" rel="nofollow">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12m0 0l-4-4m4 4l4-4M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>
                <span class="hidden sm:inline">Export CSV</span>
            </a>
            <button type="button" class="btn btn-brand btn-sm" @click="refreshAll()">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span class="hidden sm:inline">Refresh</span>
            </button>
        </div>
    </section>

    {{-- ── KPI strip ─────────────────────────────────────────────────── --}}
    <section class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-5 gap-3">
        <div class="kpi kpi-glow">
            <p class="kpi-label">Delivery ratio</p>
            <div class="flex items-center gap-3 mt-1">
                <div class="ring-gauge h-14 w-14">
                    <svg viewBox="0 0 40 40" class="h-14 w-14 -rotate-90" aria-hidden="true">
                        <circle cx="20" cy="20" r="16" fill="none" stroke="hsl(var(--muted))" stroke-width="4"/>
                        <circle cx="20" cy="20" r="16" fill="none" stroke="hsl(var(--brand))" stroke-width="4"
                                stroke-linecap="round"
                                :stroke-dasharray="100.53"
                                :stroke-dashoffset="summary ? (100.53 - (summary.totals.delivery_pct/100) * 100.53) : 100.53"/>
                    </svg>
                    <div class="ring-gauge-label">
                        <span class="text-[11px] font-bold tabular-nums" x-text="summary ? Math.round(summary.totals.delivery_pct) + '%' : '—'"></span>
                    </div>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-semibold tabular-nums"
                       x-text="summary ? summary.totals.sent + ' / ' + summary.totals.attempted : '—'"></p>
                    <p class="text-[11px] text-muted-foreground">attempted sends</p>
                </div>
            </div>
        </div>

        <div class="kpi">
            <p class="kpi-label">Failed + Bounced</p>
            <p class="kpi-value tabular-nums text-critical" x-text="summary ? (summary.totals.failed + summary.totals.bounced) : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">
                <span x-text="summary ? summary.totals.failure_pct.toFixed(1) + '%' : '—'"></span> of attempts
            </p>
        </div>

        <div class="kpi">
            <p class="kpi-label">Skipped</p>
            <p class="kpi-value tabular-nums text-warning" x-text="summary ? summary.totals.skipped : '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">suppression windows / opt-out</p>
        </div>

        <div class="kpi">
            <p class="kpi-label">Queued · Backlog</p>
            <div class="flex items-baseline gap-3 mt-1">
                <p class="kpi-value tabular-nums" x-text="summary ? summary.operational.queued_depth : '—'"></p>
                <span class="text-muted-foreground">·</span>
                <p class="kpi-value tabular-nums text-muted-foreground" x-text="summary ? summary.operational.fail_backlog : '—'"></p>
            </div>
            <p class="text-[11px] text-muted-foreground mt-1">queued · retryable fails (retry &lt; 4)</p>
        </div>

        <div class="kpi">
            <p class="kpi-label">Last send · last fail</p>
            <div class="mt-1 space-y-0.5">
                <p class="text-[12.5px] tabular-nums text-success">
                    <span class="status-dot status-dot-live inline-block mr-1.5" aria-hidden="true"></span>
                    <span x-text="summary ? fmtTime(summary.operational.last_sent_at) : '—'"></span>
                </p>
                <p class="text-[12.5px] tabular-nums text-critical">
                    <span class="status-dot status-dot-danger inline-block mr-1.5" aria-hidden="true"></span>
                    <span x-text="summary ? fmtTime(summary.operational.last_failed_at) : '—'"></span>
                </p>
            </div>
        </div>
    </section>

    {{-- ── Viz grid ──────────────────────────────────────────────────── --}}
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        {{-- Hourly stacked area (wide) --}}
        <div class="card xl:col-span-2">
            <div class="card-header !pb-2">
                <p class="card-title">Delivery · hourly</p>
                <p class="card-description">Total volume stacked by status — SENT (brand) · FAILED (critical) · SKIPPED (warning) · QUEUED (info).</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading.summary"><div class="skeleton h-44 w-full"></div></template>
                <template x-if="!loading.summary && summary">
                    <div>
                        <svg :viewBox="'0 0 ' + sparkW + ' 170'" preserveAspectRatio="none"
                             class="w-full h-44" role="img" aria-label="Hourly delivery stacked area">
                            <template x-for="y in [0.25, 0.5, 0.75]" :key="'gl-' + y">
                                <line :x1="0" :x2="sparkW" :y1="170 - (170 * y)" :y2="170 - (170 * y)"
                                      stroke="hsl(var(--border))" stroke-width="0.5" stroke-dasharray="2 3"/>
                            </template>
                            {{-- Stacked area paths — painted bottom-up --}}
                            <path :d="stackedArea(summary.hourly, ['sent_n','failed_n','bounced_n','skipped_n','queued_n'], 0, 170)"
                                  fill="hsl(var(--brand)/0.25)"/>
                            <path :d="stackedArea(summary.hourly, ['failed_n','bounced_n','skipped_n','queued_n'], 1, 170)"
                                  fill="hsl(var(--critical)/0.35)"/>
                            <path :d="stackedArea(summary.hourly, ['bounced_n','skipped_n','queued_n'], 2, 170)"
                                  fill="hsl(var(--danger)/0.35)"/>
                            <path :d="stackedArea(summary.hourly, ['skipped_n','queued_n'], 3, 170)"
                                  fill="hsl(var(--warning)/0.40)"/>
                            <path :d="stackedArea(summary.hourly, ['queued_n'], 4, 170)"
                                  fill="hsl(var(--info)/0.35)"/>
                        </svg>
                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 mt-2 text-[11px] text-muted-foreground">
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-brand"></span> Sent</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-critical"></span> Failed</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-danger"></span> Bounced</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-warning"></span> Skipped</span>
                            <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-info"></span> Queued</span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Status donut --}}
        <div class="card">
            <div class="card-header !pb-2">
                <p class="card-title">Status mix</p>
                <p class="card-description">Events by status in the window.</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading.summary"><div class="skeleton h-40 w-full"></div></template>
                <template x-if="!loading.summary && summary">
                    <div class="flex items-center gap-5">
                        <svg viewBox="0 0 120 120" class="h-36 w-36 shrink-0" role="img" aria-label="Status distribution">
                            <circle cx="60" cy="60" r="44" fill="none" stroke="hsl(var(--muted))" stroke-width="18"/>
                            <template x-for="seg in donutSegments(summary.by_status)" :key="seg.k">
                                <circle cx="60" cy="60" r="44" fill="none"
                                        :stroke="seg.color" stroke-width="18"
                                        :stroke-dasharray="seg.dash"
                                        :stroke-dashoffset="seg.offset"
                                        transform="rotate(-90 60 60)"/>
                            </template>
                            <text x="60" y="58" text-anchor="middle" class="font-semibold" font-size="18"
                                  fill="hsl(var(--foreground))" x-text="donutTotal(summary.by_status)"></text>
                            <text x="60" y="74" text-anchor="middle" font-size="9" fill="hsl(var(--muted-foreground))">total</text>
                        </svg>
                        <ul class="space-y-1.5 text-[12px] flex-1 min-w-0">
                            <template x-for="seg in donutLegend(summary.by_status)" :key="seg.k">
                                <li>
                                    <button type="button" class="w-full flex items-center justify-between gap-2 group"
                                            @click="setFilterStatus(seg.k)" :aria-label="'Filter by ' + seg.k">
                                        <span class="inline-flex items-center gap-2 min-w-0">
                                            <span class="h-2.5 w-2.5 rounded-sm shrink-0" :style="'background:' + seg.color"></span>
                                            <span class="truncate group-hover:text-brand-ink" x-text="seg.k"></span>
                                        </span>
                                        <span class="tabular-nums text-muted-foreground" x-text="seg.n"></span>
                                    </button>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>
        </div>

        {{-- Scope split --}}
        <div class="card">
            <div class="card-header !pb-2">
                <p class="card-title">Scope</p>
                <p class="card-description">Where recipients sit on the hierarchy.</p>
            </div>
            <div class="card-content !pt-0 space-y-2">
                <template x-if="loading.summary"><div class="skeleton h-28 w-full"></div></template>
                <template x-if="!loading.summary && summary">
                    <ul class="space-y-2.5">
                        <template x-for="row in scopeRows()" :key="row.k">
                            <li>
                                <div class="flex justify-between text-[11.5px] mb-1">
                                    <span class="font-medium" x-text="row.k"></span>
                                    <span class="tabular-nums text-muted-foreground">
                                        <span x-text="row.n"></span>
                                        <span class="ml-1" x-text="'· ' + row.pct + '%'"></span>
                                    </span>
                                </div>
                                <div class="h-2.5 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full rounded-full"
                                         :style="'width:' + row.pct + '%; background:' + row.color"></div>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
            </div>
        </div>

        {{-- Channel split (stacked bars) --}}
        <div class="card xl:col-span-2">
            <div class="card-header !pb-2">
                <p class="card-title">Channel mix</p>
                <p class="card-description">Stacked sent / failed / skipped / bounced / queued per channel.</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading.summary"><div class="skeleton h-32 w-full"></div></template>
                <template x-if="!loading.summary && summary && summary.by_channel.length">
                    <ul class="space-y-2.5">
                        <template x-for="row in summary.by_channel" :key="row.channel">
                            <li>
                                <div class="flex justify-between text-[11.5px] mb-1">
                                    <span class="font-mono" x-text="row.channel"></span>
                                    <span class="tabular-nums text-muted-foreground"
                                          x-text="row.total + ' total · ' + row.sent + ' sent · ' + row.failed + ' failed'"></span>
                                </div>
                                <div class="h-2.5 rounded-full bg-muted overflow-hidden flex">
                                    <div class="h-full bg-brand"    :style="'width:' + stackPct(row.sent, row.total)    + '%'"></div>
                                    <div class="h-full bg-critical" :style="'width:' + stackPct(row.failed, row.total)  + '%'"></div>
                                    <div class="h-full bg-danger"   :style="'width:' + stackPct(row.bounced, row.total) + '%'"></div>
                                    <div class="h-full bg-warning"  :style="'width:' + stackPct(row.skipped, row.total) + '%'"></div>
                                    <div class="h-full bg-info"     :style="'width:' + stackPct(row.queued, row.total)  + '%'"></div>
                                </div>
                            </li>
                        </template>
                    </ul>
                </template>
                <template x-if="!loading.summary && summary && !summary.by_channel.length">
                    <p class="text-sm text-muted-foreground">No notifications in the selected window.</p>
                </template>
            </div>
        </div>
    </section>

    {{-- ── Heatmap + Template ranking + Failure reasons ──────────────── --}}
    <section class="grid grid-cols-1 xl:grid-cols-3 gap-4">
        {{-- Heatmap --}}
        <div class="card xl:col-span-2">
            <div class="card-header !pb-2">
                <p class="card-title">When do sends happen?</p>
                <p class="card-description">Activity heatmap — day of week (Mon–Sun) × hour of day.</p>
            </div>
            <div class="card-content !pt-0 overflow-x-auto">
                <template x-if="loading.summary"><div class="skeleton h-44 w-full"></div></template>
                <template x-if="!loading.summary && summary">
                    <div class="min-w-[600px]">
                        <svg :viewBox="'0 0 ' + (24 * 20 + 40) + ' ' + (7 * 20 + 24)" class="w-full" role="img" aria-label="Heatmap">
                            <template x-for="h in 24" :key="'nl-xl-' + h">
                                <text :x="40 + (h - 1) * 20 + 10" :y="10" text-anchor="middle"
                                      font-size="8" fill="hsl(var(--muted-foreground))"
                                      x-text="(h - 1) % 3 === 0 ? ((h - 1).toString().padStart(2, '0') + ':00') : ''"></text>
                            </template>
                            <template x-for="(lbl, idx) in ['Mon','Tue','Wed','Thu','Fri','Sat','Sun']" :key="'nl-yl-' + idx">
                                <text :x="34" :y="24 + idx * 20 + 14" text-anchor="end"
                                      font-size="9" fill="hsl(var(--muted-foreground))" x-text="lbl"></text>
                            </template>
                            <template x-for="(row, d) in summary.heatmap" :key="'nl-hm-d-' + d">
                                <g>
                                    <template x-for="(val, h) in row" :key="'nl-hm-' + d + '-' + h">
                                        <rect :x="40 + h * 20" :y="24 + d * 20" width="18" height="18" rx="2"
                                              :fill="heatColor(val, heatMax)" :aria-label="'Day ' + d + ' hour ' + h + ': ' + val"/>
                                    </template>
                                </g>
                            </template>
                        </svg>
                        <div class="flex items-center gap-2 mt-2 text-[11px] text-muted-foreground">
                            <span>0</span>
                            <span class="inline-flex h-3 w-40 rounded"
                                  style="background:linear-gradient(to right, hsl(var(--muted)), hsl(var(--brand)))"></span>
                            <span class="tabular-nums" x-text="heatMax"></span>
                            <span>sends</span>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Template ranking --}}
        <div class="card">
            <div class="card-header !pb-2">
                <p class="card-title">Top templates</p>
                <p class="card-description">Click a row to filter the feed.</p>
            </div>
            <div class="card-content !pt-0">
                <template x-if="loading.summary"><div class="skeleton h-40 w-full"></div></template>
                <template x-if="!loading.summary && summary && summary.by_template.length">
                    <ul class="space-y-1.5">
                        <template x-for="row in summary.by_template.slice(0, 10)" :key="row.template_code">
                            <li>
                                <button type="button" class="w-full text-left group"
                                        @click="setFilterTemplate(row.template_code)"
                                        :aria-label="'Filter by ' + row.template_code">
                                    <div class="flex justify-between text-[11.5px] mb-1">
                                        <span class="font-mono group-hover:text-brand-ink truncate" x-text="row.template_code"></span>
                                        <span class="tabular-nums text-muted-foreground" x-text="row.n"></span>
                                    </div>
                                    <div class="h-2 rounded-full bg-muted overflow-hidden flex">
                                        <div class="h-full bg-brand"    :style="'width:' + stackPct(row.sent, row.n)    + '%'"></div>
                                        <div class="h-full bg-critical" :style="'width:' + stackPct(row.failed, row.n)  + '%'"></div>
                                        <div class="h-full bg-warning"  :style="'width:' + stackPct(row.skipped, row.n) + '%'"></div>
                                    </div>
                                </button>
                            </li>
                        </template>
                    </ul>
                </template>
                <template x-if="!loading.summary && summary && !summary.by_template.length">
                    <p class="text-sm text-muted-foreground">No templates in window.</p>
                </template>
            </div>
        </div>
    </section>

    {{-- Failure reasons --}}
    <section class="card">
        <div class="card-header !pb-2">
            <p class="card-title">Top failure reasons</p>
            <p class="card-description">First 120 chars of <span class="font-mono">error_message</span> · ranked by frequency.</p>
        </div>
        <div class="card-content !pt-0">
            <template x-if="loading.summary"><div class="skeleton h-24 w-full"></div></template>
            <template x-if="!loading.summary && summary && summary.failures.length">
                <ul class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-2">
                    <template x-for="row in summary.failures" :key="row.reason">
                        <li>
                            <div class="flex justify-between text-[11.5px] mb-1">
                                <span class="truncate pr-4" x-text="row.reason"></span>
                                <span class="tabular-nums text-muted-foreground shrink-0" x-text="row.n"></span>
                            </div>
                            <div class="h-2 rounded-full bg-muted overflow-hidden">
                                <div class="h-full rounded-full bg-critical"
                                     :style="'width:' + pctOfMax(row.n, summary.failures[0].n) + '%'"></div>
                            </div>
                        </li>
                    </template>
                </ul>
            </template>
            <template x-if="!loading.summary && summary && !summary.failures.length">
                <p class="text-sm text-muted-foreground">No failures or bounces in the selected window.</p>
            </template>
        </div>
    </section>

    {{-- ── Tabs: Feed · Failures ──────────────────────────────────────── --}}
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
                    <div class="relative w-full sm:w-72">
                        <svg class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 21l-4.35-4.35M11 19a8 8 0 110-16 8 8 0 010 16z"/></svg>
                        <label for="gov-notif-search" class="sr-only">Search feed</label>
                        <input id="gov-notif-search" type="search" class="input pl-8"
                               placeholder="Search email, phone, subject, template, error…"
                               x-model.debounce.300ms="filters.q" @input="loadFeed()">
                    </div>
                </div>

                {{-- Filter chips --}}
                <div class="flex flex-wrap items-center gap-2 -mt-1">
                    <label for="gov-notif-filter-status" class="sr-only">Filter by status</label>
                    <select id="gov-notif-filter-status" class="select w-auto !h-8 text-xs"
                            x-model="filters.status" @change="loadFeed()">
                        <option value="">Any status</option>
                        @foreach ($statuses as $s)
                            <option value="{{ $s }}">{{ $s }}</option>
                        @endforeach
                    </select>
                    <label for="gov-notif-filter-channel" class="sr-only">Filter by channel</label>
                    <select id="gov-notif-filter-channel" class="select w-auto !h-8 text-xs"
                            x-model="filters.channel" @change="loadFeed()">
                        <option value="">Any channel</option>
                        @foreach ($channels as $c)
                            <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                    <label for="gov-notif-filter-template" class="sr-only">Filter by template</label>
                    <select id="gov-notif-filter-template" class="select w-auto !h-8 text-xs"
                            x-model="filters.template_code" @change="loadFeed()">
                        <option value="">Any template</option>
                        <template x-for="opt in meta.template_codes" :key="opt">
                            <option :value="opt" x-text="opt"></option>
                        </template>
                    </select>
                    <label for="gov-notif-filter-triggered" class="sr-only">Filter by trigger</label>
                    <select id="gov-notif-filter-triggered" class="select w-auto !h-8 text-xs"
                            x-model="filters.triggered_by" @change="loadFeed()">
                        <option value="">Any trigger</option>
                        <template x-for="opt in meta.triggered_by" :key="opt">
                            <option :value="opt" x-text="opt"></option>
                        </template>
                    </select>
                    <button type="button" class="btn btn-ghost btn-xs"
                            x-show="hasActiveFilter()" @click="resetFilters()">
                        Clear filters
                    </button>
                </div>
            </div>

            {{-- Feed table --}}
            <div x-show="activeTab === 'feed' || activeTab === 'failures'" x-cloak>
                <div class="table-wrap !rounded-none !border-0 border-t-0">
                    <table class="table">
                        <thead class="table-head">
                            <tr>
                                <th class="table-head-th">When</th>
                                <th class="table-head-th">Status</th>
                                <th class="table-head-th">Template</th>
                                <th class="table-head-th hidden md:table-cell">To</th>
                                <th class="table-head-th hidden lg:table-cell">Scope</th>
                                <th class="table-head-th hidden md:table-cell">Retries</th>
                            </tr>
                        </thead>
                        <tbody class="table-body">
                            <template x-if="loading.feed">
                                <tr><td colspan="6" class="table-cell text-center py-12">
                                    <div class="inline-flex items-center gap-2 text-muted-foreground text-sm">
                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-18 0"/></svg>
                                        Loading log…
                                    </div>
                                </td></tr>
                            </template>
                            <template x-if="!loading.feed && feed.rows.length === 0">
                                <tr><td colspan="6" class="table-cell">
                                    <div class="empty-state">
                                        <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                                        <p class="text-sm font-medium">No notifications match these filters.</p>
                                        <button type="button" class="btn btn-outline btn-sm" @click="resetFilters()">Clear filters</button>
                                    </div>
                                </td></tr>
                            </template>
                            <template x-for="row in feed.rows" :key="row.id">
                                <tr class="table-row cursor-pointer" @click="openDetail(row)">
                                    <td class="table-cell font-mono text-[11px] text-muted-foreground" x-text="fmtTime(row.created_at)"></td>
                                    <td class="table-cell">
                                        <span class="badge" :class="statusBadge(row.status)" x-text="row.status"></span>
                                    </td>
                                    <td class="table-cell font-mono text-[11.5px]" x-text="row.template_code"></td>
                                    <td class="table-cell hidden md:table-cell">
                                        <div class="text-[12.5px] font-medium truncate max-w-[14rem]" x-text="row.contact_name || row.to_email || row.to_phone || '—'"></div>
                                        <div class="text-[11px] text-muted-foreground truncate max-w-[14rem]" x-text="(row.channel || '') + (row.to_email ? ' · ' + row.to_email : (row.to_phone ? ' · ' + row.to_phone : ''))"></div>
                                    </td>
                                    <td class="table-cell hidden lg:table-cell text-[11.5px] font-mono"
                                        x-text="row.poe_code || row.district_code || (row.country_code ? row.country_code + '·NAT' : '—')"></td>
                                    <td class="table-cell hidden md:table-cell text-right tabular-nums" x-text="row.retry_count"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between p-3 border-t text-[11.5px] text-muted-foreground">
                    <span x-text="feed.total + ' rows · page ' + feed.page + ' / ' + Math.max(feed.pages, 1)"></span>
                    <div class="flex gap-1.5">
                        <button type="button" class="btn btn-outline btn-xs"
                                :disabled="feed.page <= 1" @click="gotoPage(feed.page - 1)">Prev</button>
                        <button type="button" class="btn btn-outline btn-xs"
                                :disabled="feed.page >= feed.pages" @click="gotoPage(feed.page + 1)">Next</button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ── Detail drawer ─────────────────────────────────────────────── --}}
    <template x-if="detail.open">
        <div class="fixed inset-0 z-50 flex"
             x-effect="window.adminLock.set('gov-notif-detail', detail.open)"
             role="dialog" aria-modal="true" aria-label="Notification detail">
            <div class="absolute inset-0 bg-black/45" @click="closeDetail()"></div>
            <aside class="relative ml-auto h-full w-full max-w-lg bg-background border-l shadow-elevation-5 overflow-y-auto" @click.stop>
                <header class="flex items-center justify-between p-4 border-b">
                    <div class="min-w-0">
                        <p class="eyebrow">Notification</p>
                        <p class="font-mono text-[13px] truncate" x-text="detail.row?.template_code"></p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-icon-xs" @click="closeDetail()" aria-label="Close detail">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </header>
                <div class="p-4 space-y-4 text-[12.5px]">
                    <dl class="grid grid-cols-[8rem_1fr] gap-y-2">
                        <dt class="text-muted-foreground">When</dt>           <dd class="font-mono" x-text="detail.row?.created_at"></dd>
                        <dt class="text-muted-foreground">Status</dt>         <dd><span class="badge" :class="statusBadge(detail.row?.status)" x-text="detail.row?.status"></span></dd>
                        <dt class="text-muted-foreground">Channel</dt>        <dd class="font-mono" x-text="detail.row?.channel"></dd>
                        <dt class="text-muted-foreground">To</dt>             <dd x-text="detail.row?.to_email || detail.row?.to_phone || '—'"></dd>
                        <dt class="text-muted-foreground">Contact</dt>        <dd x-text="detail.row?.contact_name || '—'"></dd>
                        <dt class="text-muted-foreground">Scope</dt>          <dd class="font-mono" x-text="(detail.row?.poe_code || '—') + ' · ' + (detail.row?.district_code || '—') + ' · ' + (detail.row?.country_code || '—')"></dd>
                        <dt class="text-muted-foreground">Related</dt>        <dd class="font-mono" x-text="(detail.row?.related_entity_type || '—') + (detail.row?.related_entity_id ? ('#' + detail.row.related_entity_id) : '')"></dd>
                        <dt class="text-muted-foreground">Triggered by</dt>   <dd class="font-mono" x-text="detail.row?.triggered_by"></dd>
                        <dt class="text-muted-foreground">Retries</dt>        <dd class="tabular-nums" x-text="detail.row?.retry_count"></dd>
                        <dt class="text-muted-foreground">Sent at</dt>        <dd class="font-mono" x-text="detail.row?.sent_at || '—'"></dd>
                        <dt class="text-muted-foreground">Delivered at</dt>   <dd class="font-mono" x-text="detail.row?.delivered_at || '—'"></dd>
                        <dt class="text-muted-foreground">Failed at</dt>      <dd class="font-mono" x-text="detail.row?.failed_at || '—'"></dd>
                    </dl>

                    <div x-show="detail.row?.subject">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Subject</p>
                        <p class="card !p-3 text-[12.5px] break-words" x-text="detail.row?.subject"></p>
                    </div>
                    <div x-show="detail.row?.body_preview">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Body preview</p>
                        <pre class="card !p-3 text-[11.5px] whitespace-pre-wrap break-words max-h-72 overflow-auto" x-text="detail.row?.body_preview"></pre>
                    </div>
                    <div x-show="detail.row?.error_message">
                        <p class="text-[11px] font-semibold uppercase tracking-wider text-critical mb-1">Error</p>
                        <pre class="card !p-3 text-[11.5px] font-mono whitespace-pre-wrap break-all max-h-44 overflow-auto" x-text="detail.row?.error_message"></pre>
                    </div>
                </div>
            </aside>
        </div>
    </template>
</div>

@push('scripts')
<script>
    const GOV_NOTIF_URLS = {
        summary:    @json(route('admin.governance.notification-log.summary')),
        data:       @json(route('admin.governance.notification-log.data')),
        meta:       @json(route('admin.governance.notification-log.meta')),
        exportBase: @json(route('admin.governance.notification-log.export')),
    };

    function notifLogPage() {
        return {
            windowHours: 168,
            windowOptions: [
                { h: 24,  label: '24h' },
                { h: 72,  label: '3d'  },
                { h: 168, label: '7d'  },
                { h: 720, label: '30d' },
            ],
            activeTab: 'feed',
            tabs: [
                { key: 'feed',     label: 'Feed'         },
                { key: 'failures', label: 'Failures only'},
            ],
            loading:  { summary: true, feed: true },
            summary:  null,
            feed:     { rows: [], page: 1, per_page: 50, total: 0, pages: 0 },
            meta:     { template_codes: [], triggered_by: [] },
            filters:  { q: '', status: '', channel: '', template_code: '', triggered_by: '', poe_code: '', district_code: '' },
            sparkW:   600,
            heatMax:  1,
            detail:   { open: false, row: null },

            boot() {
                const url = new URL(window.location.href);
                const w   = parseInt(url.searchParams.get('hours') || '', 10);
                if ([24, 72, 168, 720].includes(w)) this.windowHours = w;
                this.loadMeta();
                this.refreshAll();
            },

            refreshAll() {
                this.loadSummary();
                this.loadFeed();
            },

            setWindow(h) {
                this.windowHours = h;
                const url = new URL(window.location.href);
                url.searchParams.set('hours', String(h));
                window.history.replaceState(null, '', url.toString());
                this.refreshAll();
            },

            setTab(k) {
                this.activeTab = k;
                if (k === 'failures') this.filters.status = 'FAILED';
                if (k === 'feed'     && this.filters.status === 'FAILED') this.filters.status = '';
                this.feed.page = 1;
                this.loadFeed();
            },

            async loadSummary() {
                this.loading.summary = true;
                try {
                    const r = await fetch(GOV_NOTIF_URLS.summary + '?hours=' + this.windowHours, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) {
                        this.summary = j.data;
                        this.sparkW = Math.max(60, this.summary.hourly.length * 2);
                        let max = 1;
                        for (const row of this.summary.heatmap) for (const v of row) if (v > max) max = v;
                        this.heatMax = max;
                    }
                } catch (e) {}
                this.loading.summary = false;
            },

            async loadFeed() {
                this.loading.feed = true;
                try {
                    const p = new URLSearchParams({
                        hours: String(this.windowHours),
                        page: String(this.feed.page),
                        per_page: String(this.feed.per_page),
                    });
                    if (this.filters.q)             p.set('q', this.filters.q);
                    if (this.filters.status)        p.set('status', this.filters.status);
                    if (this.filters.channel)       p.set('channel', this.filters.channel);
                    if (this.filters.template_code) p.set('template_code', this.filters.template_code);
                    if (this.filters.triggered_by)  p.set('triggered_by', this.filters.triggered_by);
                    if (this.filters.poe_code)      p.set('poe_code', this.filters.poe_code);
                    if (this.filters.district_code) p.set('district_code', this.filters.district_code);

                    const r = await fetch(GOV_NOTIF_URLS.data + '?' + p.toString(), { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) {
                        this.feed.rows     = j.data.rows;
                        this.feed.page     = j.data.page;
                        this.feed.per_page = j.data.per_page;
                        this.feed.total    = j.data.total;
                        this.feed.pages    = j.data.pages;
                    }
                } catch (e) {}
                this.loading.feed = false;
            },

            async loadMeta() {
                try {
                    const r = await fetch(GOV_NOTIF_URLS.meta, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.meta = j.data;
                } catch (e) {}
            },

            gotoPage(p) {
                if (p < 1 || p > this.feed.pages) return;
                this.feed.page = p; this.loadFeed();
            },

            // Filter convenience
            setFilterStatus(s)   { this.filters.status = s; this.activeTab = s === 'FAILED' ? 'failures' : 'feed'; this.feed.page = 1; this.loadFeed(); },
            setFilterTemplate(t) { this.filters.template_code = t; this.activeTab = 'feed'; this.feed.page = 1; this.loadFeed(); },
            hasActiveFilter()    { return this.filters.q || this.filters.status || this.filters.channel || this.filters.template_code || this.filters.triggered_by || this.filters.poe_code || this.filters.district_code; },
            resetFilters()       { this.filters = { q: '', status: '', channel: '', template_code: '', triggered_by: '', poe_code: '', district_code: '' }; this.feed.page = 1; this.loadFeed(); },

            exportHref() { return GOV_NOTIF_URLS.exportBase + '?hours=' + this.windowHours; },

            // Detail
            openDetail(row) { this.detail.row = row; this.detail.open = true; },
            closeDetail()   { this.detail.open = false; this.detail.row = null; },

            // Tab counts
            tabCount(k) {
                if (!this.summary) return 0;
                if (k === 'feed')     return this.summary.totals.total;
                if (k === 'failures') return this.summary.totals.failed + this.summary.totals.bounced;
                return 0;
            },

            fmtTime(s) {
                if (!s) return '—';
                try { return new Date(s.replace(' ', 'T')).toISOString().replace('T', ' ').slice(0, 16); }
                catch { return s; }
            },

            // Charts
            stackedArea(points, visibleKeys, layerIdx, h) {
                if (!points || !points.length) return '';
                const w = this.sparkW;
                // Find max total across all layers for y-scale
                const totals = points.map(p =>
                    (p.sent_n||0)+(p.failed_n||0)+(p.bounced_n||0)+(p.skipped_n||0)+(p.queued_n||0)
                );
                const maxV = Math.max(1, ...totals);
                const step = w / Math.max(points.length - 1, 1);

                // Top of this layer = sum of visibleKeys
                // Bottom of this layer = sum of visibleKeys excluding first (layer below)
                const top = points.map(p => visibleKeys.reduce((s, k) => s + (p[k] || 0), 0));
                const bot = points.map(p => visibleKeys.slice(1).reduce((s, k) => s + (p[k] || 0), 0));

                const topPts = top.map((v, i) => [i * step, h - (v / maxV) * (h - 2) - 1]);
                const botPts = bot.map((v, i) => [i * step, h - (v / maxV) * (h - 2) - 1]).reverse();

                const d = topPts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(2) + ' ' + p[1].toFixed(2)).join(' ')
                       + ' ' + botPts.map(p => 'L' + p[0].toFixed(2) + ' ' + p[1].toFixed(2)).join(' ')
                       + ' Z';
                return d;
            },

            donutSegments(by) {
                const order = ['SENT','FAILED','BOUNCED','SKIPPED','QUEUED'];
                const palette = {
                    SENT:     'hsl(var(--brand))',
                    FAILED:   'hsl(var(--critical))',
                    BOUNCED:  'hsl(var(--danger))',
                    SKIPPED:  'hsl(var(--warning))',
                    QUEUED:   'hsl(var(--info))',
                };
                const total = this.donutTotal(by) || 1;
                const C = 2 * Math.PI * 44;
                let acc = 0;
                const out = [];
                for (const k of order) {
                    const n = by[k] || 0;
                    if (n === 0) continue;
                    const len = C * (n / total);
                    const gap = C - len;
                    out.push({ k, n, color: palette[k], dash: len.toFixed(2) + ' ' + gap.toFixed(2), offset: (-acc).toFixed(2) });
                    acc += len;
                }
                return out;
            },
            donutLegend(by) {
                const palette = {
                    SENT:     'hsl(var(--brand))',
                    FAILED:   'hsl(var(--critical))',
                    BOUNCED:  'hsl(var(--danger))',
                    SKIPPED:  'hsl(var(--warning))',
                    QUEUED:   'hsl(var(--info))',
                };
                return ['SENT','FAILED','BOUNCED','SKIPPED','QUEUED'].map(k => ({ k, n: by[k] || 0, color: palette[k] }));
            },
            donutTotal(by) {
                return ['SENT','FAILED','BOUNCED','SKIPPED','QUEUED'].reduce((a, k) => a + (by[k] || 0), 0);
            },

            scopeRows() {
                const by = this.summary.by_scope || {};
                const order = ['POE','DISTRICT','NATIONAL','UNKNOWN'];
                const palette = {
                    POE:      'hsl(var(--brand))',
                    DISTRICT: 'hsl(var(--info))',
                    NATIONAL: 'hsl(var(--warning))',
                    UNKNOWN:  'hsl(var(--muted-foreground))',
                };
                const total = order.reduce((s, k) => s + (by[k] || 0), 0) || 1;
                return order.filter(k => (by[k] || 0) > 0).map(k => ({
                    k, n: by[k] || 0, color: palette[k],
                    pct: Math.round((by[k] || 0) / total * 100),
                }));
            },

            stackPct(part, whole) { return !whole || whole <= 0 ? 0 : Math.max(0, Math.min(100, part / whole * 100)); },
            pctOfMax(v, max)      { return max > 0 ? Math.round(v / max * 100) : 0; },

            heatColor(v, max) {
                if (!v) return 'hsl(var(--muted))';
                const t = Math.min(1, v / Math.max(max, 1));
                const alpha = 0.15 + t * 0.85;
                return 'hsl(var(--brand) / ' + alpha.toFixed(2) + ')';
            },

            statusBadge(s) {
                return {
                    'badge-success':  s === 'SENT',
                    'badge-critical': s === 'FAILED',
                    'badge-danger':   s === 'BOUNCED',
                    'badge-warning':  s === 'SKIPPED',
                    'badge-info':     s === 'QUEUED',
                };
            },
        };
    }
</script>
@endpush
@endsection
