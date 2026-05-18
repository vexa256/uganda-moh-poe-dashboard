{{-- Admin · System Health · Mobile App Health (sys-mobile) — v4 shell --}}
@extends('admin.layout')

@section('crumb', 'System Health')
@section('title', $page_title)

@php
    /** @var array $coach */
    $coach = $coach ?? \App\Support\System\CoachManifest::forView('mobile');
@endphp

@section('content')
<div x-data="mobilePage()" x-init="boot()"
     x-effect="window.adminLock?.set?.('page', coachSheet?.open || wizardSheet?.open || rowSheet?.open || interpretSheet?.open)"
     class="flex flex-col gap-4 min-h-[calc(100vh-7rem)]">

    <script type="application/json" id="sys-mobile-coach">@json($coach)</script>

    {{-- ─── Coach intro strip ─────────────────────────────────────────── --}}
    <section class="rounded-2xl border bg-card shadow-sm">
        <button type="button" class="w-full text-left flex items-start gap-3 px-4 sm:px-5 py-3"
                @click="coachIntroOpen=!coachIntroOpen" :aria-expanded="coachIntroOpen">
            <span class="grid place-items-center h-8 w-8 rounded-lg bg-brand-soft text-brand-ink shrink-0 mt-0.5">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 002-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
            </span>
            <span class="flex-1 min-w-0">
                <span class="block text-[12.5px] font-semibold leading-tight" x-text="coach.view.title"></span>
                <span class="block text-[11.5px] text-muted-foreground mt-0.5 leading-snug" x-text="coach.view.header_intro"></span>
            </span>
            <span class="shrink-0 flex items-center gap-2">
                <button type="button" class="btn btn-outline btn-xs" @click.stop="wizardSheet.open=true">What do you want to do?</button>
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
                <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">A note on “waiting”</p>
                <p class="mt-0.5">Pending uploads usually clear by themselves once the phone has a signal. A small backlog is normal; a single device with hundreds of pending rows for many hours is not.</p>
            </div>
        </div>
    </section>

    {{-- ─── Hero ──────────────────────────────────────────────────────── --}}
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
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Devices reporting</p>
                    <p class="text-[20px] font-bold tabular-nums" x-text="formatN(summary?.totals?.distinct_devices)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">in the chosen window</p>
                </div>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Waiting to upload</p>
                    <p class="text-[20px] font-bold tabular-nums" :class="(summary?.totals?.unsynced_total||0)>0?'text-warning':'text-muted-foreground'" x-text="formatN(summary?.totals?.unsynced_total)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">on operators' phones</p>
                </div>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Retrying after a problem</p>
                    <p class="text-[20px] font-bold tabular-nums" :class="(summary?.totals?.failed_total||0)>0?'text-critical':'text-muted-foreground'" x-text="formatN(summary?.totals?.failed_total)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">previous attempt did not deliver</p>
                </div>
            </div>
            <div class="lg:col-span-4 px-4 py-3.5">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Period</p>
                    <p class="text-[10px] text-muted-foreground" x-show="freshness" x-text="freshness"></p>
                </div>
                <div class="flex flex-wrap gap-1.5 mb-3">
                    <template x-for="opt in windowOptions" :key="opt.d">
                        <button type="button" class="px-2 py-1 text-[11px] rounded-full border transition" :class="days===opt.d?'bg-brand text-white border-brand':'bg-card hover:bg-muted/40'" @click="days=opt.d; pushUrl(); load()" x-text="opt.label"></button>
                    </template>
                </div>
                <svg viewBox="0 0 200 36" class="w-full h-9" preserveAspectRatio="none" aria-hidden="true">
                    <template x-if="!sparklinePath"><text x="100" y="22" text-anchor="middle" class="fill-muted-foreground" style="font-size:8px">no data yet</text></template>
                    <template x-if="sparklinePath">
                        <g>
                            <polyline :points="sparklinePath.fill" fill="rgb(245 158 11 / .15)" stroke="none"/>
                            <polyline :points="sparklinePath.line" fill="none" stroke="rgb(245 158 11)" stroke-width="1.2"/>
                        </g>
                    </template>
                </svg>
                <div class="flex justify-between items-center mt-1">
                    <p class="text-[9.5px] text-muted-foreground italic">Pending uploads per day. Healthy = drops near zero each day.</p>
                    <button class="text-[10.5px] text-brand underline-offset-2 hover:underline" @click="openInterpret('pending_sparkline')">How to read</button>
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
                        <button class="tabs-trigger flex-1 sm:flex-none" :data-state="activeTab===t.key?'active':'inactive'" @click="activeTab=t.key; pushUrl(); maybeLoadTab()">
                            <span x-text="t.label"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- ── Overview ── --}}
            <div x-show="activeTab==='overview'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-4">
                <div class="rounded-xl border p-4">
                    <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold mb-2">Per mobile-write area</p>
                    <ul class="space-y-1.5">
                        <template x-for="t in (summary?.tables || [])" :key="'tbl-'+t.table">
                            <li class="text-[11.5px]">
                                <div class="flex items-center justify-between gap-2 mb-0.5">
                                    <span class="font-semibold" x-text="tableLabel(t.table)"></span>
                                    <span class="tabular-nums text-muted-foreground"><span x-text="t.synced"></span> uploaded · <span :class="(t.unsynced||0)>0?'text-warning':''" x-text="t.unsynced+' waiting'"></span> · <span :class="(t.failed||0)>0?'text-critical':''" x-text="t.failed+' retrying'"></span></span>
                                </div>
                                <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full bg-success" :style="`width:${t.health_pct}%`"></div>
                                </div>
                                <p class="text-[10.5px] text-muted-foreground mt-0.5" x-show="t.missing">This area is not yet present on this server.</p>
                            </li>
                        </template>
                    </ul>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="rounded-xl border p-4">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold">Device platform mix</p>
                            <button class="text-[10.5px] text-brand underline-offset-2 hover:underline" @click="openInterpret('platform_mix')">How to read</button>
                        </div>
                        <ul class="space-y-2">
                            <template x-for="p in (summary?.platforms || [])" :key="'pl-'+p.platform">
                                <li class="text-[11.5px]">
                                    <div class="flex items-center justify-between"><span class="font-semibold" x-text="p.plain_label"></span><span class="tabular-nums" x-text="formatN(p.devices)+' device(s)'"></span></div>
                                    <div class="h-1.5 rounded-full bg-muted overflow-hidden mt-1">
                                        <div class="h-full bg-brand" :style="`width:${platformPct(p)}%`"></div>
                                    </div>
                                </li>
                            </template>
                        </ul>
                    </div>
                    <div class="rounded-xl border p-4">
                        <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold mb-2">App-version distribution</p>
                        <ul class="space-y-1">
                            <template x-for="v in (summary?.versions || [])" :key="'v-'+v.version">
                                <li class="text-[11px]">
                                    <div class="flex items-center justify-between"><span class="font-mono" x-text="v.version"></span><span class="tabular-nums text-muted-foreground" x-text="formatN(v.devices)+' device(s)'"></span></div>
                                    <p class="text-[10px] text-muted-foreground italic" x-text="v.plain_status"></p>
                                </li>
                            </template>
                            <template x-if="!summary || (summary.versions||[]).length===0"><li class="text-[10.5px] italic text-muted-foreground">No versions reported yet.</li></template>
                        </ul>
                    </div>
                </div>
            </div>

            {{-- ── Pending Uploads ── --}}
            <div x-show="activeTab==='pending'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-3">
                <p class="text-[11.5px] italic text-muted-foreground">Devices with data the operator captured but hasn’t yet sent to the platform. Most clear by themselves; a stuck device deserves a phone call.</p>
                <div class="rounded-xl border p-3.5">
                    <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold mb-2">Top devices with pending rows</p>
                    <template x-if="pending.loading"><div class="space-y-2"><template x-for="i in 4" :key="'pd'+i"><div class="h-9 bg-muted/40 rounded animate-pulse"></div></template></div></template>
                    <template x-if="!pending.loading && pending.top_devices.length===0"><p class="text-[11.5px] italic text-muted-foreground">No devices have pending uploads in the chosen window.</p></template>
                    <ul class="divide-y rounded-lg overflow-hidden">
                        <template x-for="d in pending.top_devices" :key="'tp-'+d.device_id">
                            <li class="px-3 py-2.5 flex items-center gap-2 text-[11.5px]">
                                <div class="grid place-items-center h-7 w-7 rounded-full bg-warning-soft text-warning shrink-0 text-[9.5px] font-bold">{{ '!' }}</div>
                                <div class="min-w-0 flex-1">
                                    <p class="font-mono truncate" :title="d.device_id" x-text="d.device_id"></p>
                                    <p class="text-[10.5px] text-muted-foreground" x-text="'most recent capture '+(d.most_recent || '—')"></p>
                                </div>
                                <span class="badge text-[9.5px] badge-warning shrink-0" x-text="d.pending+' waiting'"></span>
                            </li>
                        </template>
                    </ul>
                </div>
                <div class="rounded-xl border p-3.5">
                    <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground font-semibold mb-2">By area and status</p>
                    <ul class="divide-y rounded-lg overflow-hidden">
                        <template x-if="pending.rows.length===0"><li class="px-3 py-2 text-[11.5px] italic text-muted-foreground">Nothing pending.</li></template>
                        <template x-for="r in pending.rows" :key="r.table+'-'+r.status">
                            <li class="px-3 py-2 flex items-center justify-between text-[11.5px]">
                                <p><span class="font-semibold" x-text="tableLabel(r.table)"></span> · <span x-text="r.plain"></span></p>
                                <span class="tabular-nums text-muted-foreground" x-text="formatN(r.count)"></span>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            {{-- ── App Versions ── --}}
            <div x-show="activeTab==='versions'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-3">
                <p class="text-[11.5px] italic text-muted-foreground">Which builds of the field app are on operators’ phones. Older versions sometimes lack newer fields.</p>
                <ul class="divide-y rounded-xl border bg-card overflow-hidden">
                    <template x-if="!summary || (summary.versions||[]).length===0"><li class="px-3 py-3 text-[11.5px] italic text-muted-foreground">No versions reported yet.</li></template>
                    <template x-for="v in (summary?.versions || [])" :key="'vl-'+v.version">
                        <li class="px-3 py-3 flex items-center gap-2 text-[12px]">
                            <span class="font-mono text-[12px]" x-text="v.version"></span>
                            <span class="tabular-nums text-muted-foreground text-[10.5px]" x-text="formatN(v.devices)+' device(s)'"></span>
                            <span class="flex-1 text-[10.5px] italic text-muted-foreground" x-text="v.plain_status"></span>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- ── Device Platforms ── --}}
            <div x-show="activeTab==='platforms'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-3">
                <p class="text-[11.5px] italic text-muted-foreground">Which kinds of devices are reporting in the chosen window. Useful for spotting platform-specific issues.</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <template x-for="p in (summary?.platforms || [])" :key="'pf-'+p.platform">
                        <div class="rounded-xl border p-4">
                            <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground" x-text="p.plain_label"></p>
                            <p class="text-[20px] font-bold tabular-nums mt-1" x-text="formatN(p.devices)"></p>
                            <p class="text-[10.5px] text-muted-foreground mt-0.5">distinct devices</p>
                        </div>
                    </template>
                </div>
            </div>

            {{-- ── Quiet Devices ── --}}
            <div x-show="activeTab==='quiet'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-5 space-y-3">
                <div class="flex items-center gap-2">
                    <p class="text-[11.5px] italic text-muted-foreground flex-1">Devices that have not been heard from in longer than the threshold.</p>
                    <select class="select w-auto !h-7 text-xs" x-model.number="quiet.threshold" @change="loadQuiet()">
                        <option :value="3">Quiet over 3 days</option>
                        <option :value="7">Quiet over 7 days</option>
                        <option :value="14">Quiet over 14 days</option>
                        <option :value="30">Quiet over 30 days</option>
                    </select>
                </div>
                <ul class="divide-y rounded-xl border bg-card overflow-hidden">
                    <template x-if="quiet.loading"><li class="px-3 py-3 text-[11.5px] italic text-muted-foreground">Loading…</li></template>
                    <template x-if="!quiet.loading && quiet.rows.length===0"><li class="px-3 py-3 text-[11.5px] italic text-muted-foreground">No devices quiet beyond that threshold.</li></template>
                    <template x-for="r in quiet.rows" :key="'qd-'+r.device_id">
                        <li class="px-3 py-3 flex items-center gap-2 text-[12px]">
                            <div class="grid place-items-center h-7 w-7 rounded-full bg-muted text-muted-foreground shrink-0 text-[9.5px] font-bold">{{ 'Z' }}</div>
                            <div class="min-w-0 flex-1">
                                <p class="font-mono truncate" :title="r.device_id" x-text="r.device_id"></p>
                                <p class="text-[10.5px] text-muted-foreground" x-text="r.platform+' · v'+r.app_version+' · '+r.last_seen_plain"></p>
                            </div>
                            <span class="text-[10.5px] tabular-nums text-muted-foreground shrink-0" x-text="r.idle_days+' days idle'"></span>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- ── Methodology ── --}}
            <div x-show="activeTab==='method'" x-cloak class="flex-1 min-h-0 overflow-auto p-4 sm:p-6 space-y-4 text-[12px] leading-relaxed">
                <h3 class="text-[14px] font-bold">How this page works</h3>
                <p>The platform reads the <em>sync_status</em> column on each mobile-write area to tell whether a captured row has uploaded yet. <em>Uploaded</em> means the row has reached the platform; <em>waiting</em> means the phone has captured it but not yet sent it; <em>retrying</em> means a previous send did not land.</p>
                <p><strong>Device identifiers.</strong> Each phone reports a unique device identifier with every save. The screen unmasks that identifier on the Pending and Quiet tabs because diagnostics need it. Your view of those identifiers is recorded.</p>
                <p><strong>App versions.</strong> The field app reports its version with every save. The "latest" version is the highest version seen in the field — the platform does not hardcode an expectation. Older versions are flagged so the field team knows which devices to update.</p>
                <p><strong>Quiet devices.</strong> A device is "quiet" if its most-recent capture is older than the threshold. Quiet does not mean broken; it usually means the device is off, has no signal, or the operator is on leave.</p>
                <h3 class="text-[14px] font-bold mt-4">Translator versions</h3>
                <p class="text-[10.5px] text-muted-foreground">Mobile-status translator: <span class="font-mono">v1 · domain sign-off pending</span>.</p>
            </div>
        </div>
    </section>

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
function mobilePage(){
    const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');

    let coach = {};
    try{ coach = JSON.parse(document.getElementById('sys-mobile-coach')?.textContent || '{}') || {}; }catch(e){ coach = {}; }
    coach.glossary = coach.glossary || [];
    coach.charts   = coach.charts   || {};
    coach.wizard   = coach.wizard   || { options: [] };

    const TABLE_LABELS = {
        primary_screenings:'Primary screenings',
        secondary_screenings:'Secondary screenings (PII home)',
        aggregated_submissions:'Aggregated submissions',
        alerts:'Alerts captured on the phone',
        alert_followups:'Alert follow-up actions',
    };

    return {
        coach,
        coachIntroOpen:false,
        coachSheet:{open:false},
        wizardSheet:{open:false},
        interpretSheet:{open:false, chart:null},
        rowSheet:{open:false, row:null},
        opToast:{open:false, kind:'success', title:'', body:'', t:null},

        days: 30,
        windowOptions: [
            {d:7,  label:'Past 7d'},
            {d:14, label:'Past 14d'},
            {d:30, label:'Past 30d'},
            {d:60, label:'Past 60d'},
            {d:90, label:'Past 90d'},
        ],
        tabs: [
            {key:'overview',  label:'Overview'},
            {key:'pending',   label:'Pending uploads'},
            {key:'versions',  label:'App versions'},
            {key:'platforms', label:'Device platforms'},
            {key:'quiet',     label:'Quiet devices'},
            {key:'method',    label:'Methodology'},
        ],
        activeTab:'overview',

        summary:null, freshness:'',
        pending:{loading:false, rows:[], top_devices:[]},
        quiet:{loading:false, rows:[], threshold:7},

        formatN(n){ if(n===null||n===undefined) return '—'; try{ return Number(n).toLocaleString(); }catch(e){ return String(n); } },
        tableLabel(t){ return TABLE_LABELS[t] || t; },
        platformPct(p){
            const total = (this.summary?.platforms||[]).reduce((a,b)=>a+(b.devices||0),0);
            if(!total) return 0;
            return Math.round((p.devices||0)/total*100);
        },
        healthDot(){ const lvl=this.summary?.health?.level; if(lvl==='green') return 'status-dot-live'; if(lvl==='amber') return 'status-dot-warn'; if(lvl==='red') return 'status-dot-danger'; return ''; },

        get sparklinePath(){
            const h = this.summary?.sparkline || [];
            if (h.length < 2) return null;
            const max = Math.max(1, ...h.map(p => p.pending || 0));
            const w = 200, ht = 36;
            const step = w / Math.max(1, h.length - 1);
            let line='', fill='0,'+ht+' ';
            h.forEach((p,i)=>{ const x=i*step; const y=ht-((p.pending||0)/max)*(ht-4)-2; line+=`${x.toFixed(1)},${y.toFixed(1)} `; fill+=`${x.toFixed(1)},${y.toFixed(1)} `; });
            fill += `${w},${ht}`;
            return { line: line.trim(), fill: fill.trim() };
        },

        openInterpret(key){ const chart = this.coach.charts?.[key]; if(!chart) return; this.interpretSheet = {open:true, chart}; },
        pickWizardOption(opt){ this.wizardSheet.open=false; this.activeTab=opt.goto_tab||'overview'; this.pushUrl(); this.maybeLoadTab(); this.toast('success', opt.label, opt.summary); },

        async boot(){
            this.readUrl();
            await this.load();
            await this.maybeLoadTab();
        },
        readUrl(){
            try{
                const u = new URL(window.location.href);
                const t = u.searchParams.get('tab'); if(t) this.activeTab = t;
                const d = parseInt(u.searchParams.get('days') || '0', 10);
                if(d > 0) this.days = d;
            }catch(e){}
        },
        pushUrl(){
            try{
                const u = new URL(window.location.href);
                u.searchParams.set('tab', this.activeTab);
                u.searchParams.set('days', String(this.days));
                window.history.replaceState(null,'',u.toString());
            }catch(e){}
        },

        async load(){
            try{
                const r = await fetch('/admin/system/mobile/summary?'+qs({days:this.days}), {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok) this.summary = j.data;
            }catch(e){}
            this.freshness = 'Fresh as of ' + new Date().toLocaleTimeString();
        },
        async maybeLoadTab(){
            if(this.activeTab==='pending') return this.loadPending();
            if(this.activeTab==='quiet')   return this.loadQuiet();
        },
        async loadPending(){
            this.pending.loading = true;
            try{
                const r = await fetch('/admin/system/mobile/pending', {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok){ this.pending.rows = j.data.rows || []; this.pending.top_devices = j.data.top_devices || []; }
            }catch(e){}
            finally{ this.pending.loading=false; }
        },
        async loadQuiet(){
            this.quiet.loading = true;
            try{
                const r = await fetch('/admin/system/mobile/quiet?'+qs({days:this.quiet.threshold}), {headers:{'Accept':'application/json'}});
                const j = await r.json();
                if(j.ok) this.quiet.rows = j.data.rows || [];
            }catch(e){}
            finally{ this.quiet.loading=false; }
        },

        toast(kind, title, body){ this.opToast = {open:true, kind, title, body, t:null}; clearTimeout(this.opToast.t); this.opToast.t = setTimeout(()=>{ this.opToast.open=false; }, 4500); },
    };
}
</script>
@endpush
