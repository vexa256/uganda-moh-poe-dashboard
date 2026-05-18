@extends('admin.layout')

@section('crumb', 'Governance')
@section('title', $page_title)

@php
    /** @var array $coach */
    $coach = $coach ?? \App\Support\Governance\CoachManifest::forView('auth');
@endphp

@section('content')
{{--
    Governance · Auth Events (gov-auth) — v4 reference shell.

    Mobile-API impact: NONE. The four read endpoints
    (data / summary / lockouts / anomalies) and the one write
    (clearAnomaly) live entirely under /admin/governance/auth-events
    in routes/web.php and are written exclusively to here. /api/* is
    untouched.

    Viewport discipline: page never scrolls Y. Sticky toolbar; the
    activity-feed table body is the designated Y-scroll region; modals
    scroll inside themselves.

    Audit: every read (data / summary / lockouts / anomalies / export)
    is recorded in reports_access_audit by the controller via
    AccessAuditor. PII reveal is recorded with the explicit list of
    unmasked columns.
--}}
<div x-data="authEventsPage()" x-init="boot()"
     x-effect="window.adminLock?.set?.('page', clear?.open || postAction?.open || coachSheet?.open || compareSheet?.open || rowSheet?.open)"
     class="flex flex-col gap-4 min-h-[calc(100vh-7rem)]">

    <script type="application/json" id="gov-auth-coach">@json($coach)</script>

    {{-- ─── Coach intro strip ─────────────────────────────────────────── --}}
    <section class="rounded-2xl border bg-card shadow-sm">
        <button type="button" class="w-full text-left flex items-start gap-3 px-4 sm:px-5 py-3"
                @click="coachIntroOpen=!coachIntroOpen" :aria-expanded="coachIntroOpen">
            <span class="grid place-items-center h-8 w-8 rounded-lg bg-brand-soft text-brand-ink shrink-0 mt-0.5">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
            </span>
            <span class="flex-1 min-w-0">
                <span class="block text-[12.5px] font-semibold leading-tight" x-text="coach.view.title"></span>
                <span class="block text-[11.5px] text-muted-foreground mt-0.5 leading-snug" x-text="coach.view.header_intro"></span>
            </span>
            <span class="shrink-0 flex items-center gap-2">
                <label class="hidden sm:flex items-center gap-1.5 text-[10.5px] text-muted-foreground" @click.stop>
                    <input type="checkbox" x-model="stepThru" @change="persistPref('stepthru',stepThru)">
                    <span>Walk me through</span>
                </label>
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
            <div x-show="(coach.view.prerequisites||[]).length">
                <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Have ready before you start</p>
                <ul class="mt-0.5 list-disc pl-5 space-y-0.5">
                    <template x-for="p in (coach.view.prerequisites||[])" :key="p"><li x-text="p"></li></template>
                </ul>
            </div>
            <div class="flex flex-wrap gap-2 pt-1">
                <button class="btn btn-outline btn-xs" @click="compareSheet.open=true">Compare every action</button>
                <button class="btn btn-outline btn-xs" @click="coachSheet.open=true">Open glossary</button>
            </div>
        </div>
    </section>

    {{-- ─── Hero — KPIs + period control ──────────────────────────────── --}}
    <section class="rounded-2xl border bg-gradient-to-br from-card via-card to-muted/30 shadow-sm overflow-hidden">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-0">
            <div class="lg:col-span-8 grid grid-cols-2 sm:grid-cols-5 gap-0 divide-x divide-y sm:divide-y-0 border-b lg:border-b-0 lg:border-r">
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Successful sign-ins</p>
                    <p class="text-[20px] font-bold tabular-nums text-success" x-text="formatN(summary?.login?.ok)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5"><span x-text="summary?.login?.success_pct ?? '—'"></span>% success rate</p>
                </div>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Failed attempts</p>
                    <p class="text-[20px] font-bold tabular-nums text-warning" x-text="formatN(summary?.login?.fail)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">over the period</p>
                </div>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Locked out now</p>
                    <p class="text-[20px] font-bold tabular-nums text-critical" x-text="formatN(summary?.operational?.locked_now)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">awaiting cool-off</p>
                </div>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Suspended</p>
                    <p class="text-[20px] font-bold tabular-nums text-critical" x-text="formatN(summary?.operational?.suspended_now)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">access pulled</p>
                </div>
                <div class="px-4 py-3.5 col-span-2 sm:col-span-1">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Anomaly flags</p>
                    <p class="text-[20px] font-bold tabular-nums" :class="(summary?.operational?.anomaly_critical||0)>0?'text-critical':'text-foreground'" x-text="formatN(summary?.operational?.anomaly_active)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5"><span x-text="formatN(summary?.operational?.anomaly_critical)"></span> critical</p>
                </div>
            </div>

            <div class="lg:col-span-4 px-4 py-3.5">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Activity window</p>
                    <p class="text-[10px] text-muted-foreground" x-show="freshness" x-text="freshness"></p>
                </div>
                <div class="flex flex-wrap gap-1.5 mb-3">
                    <template x-for="opt in windowOptions" :key="opt.h">
                        <button type="button" class="px-2 py-1 text-[11px] rounded-full border transition" :class="hours===opt.h?'bg-brand text-white border-brand':'bg-card hover:bg-muted/40'" @click="hours=opt.h; pushUrl(); load()" x-text="opt.label"></button>
                    </template>
                </div>
                <svg viewBox="0 0 200 36" class="w-full h-9" preserveAspectRatio="none" aria-hidden="true">
                    <template x-if="!sparklinePath"><text x="100" y="22" text-anchor="middle" class="fill-muted-foreground" style="font-size:8px">no data yet</text></template>
                    <template x-if="sparklinePath">
                        <g>
                            <polyline :points="sparklinePath.fill" fill="rgb(99 102 241 / .15)" stroke="none"/>
                            <polyline :points="sparklinePath.line" fill="none" stroke="rgb(99 102 241)" stroke-width="1.2"/>
                        </g>
                    </template>
                </svg>
                <p class="text-[9.5px] text-muted-foreground italic mt-1">Total events per hour over the chosen period.</p>
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
                <div class="flex-1"></div>

                <div class="relative w-full sm:w-72" x-show="activeTab==='activity'">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    <input type="search" class="input pl-8 w-full" placeholder="Search by name, email, IP, event…" minlength="2" x-model.debounce.250ms="filters.q" @input="loadData()">
                </div>
                <select class="select w-auto !h-8 text-xs" x-show="activeTab==='activity'" x-model="filters.event_type" @change="loadData()">
                    <option value="">Any event type</option>
                    <template x-for="e in (knownEvents||[])" :key="e"><option :value="e" x-text="eventLabel(e)"></option></template>
                </select>
                <select class="select w-auto !h-8 text-xs" x-show="activeTab==='activity'" x-model="filters.severity" @change="loadData()">
                    <option value="">Any severity</option>
                    <option value="INFO">Routine</option>
                    <option value="WARN">Worth a glance</option>
                    <option value="ERROR">Investigate</option>
                    <option value="CRITICAL">Investigate now</option>
                </select>

                <button class="btn btn-outline btn-sm" @click="exportCsv()" title="Download the visible rows as CSV">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"/></svg>
                    <span class="hidden sm:inline">Export</span>
                </button>
            </div>

            {{-- ── Activity tab ── --}}
            <div x-show="activeTab==='activity'" x-cloak class="flex-1 min-h-0 flex flex-col overflow-hidden">
                <div class="flex-1 min-h-0 overflow-auto">
                    <table class="table">
                        <thead class="table-head sticky top-0 z-10 bg-card"><tr>
                            <th class="table-head-th">When</th>
                            <th class="table-head-th">Event</th>
                            <th class="table-head-th">Severity</th>
                            <th class="table-head-th">Person</th>
                            <th class="table-head-th hidden md:table-cell">Origin</th>
                        </tr></thead>
                        <tbody class="table-body">
                            <template x-if="data.loading">
                                <template x-for="i in 8" :key="'sk'+i">
                                    <tr class="table-row"><td class="table-cell" colspan="5"><div class="h-4 w-full bg-muted/40 rounded animate-pulse"></div></td></tr>
                                </template>
                            </template>
                            <template x-if="!data.loading && data.rows.length===0">
                                <tr><td colspan="5" class="table-cell"><div class="empty-state"><p class="text-sm">No sign-in events match those filters.</p><p class="text-[11.5px] italic text-muted-foreground">Try widening the period or clearing the search.</p></div></td></tr>
                            </template>
                            <template x-for="row in data.rows" :key="row.id">
                                <tr class="table-row hover:bg-muted/20 cursor-pointer transition" @click="openRow(row)">
                                    <td class="table-cell text-[11px] whitespace-nowrap" :title="row.created_at" x-text="relativeTime(row.created_at)"></td>
                                    <td class="table-cell text-[11.5px]" x-text="eventLabel(row.event_type)"></td>
                                    <td class="table-cell"><span class="badge text-[9.5px]" :class="severityBadge(row.severity)" x-text="severityLabel(row.severity)"></span></td>
                                    <td class="table-cell text-[11.5px] truncate max-w-[14rem]">
                                        <template x-if="row.user_name"><span :title="row.user_name+' · '+(row.user_email||'')" x-text="row.user_name"></span></template>
                                        <template x-if="!row.user_name && row.email_attempted"><span class="italic" x-text="row.email_attempted"></span></template>
                                        <template x-if="!row.user_name && !row.email_attempted"><span class="italic text-muted-foreground">Anonymous</span></template>
                                    </td>
                                    <td class="table-cell hidden md:table-cell text-[11px] text-muted-foreground truncate max-w-[16rem]" :title="row.user_agent">
                                        <span class="font-mono" x-text="row.ip || '—'"></span>
                                        <span x-show="row.country" class="ml-1" x-text="'· '+row.country"></span>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between gap-3 px-4 sm:px-5 py-2.5 border-t bg-muted/20 text-[11px] shrink-0">
                    <p class="text-muted-foreground">Page <span class="tabular-nums" x-text="data.page"></span> of <span class="tabular-nums" x-text="data.pages"></span> · <span class="font-semibold tabular-nums text-foreground" x-text="formatN(data.total)"></span> events</p>
                    <div class="flex items-center gap-1.5">
                        <button class="btn btn-outline btn-xs" :disabled="data.page<=1" @click="data.page--; loadData()">Previous</button>
                        <button class="btn btn-outline btn-xs" :disabled="data.page>=data.pages" @click="data.page++; loadData()">Next</button>
                    </div>
                </div>
            </div>

            {{-- ── Lockouts tab ── --}}
            <div x-show="activeTab==='lockouts'" x-cloak class="flex-1 min-h-0 flex flex-col overflow-hidden">
                <div class="flex-1 min-h-0 overflow-auto p-4 space-y-5">
                    <section>
                        <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Locked out now <span class="font-normal normal-case text-muted-foreground/60">(<span x-text="lockouts.locked.length"></span>)</span></h3>
                        <template x-if="lockouts.loading"><div class="space-y-2"><template x-for="i in 4" :key="'lk'+i"><div class="h-9 bg-muted/40 rounded animate-pulse"></div></template></div></template>
                        <template x-if="!lockouts.loading && lockouts.locked.length===0"><p class="text-[11.5px] italic text-muted-foreground">No accounts are currently locked out.</p></template>
                        <ul class="divide-y rounded-lg border bg-card overflow-hidden">
                            <template x-for="u in lockouts.locked" :key="u.id">
                                <li class="px-3 py-2 flex items-center gap-2 text-[12px]">
                                    <div class="grid place-items-center h-7 w-7 rounded-full text-[10.5px] font-bold bg-critical-soft text-critical shrink-0" x-text="initials(u.full_name)"></div>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-semibold truncate" :title="u.full_name" x-text="u.full_name"></p>
                                        <p class="text-[10.5px] text-muted-foreground truncate"><span x-text="'@'+u.username"></span> · <span x-text="u.email"></span></p>
                                    </div>
                                    <div class="text-[10.5px] text-muted-foreground text-right shrink-0">
                                        <p x-text="(u.failed_login_count||0)+' failed'"></p>
                                        <p :title="u.locked_until">unlocks <span x-text="relativeTime(u.locked_until)"></span></p>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </section>
                    <section>
                        <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Suspended <span class="font-normal normal-case text-muted-foreground/60">(<span x-text="lockouts.suspended.length"></span>)</span></h3>
                        <template x-if="!lockouts.loading && lockouts.suspended.length===0"><p class="text-[11.5px] italic text-muted-foreground">No accounts are currently suspended.</p></template>
                        <ul class="divide-y rounded-lg border bg-card overflow-hidden">
                            <template x-for="u in lockouts.suspended" :key="u.id">
                                <li class="px-3 py-2 flex items-start gap-2 text-[12px]">
                                    <div class="grid place-items-center h-7 w-7 rounded-full text-[10.5px] font-bold bg-critical-soft text-critical shrink-0" x-text="initials(u.full_name)"></div>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-semibold truncate"><span class="line-through" x-text="u.full_name"></span></p>
                                        <p class="text-[10.5px] text-muted-foreground truncate"><span x-text="'@'+u.username"></span> · <span x-text="u.email"></span></p>
                                        <p class="text-[10.5px] mt-0.5 italic" x-show="u.suspension_reason" x-text="'“'+u.suspension_reason+'”'"></p>
                                    </div>
                                    <p class="text-[10.5px] text-muted-foreground shrink-0" :title="u.suspended_at" x-text="relativeTime(u.suspended_at)"></p>
                                </li>
                            </template>
                        </ul>
                    </section>
                </div>
            </div>

            {{-- ── MFA tab ── --}}
            <div x-show="activeTab==='mfa'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="rounded-xl border p-3"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Coverage</p><p class="text-[20px] font-bold tabular-nums" x-text="(summary?.mfa?.coverage_pct ?? 0)+'%'"></p><p class="text-[10.5px] text-muted-foreground mt-0.5"><span x-text="formatN(summary?.mfa?.enrolled)"></span> of <span x-text="formatN(summary?.mfa?.users)"></span> users</p></div>
                    <div class="rounded-xl border p-3"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Challenges</p><p class="text-[20px] font-bold tabular-nums" x-text="formatN(summary?.mfa?.challenged)"></p><p class="text-[10.5px] text-muted-foreground mt-0.5"><span x-text="formatN(summary?.mfa?.ok)"></span> answered correctly</p></div>
                    <div class="rounded-xl border p-3"><p class="text-[10px] uppercase tracking-wider text-muted-foreground">Failed second factor</p><p class="text-[20px] font-bold tabular-nums" :class="(summary?.mfa?.fail||0)>0?'text-critical':''" x-text="formatN(summary?.mfa?.fail)"></p><p class="text-[10.5px] text-muted-foreground mt-0.5">in this period</p></div>
                </div>
                <div class="rounded-xl border p-3">
                    <p class="text-[10px] uppercase tracking-wider text-muted-foreground mb-1">Login outcome by role</p>
                    <p class="text-[10.5px] italic text-muted-foreground mb-2">One bar per role; green is successful sign-ins, red is failed attempts.</p>
                    <ul class="space-y-1.5">
                        <template x-if="!summary || (summary.by_role||[]).length===0"><li class="text-[11px] italic text-muted-foreground">Not yet recorded</li></template>
                        <template x-for="r in (summary?.by_role||[])" :key="r.role_key">
                            <li class="text-[10.5px]" :title="`${r.ok} successful · ${r.fail} failed`">
                                <div class="flex items-center justify-between gap-2 mb-0.5">
                                    <span class="font-semibold" x-text="roleLabel(r.role_key)"></span>
                                    <span class="tabular-nums text-muted-foreground"><span x-text="r.ok"></span> ok · <span x-text="r.fail"></span> fail</span>
                                </div>
                                <div class="h-1.5 rounded-full bg-muted overflow-hidden flex">
                                    <div class="h-full bg-success" :style="`width:${roleBarPct(r,'ok')}%`"></div>
                                    <div class="h-full bg-critical" :style="`width:${roleBarPct(r,'fail')}%`"></div>
                                </div>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            {{-- ── Sources tab ── --}}
            <div x-show="activeTab==='sources'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 space-y-4">
                <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground">Top IPs by failed-attempt volume</h3>
                <p class="text-[10.5px] italic text-muted-foreground">Read the IP rows from top to bottom — the first ones are the most worth investigating. A high failure rate from one IP can indicate a brute-force attempt; a moderate failure rate across many users from one IP can indicate compromised credentials.</p>
                <ul class="divide-y rounded-lg border bg-card overflow-hidden">
                    <template x-if="!summary || (summary.top_ips||[]).length===0"><li class="px-3 py-2 text-[11.5px] italic text-muted-foreground">Not yet recorded</li></template>
                    <template x-for="r in (summary?.top_ips||[])" :key="r.ip">
                        <li class="px-3 py-2 flex items-center gap-2 text-[12px]">
                            <div class="min-w-0 flex-1">
                                <p class="font-mono text-[12px]" x-text="r.ip"></p>
                                <p class="text-[10.5px] text-muted-foreground"><span x-text="r.users_seen"></span> distinct user(s) · <span x-text="r.n"></span> events</p>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <span class="badge badge-success text-[9.5px]" x-text="r.oks+' ok'"></span>
                                <span class="badge text-[9.5px]" :class="r.fails>0?'badge-critical':'badge-outline'" x-text="r.fails+' fail'"></span>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- ── Anomalies tab ── --}}
            <div x-show="activeTab==='anomalies'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 space-y-4">
                <p class="text-[10.5px] italic text-muted-foreground">Flags raised by a small set of plain-English rules — too many failed sign-ins, sign-ins from unusual countries, role-changes the user did not request. The current rule set is version 1 and pending domain sign-off; clearing a flag without investigation will be visible in the audit trail.</p>
                <template x-if="anomalies.loading"><div class="space-y-2"><template x-for="i in 5" :key="'aa'+i"><div class="h-12 bg-muted/40 rounded animate-pulse"></div></template></div></template>
                <template x-if="!anomalies.loading && anomalies.rows.length===0"><p class="text-[12px] italic text-muted-foreground">No active anomaly flags. The screen will update on the next refresh.</p></template>
                <ul class="divide-y rounded-lg border bg-card overflow-hidden">
                    <template x-for="r in anomalies.rows" :key="r.id">
                        <li class="px-3 py-3 flex items-start gap-2.5 text-[12px]">
                            <span class="badge text-[9.5px] shrink-0" :class="severityBadge(r.severity)" x-text="severityLabel(r.severity)"></span>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold" x-text="anomalyLabel(r.flag_code)"></p>
                                <p class="text-[10.5px] text-muted-foreground truncate" x-text="(r.user_name||'Anonymous')+' · @'+(r.user_username||'')"></p>
                                <p class="text-[10.5px] text-muted-foreground" :title="r.last_seen_at">last seen <span x-text="relativeTime(r.last_seen_at)"></span></p>
                            </div>
                            <button class="btn btn-outline btn-xs shrink-0" @click="askClear(r)">Clear flag</button>
                        </li>
                    </template>
                </ul>
            </div>
        </div>
    </section>

    {{-- ─── Clear-anomaly typed-confirm modal ─────────────────────────── --}}
    <template x-if="clear.open">
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="clear.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="clear.open=false"></div>
            <div class="relative w-full max-w-md bg-card border rounded-2xl shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold">Clearing an anomaly flag</h3>
                <p class="text-[12px] text-muted-foreground mt-1">
                    For <span class="font-semibold text-foreground" x-text="clear.row?.user_name || 'Anonymous'"></span>:
                    <span x-text="anomalyLabel(clear.row?.flag_code)"></span>.
                </p>
                <p class="text-[11.5px] text-muted-foreground mt-2">Once cleared, this flag will not return on its own. The original flag stays in the historical record. Clearing without investigating will be visible in the audit trail.</p>
                <label class="label mt-3 block">What you concluded <span class="text-critical">*</span></label>
                <textarea class="input mt-1.5" rows="3" x-model="clear.note" placeholder="Plain words. At least thirty characters. Recorded permanently."></textarea>
                <p class="text-[10.5px] text-muted-foreground mt-1"><span x-text="(clear.note||'').length"></span> / 30 characters minimum.</p>
                <div class="flex justify-end gap-2 mt-5">
                    <button class="btn btn-outline btn-sm" @click="clear.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" :disabled="(clear.note||'').trim().length<30 || clear.busy" @click="performClear()">
                        <span x-show="!clear.busy">Clear flag</span>
                        <span x-show="clear.busy">Recording…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Row detail sheet ─────────────────────────────────────────── --}}
    <template x-if="rowSheet.open">
        <div class="fixed inset-0 z-[55] flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="rowSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="rowSheet.open=false"></div>
            <div class="relative w-full sm:max-w-lg bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <span class="badge text-[10px]" :class="severityBadge(rowSheet.row?.severity)" x-text="severityLabel(rowSheet.row?.severity)"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Sign-in event</p>
                        <h2 class="text-[14px] font-bold truncate" x-text="eventLabel(rowSheet.row?.event_type)"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="rowSheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-4 text-[12px]">
                    <section class="grid grid-cols-2 gap-3">
                        <div><p class="text-muted-foreground">When</p><p :title="rowSheet.row?.created_at" x-text="rowSheet.row?.created_at ? new Date(rowSheet.row.created_at).toLocaleString() : '—'"></p></div>
                        <div><p class="text-muted-foreground">Risk shift</p><p class="tabular-nums" x-text="rowSheet.row?.risk_delta ?? '—'"></p></div>
                        <div><p class="text-muted-foreground">Person</p><p x-text="rowSheet.row?.user_name || rowSheet.row?.email_attempted || 'Anonymous'"></p></div>
                        <div><p class="text-muted-foreground">Role</p><p x-text="roleLabel(rowSheet.row?.user_role) || '—'"></p></div>
                        <div><p class="text-muted-foreground">IP address</p><p class="font-mono text-[11px]" x-text="rowSheet.row?.ip || '—'"></p></div>
                        <div><p class="text-muted-foreground">Origin</p><p x-text="[rowSheet.row?.city, rowSheet.row?.country].filter(Boolean).join(', ') || '—'"></p></div>
                    </section>
                    <section>
                        <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-1">Device</p>
                        <p class="text-[11px] font-mono break-all" x-text="rowSheet.row?.user_agent || '—'"></p>
                    </section>
                    <section x-show="rowSheet.row?.payload">
                        <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-1">Technical details</p>
                        <pre class="text-[10.5px] font-mono bg-muted/40 rounded-md p-2 overflow-auto max-h-48" x-text="JSON.stringify(rowSheet.row?.payload, null, 2)"></pre>
                    </section>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Comparison sheet ──────────────────────────────────────────── --}}
    <template x-if="compareSheet.open">
        <div class="fixed inset-0 z-[58] flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true" @keydown.escape.window="compareSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="compareSheet.open=false"></div>
            <div class="relative w-full sm:max-w-3xl bg-card border-t sm:border sm:rounded-2xl shadow-elevation-5 max-h-[85vh] flex flex-col" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="min-w-0 flex-1"><p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Compare actions</p><h2 class="text-[14px] font-bold">Side-by-side: which one fits?</h2></div>
                    <button class="btn btn-ghost btn-icon-xs" @click="compareSheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-[11.5px]">
                        <thead class="sticky top-0 bg-card z-10 text-[10px] uppercase tracking-wider text-muted-foreground"><tr class="border-b">
                            <th class="text-left px-4 py-2 sticky left-0 bg-card">Action</th>
                            <template x-for="col in (coach.comparison_columns||[])" :key="col"><th class="text-left px-3 py-2" x-text="col"></th></template>
                        </tr></thead>
                        <tbody>
                            <template x-for="a in actionsArr" :key="a.id">
                                <tr class="border-b align-top">
                                    <td class="px-4 py-2.5 sticky left-0 bg-card"><p class="font-semibold" x-text="a.label"></p><p class="text-muted-foreground text-[10.5px]" x-text="a.one_liner"></p></td>
                                    <td class="px-3 py-2.5" x-text="a.when_to_use"></td>
                                    <td class="px-3 py-2.5 text-warning" x-text="a.when_not_to_use || '—'"></td>
                                    <td class="px-3 py-2.5"><span class="badge" :class="a.reversibility?.reversible?'badge-success':'badge-outline'" x-text="a.reversibility?.reversible?'Yes':'No'"></span></td>
                                    <td class="px-3 py-2.5 text-muted-foreground" x-text="a.estimated_time"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Coach sheet ──────────────────────────────────────────────── --}}
    <template x-if="coachSheet.open">
        <div class="fixed inset-0 z-[58] flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="coachSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="coachSheet.open=false"></div>
            <div class="relative w-full sm:max-w-md bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="min-w-0 flex-1"><p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Guide</p><h2 class="text-[14px] font-bold" x-text="coach.view.title"></h2></div>
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

    {{-- ─── Post-action ──────────────────────────────────────────────── --}}
    <template x-if="postAction.open">
        <div class="fixed inset-0 z-[63] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="postAction.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="postAction.open=false"></div>
            <div class="relative w-full max-w-md bg-card border rounded-2xl shadow-elevation-5 p-5" @click.stop>
                <div class="flex items-start gap-3">
                    <div class="grid place-items-center h-10 w-10 rounded-full bg-success-soft text-success shrink-0"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg></div>
                    <div class="min-w-0 flex-1"><h3 class="text-[14px] font-bold" x-text="postAction.title || 'Recorded'"></h3><p class="text-[12px] text-muted-foreground mt-1" x-text="postAction.body"></p></div>
                </div>
                <ul class="mt-3 list-disc pl-5 text-[11.5px] space-y-1" x-show="(postAction.changed||[]).length"><template x-for="c in (postAction.changed||[])" :key="c"><li x-text="c"></li></template></ul>
                <p class="text-[11px] text-muted-foreground mt-3" x-show="postAction.notified"><span class="font-semibold text-foreground">Who was notified:</span> <span x-text="postAction.notified"></span></p>
                <p class="text-[11px] text-muted-foreground mt-1" x-show="postAction.next" x-text="postAction.next"></p>
                <div class="flex justify-end mt-5"><button class="btn btn-brand btn-sm" @click="postAction.open=false">Close</button></div>
            </div>
        </div>
    </template>

    <div class="fixed inset-x-0 bottom-6 z-[70] flex justify-center px-3 pointer-events-none" x-show="opToast.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="opToast.kind==='success'?'toast-success':'toast-destructive'"><div><p class="toast-title" x-text="opToast.title"></p><p class="toast-description" x-text="opToast.body"></p></div></div>
    </div>
</div>

<style>
    @media (prefers-reduced-motion: reduce){ * { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
</style>
@endsection

@push('scripts')
<script>
function authEventsPage(){
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const idemKey=()=>{ if(crypto?.randomUUID) return crypto.randomUUID(); return 'k-'+Date.now()+'-'+Math.random().toString(36).slice(2); };
    const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf(),'Idempotency-Key':idemKey()});
    const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null&&v!==0&&v!=='0').map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');

    let coach = {};
    try{ coach = JSON.parse(document.getElementById('gov-auth-coach')?.textContent || '{}') || {}; }catch(e){ coach = {}; }
    coach.glossary = coach.glossary || [];
    coach.comparison_columns = coach.comparison_columns || ['When this fits','Heads-up','Reversible','Time'];
    const ACTIONS_ARR = Object.values(coach.actions || {});

    const ROLE_LABELS = { NATIONAL_ADMIN:'National Admin', PHEOC_ADMIN:'PHEOC Admin', PHEOC_OFFICER:'PHEOC Officer', DISTRICT_ADMIN:'District Admin', DISTRICT_SUPERVISOR:'District Supervisor', POE_ADMIN:'PoE Admin', POE_OFFICER:'PoE Officer', POE_DATA_OFFICER:'PoE Data Officer', SCREENER:'Screener', OBSERVER:'Observer', SERVICE:'Service Account', UNKNOWN:'Unknown account' };
    const SEVERITY_LABELS = { INFO:'Routine', WARN:'Worth a glance', ERROR:'Investigate', CRITICAL:'Investigate now' };
    const SEVERITY_BADGE  = { INFO:'badge-outline', WARN:'badge-warning', ERROR:'badge-critical', CRITICAL:'badge-critical' };
    const EVENT_LABELS = {
        LOGIN_OK:'Sign-in successful', LOGIN_FAIL:'Sign-in failed', LOGOUT:'Signed out',
        LOCKED:'Account locked', UNLOCKED:'Account unlocked',
        PASSWORD_CHANGED:'Password changed', PASSWORD_RESET_REQUESTED:'Password reset requested', PASSWORD_RESET_USED:'Password reset used',
        EMAIL_VERIFY_SENT:'Verification email sent', EMAIL_VERIFIED:'Email verified',
        EMAIL_CHANGE_REQUESTED:'Email change requested', EMAIL_CHANGED:'Email changed',
        TWOFA_ENABLED:'Multi-factor enabled', TWOFA_DISABLED:'Multi-factor disabled',
        TWOFA_CHALLENGED:'Multi-factor challenge offered', TWOFA_OK:'Multi-factor passed', TWOFA_FAIL:'Multi-factor failed',
        TRUSTED_DEVICE_ADDED:'Trusted device added', TRUSTED_DEVICE_REMOVED:'Trusted device removed', TRUSTED_DEVICE_USED:'Trusted device used',
        WEBAUTHN_REGISTERED:'Security key registered', WEBAUTHN_REMOVED:'Security key removed', WEBAUTHN_USED:'Security key used',
        ADMIN_CREATED:'Account created by admin', ADMIN_UPDATED:'Account updated by admin', ADMIN_SUSPENDED:'Account suspended', ADMIN_REACTIVATED:'Account reactivated',
        ROLE_CHANGED:'Role changed', ASSIGNMENT_CHANGED:'Jurisdiction changed',
        INVITATION_SENT:'Invitation sent', INVITATION_ACCEPTED:'Invitation accepted', INVITATION_EXPIRED:'Invitation expired',
        SESSION_REVOKED:'Session revoked', TOKEN_REVOKED:'Token revoked',
        LOGIN_RISK_HIGH:'Sign-in flagged as risky', ANOMALY_FLAGGED:'Anomaly flag raised', FORBIDDEN:'Access denied',
    };
    const ANOMALY_LABELS = {
        REPEATED_FAILURES:'Repeated failed sign-ins',
        UNUSUAL_COUNTRY:'Sign-in from unusual country',
        UNFAMILIAR_DEVICE:'Sign-in from unfamiliar device',
        ROLE_CHANGE_WITHOUT_TICKET:'Role changed without an authorising ticket',
        AFTER_HOURS_ELEVATION:'Privilege use outside usual hours',
        DORMANT_AWAKENED:'Dormant account suddenly active',
        IP_VELOCITY:'Many sign-ins from one address',
        BROKEN_MFA:'Multi-factor failed repeatedly',
    };

    const KNOWN_EVENTS = @json($known_events ?? []);

    return {
        coach, actionsArr: ACTIONS_ARR, knownEvents: KNOWN_EVENTS,
        coachIntroOpen:false, coachSheet:{open:false}, compareSheet:{open:false},
        stepThru:false,
        hours: 168,
        windowOptions: [
            {h:24,  label:'Past 24h'},
            {h:72,  label:'Past 3d'},
            {h:168, label:'Past 7d'},
            {h:336, label:'Past 14d'},
            {h:720, label:'Past 30d'},
        ],
        tabs: [
            {key:'activity', label:'Activity'},
            {key:'lockouts', label:'Lockouts & suspensions'},
            {key:'mfa', label:'MFA'},
            {key:'sources', label:'By origin'},
            {key:'anomalies', label:'Anomalies'},
        ],
        activeTab:'activity',
        summary: null,
        freshness:'',
        data: { loading:false, rows:[], page:1, pages:1, total:0, per_page:50 },
        lockouts: { loading:false, locked:[], suspended:[] },
        anomalies: { loading:false, rows:[], ranking:[] },
        rowSheet: { open:false, row:null },
        clear: { open:false, row:null, note:'', busy:false },
        postAction: { open:false, title:'', body:'', changed:[], notified:'', next:'' },
        opToast: { open:false, kind:'success', title:'', body:'', t:null },
        filters: { q:'', event_type:'', severity:'' },
        offline: !navigator.onLine,
        meId: parseInt(document.querySelector('meta[name="user-id"]')?.content || '0', 10) || 0,

        get sparklinePath(){
            const h = this.summary?.hourly || [];
            if (h.length < 2) return null;
            const max = Math.max(1, ...h.map(p => p.n));
            const w = 200, ht = 36;
            const step = w / Math.max(1, h.length - 1);
            let line = '', fill = '0,'+ht+' ';
            h.forEach((p, i) => {
                const x = i * step;
                const y = ht - (p.n / max) * (ht - 4) - 2;
                line += `${x.toFixed(1)},${y.toFixed(1)} `;
                fill += `${x.toFixed(1)},${y.toFixed(1)} `;
            });
            fill += `${w},${ht}`;
            return { line: line.trim(), fill: fill.trim() };
        },

        formatN(n){ if(n===null||n===undefined) return '—'; try{ return Number(n).toLocaleString(); }catch(e){ return String(n); } },
        roleLabel(k){ return ROLE_LABELS[k] || k || ''; },
        severityLabel(s){ return SEVERITY_LABELS[s] || s || ''; },
        severityBadge(s){ return SEVERITY_BADGE[s] || 'badge-outline'; },
        eventLabel(e){ return EVENT_LABELS[e] || e || ''; },
        anomalyLabel(c){ return ANOMALY_LABELS[c] || c || ''; },
        initials(name){ if(!name) return '·'; return (''+name).trim().split(/\s+/).slice(0,2).map(p=>p[0]||'').join('').toUpperCase() || '·'; },
        relativeTime(iso){
            if(!iso) return '—'; const d = new Date(iso); if(isNaN(d)) return '—';
            const diff = Date.now() - d.getTime(); const m=60_000, h=3600_000, day=86400_000;
            if(Math.abs(diff) < m) return 'just now';
            const future = diff < 0;
            const abs = Math.abs(diff);
            const v = abs < h ? Math.floor(abs/m)+'m' : abs < day ? Math.floor(abs/h)+'h' : abs < 7*day ? Math.floor(abs/day)+'d' : d.toLocaleDateString();
            return future ? 'in '+v : v+' ago';
        },
        roleBarPct(r, kind){
            const total = (r.ok||0) + (r.fail||0);
            if(total === 0) return 0;
            const ratio = (r[kind]||0) / total;
            return Math.round(ratio * 100);
        },

        async boot(){
            this.readUrl();
            this.stepThru = this.readPref('stepthru', false);
            window.addEventListener('online',  ()=>{ this.offline=false; this.load(); });
            window.addEventListener('offline', ()=>{ this.offline=true; });
            await this.load();
        },
        readUrl(){
            try{
                const u = new URL(window.location.href);
                const h = parseInt(u.searchParams.get('hours') || '0', 10);
                if(h > 0) this.hours = h;
                const t = u.searchParams.get('tab'); if(t) this.activeTab = t;
                ['q','event_type','severity'].forEach(k => { const v = u.searchParams.get(k); if(v) this.filters[k] = v; });
            }catch(e){}
        },
        pushUrl(){
            try{
                const u = new URL(window.location.href);
                u.searchParams.set('hours', String(this.hours));
                u.searchParams.set('tab', this.activeTab);
                ['q','event_type','severity'].forEach(k => { const v = this.filters[k]; if(v) u.searchParams.set(k, v); else u.searchParams.delete(k); });
                window.history.replaceState(null,'',u.toString());
            }catch(e){}
        },
        prefKey(k){ return 'gov:auth:'+k+':'+this.meId; },
        readPref(k,def){ try{ const v = localStorage.getItem(this.prefKey(k)); return v===null?def:JSON.parse(v); }catch(e){ return def; } },
        persistPref(k,v){ try{ localStorage.setItem(this.prefKey(k), JSON.stringify(v)); }catch(e){} },

        async load(){
            await Promise.all([this.loadSummary(), this.loadData(), this.loadLockouts(), this.loadAnomalies()]);
            this.freshness = 'Fresh as of ' + new Date().toLocaleTimeString();
        },

        async loadSummary(){
            try{
                const r = await fetch('/admin/governance/auth-events/summary?'+qs({hours:this.hours}), {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok) this.summary = j.data;
            }catch(e){}
        },
        async loadData(){
            this.data.loading = true;
            try{
                const r = await fetch('/admin/governance/auth-events/data?'+qs({hours:this.hours, page:this.data.page, per_page:this.data.per_page, ...this.filters}), {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok){ this.data.rows = j.data.rows; this.data.page = j.data.page; this.data.pages = j.data.pages; this.data.total = j.data.total; }
            }catch(e){ this.toast('error','Network',e.message); }
            finally{ this.data.loading = false; }
        },
        async loadLockouts(){
            this.lockouts.loading = true;
            try{
                const r = await fetch('/admin/governance/auth-events/lockouts', {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok){ this.lockouts.locked = j.data.locked || []; this.lockouts.suspended = j.data.suspended || []; }
            }catch(e){}
            finally{ this.lockouts.loading = false; }
        },
        async loadAnomalies(){
            this.anomalies.loading = true;
            try{
                const r = await fetch('/admin/governance/auth-events/anomalies', {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok){ this.anomalies.rows = j.data.rows || []; this.anomalies.ranking = j.data.ranking || []; }
            }catch(e){}
            finally{ this.anomalies.loading = false; }
        },

        openRow(row){ this.rowSheet = { open:true, row }; },

        askClear(row){ this.clear = { open:true, row, note:'', busy:false }; },
        async performClear(){
            if((this.clear.note || '').trim().length < 30) return;
            this.clear.busy = true;
            try{
                const r = await fetch('/admin/governance/auth-events/anomalies/'+this.clear.row.id+'/clear', {method:'POST', headers:headersJson(), body:JSON.stringify({note:this.clear.note})});
                const j = await r.json();
                if(j.ok){
                    this.clear.open = false;
                    this.postAction = {
                        open:true,
                        title:'Flag cleared',
                        body:'The anomaly flag for '+(this.clear.row.user_name||'this account')+' is marked reviewed and will not return on its own.',
                        changed:['Flag row marked cleared with your name and the time','Audit log records the clearance with your note'],
                        notified:'Audit-log reviewers.',
                        next:'Continue your review of the remaining flags, or close this and move on.',
                    };
                    await this.loadAnomalies();
                } else {
                    this.toast('error','Could not clear', j.error==='already_cleared' ? 'This flag was cleared by someone else just now.' : (j.error || 'Unknown error.'));
                }
            }catch(e){ this.toast('error','Network', e.message); }
            finally{ this.clear.busy = false; }
        },

        exportCsv(){
            const url = '/admin/governance/auth-events/export?'+qs({hours:this.hours});
            window.location.href = url;
            this.toast('success','Export starting','The download will begin in a moment. The export is logged with your name.');
        },

        toast(kind, title, body){ this.opToast = {open:true, kind, title, body, t:null}; clearTimeout(this.opToast.t); this.opToast.t = setTimeout(()=>{ this.opToast.open=false; }, 3500); },
    };
}
</script>
@endpush
