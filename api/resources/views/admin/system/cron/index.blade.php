{{-- Admin · System Health · Scheduled Jobs (sys-cron) — v4 shell --}}
@extends('admin.layout')

@section('crumb', 'System Health')
@section('title', $page_title)

@php
    /** @var array $coach */
    $coach = $coach ?? \App\Support\System\CoachManifest::forView('cron');
@endphp

@section('content')
{{--
    System Health · sys-cron — premium read-only view of the platform's
    background timekeeper.

    Mobile-API impact: NONE. Routes live entirely under /admin/system/cron/*.

    Audit: every read records a row in reports_access_audit; the manual
    trigger records its own audit row naming the command that was run.

    Boundary: this view does NOT edit the scheduler, the dispatcher, or the
    digest builder. The only mutation is /trigger, which calls into the
    existing artisan command path that the scheduler itself uses.
--}}
<div x-data="cronPage()" x-init="boot()"
     x-effect="window.adminLock?.set?.('page', triggerSheet?.open || coachSheet?.open || wizardSheet?.open || rowSheet?.open || interpretSheet?.open)"
     class="flex flex-col gap-4 min-h-[calc(100vh-7rem)]">

    <script type="application/json" id="sys-cron-coach">@json($coach)</script>

    {{-- ─── Coach intro strip ─────────────────────────────────────────── --}}
    <section class="rounded-2xl border bg-card shadow-sm">
        <button type="button" class="w-full text-left flex items-start gap-3 px-4 sm:px-5 py-3"
                @click="coachIntroOpen=!coachIntroOpen" :aria-expanded="coachIntroOpen">
            <span class="grid place-items-center h-8 w-8 rounded-lg bg-brand-soft text-brand-ink shrink-0 mt-0.5">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <span class="flex-1 min-w-0">
                <span class="block text-[12.5px] font-semibold leading-tight" x-text="coach.view.title"></span>
                <span class="block text-[11.5px] text-muted-foreground mt-0.5 leading-snug" x-text="coach.view.header_intro"></span>
            </span>
            <span class="shrink-0 flex items-center gap-2">
                <button type="button" class="btn btn-outline btn-xs" @click.stop="wizardSheet.open=true" title="Help me decide where to start">
                    What do you want to do?
                </button>
                <button type="button" class="btn btn-ghost btn-icon-xs" @click.stop="coachSheet.open=true" title="Glossary &amp; full guide">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </button>
                <svg class="h-4 w-4 text-muted-foreground transition-transform" :class="coachIntroOpen?'rotate-180':''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
            </span>
        </button>
        <div x-show="coachIntroOpen" x-cloak x-collapse class="px-4 sm:px-5 pb-4 border-t pt-3 space-y-2.5 text-[12px]">
            <div>
                <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">What this page is</p>
                <p class="mt-0.5 leading-relaxed" x-text="coach.view.purpose"></p>
            </div>
            <div>
                <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Who this is for</p>
                <p class="mt-0.5" x-text="coach.view.audience"></p>
            </div>
            <div>
                <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Read-only</p>
                <p class="mt-0.5">You can see what is happening and re-run a job on demand. You cannot change schedules, cadences, or the dispatcher from this page — those are owned by the engineering team.</p>
            </div>
        </div>
    </section>

    {{-- ─── Hero — health pill + counters + freshness ────────────────── --}}
    <section class="rounded-2xl border bg-gradient-to-br from-card via-card to-muted/30 shadow-sm overflow-hidden">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-0">
            <div class="lg:col-span-8 grid grid-cols-2 sm:grid-cols-4 gap-0 divide-x divide-y sm:divide-y-0 border-b lg:border-b-0 lg:border-r">
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Overall health</p>
                    <div class="flex items-center gap-2 mt-1.5">
                        <span class="status-dot" :class="healthDot()" aria-hidden="true"></span>
                        <p class="text-[14.5px] font-bold leading-tight" x-text="summary?.health?.plain || '—'"></p>
                    </div>
                    <button class="text-[10.5px] text-brand mt-1.5 underline-offset-2 hover:underline" @click="openInterpret('overview_pill')">
                        How do I read this?
                    </button>
                </div>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Schedules registered</p>
                    <p class="text-[20px] font-bold tabular-nums" x-text="formatN(summary?.registered_count)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">discovered live from the timekeeper</p>
                </div>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Need attention</p>
                    <p class="text-[20px] font-bold tabular-nums" :class="(summary?.overdue_count||0)>0?'text-critical':'text-success'" x-text="formatN(summary?.overdue_count)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">delayed past expected interval</p>
                </div>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Waiting / retrying</p>
                    <div class="flex items-baseline gap-2 mt-1">
                        <p class="text-[20px] font-bold tabular-nums" x-text="formatN(summary?.queue?.waiting)"></p>
                        <span class="text-muted-foreground">·</span>
                        <p class="text-[14px] font-semibold tabular-nums text-muted-foreground" x-text="formatN(summary?.queue?.retrying)"></p>
                    </div>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">queued · being retried</p>
                </div>
            </div>
            <div class="lg:col-span-4 px-4 py-3.5 flex flex-col">
                <div class="flex items-center justify-between mb-1">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Activity window</p>
                    <p class="text-[10px] text-muted-foreground" x-show="freshness" x-text="freshness"></p>
                </div>
                <div class="flex flex-wrap gap-1.5 mb-2">
                    <template x-for="opt in windowOptions" :key="opt.h">
                        <button type="button" class="px-2 py-1 text-[11px] rounded-full border transition" :class="hours===opt.h?'bg-brand text-white border-brand':'bg-card hover:bg-muted/40'" @click="hours=opt.h; pushUrl(); load()" x-text="opt.label"></button>
                    </template>
                </div>
                <button class="btn btn-outline btn-sm mt-auto" @click="load()">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    <span>Refresh</span>
                </button>
            </div>
        </div>
    </section>

    {{-- ─── Tabs + content ──────────────────────────────────────────── --}}
    <section class="card flex-1 flex flex-col min-h-0">
        <div class="card-content !p-0 flex flex-col min-h-0">

            <div class="flex flex-col sm:flex-row sm:items-center gap-3 p-4 sm:p-5 border-b bg-card sticky top-0 z-20">
                <div class="tabs-list w-full sm:w-auto">
                    <template x-for="t in tabs" :key="t.key">
                        <button class="tabs-trigger flex-1 sm:flex-none" :data-state="activeTab===t.key?'active':'inactive'" @click="activeTab=t.key; pushUrl()">
                            <span x-text="t.label"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- ── Overview tab — heartbeat strips for every schedule ── --}}
            <div x-show="activeTab==='overview'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-4">
                <div class="flex items-center gap-2">
                    <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold">Last 24 hours · one strip per schedule</p>
                    <button class="text-[10.5px] text-brand underline-offset-2 hover:underline" @click="openInterpret('heartbeat_strip')">How do I read this?</button>
                </div>

                <template x-if="!summary"><div class="space-y-2"><template x-for="i in 4" :key="'sk'+i"><div class="h-12 bg-muted/40 rounded animate-pulse"></div></template></div></template>

                <template x-if="summary && (summary.jobs||[]).length===0">
                    <div class="empty-state"><p class="text-sm">No schedules are registered. The timekeeper appears to be off — call the engineering team.</p></div>
                </template>

                <ul class="divide-y rounded-xl border bg-card overflow-hidden">
                    <template x-for="(job, idx) in (summary?.jobs || [])" :key="'job-'+job.command">
                        <li class="px-3 py-3 flex flex-col sm:flex-row sm:items-center gap-3 hover:bg-muted/20 cursor-pointer" @click="openJob(job)">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <span class="status-dot" :class="jobDot(job)" aria-hidden="true"></span>
                                    <p class="text-[13px] font-semibold truncate" x-text="job.label"></p>
                                    <span x-show="job.untranslated" class="badge text-[9.5px] badge-warning">Untranslated</span>
                                </div>
                                <p class="text-[11px] text-muted-foreground truncate" x-text="job.summary"></p>
                                <p class="text-[10.5px] text-muted-foreground mt-0.5">
                                    <span x-text="job.expression_plain"></span>
                                    · last ran <span class="font-semibold" x-text="job.last_run_plain"></span>
                                </p>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <template x-for="cell in (stripFor(job.command) || [])" :key="cell.hour">
                                    <span class="block w-2 h-5 rounded-sm" :class="cellClass(cell)" :title="cell.hour + ' · ' + cell.state"></span>
                                </template>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- ── Each Schedule tab — one card per schedule ── --}}
            <div x-show="activeTab==='each'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 grid grid-cols-1 xl:grid-cols-2 gap-3">
                <template x-for="job in (summary?.jobs || [])" :key="'each-'+job.command">
                    <div class="rounded-xl border bg-card p-4">
                        <div class="flex items-start gap-2">
                            <span class="status-dot mt-1.5" :class="jobDot(job)" aria-hidden="true"></span>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-[13.5px] font-semibold" x-text="job.label"></h3>
                                <p class="text-[11.5px] text-muted-foreground" x-text="job.summary"></p>
                            </div>
                            <span class="badge text-[9.5px]" :class="jobBadge(job)" x-text="jobBadgeLabel(job)"></span>
                        </div>
                        <dl class="mt-3 grid grid-cols-[8rem_1fr] gap-y-1.5 text-[11.5px]">
                            <dt class="text-muted-foreground">Cadence</dt>
                            <dd x-text="job.expression_plain"></dd>
                            <dt class="text-muted-foreground">Last run</dt>
                            <dd>
                                <span x-text="job.last_run_plain"></span>
                                <span class="text-muted-foreground" x-show="job.last_run_iso" :title="job.last_run_iso">·</span>
                            </dd>
                            <dt class="text-muted-foreground">Next run</dt>
                            <dd>
                                <span x-text="nextRunPlain(job.next_due_iso)"></span>
                            </dd>
                            <dt class="text-muted-foreground">Who is affected</dt>
                            <dd x-text="job.affects"></dd>
                            <dt class="text-muted-foreground">If this fails</dt>
                            <dd x-text="job.when_problems"></dd>
                            <dt class="text-muted-foreground">What we do</dt>
                            <dd x-text="job.what_we_do"></dd>
                        </dl>
                        <details class="mt-2.5 text-[10.5px]">
                            <summary class="cursor-pointer text-muted-foreground">Show technical detail</summary>
                            <dl class="mt-2 grid grid-cols-[8rem_1fr] gap-y-1 text-[10.5px]">
                                <dt class="text-muted-foreground">Cron expression</dt><dd class="font-mono" x-text="job.expression_raw || '—'"></dd>
                                <dt class="text-muted-foreground">Timezone</dt><dd class="font-mono" x-text="job.timezone || 'UTC'"></dd>
                                <dt class="text-muted-foreground">Internal command</dt><dd class="font-mono break-all" x-text="job.command"></dd>
                                <dt class="text-muted-foreground">Single-server lock</dt><dd x-text="job.on_one_server ? 'Yes' : 'No'"></dd>
                                <dt class="text-muted-foreground">No overlap</dt><dd x-text="job.without_overlapping ? 'Yes' : 'No'"></dd>
                            </dl>
                        </details>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <button class="btn btn-outline btn-xs" @click="openJob(job)">Open detail</button>
                            <button class="btn btn-brand btn-xs" :disabled="!job.triggerable" @click="askTrigger(job)" :title="job.triggerable ? 'Run this schedule on demand' : 'Manual trigger is not exposed for this schedule'">
                                Run on demand
                            </button>
                        </div>
                    </div>
                </template>
                <template x-if="!summary || (summary.jobs||[]).length===0">
                    <div class="rounded-xl border-dashed border-2 p-6 text-center text-[12px] text-muted-foreground col-span-full">
                        No schedules registered yet. Once the engineering team registers one, it will appear here automatically.
                    </div>
                </template>
            </div>

            {{-- ── Recent Runs tab ── --}}
            <div x-show="activeTab==='recent'" x-cloak class="flex-1 min-h-0 flex flex-col overflow-hidden">
                <div class="flex-1 min-h-0 overflow-auto">
                    <table class="table">
                        <thead class="table-head sticky top-0 z-10 bg-card"><tr>
                            <th class="table-head-th">When</th>
                            <th class="table-head-th">Schedule</th>
                            <th class="table-head-th hidden sm:table-cell">Template</th>
                            <th class="table-head-th text-right">Total</th>
                            <th class="table-head-th text-right hidden md:table-cell">Sent</th>
                            <th class="table-head-th text-right hidden md:table-cell">Failed</th>
                        </tr></thead>
                        <tbody class="table-body">
                            <template x-if="runs.loading">
                                <template x-for="i in 6" :key="'sk'+i">
                                    <tr class="table-row"><td class="table-cell" colspan="6"><div class="h-4 w-full bg-muted/40 rounded animate-pulse"></div></td></tr>
                                </template>
                            </template>
                            <template x-if="!runs.loading && runs.rows.length===0">
                                <tr><td colspan="6" class="table-cell"><div class="empty-state"><p class="text-sm">No runs in the chosen window. Try widening the period.</p></div></td></tr>
                            </template>
                            <template x-for="row in runs.rows" :key="row.when+'-'+row.template+'-'+row.triggered_by">
                                <tr class="table-row">
                                    <td class="table-cell text-[11px] whitespace-nowrap" x-text="row.when"></td>
                                    <td class="table-cell text-[11.5px]" x-text="triggeredByLabel(row.triggered_by)"></td>
                                    <td class="table-cell text-[11.5px] hidden sm:table-cell font-mono" x-text="row.template"></td>
                                    <td class="table-cell text-right tabular-nums" x-text="formatN(row.count)"></td>
                                    <td class="table-cell text-right tabular-nums hidden md:table-cell text-success" x-text="formatN(row.sent)"></td>
                                    <td class="table-cell text-right tabular-nums hidden md:table-cell" :class="(row.failed||0)>0?'text-critical':'text-muted-foreground'" x-text="formatN(row.failed)"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- ── Failures tab ── --}}
            <div x-show="activeTab==='failures'" x-cloak class="flex-1 min-h-0 flex flex-col overflow-hidden">
                <div class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-3">
                    <p class="text-[11.5px] italic text-muted-foreground">Each row is one message that did not deliver. The reason is shown in plain language; the original provider response is behind a disclosure.</p>
                    <template x-if="failures.loading"><div class="space-y-2"><template x-for="i in 5" :key="'fl'+i"><div class="h-14 bg-muted/40 rounded animate-pulse"></div></template></div></template>
                    <template x-if="!failures.loading && failures.rows.length===0">
                        <div class="empty-state"><p class="text-sm">Nothing failed in the chosen window.</p></div>
                    </template>
                    <ul class="divide-y rounded-xl border bg-card overflow-hidden">
                        <template x-for="row in failures.rows" :key="'fl-'+row.id">
                            <li class="px-3 py-3 text-[12px]">
                                <div class="flex items-start gap-2">
                                    <span class="badge text-[9.5px]" :class="row.status==='BOUNCED'?'badge-warning':'badge-critical'" x-text="row.status==='BOUNCED'?'Bounced':'Failed'"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-semibold" x-text="row.plain_reason"></p>
                                        <p class="text-[10.5px] text-muted-foreground mt-0.5">
                                            <span x-text="triggeredByLabel(row.triggered_by)"></span>
                                            · template <span class="font-mono" x-text="row.template"></span>
                                            <span x-show="row.recipient_hint">· recipient at <span class="font-mono" x-text="row.recipient_hint"></span></span>
                                            · <span x-text="row.when" :title="row.when"></span>
                                        </p>
                                        <p class="text-[10.5px] text-muted-foreground mt-0.5" x-show="row.retry_count">Already retried <span class="tabular-nums font-semibold" x-text="row.retry_count"></span> time(s).</p>
                                    </div>
                                </div>
                                <details class="mt-2 text-[10.5px]" x-show="row.technical_raw">
                                    <summary class="cursor-pointer text-muted-foreground">Show technical detail</summary>
                                    <pre class="mt-1 p-2 rounded bg-muted/40 font-mono text-[10.5px] overflow-auto max-h-32" x-text="row.technical_raw"></pre>
                                </details>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            {{-- ── Manual Triggers tab ── --}}
            <div x-show="activeTab==='manual'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-3">
                <p class="text-[11.5px] italic text-muted-foreground">Run a schedule on demand. Every well-known schedule is idempotent — the platform will not produce duplicate messages because of the suppression rule. Each trigger is recorded with your name.</p>
                <ul class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    <template x-for="job in (summary?.jobs || [])" :key="'mt-'+job.command">
                        <li class="rounded-xl border bg-card p-3.5">
                            <div class="flex items-start gap-2">
                                <span class="status-dot mt-1.5" :class="jobDot(job)" aria-hidden="true"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-[12.5px] font-semibold" x-text="job.label"></p>
                                    <p class="text-[10.5px] text-muted-foreground" x-text="job.summary"></p>
                                </div>
                            </div>
                            <p class="text-[10.5px] mt-2" x-show="!job.triggerable">
                                <span class="font-semibold">Manual trigger not exposed.</span>
                                <span class="text-muted-foreground">The engineering team must add a translation and an allow-list entry first.</span>
                            </p>
                            <div class="mt-2 flex justify-end">
                                <button class="btn btn-brand btn-xs" :disabled="!job.triggerable" @click="askTrigger(job)">Run on demand</button>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- ── Methodology tab ── --}}
            <div x-show="activeTab==='method'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-6 space-y-4 text-[12px] leading-relaxed">
                <h3 class="text-[14px] font-bold">How this page works</h3>
                <p>The platform uses a small set of <em>scheduled jobs</em> — bits of background work that run on fixed cadences. The list shown above is read live from the platform’s internal scheduler each time you load this page, so a new job appears here automatically without code changes.</p>
                <p><strong>Plain-language cadence.</strong> Internal cadences are written in a compact format called a <em>cron expression</em>. We translate every expression into a single English line so you do not need to learn the format. Where we cannot translate (a brand-new pattern, for example), the page falls back to a transparent “Custom schedule” line and the raw expression stays available behind the technical-detail disclosure.</p>
                <p><strong>“Last run” evidence.</strong> A run’s evidence is the message it produced — a sent digest, a filed breach report. Where the platform records evidence in a known table, we show when the most recent piece of evidence was written. Where it does not, the row says “No run recorded yet.” and the heartbeat strip shows the schedule as untracked.</p>
                <p><strong>Manual triggers.</strong> When you run a schedule on demand, the platform calls the same internal command that the timekeeper would call. Suppression and idempotency rules apply identically — you cannot send the same daily digest twice in a day by clicking faster.</p>
                <p><strong>What this page does not do.</strong> It does not edit cadences, does not change the dispatcher, does not alter mail configuration, and does not touch the mobile app. Read-only by design. If a schedule needs to change, that is an engineering task.</p>
                <h3 class="text-[14px] font-bold mt-4">Translator versions</h3>
                <p class="text-[10.5px] text-muted-foreground">Cron-expression translator: <span class="font-mono">v1 · domain sign-off pending</span>. Job-name translator: <span class="font-mono">v1 · domain sign-off pending</span>. Error-string translator: <span class="font-mono">DeliveryErrorTranslator/v1</span>.</p>
            </div>

        </div>
    </section>

    {{-- ─── Job detail sheet ─────────────────────────────────────────── --}}
    <template x-if="rowSheet.open">
        <div class="fixed inset-0 z-[55] flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="rowSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="rowSheet.open=false"></div>
            <div class="relative w-full sm:max-w-lg bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <span class="status-dot" :class="jobDot(rowSheet.job)" aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Scheduled job</p>
                        <h2 class="text-[14px] font-bold truncate" x-text="rowSheet.job?.label"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="rowSheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-4 text-[12px]">
                    <p x-text="rowSheet.job?.summary"></p>
                    <section class="grid grid-cols-2 gap-3">
                        <div><p class="text-muted-foreground">Cadence</p><p x-text="rowSheet.job?.expression_plain"></p></div>
                        <div><p class="text-muted-foreground">Last run</p><p x-text="rowSheet.job?.last_run_plain"></p></div>
                        <div><p class="text-muted-foreground">Next run</p><p x-text="nextRunPlain(rowSheet.job?.next_due_iso)"></p></div>
                        <div><p class="text-muted-foreground">Status</p><p :class="rowSheet.job?.overdue?'text-critical':'text-success'" x-text="rowSheet.job?.overdue?'Overdue':'On time'"></p></div>
                    </section>
                    <section>
                        <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-1">Who is affected</p>
                        <p x-text="rowSheet.job?.affects"></p>
                    </section>
                    <section>
                        <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-1">If this fails</p>
                        <p x-text="rowSheet.job?.when_problems"></p>
                    </section>
                    <section>
                        <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-1">What the platform does automatically</p>
                        <p x-text="rowSheet.job?.what_we_do"></p>
                    </section>
                    <details class="text-[10.5px]">
                        <summary class="cursor-pointer text-muted-foreground">Show technical detail</summary>
                        <dl class="mt-2 grid grid-cols-[9rem_1fr] gap-y-1">
                            <dt class="text-muted-foreground">Cron expression</dt><dd class="font-mono" x-text="rowSheet.job?.expression_raw || '—'"></dd>
                            <dt class="text-muted-foreground">Timezone</dt><dd class="font-mono" x-text="rowSheet.job?.timezone || 'UTC'"></dd>
                            <dt class="text-muted-foreground">Command</dt><dd class="font-mono break-all" x-text="rowSheet.job?.command"></dd>
                        </dl>
                    </details>
                </div>
                <footer class="border-t px-4 sm:px-6 py-3 flex justify-end gap-2">
                    <button class="btn btn-outline btn-sm" @click="rowSheet.open=false">Close</button>
                    <button class="btn btn-brand btn-sm" :disabled="!rowSheet.job?.triggerable" @click="askTrigger(rowSheet.job)">Run on demand</button>
                </footer>
            </div>
        </div>
    </template>

    {{-- ─── Trigger pre-confirm ─────────────────────────────────────── --}}
    <template x-if="triggerSheet.open">
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="triggerSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="triggerSheet.open=false"></div>
            <div class="relative w-full max-w-md bg-card border rounded-2xl shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold">About to run on demand</h3>
                <p class="text-[12px] text-muted-foreground mt-1">
                    <span class="font-semibold text-foreground" x-text="triggerSheet.job?.label"></span> will be run now.
                </p>
                <ul class="mt-3 list-disc pl-5 text-[11.5px] space-y-1">
                    <li><span x-text="triggerSheet.job?.summary"></span></li>
                    <li>The job is idempotent — the platform’s suppression rules will block any duplicate message.</li>
                    <li>The trigger is recorded with your name in the audit log.</li>
                </ul>
                <div class="mt-3 rounded-lg bg-muted/40 p-2.5 text-[10.5px]">
                    <p class="font-semibold uppercase tracking-wider text-muted-foreground text-[10px]">Who will be affected</p>
                    <p class="mt-0.5" x-text="triggerSheet.job?.affects"></p>
                </div>
                <div class="flex justify-end gap-2 mt-5">
                    <button class="btn btn-outline btn-sm" @click="triggerSheet.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" :disabled="triggerSheet.busy" @click="performTrigger()">
                        <span x-show="!triggerSheet.busy">Run now</span>
                        <span x-show="triggerSheet.busy">Running…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Post-action ──────────────────────────────────────────────── --}}
    <template x-if="postAction.open">
        <div class="fixed inset-0 z-[63] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="postAction.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="postAction.open=false"></div>
            <div class="relative w-full max-w-md bg-card border rounded-2xl shadow-elevation-5 p-5" @click.stop>
                <div class="flex items-start gap-3">
                    <div class="grid place-items-center h-10 w-10 rounded-full shrink-0" :class="postAction.kind==='success'?'bg-success-soft text-success':'bg-warning-soft text-warning'">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-[14px] font-bold" x-text="postAction.title"></h3>
                        <p class="text-[12px] text-muted-foreground mt-1" x-text="postAction.body"></p>
                    </div>
                </div>
                <details class="mt-3 text-[10.5px]" x-show="postAction.technical">
                    <summary class="cursor-pointer text-muted-foreground">Show technical detail</summary>
                    <pre class="mt-1 p-2 rounded bg-muted/40 font-mono text-[10.5px] overflow-auto max-h-40" x-text="postAction.technical"></pre>
                </details>
                <div class="flex justify-end mt-5"><button class="btn btn-brand btn-sm" @click="postAction.open=false">Close</button></div>
            </div>
        </div>
    </template>

    {{-- ─── Wizard sheet ──────────────────────────────────────────────── --}}
    <template x-if="wizardSheet.open">
        <div class="fixed inset-0 z-[58] flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="wizardSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="wizardSheet.open=false"></div>
            <div class="relative w-full sm:max-w-md bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Wizard</p>
                        <h2 class="text-[14px] font-bold" x-text="coach.wizard?.launcher_label || 'What do you want to do?'"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="wizardSheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-2.5 text-[12px]">
                    <template x-for="opt in (coach.wizard?.options || [])" :key="opt.id">
                        <button class="w-full text-left rounded-xl border p-3 hover:bg-muted/30 transition" @click="pickWizardOption(opt)">
                            <p class="font-semibold" x-text="opt.label"></p>
                            <p class="text-[11px] text-muted-foreground mt-0.5" x-text="opt.summary"></p>
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Coach sheet (glossary + actions) ─────────────────────────── --}}
    <template x-if="coachSheet.open">
        <div class="fixed inset-0 z-[58] flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="coachSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="coachSheet.open=false"></div>
            <div class="relative w-full sm:max-w-md bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Guide</p>
                        <h2 class="text-[14px] font-bold" x-text="coach.view.title"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="coachSheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-5 text-[12px]">
                    <section>
                        <h3 class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">What you can do here</h3>
                        <ul class="space-y-2">
                            <template x-for="a in actionsArr" :key="'g-'+a.id">
                                <li class="rounded-lg border p-2.5">
                                    <p class="font-semibold" x-text="a.label"></p>
                                    <p class="text-[11px] text-muted-foreground mt-0.5" x-text="a.one_liner"></p>
                                    <p class="text-[10.5px] mt-1" x-show="a.when_to_use"><span class="text-muted-foreground">When this fits: </span><span x-text="a.when_to_use"></span></p>
                                </li>
                            </template>
                        </ul>
                    </section>
                    <section>
                        <h3 class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Words you may meet</h3>
                        <dl class="space-y-2">
                            <template x-for="g in coach.glossary" :key="g.term">
                                <div class="rounded-lg border p-2.5">
                                    <dt class="font-semibold" x-text="g.term"></dt>
                                    <dd class="text-[11px] text-muted-foreground mt-0.5" x-text="g.plain_english"></dd>
                                </div>
                            </template>
                        </dl>
                    </section>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Interpretation modal (per chart) ─────────────────────────── --}}
    <template x-if="interpretSheet.open">
        <div class="fixed inset-0 z-[59] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="interpretSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="interpretSheet.open=false"></div>
            <div class="relative w-full max-w-md bg-card border rounded-2xl shadow-elevation-5 p-5 max-h-[85vh] overflow-y-auto" @click.stop>
                <h3 class="text-[14px] font-bold" x-text="interpretSheet.chart?.title || 'Reading this chart'"></h3>
                <dl class="mt-3 space-y-3 text-[11.5px]">
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">What this shows</dt><dd x-text="interpretSheet.chart?.shows"></dd></div>
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">How to read it</dt><dd x-text="interpretSheet.chart?.how_to_read"></dd></div>
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">Healthy</dt><dd x-text="interpretSheet.chart?.healthy"></dd></div>
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">Concerning</dt><dd x-text="interpretSheet.chart?.concerning"></dd></div>
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">What to do</dt><dd x-text="interpretSheet.chart?.what_to_do"></dd></div>
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">What it cannot tell you</dt><dd x-text="interpretSheet.chart?.cannot_tell"></dd></div>
                    <div><dt class="text-[10.5px] uppercase tracking-wider text-muted-foreground">Where the data comes from</dt><dd x-text="interpretSheet.chart?.data_source"></dd></div>
                </dl>
                <div class="flex justify-end mt-4"><button class="btn btn-brand btn-sm" @click="interpretSheet.open=false">Got it</button></div>
            </div>
        </div>
    </template>

    {{-- Toast --}}
    <div class="fixed inset-x-0 bottom-6 z-[70] flex justify-center px-3 pointer-events-none" x-show="opToast.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="opToast.kind==='success'?'toast-success':'toast-destructive'">
            <div><p class="toast-title" x-text="opToast.title"></p><p class="toast-description" x-text="opToast.body"></p></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function cronPage(){
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const idemKey=()=>{ if(crypto?.randomUUID) return crypto.randomUUID(); return 'k-'+Date.now()+'-'+Math.random().toString(36).slice(2); };
    const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf(),'Idempotency-Key':idemKey()});
    const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');

    let coach = {};
    try{ coach = JSON.parse(document.getElementById('sys-cron-coach')?.textContent || '{}') || {}; }catch(e){ coach = {}; }
    coach.glossary = coach.glossary || [];
    coach.charts   = coach.charts   || {};
    coach.wizard   = coach.wizard   || { options: [] };
    const ACTIONS_ARR = Object.values(coach.actions || {});

    return {
        coach, actionsArr: ACTIONS_ARR,
        coachIntroOpen:false,
        coachSheet:{open:false},
        wizardSheet:{open:false},
        interpretSheet:{open:false, chart:null},
        rowSheet:{open:false, job:null},
        triggerSheet:{open:false, job:null, busy:false},
        postAction:{open:false, kind:'success', title:'', body:'', technical:''},
        opToast:{open:false, kind:'success', title:'', body:'', t:null},

        hours: 168,
        windowOptions: [
            {h:24,  label:'Past 24h'},
            {h:72,  label:'Past 3d'},
            {h:168, label:'Past 7d'},
            {h:336, label:'Past 14d'},
            {h:720, label:'Past 30d'},
        ],
        tabs: [
            {key:'overview', label:'Overview'},
            {key:'each',     label:'Each schedule'},
            {key:'recent',   label:'Recent runs'},
            {key:'failures', label:'Failures'},
            {key:'manual',   label:'Manual triggers'},
            {key:'method',   label:'Methodology'},
        ],
        activeTab:'overview',

        summary:null, freshness:'',
        runs:{loading:false, rows:[]},
        failures:{loading:false, rows:[]},

        formatN(n){ if(n===null||n===undefined) return '—'; try{ return Number(n).toLocaleString(); }catch(e){ return String(n); } },

        triggeredByLabel(t){
            if(!t) return '—';
            const m = String(t).replace(/^CRON:/,'');
            const map = { 'daily-digest':'Morning digest', 'followup-reminders':'Follow-up reminders', 'retry':'Retry queue', 'national-digest':'National intelligence digest', 'scan-sla-breaches':'SLA breach scanner' };
            return map[m] || t;
        },

        healthDot(){ const lvl=this.summary?.health?.level; if(lvl==='green') return 'status-dot-live'; if(lvl==='amber') return 'status-dot-warn'; if(lvl==='red') return 'status-dot-danger'; return ''; },
        jobDot(j){ if(!j) return ''; if(j.overdue) return 'status-dot-danger'; if(j.has_evidence===false) return 'status-dot-warn'; return 'status-dot-live'; },
        jobBadge(j){ if(!j) return 'badge-outline'; if(j.overdue) return 'badge-critical'; if(!j.has_evidence) return 'badge-warning'; return 'badge-success'; },
        jobBadgeLabel(j){ if(!j) return ''; if(j.overdue) return 'Overdue'; if(!j.has_evidence) return 'Untracked'; return 'On time'; },
        cellClass(c){ const m={success:'bg-success', failed:'bg-critical', idle:'bg-muted/40', pending:'bg-muted/20', unknown:'bg-muted/20 ring-1 ring-warning/50'}; return m[c.state]||'bg-muted/20'; },

        stripFor(command){ return (this.summary?.heartbeat_strips||[]).find(s=>s.command===command)?.cells || []; },

        nextRunPlain(iso){
            if(!iso) return 'Not yet computed.';
            const d = new Date(iso); if(isNaN(d)) return 'Not yet computed.';
            const diff = d.getTime() - Date.now();
            if(diff < 0) return 'Was due to start; check the heartbeat strip.';
            const m=60_000, h=3600_000, day=86400_000;
            if(diff < m) return 'Within the next minute.';
            if(diff < h) return 'In '+Math.round(diff/m)+' minutes.';
            if(diff < day) return 'In about '+Math.round(diff/h)+' hours.';
            return 'In '+Math.round(diff/day)+' days.';
        },

        openInterpret(key){
            const chart = this.coach.charts?.[key];
            if(!chart) return;
            this.interpretSheet = {open:true, chart};
        },
        openJob(job){ this.rowSheet = {open:true, job}; },

        askTrigger(job){
            if(!job?.triggerable) return;
            this.triggerSheet = {open:true, job, busy:false};
        },
        async performTrigger(){
            if(!this.triggerSheet.job) return;
            this.triggerSheet.busy = true;
            try{
                const r = await fetch('/admin/system/cron/trigger', {
                    method:'POST', headers:headersJson(),
                    body:JSON.stringify({command:this.triggerSheet.job.command}),
                });
                const j = await r.json();
                if(j.ok){
                    this.triggerSheet.open = false;
                    this.postAction = {
                        open:true,
                        kind: (j.data?.exit_code===0)?'success':'warning',
                        title: (j.data?.exit_code===0)?'The schedule has been triggered':'Triggered with a non-zero exit',
                        body: j.data?.plain_summary || 'The platform ran the schedule.',
                        technical: j.data?.technical_output || '',
                    };
                    await this.load();
                } else {
                    this.toast('error','Could not run', j.message || 'Unknown error.');
                }
            }catch(e){ this.toast('error','Network', e.message); }
            finally{ this.triggerSheet.busy = false; }
        },

        pickWizardOption(opt){
            this.wizardSheet.open = false;
            const map = { overview:'overview', each:'each', recent:'recent', failures:'failures', manual:'manual', method:'method' };
            const tab = map[opt.goto_tab] || 'overview';
            this.activeTab = tab;
            this.pushUrl();
            this.toast('success', opt.label, opt.summary);
        },

        async boot(){
            this.readUrl();
            await this.load();
        },
        readUrl(){
            try{
                const u = new URL(window.location.href);
                const t = u.searchParams.get('tab'); if(t) this.activeTab = t;
                const h = parseInt(u.searchParams.get('hours') || '0', 10);
                if(h > 0) this.hours = h;
            }catch(e){}
        },
        pushUrl(){
            try{
                const u = new URL(window.location.href);
                u.searchParams.set('tab', this.activeTab);
                u.searchParams.set('hours', String(this.hours));
                window.history.replaceState(null,'',u.toString());
            }catch(e){}
        },

        async load(){
            await Promise.all([this.loadSummary(), this.loadRuns(), this.loadFailures()]);
            this.freshness = 'Fresh as of ' + new Date().toLocaleTimeString();
        },
        async loadSummary(){
            try{
                const r = await fetch('/admin/system/cron/summary', {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok) this.summary = j.data;
            }catch(e){}
        },
        async loadRuns(){
            this.runs.loading = true;
            try{
                const r = await fetch('/admin/system/cron/runs?'+qs({hours:this.hours}), {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok) this.runs.rows = j.data.rows || [];
            }catch(e){}
            finally{ this.runs.loading = false; }
        },
        async loadFailures(){
            this.failures.loading = true;
            try{
                const r = await fetch('/admin/system/cron/failures?'+qs({hours:this.hours}), {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok) this.failures.rows = j.data.rows || [];
            }catch(e){}
            finally{ this.failures.loading = false; }
        },

        toast(kind, title, body){ this.opToast = {open:true, kind, title, body, t:null}; clearTimeout(this.opToast.t); this.opToast.t = setTimeout(()=>{ this.opToast.open=false; }, 4500); },
    };
}
</script>
@endpush
