{{-- Admin · System Health · Mail Delivery (sys-mail) — v4 shell --}}
@extends('admin.layout')

@section('crumb', 'System Health')
@section('title', $page_title)

@php
    /** @var array $coach */
    $coach = $coach ?? \App\Support\System\CoachManifest::forView('mail');
@endphp

@section('content')
<div x-data="mailPage()" x-init="boot()"
     x-effect="window.adminLock?.set?.('page', coachSheet?.open || wizardSheet?.open || rowSheet?.open || interpretSheet?.open)"
     class="flex flex-col gap-4 min-h-[calc(100vh-7rem)]">

    <script type="application/json" id="sys-mail-coach">@json($coach)</script>

    {{-- ─── Coach intro strip ─────────────────────────────────────────── --}}
    <section class="rounded-2xl border bg-card shadow-sm">
        <button type="button" class="w-full text-left flex items-start gap-3 px-4 sm:px-5 py-3"
                @click="coachIntroOpen=!coachIntroOpen" :aria-expanded="coachIntroOpen">
            <span class="grid place-items-center h-8 w-8 rounded-lg bg-brand-soft text-brand-ink shrink-0 mt-0.5">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </span>
            <span class="flex-1 min-w-0">
                <span class="block text-[12.5px] font-semibold leading-tight" x-text="coach.view.title"></span>
                <span class="block text-[11.5px] text-muted-foreground mt-0.5 leading-snug" x-text="coach.view.header_intro"></span>
            </span>
            <span class="shrink-0 flex items-center gap-2">
                <button type="button" class="btn btn-outline btn-xs" @click.stop="wizardSheet.open=true" title="Help me decide where to start">What do you want to do?</button>
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
                <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Heads-up on PII</p>
                <p class="mt-0.5">Recipient identifiers are partly hidden by default. Opening a row unmasks the recipient — that action is recorded with your name.</p>
            </div>
        </div>
    </section>

    {{-- ─── Hero — health pill + counters + sparkline ────────────────── --}}
    <section class="rounded-2xl border bg-gradient-to-br from-card via-card to-muted/30 shadow-sm overflow-hidden">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-0">
            <div class="lg:col-span-8 grid grid-cols-2 sm:grid-cols-4 gap-0 divide-x divide-y sm:divide-y-0 border-b lg:border-b-0 lg:border-r">
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Overall health</p>
                    <div class="flex items-center gap-2 mt-1.5">
                        <span class="status-dot" :class="healthDot()" aria-hidden="true"></span>
                        <p class="text-[14px] font-bold leading-tight" x-text="summary?.health?.plain || '—'"></p>
                    </div>
                </div>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Delivery rate</p>
                    <p class="text-[20px] font-bold tabular-nums" :class="rateClass(summary?.totals?.delivery_pct)" x-text="(summary?.totals?.delivery_pct ?? '—') + '%'"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">accepted by recipient mail server</p>
                </div>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Came back undeliverable</p>
                    <p class="text-[20px] font-bold tabular-nums" :class="(summary?.totals?.bounced||0)>0?'text-warning':'text-muted-foreground'" x-text="formatN(summary?.totals?.bounced)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5"><span x-text="summary?.totals?.bounce_pct ?? '—'"></span>% of attempts</p>
                </div>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Failed in transit</p>
                    <p class="text-[20px] font-bold tabular-nums" :class="(summary?.totals?.failed||0)>0?'text-critical':'text-muted-foreground'" x-text="formatN(summary?.totals?.failed)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">network or server problem</p>
                </div>
            </div>
            <div class="lg:col-span-4 px-4 py-3.5">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Period</p>
                    <p class="text-[10px] text-muted-foreground" x-show="freshness" x-text="freshness"></p>
                </div>
                <div class="flex flex-wrap gap-1.5 mb-3">
                    <template x-for="opt in windowOptions" :key="opt.h">
                        <button type="button" class="px-2 py-1 text-[11px] rounded-full border transition" :class="hours===opt.h?'bg-brand text-white border-brand':'bg-card hover:bg-muted/40'" @click="hours=opt.h; pushUrl(); load()" x-text="opt.label"></button>
                    </template>
                </div>
                <svg viewBox="0 0 200 36" class="w-full h-9" preserveAspectRatio="none" aria-hidden="true">
                    <template x-if="!sparklinePaths"><text x="100" y="22" text-anchor="middle" class="fill-muted-foreground" style="font-size:8px">no data yet</text></template>
                    <template x-if="sparklinePaths">
                        <g>
                            <polyline :points="sparklinePaths.sentFill" fill="rgb(34 197 94 / .15)" stroke="none"/>
                            <polyline :points="sparklinePaths.sent" fill="none" stroke="rgb(34 197 94)" stroke-width="1.2"/>
                            <polyline :points="sparklinePaths.failed" fill="none" stroke="rgb(239 68 68)" stroke-width="1"/>
                        </g>
                    </template>
                </svg>
                <div class="flex justify-between items-center mt-1">
                    <p class="text-[9.5px] text-muted-foreground italic">Sends per hour: green delivered, red failed.</p>
                    <button class="text-[10.5px] text-brand underline-offset-2 hover:underline" @click="openInterpret('sparkline')">How to read</button>
                </div>
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
                <select class="select w-auto !h-8 text-xs" x-show="activeTab==='recent'" x-model="filters.status" @change="loadSends()">
                    <option value="">Any outcome</option>
                    <option value="SENT">Delivered</option>
                    <option value="FAILED">Failed</option>
                    <option value="BOUNCED">Came back undeliverable</option>
                </select>
            </div>

            {{-- ── Overview tab ── --}}
            <div x-show="activeTab==='overview'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="rounded-xl border p-4">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold">By recipient organisation (top 12)</p>
                            <button class="text-[10.5px] text-brand underline-offset-2 hover:underline" @click="openInterpret('domain_league')">How to read</button>
                        </div>
                        <ul class="space-y-1.5">
                            <template x-if="!summary || (summary.domains||[]).length===0">
                                <li class="text-[11.5px] italic text-muted-foreground">No mail in this window yet.</li>
                            </template>
                            <template x-for="d in (summary?.domains || [])" :key="d.domain">
                                <li class="text-[10.5px]">
                                    <div class="flex items-center justify-between gap-2 mb-0.5">
                                        <span class="font-mono truncate flex-1" x-text="d.domain"></span>
                                        <span class="tabular-nums text-muted-foreground">
                                            <span x-text="d.sent"></span> ok ·
                                            <span :class="d.failed>0?'text-critical':''" x-text="d.failed+' fail'"></span> ·
                                            <span :class="d.bounced>0?'text-warning':''" x-text="d.bounced+' bounce'"></span>
                                        </span>
                                    </div>
                                    <div class="h-1.5 rounded-full bg-muted overflow-hidden flex">
                                        <div class="h-full bg-success" :style="`width:${barPct(d,'sent',d.n)}%`"></div>
                                        <div class="h-full bg-critical" :style="`width:${barPct(d,'failed',d.n)}%`"></div>
                                        <div class="h-full bg-warning" :style="`width:${barPct(d,'bounced',d.n)}%`"></div>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </div>
                    <div class="rounded-xl border p-4">
                        <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold mb-2">By send type</p>
                        <ul class="space-y-1.5">
                            <template x-if="!summary || (summary.send_types||[]).length===0">
                                <li class="text-[11.5px] italic text-muted-foreground">No mail in this window yet.</li>
                            </template>
                            <template x-for="t in (summary?.send_types || [])" :key="t.template_code">
                                <li class="text-[10.5px]">
                                    <div class="flex items-center justify-between gap-2 mb-0.5">
                                        <span class="font-semibold flex-1" x-text="sendTypeLabel(t.template_code)"></span>
                                        <span class="tabular-nums text-muted-foreground"><span x-text="t.n"></span> attempts · <span :class="rateClass(t.success_pct)" x-text="t.success_pct+'%'"></span></span>
                                    </div>
                                    <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                                        <div class="h-full bg-success" :style="`width:${t.success_pct}%`"></div>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </div>
                </div>
                <div class="rounded-xl border p-4">
                    <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold mb-2">When mail last moved</p>
                    <dl class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-[11.5px]">
                        <div><dt class="text-muted-foreground">Last successful delivery</dt><dd class="font-semibold" x-text="latestPhrase(summary?.latest?.minutes_since_sent)"></dd></div>
                        <div><dt class="text-muted-foreground">Last failure</dt><dd class="font-semibold" x-text="latestPhrase(summary?.latest?.minutes_since_failed) || 'No failures recorded.'"></dd></div>
                        <div><dt class="text-muted-foreground">Last bounce</dt><dd class="font-semibold" x-text="latestPhrase(summary?.latest?.minutes_since_bounced) || 'No bounces recorded.'"></dd></div>
                    </dl>
                </div>
            </div>

            {{-- ── Recent Sends tab ── --}}
            <div x-show="activeTab==='recent'" x-cloak class="flex-1 min-h-0 flex flex-col overflow-hidden">
                <div class="flex-1 min-h-0 overflow-auto">
                    <table class="table">
                        <thead class="table-head sticky top-0 z-10 bg-card"><tr>
                            <th class="table-head-th">When</th>
                            <th class="table-head-th">Outcome</th>
                            <th class="table-head-th">Send type</th>
                            <th class="table-head-th">Recipient</th>
                            <th class="table-head-th hidden md:table-cell">Reason</th>
                        </tr></thead>
                        <tbody class="table-body">
                            <template x-if="sends.loading">
                                <template x-for="i in 8" :key="'sk'+i">
                                    <tr class="table-row"><td class="table-cell" colspan="5"><div class="h-4 w-full bg-muted/40 rounded animate-pulse"></div></td></tr>
                                </template>
                            </template>
                            <template x-if="!sends.loading && sends.rows.length===0">
                                <tr><td colspan="5" class="table-cell"><div class="empty-state"><p class="text-sm">No mail matches those filters.</p></div></td></tr>
                            </template>
                            <template x-for="row in sends.rows" :key="row.id">
                                <tr class="table-row hover:bg-muted/20 cursor-pointer" @click="openRow(row)">
                                    <td class="table-cell text-[11px] whitespace-nowrap" :title="row.when" x-text="row.when"></td>
                                    <td class="table-cell"><span class="badge text-[9.5px]" :class="statusBadge(row.status)" x-text="statusLabel(row.status)"></span></td>
                                    <td class="table-cell text-[11.5px]" x-text="sendTypeLabel(row.template_code)"></td>
                                    <td class="table-cell text-[11.5px] font-mono" x-text="row.recipient_mask || '—'"></td>
                                    <td class="table-cell text-[11.5px] hidden md:table-cell italic text-muted-foreground" x-text="row.plain_reason || '—'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <div class="flex items-center justify-between gap-3 px-4 sm:px-5 py-2.5 border-t bg-muted/20 text-[11px] shrink-0">
                    <p class="text-muted-foreground">Page <span class="tabular-nums" x-text="sends.page"></span> of <span class="tabular-nums" x-text="sends.pages"></span> · <span class="font-semibold tabular-nums text-foreground" x-text="formatN(sends.total)"></span> sends</p>
                    <div class="flex items-center gap-1.5">
                        <button class="btn btn-outline btn-xs" :disabled="sends.page<=1" @click="sends.page--; loadSends()">Previous</button>
                        <button class="btn btn-outline btn-xs" :disabled="sends.page>=sends.pages" @click="sends.page++; loadSends()">Next</button>
                    </div>
                </div>
            </div>

            {{-- ── Bounces tab ── --}}
            <div x-show="activeTab==='bounces'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-3">
                <p class="text-[11.5px] italic text-muted-foreground">Mail that came back undeliverable, with the recipient mail server’s reason in plain language. The original response is behind a disclosure.</p>
                <ul class="divide-y rounded-xl border bg-card overflow-hidden">
                    <template x-if="!sends.loading && bounceRows.length===0">
                        <li class="px-3 py-3 text-[12px] italic text-muted-foreground">No bounces in the chosen window.</li>
                    </template>
                    <template x-for="row in bounceRows" :key="'bn-'+row.id">
                        <li class="px-3 py-3 text-[12px] cursor-pointer hover:bg-muted/20" @click="openRow(row)">
                            <div class="flex items-start gap-2">
                                <span class="badge text-[9.5px]" :class="statusBadge(row.status)" x-text="statusLabel(row.status)"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="font-semibold" x-text="row.plain_reason"></p>
                                    <p class="text-[10.5px] text-muted-foreground mt-0.5">
                                        <span x-text="sendTypeLabel(row.template_code)"></span>
                                        · recipient at <span class="font-mono" x-text="row.recipient_dom"></span>
                                        · <span x-text="row.when"></span>
                                    </p>
                                </div>
                            </div>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- ── By Recipient tab ── --}}
            <div x-show="activeTab==='recipients'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-3">
                <p class="text-[11.5px] italic text-muted-foreground">Recipient organisations grouped by the part of the email after the @. Useful for spotting whole-organisation problems.</p>
                <ul class="divide-y rounded-xl border bg-card overflow-hidden">
                    <template x-if="!summary || (summary.domains||[]).length===0">
                        <li class="px-3 py-3 text-[12px] italic text-muted-foreground">No mail in this window yet.</li>
                    </template>
                    <template x-for="d in (summary?.domains || [])" :key="'rl-'+d.domain">
                        <li class="px-3 py-3 text-[12px]">
                            <div class="flex items-center justify-between gap-2 mb-1">
                                <p class="font-mono text-[12px] truncate" x-text="d.domain"></p>
                                <p class="text-[10.5px] tabular-nums" :class="rateClass(d.success_pct)" x-text="d.success_pct+'% delivered'"></p>
                            </div>
                            <p class="text-[10.5px] text-muted-foreground">
                                <span x-text="d.n"></span> attempts ·
                                <span x-text="d.sent"></span> delivered ·
                                <span :class="d.failed>0?'text-critical':''" x-text="d.failed"></span> failed ·
                                <span :class="d.bounced>0?'text-warning':''" x-text="d.bounced"></span> undeliverable
                            </p>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- ── Methodology tab ── --}}
            <div x-show="activeTab==='method'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-6 space-y-4 text-[12px] leading-relaxed">
                <h3 class="text-[14px] font-bold">How this page works</h3>
                <p>For every email the platform tried to send, this page reads one row in the master notification log. The status — <em>delivered</em>, <em>came back undeliverable</em>, or <em>failed in transit</em> — tells you what the recipient mail server replied. Acceptance is not the same as “the recipient read the message”; the platform cannot know that.</p>
                <p><strong>Sends, deliveries, bounces, failures.</strong> A <em>send</em> is one attempt to one recipient. A <em>delivery</em> is a send the recipient mail server accepted. A <em>bounce</em> is a send that came back undeliverable — usually the address is wrong, the mailbox is full, or the recipient mail server treated us as untrusted. A <em>failure</em> is a problem at our end or in transit; failures are usually retried automatically.</p>
                <p><strong>Send type.</strong> The platform sends several kinds of mail — daily digests, follow-up reminders, sign-in codes, notifications. The list is discovered from the actual messages sent in the chosen window; new send types appear here automatically.</p>
                <p><strong>Recipient privacy.</strong> Recipient identifiers are masked by default. Opening a row, exporting a list, or running a manual trigger that names a recipient is recorded in the audit log with your name and the columns that were unmasked.</p>
                <details class="text-[10.5px]">
                    <summary class="cursor-pointer text-muted-foreground">Show technical detail (transport configuration)</summary>
                    <p class="mt-2 text-[10.5px]">For administrators who want it: this is the underlying configuration the platform uses to send mail. The fields are read from <span class="font-mono">config/mail.php</span>; secrets are never surfaced here.</p>
                    <dl class="mt-2 grid grid-cols-[10rem_1fr] gap-y-1 text-[10.5px]">
                        <dt class="text-muted-foreground">Transport name</dt><dd class="font-mono" x-text="summary?.transport?.transport || '—'"></dd>
                        <dt class="text-muted-foreground">Mailer key</dt><dd class="font-mono" x-text="summary?.transport?.mailer || '—'"></dd>
                        <dt class="text-muted-foreground">Outbound host</dt><dd class="font-mono" x-text="summary?.transport?.host || '—'"></dd>
                        <dt class="text-muted-foreground">Port</dt><dd class="font-mono" x-text="summary?.transport?.port ?? '—'"></dd>
                        <dt class="text-muted-foreground">Encryption scheme</dt><dd class="font-mono" x-text="summary?.transport?.scheme || '—'"></dd>
                        <dt class="text-muted-foreground">Authenticated</dt><dd x-text="summary?.transport?.has_auth ? 'Yes' : 'No'"></dd>
                        <dt class="text-muted-foreground">From address</dt><dd class="font-mono" x-text="summary?.transport?.from_addr || '—'"></dd>
                    </dl>
                </details>
                <h3 class="text-[14px] font-bold mt-4">Translator versions</h3>
                <p class="text-[10.5px] text-muted-foreground">Provider-error translator: <span class="font-mono">DeliveryErrorTranslator/v1 · domain sign-off pending</span>.</p>
            </div>
        </div>
    </section>

    {{-- ─── Row detail sheet ─────────────────────────────────────────── --}}
    <template x-if="rowSheet.open">
        <div class="fixed inset-0 z-[55] flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="rowSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="rowSheet.open=false"></div>
            <div class="relative w-full sm:max-w-lg bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <span class="badge text-[10px]" :class="statusBadge(rowSheet.row?.status)" x-text="statusLabel(rowSheet.row?.status)"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Mail delivery</p>
                        <h2 class="text-[14px] font-bold truncate" x-text="sendTypeLabel(rowSheet.row?.template_code)"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="rowSheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-4 text-[12px]">
                    <section class="grid grid-cols-2 gap-3">
                        <div><p class="text-muted-foreground">When</p><p :title="rowSheet.row?.when" x-text="rowSheet.row?.when"></p></div>
                        <div><p class="text-muted-foreground">Outcome</p><p x-text="statusLabel(rowSheet.row?.status)"></p></div>
                        <div><p class="text-muted-foreground">Recipient</p><p class="font-mono" x-text="rowSheet.row?.recipient_mask || '—'"></p></div>
                        <div><p class="text-muted-foreground">Recipient organisation</p><p class="font-mono" x-text="rowSheet.row?.recipient_dom || '—'"></p></div>
                        <div><p class="text-muted-foreground">Triggered by</p><p x-text="triggeredByLabel(rowSheet.row?.triggered_by)"></p></div>
                        <div><p class="text-muted-foreground">Retried</p><p class="tabular-nums" x-text="(rowSheet.row?.retry_count ?? 0)+' time(s)'"></p></div>
                    </section>
                    <section x-show="rowSheet.row?.plain_reason">
                        <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-1">Why this didn’t deliver</p>
                        <p x-text="rowSheet.row?.plain_reason"></p>
                    </section>
                    <details class="text-[10.5px]" x-show="rowSheet.row?.technical_raw">
                        <summary class="cursor-pointer text-muted-foreground">Show technical detail</summary>
                        <pre class="mt-1 p-2 rounded bg-muted/40 font-mono text-[10.5px] overflow-auto max-h-40" x-text="rowSheet.row?.technical_raw"></pre>
                    </details>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Wizard sheet ──────────────────────────────────────────────── --}}
    <template x-if="wizardSheet.open">
        <div class="fixed inset-0 z-[58] flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="wizardSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="wizardSheet.open=false"></div>
            <div class="relative w-full sm:max-w-md bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="min-w-0 flex-1"><p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Wizard</p><h2 class="text-[14px] font-bold" x-text="coach.wizard?.launcher_label || 'What do you want to do?'"></h2></div>
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

    {{-- ─── Interpretation modal ─────────────────────────────────────── --}}
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
function mailPage(){
    const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');

    let coach = {};
    try{ coach = JSON.parse(document.getElementById('sys-mail-coach')?.textContent || '{}') || {}; }catch(e){ coach = {}; }
    coach.glossary = coach.glossary || [];
    coach.charts   = coach.charts   || {};
    coach.wizard   = coach.wizard   || { options: [] };
    const ACTIONS_ARR = Object.values(coach.actions || {});

    const STATUS_LABEL = { SENT:'Delivered', FAILED:'Failed', BOUNCED:'Came back undeliverable', SKIPPED:'Skipped', QUEUED:'Waiting to send' };
    const STATUS_BADGE = { SENT:'badge-success', FAILED:'badge-critical', BOUNCED:'badge-warning', SKIPPED:'badge-outline', QUEUED:'badge-outline' };
    const TEMPLATE_LABEL = {
        DAILY_REPORT:'Morning digest',
        NATIONAL_INTELLIGENCE:'National intelligence digest',
        FOLLOWUP_DUE:'Follow-up reminder',
        FOLLOWUP_OVERDUE:'Overdue follow-up reminder',
        BREACH_717:'7-1-7 breach notification',
        ALERT_CRITICAL:'Critical alert notification',
        ALERT_NEW:'New alert notification',
        SIGNIN_CODE:'Sign-in code',
        PASSWORD_RESET:'Password-reset link',
        EMAIL_VERIFY:'Email verification',
    };
    const TRIG_LABEL = { 'CRON:daily-digest':'Morning digest', 'CRON:followup-reminders':'Follow-up reminders', 'CRON:retry':'Retry queue', 'CRON:national-digest':'National intelligence digest', 'CRON:scan-sla-breaches':'SLA breach scanner' };

    return {
        coach, actionsArr: ACTIONS_ARR,
        coachIntroOpen:false,
        coachSheet:{open:false},
        wizardSheet:{open:false},
        interpretSheet:{open:false, chart:null},
        rowSheet:{open:false, row:null},
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
            {key:'overview',   label:'Overview'},
            {key:'recent',     label:'Recent sends'},
            {key:'bounces',    label:'Bounces'},
            {key:'recipients', label:'By recipient'},
            {key:'method',     label:'Methodology'},
        ],
        activeTab:'overview',

        summary:null, freshness:'',
        sends:{loading:false, rows:[], page:1, pages:1, total:0, per_page:50},
        filters:{ status:'', template:'' },

        formatN(n){ if(n===null||n===undefined) return '—'; try{ return Number(n).toLocaleString(); }catch(e){ return String(n); } },

        statusLabel(s){ return STATUS_LABEL[s] || (s ?? ''); },
        statusBadge(s){ return STATUS_BADGE[s] || 'badge-outline'; },
        sendTypeLabel(t){ if(!t) return ''; return TEMPLATE_LABEL[t] || t; },
        triggeredByLabel(t){ return TRIG_LABEL[t] || (t ?? '—'); },

        rateClass(p){ if(p===undefined||p===null) return 'text-muted-foreground'; if(p>=95) return 'text-success'; if(p>=90) return 'text-warning'; return 'text-critical'; },
        latestPhrase(min){
            if(min === null || min === undefined) return null;
            if(min < 1) return 'Just now.';
            if(min < 60) return min+' minutes ago.';
            const h = Math.round(min/60);
            if(h < 24) return h+' hours ago.';
            const d = Math.round(h/24);
            return d+' days ago.';
        },
        barPct(d, k, total){ if(!total) return 0; return Math.round((d[k]||0)/total*100); },
        healthDot(){ const lvl=this.summary?.health?.level; if(lvl==='green') return 'status-dot-live'; if(lvl==='amber') return 'status-dot-warn'; if(lvl==='red') return 'status-dot-danger'; return ''; },

        get bounceRows(){ return (this.sends.rows||[]).filter(r => r.status==='BOUNCED'); },
        get sparklinePaths(){
            const h = this.summary?.sparkline || [];
            if (h.length < 2) return null;
            const max = Math.max(1, ...h.map(p => Math.max(p.sent||0, p.failed||0, p.bounced||0)));
            const w = 200, ht = 36;
            const step = w / Math.max(1, h.length - 1);
            const ptsLine = (key) => h.map((p, i) => {
                const x = i * step;
                const y = ht - ((p[key]||0) / max) * (ht - 4) - 2;
                return `${x.toFixed(1)},${y.toFixed(1)}`;
            }).join(' ');
            return {
                sent: ptsLine('sent'),
                sentFill: '0,'+ht+' '+ptsLine('sent')+' '+w+','+ht,
                failed: ptsLine('failed'),
            };
        },

        openInterpret(key){ const chart = this.coach.charts?.[key]; if(!chart) return; this.interpretSheet = {open:true, chart}; },
        openRow(row){ this.rowSheet = {open:true, row}; },

        pickWizardOption(opt){
            this.wizardSheet.open = false;
            this.activeTab = opt.goto_tab || 'overview';
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
            await Promise.all([this.loadSummary(), this.loadSends()]);
            this.freshness = 'Fresh as of ' + new Date().toLocaleTimeString();
        },
        async loadSummary(){
            try{
                const r = await fetch('/admin/system/mail/summary?'+qs({hours:this.hours}), {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok) this.summary = j.data;
            }catch(e){}
        },
        async loadSends(){
            this.sends.loading = true;
            try{
                const r = await fetch('/admin/system/mail/sends?'+qs({hours:this.hours, page:this.sends.page, per_page:this.sends.per_page, ...this.filters}), {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok){
                    this.sends.rows = j.data.rows || [];
                    this.sends.page = j.data.page || 1;
                    this.sends.pages = j.data.pages || 1;
                    this.sends.total = j.data.total || 0;
                }
            }catch(e){ this.toast('error','Network', e.message); }
            finally{ this.sends.loading = false; }
        },

        toast(kind, title, body){ this.opToast = {open:true, kind, title, body, t:null}; clearTimeout(this.opToast.t); this.opToast.t = setTimeout(()=>{ this.opToast.open=false; }, 4500); },
    };
}
</script>
@endpush
