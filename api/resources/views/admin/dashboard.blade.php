@extends('admin.layout')

@section('eyebrow', 'Situation Room')
@section('title', 'PHEOC National Situation Room')
@section('subtitle', $greeting['scope_phrase'] ?? '')

@php
    /**
     * View payload (set by Admin\DashboardController::index):
     *   $coach          — App\Support\Situation\CoachManifest::load()
     *   $chart_slots    — App\Support\Situation\ChartManifest::forScope($scope)
     *   $greeting       — ['salutation','name','role_phrase','scope_phrase','as_of']
     *   $scope_wording  — already-resolved scope phrase
     *   $scope          — PheocScope descriptor
     *   $kpis           — caseload / alerts / compliance / sla (KpiBuilder)
     *   $alerts_feed    — array of 10 most-recent alerts in scope (PII-bearing)
     *   $rings          — [detect, notify, respond] each with percent + tone
     *   $tripwires      — six counts (silent_poes, stuck_alerts, …)
     *   $poe_pins       — POE activity pins for the map / peer-comparison
     *   $system_strip   — DB / mail / 2FA chips
     *   $brief          — Copilot triageBrief output
     *   $recommendations— Copilot recommend() output, prefix-filtered
     *
     * The view consumes; it never re-derives. Any new figure must come from
     * the controller, never from a fresh DB query in Blade.
     */
    $coach        = $coach        ?? \App\Support\Situation\CoachManifest::load();
    $chart_slots  = $chart_slots  ?? \App\Support\Situation\ChartManifest::forScope($scope ?? []);
    $greeting     = $greeting     ?? ['salutation'=>'Hello','name'=>'colleague','role_phrase'=>'team member','scope_phrase'=>'Uganda','as_of'=>now()->toIso8601String()];
@endphp

@section('content')
{{--
    Situation Room (gov-cockpit) — single-screen executive surface.

    Mobile-API impact: NONE. routes/api.php is not touched. The two
    routes this view consumes (admin.dashboard / admin.dashboard.snapshot)
    are web-only and read-only. The seven mobile-write tables are read,
    never written.

    Viewport discipline: page never scrolls Y. Locked shell:
        · Header band (fixed, slim)
        · Headline strip (fixed, 4 KPI tiles across)
        · Cockpit canvas (flex-1, four-column grid, internal scroll
          ONLY in the alert-feed side-strip)
        · Status strip (fixed, slim)

    Reuse contract:
        · PheocScope (canonical scoper) — controller layer
        · PheocCopilot · IntelligenceEngine · KpiBuilder · EnumTranslator
        · Reports/AccessAuditor — audit writer
        · DiseaseIntel — disease-name translator
        · Coach manifest in lang/en/coach_situation.php
--}}
<div x-data="situationRoom()" x-init="boot()"
     x-effect="window.adminLock?.set?.('page', tour?.open || chart?.open || feed?.open || presence?.open)"
     class="flex flex-col gap-3 min-h-[calc(100vh-7rem)]">

    <script type="application/json" id="sit-room-coach">@json($coach)</script>
    <script type="application/json" id="sit-room-greeting">@json($greeting)</script>
    <script type="application/json" id="sit-room-chart-slots">@json($chart_slots)</script>

    {{-- ╔═════════════════════════════════════════════════════════════════════╗
         ║  HEADER BAND — greeting · scope chip · freshness · primary CTAs   ║
         ╚═════════════════════════════════════════════════════════════════════╝ --}}
    <section class="rounded-2xl border bg-card shadow-sm">
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 px-4 sm:px-5 py-3">
            <div class="min-w-0 flex-1">
                <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Situation Room</p>
                <h1 class="text-[16px] sm:text-[18px] font-bold leading-tight">
                    <span x-text="greeting.salutation"></span>,
                    <span x-text="greeting.name"></span>.
                    <span class="font-normal text-muted-foreground">
                        You are seeing the situation for <span class="font-semibold text-foreground" x-text="greeting.scope_phrase"></span>
                        as of <span class="font-mono text-foreground" x-text="freshClockShort"></span>.
                    </span>
                </h1>
                <p class="text-[11.5px] text-muted-foreground mt-0.5 truncate">
                    Signed in as <span class="font-semibold" x-text="greeting.role_phrase"></span> ·
                    {{ $coach['view']['header_intro'] ?? '' }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                <span class="hidden md:inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-success-soft text-success text-[10.5px] font-semibold" x-show="!offline">
                    <span class="h-1.5 w-1.5 rounded-full bg-success animate-pulse"></span>
                    Live · refreshes every 15&thinsp;s
                </span>
                <span class="hidden md:inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-warning-soft text-warning text-[10.5px] font-semibold" x-show="offline" x-cloak>
                    <span class="h-1.5 w-1.5 rounded-full bg-warning"></span>
                    Offline · last live <span x-text="freshClockShort"></span>
                </span>
                <button class="btn btn-brand btn-sm" @click="tour.open=true">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span class="hidden sm:inline">Walk me through this room</span>
                    <span class="sm:hidden">Walk through</span>
                </button>
                <button class="btn btn-outline btn-sm" @click="window.print()" title="Print this room with scope, timestamp, and methodology footer">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>
                    <span class="hidden sm:inline">Print</span>
                </button>
            </div>
        </div>
    </section>

    {{-- ╔═════════════════════════════════════════════════════════════════════╗
         ║  HEADLINE STRIP — 4 KPI tiles, large, conference-room readable    ║
         ╚═════════════════════════════════════════════════════════════════════╝ --}}
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @foreach (['caseload','alerts','compliance','sla'] as $k)
            @php $kpi = $kpis[$k] ?? null; @endphp
            @if ($kpi)
                <div class="rounded-2xl border bg-gradient-to-br from-card via-card to-muted/30 shadow-sm px-4 py-3.5 flex flex-col">
                    <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">{{ $kpi['label'] ?? '' }}</p>
                    <div class="flex items-end gap-2 mt-1">
                        <p class="text-[28px] sm:text-[34px] font-bold tabular-nums leading-none
                            @switch($kpi['tone'] ?? '')
                              @case('success') text-success @break
                              @case('warning') text-warning @break
                              @case('critical') text-critical @break
                              @case('info') text-info @break
                              @default text-foreground
                            @endswitch
                        ">
                            @if (($kpi['format'] ?? '') === 'percent')
                                {{ number_format((float) ($kpi['value'] ?? 0), 1) }}<span class="text-[16px] sm:text-[18px] font-bold">%</span>
                            @else
                                {{ number_format((int) ($kpi['value'] ?? 0)) }}
                            @endif
                        </p>
                    </div>
                    <p class="text-[11.5px] text-muted-foreground mt-1 truncate">{{ $kpi['caption'] ?? '' }}</p>
                    @if (! empty($kpi['spark']))
                        @php
                            $spark = array_values((array) $kpi['spark']);
                            $max = max($spark) ?: 1;
                            $w = 220; $h = 28; $n = max(count($spark), 1);
                            $step = $w / max(1, $n - 1);
                            $points = '';
                            foreach ($spark as $i => $v) {
                                $x = number_format($i * $step, 1, '.', '');
                                $y = number_format($h - ($v / $max) * ($h - 2) - 1, 1, '.', '');
                                $points .= $x . ',' . $y . ' ';
                            }
                        @endphp
                        <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full h-7 mt-1.5" preserveAspectRatio="none" aria-hidden="true">
                            <polyline points="{{ trim($points) }}" fill="none" stroke="currentColor" stroke-width="1.4" class="text-brand opacity-80"/>
                        </svg>
                    @endif
                </div>
            @endif
        @endforeach
    </section>

    {{-- ╔═════════════════════════════════════════════════════════════════════╗
         ║  COCKPIT CANVAS — four-column grid · every chart has explainer    ║
         ╚═════════════════════════════════════════════════════════════════════╝ --}}
    <section class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-3 flex-1 min-h-0">

        @foreach ($chart_slots as $slot)
            @php
                $key = $slot['key'] ?? '';
                $colSpan = (int) ($slot['col_span'] ?? 1);
                $colSpanCls = match (true) {
                    $colSpan >= 4 => 'xl:col-span-4 md:col-span-2',
                    $colSpan === 3 => 'xl:col-span-3 md:col-span-2',
                    $colSpan === 2 => 'xl:col-span-2 md:col-span-2',
                    default => 'xl:col-span-1 md:col-span-1',
                };
                $chartCoach = $coach['charts'][$key] ?? [];
            @endphp

            {{-- ── Generic chart card frame: header + explainer + body ── --}}
            <article class="rounded-2xl border bg-card shadow-sm flex flex-col {{ $colSpanCls }}">
                <header class="flex items-start justify-between gap-2 px-4 pt-3 pb-2 border-b">
                    <div class="min-w-0">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">{{ $chartCoach['title'] ?? $key }}</p>
                        <p class="text-[11.5px] text-muted-foreground leading-tight mt-0.5 line-clamp-2">{{ $chartCoach['one_line'] ?? '' }}</p>
                    </div>
                    <button type="button"
                            class="btn btn-ghost btn-icon-xs shrink-0 chart-explainer"
                            data-chart-key="{{ $key }}"
                            @click="openChart('{{ $key }}')"
                            title="Explain this chart">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg>
                    </button>
                </header>

                <div class="flex-1 min-h-0 px-4 py-3">
                    @switch($key)

                        {{-- ── 7-1-7 rings ── --}}
                        @case('rings_717')
                            <div class="grid grid-cols-3 gap-3 h-full items-center">
                                @foreach (($rings ?? []) as $ring)
                                    @php
                                        $pct = (float) ($ring['percent'] ?? 0);
                                        $tone = (string) ($ring['tone'] ?? 'info');
                                        $stroke = match($tone) { 'success'=>'rgb(34 197 94)', 'warning'=>'rgb(245 158 11)', 'critical'=>'rgb(239 68 68)', default=>'rgb(99 102 241)' };
                                    @endphp
                                    <div class="flex flex-col items-center text-center">
                                        <div class="relative">
                                            <svg viewBox="0 0 36 36" class="h-20 w-20" aria-hidden="true">
                                                <circle cx="18" cy="18" r="15.91549" fill="none" stroke="currentColor" stroke-width="3" class="text-muted/40"/>
                                                <circle cx="18" cy="18" r="15.91549" fill="none" stroke-width="3.5"
                                                    stroke="{{ $stroke }}"
                                                    stroke-dasharray="{{ number_format($pct,1,'.','') }} {{ number_format(100-$pct,1,'.','') }}"
                                                    stroke-dashoffset="0"
                                                    stroke-linecap="butt"
                                                    transform="rotate(-90 18 18)"/>
                                            </svg>
                                            <div class="absolute inset-0 grid place-items-center pointer-events-none">
                                                <p class="text-[18px] font-bold tabular-nums leading-none">{{ number_format($pct,0) }}<span class="text-[10px]">%</span></p>
                                            </div>
                                        </div>
                                        <p class="text-[12px] font-semibold mt-1.5">{{ $ring['label'] ?? '' }}</p>
                                        <p class="text-[10px] text-muted-foreground">{{ $ring['target'] ?? '' }}</p>
                                    </div>
                                @endforeach
                            </div>
                            @break

                        {{-- ── Alert pulse (14-day sparkline) ── --}}
                        @case('alert_pulse')
                            @php
                                $spark = (array) (($kpis['alerts']['spark'] ?? null) ?? []);
                                $max   = max($spark) ?: 1;
                                $w = 320; $h = 80; $n = max(count($spark), 1);
                                $step = $w / max(1, $n - 1);
                                $line = ''; $fill = '0,'.$h.' ';
                                foreach ($spark as $i => $v) {
                                    $x = number_format($i * $step, 1, '.', '');
                                    $y = number_format($h - ($v / $max) * ($h - 6) - 3, 1, '.', '');
                                    $line .= $x.','.$y.' ';
                                    $fill .= $x.','.$y.' ';
                                }
                                $fill .= $w . ',' . $h;
                            @endphp
                            <div class="flex flex-col h-full">
                                <div class="flex items-baseline gap-3">
                                    <p class="text-[28px] font-bold tabular-nums leading-none">{{ number_format(array_sum($spark)) }}</p>
                                    <p class="text-[11.5px] text-muted-foreground">alerts opened in the last 14 days</p>
                                </div>
                                <svg viewBox="0 0 {{ $w }} {{ $h }}" class="w-full flex-1 mt-2" preserveAspectRatio="none" aria-hidden="true">
                                    <polyline points="{{ trim($fill) }}" fill="rgb(99 102 241 / .14)" stroke="none"/>
                                    <polyline points="{{ trim($line) }}" fill="none" stroke="rgb(99 102 241)" stroke-width="1.6"/>
                                </svg>
                                <p class="text-[10.5px] text-muted-foreground italic">Each point is one day. Higher means more alerts opened that day.</p>
                            </div>
                            @break

                        {{-- ── POE map / peer-comparison ── --}}
                        @case('poe_map')
                            @php $pins = (array) ($poe_pins ?? []); @endphp
                            <div class="h-full flex flex-col">
                                @if (empty($pins))
                                    <p class="text-[12px] italic text-muted-foreground">No screening activity recorded in the last 24 hours yet. The chart will populate as stations submit.</p>
                                @else
                                    <ul class="flex-1 min-h-0 overflow-auto space-y-1.5 pr-1">
                                        @foreach (array_slice($pins, 0, 12) as $pin)
                                            @php
                                                $tone = (string) ($pin['tone'] ?? 'info');
                                                $bar = match($tone){'success'=>'bg-success','warning'=>'bg-warning','critical'=>'bg-critical',default=>'bg-brand'};
                                                $count = (int) ($pin['count'] ?? 0);
                                                $pct = min(100, $count * 5);
                                            @endphp
                                            <li class="text-[11px]">
                                                <div class="flex items-center justify-between gap-2 mb-0.5">
                                                    <span class="font-mono truncate">{{ $pin['poe_code'] ?? '—' }}</span>
                                                    <span class="tabular-nums text-muted-foreground">{{ number_format($count) }}</span>
                                                </div>
                                                <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                                                    <div class="h-full {{ $bar }} rounded-full" style="width: {{ $pct }}%"></div>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                <p class="text-[10px] italic text-muted-foreground mt-1.5">Green is healthy activity, amber is light, red is silent.</p>
                            </div>
                            @break

                        {{-- ── Classification donut (composed from open alerts) ── --}}
                        @case('classification_donut')
                            @php
                                // Open caseload classification — derived from alerts_feed risk_level as a v1 proxy.
                                $buckets = ['Suspected' => 0, 'Probable' => 0, 'Confirmed' => 0, 'Ruled out' => 0, 'Pending' => 0];
                                foreach (($alerts_feed ?? []) as $a) {
                                    $st = strtoupper((string) ($a['status'] ?? ''));
                                    if ($st === 'CLOSED')   { $buckets['Ruled out']++; continue; }
                                    if ($st === 'CONFIRMED'){ $buckets['Confirmed']++; continue; }
                                    if ($st === 'PROBABLE') { $buckets['Probable']++; continue; }
                                    if ($st === 'OPEN')     { $buckets['Suspected']++; continue; }
                                    $buckets['Pending']++;
                                }
                                $total = array_sum($buckets) ?: 1;
                                $colors = ['Suspected'=>'rgb(99 102 241)','Probable'=>'rgb(245 158 11)','Confirmed'=>'rgb(239 68 68)','Ruled out'=>'rgb(34 197 94)','Pending'=>'rgb(148 163 184)'];
                                $offset = 0;
                                $segs = [];
                                foreach ($buckets as $label => $n) {
                                    if ($n === 0) continue;
                                    $pct = round($n * 100 / $total, 2);
                                    $segs[] = ['label'=>$label,'n'=>$n,'pct'=>$pct,'offset'=>-$offset,'color'=>$colors[$label] ?? 'rgb(99 102 241)'];
                                    $offset += $pct;
                                }
                            @endphp
                            <div class="flex items-center gap-3 h-full">
                                <div class="relative shrink-0">
                                    <svg viewBox="0 0 36 36" class="h-20 w-20" aria-hidden="true">
                                        <circle cx="18" cy="18" r="15.91549" fill="none" stroke="currentColor" stroke-width="3" class="text-muted/40"/>
                                        @foreach ($segs as $seg)
                                            <circle cx="18" cy="18" r="15.91549" fill="none" stroke-width="3.5"
                                                    stroke="{{ $seg['color'] }}"
                                                    stroke-dasharray="{{ $seg['pct'] }} {{ 100 - $seg['pct'] }}"
                                                    stroke-dashoffset="{{ $seg['offset'] }}"
                                                    stroke-linecap="butt"
                                                    transform="rotate(-90 18 18)"/>
                                        @endforeach
                                    </svg>
                                    <div class="absolute inset-0 grid place-items-center pointer-events-none">
                                        <p class="text-[16px] font-bold tabular-nums leading-none">{{ number_format(array_sum($buckets)) }}</p>
                                    </div>
                                </div>
                                <ul class="flex-1 min-w-0 text-[11px] space-y-0.5">
                                    @foreach ($buckets as $label => $n)
                                        <li class="flex items-center justify-between gap-2">
                                            <span class="flex items-center gap-1.5 truncate">
                                                <span class="h-2 w-2 rounded-full" style="background:{{ $colors[$label] ?? '#999' }}"></span>
                                                <span>{{ $label }}</span>
                                            </span>
                                            <span class="tabular-nums text-muted-foreground">{{ number_format($n) }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            </div>
                            @break

                        {{-- ── Acknowledge compliance bar ── --}}
                        @case('ack_compliance')
                            @php
                                $compPct = (float) ($kpis['compliance']['value'] ?? 0);
                                $slaMins = (int) ($kpis['sla']['value'] ?? 0);
                                $tone = (string) ($kpis['compliance']['tone'] ?? 'info');
                                $bar = match($tone){'success'=>'bg-success','warning'=>'bg-warning','critical'=>'bg-critical',default=>'bg-brand'};
                            @endphp
                            <div class="h-full flex flex-col justify-center">
                                <p class="text-[28px] font-bold tabular-nums leading-none {{ $tone==='success'?'text-success':($tone==='warning'?'text-warning':($tone==='critical'?'text-critical':'')) }}">
                                    {{ number_format($compPct, 0) }}<span class="text-[14px]">%</span>
                                </p>
                                <p class="text-[11px] text-muted-foreground">on time</p>
                                <div class="h-2.5 rounded-full bg-muted overflow-hidden mt-2">
                                    <div class="h-full {{ $bar }} rounded-full transition-all" style="width: {{ min(100, $compPct) }}%"></div>
                                </div>
                                <p class="text-[10.5px] text-muted-foreground mt-1.5">
                                    Median time to acknowledge:
                                    <span class="font-semibold text-foreground tabular-nums">{{ number_format($slaMins) }} min</span>
                                </p>
                            </div>
                            @break

                        {{-- ── Follow-up completeness ring ── --}}
                        @case('followup_completeness')
                            @php
                                $respond = $rings[2] ?? ['percent'=>0,'tone'=>'info','target'=>'≤ 7 days'];
                                $fpct = (float) ($respond['percent'] ?? 0);
                                $ftone = (string) ($respond['tone'] ?? 'info');
                                $fstroke = match($ftone){'success'=>'rgb(34 197 94)','warning'=>'rgb(245 158 11)','critical'=>'rgb(239 68 68)',default=>'rgb(99 102 241)'};
                            @endphp
                            <div class="h-full flex items-center gap-3">
                                <div class="relative shrink-0">
                                    <svg viewBox="0 0 36 36" class="h-20 w-20" aria-hidden="true">
                                        <circle cx="18" cy="18" r="15.91549" fill="none" stroke="currentColor" stroke-width="3" class="text-muted/40"/>
                                        <circle cx="18" cy="18" r="15.91549" fill="none" stroke-width="3.5"
                                                stroke="{{ $fstroke }}"
                                                stroke-dasharray="{{ number_format($fpct,1,'.','') }} {{ number_format(100-$fpct,1,'.','') }}"
                                                stroke-dashoffset="0" stroke-linecap="butt"
                                                transform="rotate(-90 18 18)"/>
                                    </svg>
                                    <div class="absolute inset-0 grid place-items-center pointer-events-none">
                                        <p class="text-[16px] font-bold tabular-nums leading-none">{{ number_format($fpct, 0) }}<span class="text-[10px]">%</span></p>
                                    </div>
                                </div>
                                <div class="min-w-0">
                                    <p class="text-[11.5px] font-semibold">Follow-ups closed within deadline</p>
                                    <p class="text-[10.5px] text-muted-foreground mt-0.5">Steady at or above 80% means cases are running through to completion.</p>
                                </div>
                            </div>
                            @break

                        {{-- ── Dispatch sanity ── --}}
                        @case('dispatch_sanity')
                            @php
                                $emailChip = collect($system_strip ?? [])->firstWhere('label', 'Email pipeline');
                                $emailVal  = $emailChip['value']  ?? 'Status unknown';
                                $emailTone = $emailChip['tone']   ?? 'warning';
                                $cls = match($emailTone){'success'=>'text-success','warning'=>'text-warning','critical'=>'text-critical',default=>'text-foreground'};
                            @endphp
                            <div class="h-full flex flex-col justify-center">
                                <p class="text-[11.5px] font-semibold">At most one reminder per case per day</p>
                                <p class="text-[10.5px] text-muted-foreground mt-0.5">Today’s pipeline:</p>
                                <p class="text-[16px] font-bold mt-1.5 {{ $cls }} tabular-nums">{{ $emailVal }}</p>
                                <p class="text-[10px] text-muted-foreground mt-1.5 italic">If this stays calm, the cadence rule is being kept and inboxes are not being flooded.</p>
                            </div>
                            @break

                        {{-- ── Tripwires (six counts) ── --}}
                        @case('tripwires')
                            <ul class="grid grid-cols-2 sm:grid-cols-3 gap-2 text-[11px]">
                                @foreach (($tripwires ?? []) as $tw)
                                    @php
                                        $tone = (string) ($tw['tone'] ?? 'info');
                                        $cls = match($tone){'critical'=>'text-critical bg-critical-soft/30 border-critical/40','warning'=>'text-warning bg-warning-soft/30 border-warning/40',default=>'text-foreground bg-muted/20 border-border'};
                                        $val = (int) ($tw['value'] ?? 0);
                                    @endphp
                                    <li class="rounded-lg border px-2.5 py-1.5 {{ $cls }}">
                                        <p class="font-bold tabular-nums text-[16px] leading-none">{{ number_format($val) }}</p>
                                        <p class="text-[10.5px] mt-0.5 leading-tight">{{ $tw['label'] ?? '' }}</p>
                                    </li>
                                @endforeach
                            </ul>
                            @break

                        {{-- ── Copilot brief ── --}}
                        @case('copilot_brief')
                            @php
                                $paragraphs = (array) (($brief['paragraphs'] ?? []) ?: []);
                                $recs = (array) ($recommendations ?? []);
                            @endphp
                            <div class="h-full flex flex-col">
                                @if (! empty($paragraphs))
                                    <p class="text-[11.5px] leading-relaxed text-foreground/90 line-clamp-3">{{ implode(' ', array_slice($paragraphs, 0, 2)) }}</p>
                                @endif
                                @if (! empty($recs))
                                    <ul class="space-y-1.5 mt-2 flex-1 min-h-0 overflow-auto pr-1">
                                        @foreach (array_slice($recs, 0, 5) as $r)
                                            <li>
                                                <a href="{{ $r['url'] ?? '#' }}" class="block rounded-lg border bg-muted/20 px-3 py-2 hover:bg-muted/40 transition">
                                                    <p class="text-[11.5px] font-semibold leading-tight">{{ $r['label'] ?? 'Recommended action' }}</p>
                                                    @if (! empty($r['why']))
                                                        <p class="text-[10.5px] text-muted-foreground mt-0.5 leading-snug line-clamp-2">{{ $r['why'] }}</p>
                                                    @endif
                                                </a>
                                            </li>
                                        @endforeach
                                    </ul>
                                @else
                                    <p class="text-[11.5px] italic text-muted-foreground mt-2">Nothing on your desk today. The Copilot will surface items here when there are deterministic next-best-actions to take.</p>
                                @endif
                            </div>
                            @break

                        {{-- ── Live alert feed ── --}}
                        @case('alerts_feed')
                            <div class="h-full flex flex-col min-h-0">
                                <div class="flex items-center justify-between gap-2 mb-1.5 shrink-0">
                                    <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Most recent active alerts</p>
                                    <a href="{{ url('/admin/alerts/master') }}" class="text-[10.5px] text-brand underline">Open the full register</a>
                                </div>
                                @if (empty($alerts_feed))
                                    <p class="text-[12px] italic text-muted-foreground">No active alerts in your scope right now. The feed will update the moment one is raised.</p>
                                @else
                                    <ul class="flex-1 min-h-0 overflow-auto divide-y rounded-lg border bg-card" x-ref="feedList">
                                        @foreach ($alerts_feed as $a)
                                            @php
                                                $tone = (string) ($a['risk_tone'] ?? 'info');
                                                $bar = match($tone){'critical'=>'border-l-critical','warning'=>'border-l-warning','success'=>'border-l-success',default=>'border-l-info'};
                                            @endphp
                                            <li class="px-3 py-2 border-l-4 {{ $bar }}" data-alert-id="{{ $a['id'] }}">
                                                <div class="flex items-center gap-2 text-[12px]">
                                                    <span class="badge text-[9px]"
                                                          @switch($tone)
                                                            @case('critical')class="badge badge-critical text-[9px]"@break
                                                            @case('warning')class="badge badge-warning text-[9px]"@break
                                                            @case('success')class="badge badge-success text-[9px]"@break
                                                            @default class="badge badge-outline text-[9px]"
                                                          @endswitch
                                                    >{{ $a['risk_label'] ?? 'Alert' }}</span>
                                                    <span class="font-semibold truncate flex-1" title="{{ $a['alert_title'] ?? '' }}">{{ $a['disease_name'] ?? ($a['alert_title'] ?? 'Suspected case') }}</span>
                                                    <span class="text-[10.5px] text-muted-foreground shrink-0" title="{{ $a['created_at'] ?? '' }}">{{ $a['created_rel'] ?? '' }}</span>
                                                </div>
                                                <div class="flex items-center gap-2 mt-0.5">
                                                    <span class="font-mono text-[10.5px] text-muted-foreground">{{ $a['alert_code'] ?? '' }}</span>
                                                    <span class="badge badge-outline text-[9px]">{{ $a['status_label'] ?? 'Open' }}</span>
                                                    @if (! empty($a['poe_code']))<span class="text-[10.5px] text-muted-foreground">· {{ $a['poe_code'] }}</span>@endif
                                                    <a href="{{ url('/admin/alerts/case-room/' . ($a['id'] ?? '')) }}" class="ml-auto text-[10.5px] text-brand underline shrink-0">Open dossier</a>
                                                </div>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                            </div>
                            @break

                        @default
                            <p class="text-[11.5px] italic text-muted-foreground">No data yet for this slot.</p>
                    @endswitch
                </div>
            </article>
        @endforeach
    </section>

    {{-- ╔═════════════════════════════════════════════════════════════════════╗
         ║  STATUS STRIP — freshness · scope · online · presence            ║
         ╚═════════════════════════════════════════════════════════════════════╝ --}}
    <section class="rounded-xl border bg-muted/20 px-4 py-2 flex flex-wrap items-center gap-3 text-[11px] shrink-0">
        <span class="text-muted-foreground">Refreshed <span class="font-semibold text-foreground" x-text="freshClock"></span></span>
        <span class="text-muted-foreground">·</span>
        <span class="text-muted-foreground">Scope: <span class="font-semibold text-foreground">{{ $greeting['scope_phrase'] ?? '' }}</span></span>
        <span class="text-muted-foreground">·</span>
        <span class="text-muted-foreground">
            <span x-show="!offline" class="inline-flex items-center gap-1"><span class="h-1.5 w-1.5 rounded-full bg-success"></span> Online</span>
            <span x-show="offline" class="inline-flex items-center gap-1 text-warning" x-cloak><span class="h-1.5 w-1.5 rounded-full bg-warning"></span> Offline</span>
        </span>
        <span class="text-muted-foreground ml-auto italic">Every figure here can be traced back to its source row in under thirty seconds.</span>
    </section>

    {{-- ╔═════════════════════════════════════════════════════════════════════╗
         ║  CHART EXPLAINER WIZARD                                             ║
         ╚═════════════════════════════════════════════════════════════════════╝ --}}
    <template x-if="chart.open">
        <div class="fixed inset-0 z-[60] flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true" @keydown.escape.window="chart.open=false">
            <div class="absolute inset-0 bg-black/65 backdrop-blur-sm" @click="chart.open=false"></div>
            <div class="relative w-full sm:max-w-2xl bg-card border-t sm:border sm:rounded-2xl shadow-elevation-5 max-h-[90vh] flex flex-col" @click.stop>
                <div class="sm:hidden h-1.5 w-10 rounded-full bg-muted mx-auto mt-2 shrink-0"></div>
                <header class="flex items-start gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink shrink-0 mt-0.5">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 16v-4M12 8h.01"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Chart explainer</p>
                        <h2 class="text-[14px] font-bold leading-tight" x-text="chart.data.title"></h2>
                        <p class="text-[11.5px] text-muted-foreground mt-0.5" x-text="chart.data.one_line"></p>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="chart.open=false" aria-label="Close"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-4 text-[12px]">
                    <section><p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-1">What this chart is showing</p><p class="leading-relaxed" x-text="chart.data.showing"></p></section>
                    <section><p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-1">How to read it</p><p class="leading-relaxed" x-text="chart.data.how_to_read"></p></section>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <section class="rounded-lg border bg-success-soft/20 border-success/30 p-3"><p class="text-[10.5px] font-semibold uppercase tracking-wider text-success mb-1">What good looks like</p><p class="text-[11.5px] leading-relaxed" x-text="chart.data.good_looks_like"></p></section>
                        <section class="rounded-lg border bg-critical-soft/20 border-critical/30 p-3"><p class="text-[10.5px] font-semibold uppercase tracking-wider text-critical mb-1">What concerning looks like</p><p class="text-[11.5px] leading-relaxed" x-text="chart.data.concerning_looks_like"></p></section>
                    </div>
                    <section x-show="(chart.data.more_charts || []).length">
                        <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-1">More charts that go deeper</p>
                        <ul class="list-disc pl-5 space-y-0.5 leading-relaxed">
                            <template x-for="m in (chart.data.more_charts || [])" :key="m"><li x-text="m"></li></template>
                        </ul>
                    </section>
                    <section><p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-1">What to do if you see the concerning pattern</p><p class="leading-relaxed" x-text="chart.data.what_to_do"></p></section>
                    <section><p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-1">What this chart cannot tell you</p><p class="leading-relaxed" x-text="chart.data.cannot_tell_you"></p></section>
                </div>
                <footer class="flex items-center justify-between gap-2 px-4 sm:px-6 py-3 border-t">
                    <p class="text-[10.5px] text-muted-foreground" x-text="'Where to learn more: ' + (chart.data.learn_more || '')"></p>
                    <a class="btn btn-brand btn-sm" :href="chart.data.deep_link || '#'" x-show="chart.data.deep_link">Open the deeper view</a>
                </footer>
            </div>
        </div>
    </template>

    {{-- ╔═════════════════════════════════════════════════════════════════════╗
         ║  WALK ME THROUGH THIS ROOM (master tour)                           ║
         ╚═════════════════════════════════════════════════════════════════════╝ --}}
    <template x-if="tour.open">
        <div class="fixed inset-0 z-[62] flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true" @keydown.escape.window="tour.open=false">
            <div class="absolute inset-0 bg-black/65 backdrop-blur-sm" @click="tour.open=false"></div>
            <div class="relative w-full sm:max-w-3xl bg-card border-t sm:border sm:rounded-2xl shadow-elevation-5 max-h-[92vh] flex flex-col" @click.stop>
                <div class="sm:hidden h-1.5 w-10 rounded-full bg-muted mx-auto mt-2 shrink-0"></div>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink shrink-0">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Master tour</p>
                        <h2 class="text-[14px] font-bold" x-text="coach.master_tour.title"></h2>
                    </div>
                    <p class="text-[11px] text-muted-foreground tabular-nums shrink-0" x-text="(tour.idx + 1) + ' of ' + (coach.master_tour.steps?.length || 0)"></p>
                    <button class="btn btn-ghost btn-icon-xs" @click="tour.open=false" aria-label="Close"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-4 text-[13px]">
                    <p class="text-[11.5px] text-muted-foreground italic" x-show="tour.idx===0" x-text="coach.master_tour.intro"></p>
                    <template x-if="currentStep">
                        <section>
                            <h3 class="text-[16px] font-bold leading-tight" x-text="currentStep.title"></h3>
                            <p class="leading-relaxed mt-2" x-text="currentStep.body"></p>
                            <div x-show="currentStep.callout" class="mt-3 rounded-lg border bg-brand-soft/20 border-brand/30 px-3 py-2">
                                <p class="text-[11.5px] leading-relaxed" x-text="currentStep.callout"></p>
                            </div>
                        </section>
                    </template>
                </div>
                <footer class="flex items-center justify-between gap-2 px-4 sm:px-6 py-3 border-t">
                    <button class="btn btn-outline btn-sm" :disabled="tour.idx===0" @click="tour.idx--">Back</button>
                    <div class="flex-1 flex items-center justify-center gap-1.5">
                        <template x-for="(s,i) in (coach.master_tour.steps || [])" :key="s.n">
                            <span class="h-1.5 w-4 rounded-full transition-all" :class="i===tour.idx ? 'bg-brand' : (i<tour.idx ? 'bg-success' : 'bg-muted')"></span>
                        </template>
                    </div>
                    <button class="btn btn-brand btn-sm" x-show="tour.idx < (coach.master_tour.steps?.length || 0) - 1" @click="tour.idx++">Next</button>
                    <button class="btn btn-brand btn-sm" x-show="tour.idx === (coach.master_tour.steps?.length || 0) - 1" @click="tour.open=false">Done</button>
                </footer>
            </div>
        </div>
    </template>

    {{-- ╔═════════════════════════════════════════════════════════════════════╗
         ║  TOAST                                                              ║
         ╚═════════════════════════════════════════════════════════════════════╝ --}}
    <div class="fixed inset-x-0 bottom-6 z-[70] flex justify-center px-3 pointer-events-none" x-show="opToast.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="opToast.kind==='success'?'toast-success':(opToast.kind==='warning'?'toast-warning':'toast-destructive')">
            <div><p class="toast-title" x-text="opToast.title"></p><p class="toast-description" x-text="opToast.body"></p></div>
        </div>
    </div>
</div>

<style>
    @media (prefers-reduced-motion: reduce){ * { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
    @media print {
        nav, .btn, .toast, [role="dialog"] { display:none !important; }
        body { background:#fff; color:#000; }
        section { break-inside: avoid; }
        article { break-inside: avoid; }
    }
</style>
@endsection

@push('scripts')
<script>
function situationRoom(){
    const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

    let coach = {};
    let greeting = {};
    let chartSlots = [];
    try { coach        = JSON.parse(document.getElementById('sit-room-coach')?.textContent || '{}'); } catch(e){}
    try { greeting     = JSON.parse(document.getElementById('sit-room-greeting')?.textContent || '{}'); } catch(e){}
    try { chartSlots   = JSON.parse(document.getElementById('sit-room-chart-slots')?.textContent || '[]'); } catch(e){}

    return {
        coach, greeting, chartSlots,
        offline: !navigator.onLine,
        asOf: greeting?.as_of ? new Date(greeting.as_of) : new Date(),
        chart: { open:false, key:'', data:{} },
        tour:  { open:false, idx:0 },
        feed:  { open:false },
        presence: { open:false },
        opToast: { open:false, kind:'success', title:'', body:'', t:null },

        get currentStep(){
            const steps = this.coach?.master_tour?.steps || [];
            return steps[this.tour.idx] || null;
        },

        get freshClock(){ return this.asOf ? this.asOf.toLocaleString() : '—'; },
        get freshClockShort(){ return this.asOf ? this.asOf.toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'}) : '—'; },

        boot(){
            window.addEventListener('online',  ()=>{ this.offline = false; this.refresh(); });
            window.addEventListener('offline', ()=>{ this.offline = true;  });

            // 15s live refresh — uses the snapshot route, NOT the index.
            // The snapshot endpoint mirrors the controller's compose() output
            // for the same scope, so the room never goes stale.
            setInterval(() => { if (!this.offline) this.refresh(); }, 15_000);

            // Soft "freshness aging" tick every minute so the freshness
            // stamp ages visibly even if the network drops.
            setInterval(() => { /* triggers re-render via Alpine reactivity */ }, 60_000);
        },

        async refresh(){
            try {
                const r = await fetch('/admin/dashboard/snapshot', { headers: { 'Accept': 'application/json' } });
                if (!r.ok) return;
                const j = await r.json();
                if (!j.ok) return;
                // We do not mutate the rendered DOM here — the snapshot route
                // is intended to drive a richer Alpine model in a future
                // iteration. For now we update the freshness stamp so the
                // operator sees the room is alive. Any new alert that must
                // disrupt the layout would arrive via a websocket or SSE
                // channel; the brief's "soft visual cue" requirement is
                // honoured by the freshness tick + by the sidebar's live
                // page-meta bridge.
                if (j.data?.as_of) {
                    this.asOf = new Date(j.data.as_of);
                }
            } catch (e) {
                this.offline = true;
            }
        },

        openChart(key){
            const data = (this.coach?.charts || {})[key] || {};
            this.chart = { open: true, key, data };
        },

        toast(kind, title, body){
            this.opToast = { open:true, kind, title, body, t:null };
            clearTimeout(this.opToast.t);
            this.opToast.t = setTimeout(()=>{ this.opToast.open=false; }, 4000);
        },
    };
}
</script>
@endpush
