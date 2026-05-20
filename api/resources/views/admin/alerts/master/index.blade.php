@extends('admin.layout')

@section('crumb', 'Alerts')
@section('title', 'Alerts')

@section('content')
<div
    x-data="alertHub({
        endpoints: {
            data:      '{{ route('admin.alerts.data') }}',
            meta:      '{{ route('admin.alerts.meta') }}',
            insights:  '{{ route('admin.alerts.insights') }}',
            wizardOf:  function (id) { return '{{ url('/admin/alerts') }}/' + id + '/wizard'; },
            casefileOf: function (id) { return '{{ url('/admin/alerts') }}/' + id + '/case-file'; },
            gatewayOf: function (id) { return '{{ url('/admin/alerts') }}/' + id + '/wizard/gateway'; },
        }
    })"
    x-init="boot()"
    class="space-y-5"
>

    {{-- HERO INSIGHTS STRIP — premium, mobile-first --}}
    <section class="rounded-3xl bg-gradient-to-br from-slate-900 to-slate-800 text-white shadow-2xl overflow-hidden">
        <div class="px-5 py-5 sm:px-6 sm:py-6 space-y-5 min-w-0">

            {{-- Top: KPIs + sparkline --}}
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 min-w-0">
                {{-- KPI tiles (4 across on mobile -> 5 on lg) --}}
                <div class="lg:col-span-7 grid grid-cols-2 sm:grid-cols-4 gap-3 min-w-0">
                    <div class="rounded-2xl bg-white/5 border border-white/10 px-3.5 py-3 min-w-0">
                        <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-300 font-semibold">New</p>
                        <p class="mt-1 text-xl sm:text-2xl font-bold tabular-nums" x-text="insights.totals.open ?? '—'"></p>
                        <p class="text-[10.5px] text-slate-400">need attention</p>
                    </div>
                    <div class="rounded-2xl bg-white/5 border border-white/10 px-3.5 py-3 min-w-0">
                        <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-300 font-semibold">Being worked on</p>
                        <p class="mt-1 text-xl sm:text-2xl font-bold tabular-nums text-sky-300" x-text="insights.totals.acknowledged ?? 0"></p>
                        <p class="text-[10.5px] text-slate-400">in progress</p>
                    </div>
                    <div class="rounded-2xl bg-white/5 border border-white/10 px-3.5 py-3 min-w-0">
                        <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-300 font-semibold">Closed</p>
                        <p class="mt-1 text-xl sm:text-2xl font-bold tabular-nums text-emerald-300" x-text="insights.totals.closed ?? 0"></p>
                        <p class="text-[10.5px] text-slate-400">resolved</p>
                    </div>
                    <div class="rounded-2xl bg-white/5 border border-white/10 px-3.5 py-3 min-w-0">
                        <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-300 font-semibold">In your area</p>
                        <p class="mt-1 text-xl sm:text-2xl font-bold tabular-nums" x-text="insights.totals.all ?? 0"></p>
                        <p class="text-[10.5px] text-slate-400">all time</p>
                    </div>
                </div>

                {{-- 30-day sparkline (SVG inline, 100 % accurate to data) --}}
                <div class="lg:col-span-5 rounded-2xl bg-white/5 border border-white/10 px-3.5 py-3 min-w-0">
                    <div class="flex items-center justify-between min-w-0">
                        <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-300 font-semibold">Last 30 days</p>
                        <p class="text-[10.5px] text-slate-400 tabular-nums" x-text="`peak ${insights.trend_max} per day`"></p>
                    </div>
                    <svg class="mt-2 w-full" :viewBox="`0 0 ${(insights.trend || []).length * 10} 40`" preserveAspectRatio="none" aria-hidden="true" style="height: 56px;">
                        <template x-for="(d, i) in (insights.trend || [])" :key="d.date">
                            <rect :x="i * 10 + 1" :y="40 - sparkBar(d.count)" width="8" :height="sparkBar(d.count)" rx="2"
                                  :class="d.count === insights.trend_max && d.count > 0 ? 'fill-amber-300' : 'fill-sky-300/70'"></rect>
                        </template>
                    </svg>
                </div>
            </div>

            {{-- Risk ring + Group bars + WHO outcomes --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 min-w-0">
                {{-- Risk distribution ring --}}
                <div class="rounded-2xl bg-white/5 border border-white/10 px-4 py-3 min-w-0">
                    <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-300 font-semibold mb-2">Open by risk</p>
                    <div class="flex items-center gap-3 min-w-0">
                        <svg viewBox="0 0 36 36" class="w-16 h-16 -rotate-90 shrink-0">
                            <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="rgba(255,255,255,0.08)" stroke-width="3"/>
                            <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="#f87171" stroke-width="3"
                                    :stroke-dasharray="`${ringPart('CRITICAL')} 100`" stroke-dashoffset="0"/>
                            <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="#fbbf24" stroke-width="3"
                                    :stroke-dasharray="`${ringPart('HIGH')} 100`" :stroke-dashoffset="`${-ringPart('CRITICAL')}`"/>
                            <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="#94a3b8" stroke-width="3"
                                    :stroke-dasharray="`${ringPart('MEDIUM')} 100`" :stroke-dashoffset="`${-(ringPart('CRITICAL') + ringPart('HIGH'))}`"/>
                        </svg>
                        <div class="text-[12px] space-y-0.5 min-w-0">
                            <p class="flex items-center gap-1.5"><span class="inline-block w-2 h-2 rounded-full bg-red-400"></span><span class="text-slate-300">Top priority</span><span class="ml-auto tabular-nums" x-text="insights.risk?.CRITICAL ?? 0"></span></p>
                            <p class="flex items-center gap-1.5"><span class="inline-block w-2 h-2 rounded-full bg-amber-400"></span><span class="text-slate-300">Serious</span><span class="ml-auto tabular-nums" x-text="insights.risk?.HIGH ?? 0"></span></p>
                            <p class="flex items-center gap-1.5"><span class="inline-block w-2 h-2 rounded-full bg-slate-400"></span><span class="text-slate-300">Watch / low</span><span class="ml-auto tabular-nums" x-text="(insights.risk?.MEDIUM ?? 0) + (insights.risk?.LOW ?? 0)"></span></p>
                        </div>
                    </div>
                </div>

                {{-- Top disease groups --}}
                <div class="rounded-2xl bg-white/5 border border-white/10 px-4 py-3 min-w-0">
                    <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-300 font-semibold mb-2">Top suspected illnesses</p>
                    <ul class="space-y-1.5">
                        <template x-for="g in (insights.groups || []).slice(0, 4)" :key="g.code">
                            <li class="min-w-0">
                                <div class="flex items-center justify-between text-[12px] min-w-0">
                                    <span class="text-slate-200 truncate" x-text="g.label"></span>
                                    <span class="tabular-nums shrink-0" x-text="g.count"></span>
                                </div>
                                <div class="mt-1 h-1.5 rounded-full bg-white/5 overflow-hidden">
                                    <div class="h-full bg-sky-400/90" :style="`width: ${groupPct(g.count)}%`"></div>
                                </div>
                            </li>
                        </template>
                        <li class="text-[11.5px] italic text-slate-400" x-show="(insights.groups || []).length === 0">No alerts to summarise yet.</li>
                    </ul>
                </div>

                {{-- WHO outcome distribution --}}
                <div class="rounded-2xl bg-white/5 border border-white/10 px-4 py-3 min-w-0">
                    <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-300 font-semibold mb-2">WHO outcomes recorded</p>
                    <div class="grid grid-cols-2 gap-1 text-[12px]">
                        <p class="flex items-center gap-1.5"><span class="inline-block w-2 h-2 rounded-full bg-emerald-300"></span><span class="text-slate-300">Confirmed</span><span class="ml-auto tabular-nums" x-text="insights.outcomes?.CONFIRMED ?? 0"></span></p>
                        <p class="flex items-center gap-1.5"><span class="inline-block w-2 h-2 rounded-full bg-sky-300"></span><span class="text-slate-300">Probable</span><span class="ml-auto tabular-nums" x-text="insights.outcomes?.PROBABLE ?? 0"></span></p>
                        <p class="flex items-center gap-1.5"><span class="inline-block w-2 h-2 rounded-full bg-amber-300"></span><span class="text-slate-300">Suspected</span><span class="ml-auto tabular-nums" x-text="insights.outcomes?.SUSPECTED ?? 0"></span></p>
                        <p class="flex items-center gap-1.5"><span class="inline-block w-2 h-2 rounded-full bg-slate-400"></span><span class="text-slate-300">Discarded</span><span class="ml-auto tabular-nums" x-text="insights.outcomes?.DISCARDED ?? 0"></span></p>
                    </div>
                    <p class="mt-2 text-[10.5px] text-slate-400" x-show="(insights.hotspots || []).length > 0">
                        Hotspots: <span x-text="(insights.hotspots || []).map(h => h.label + ' (' + h.count + ')').join(' · ')"></span>
                    </p>
                </div>
            </div>

        </div>
    </section>

    {{-- DISEASE GROUP STRIP --}}
    <section class="card" x-show="groups.length > 0">
        <div class="card-content !p-3 sm:!p-4">
            <div class="flex items-center justify-between mb-2">
                <h2 class="text-sm font-semibold">By suspected illness</h2>
                <button class="text-xs text-muted-foreground hover:text-foreground" @click="filters.group=null; loadData()" x-show="filters.group">Clear filter</button>
            </div>
            <div class="flex gap-2 overflow-x-auto pb-1">
                <template x-for="g in groups" :key="g.code">
                    <button type="button"
                            :class="{
                                'flex items-center gap-2 rounded-full border px-3 py-1.5 text-[12px] font-medium whitespace-nowrap transition shrink-0': true,
                                'border-primary bg-primary/5 text-primary': filters.group === g.code,
                                'border-slate-200 hover:border-slate-300': filters.group !== g.code,
                            }"
                            @click="filters.group = (filters.group === g.code ? null : g.code); loadData()">
                        <span :class="{
                            'inline-block w-1.5 h-1.5 rounded-full': true,
                            'bg-red-500':   g.top_priority_count > 0,
                            'bg-slate-400': g.top_priority_count === 0,
                        }"></span>
                        <span x-text="g.label"></span>
                        <span class="text-muted-foreground tabular-nums" x-text="`(${g.count})`"></span>
                    </button>
                </template>
            </div>
        </div>
    </section>

    {{-- TABS + SEARCH --}}
    <section class="card">
        <div class="card-content !p-0">
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b min-w-0">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3 min-w-0">
                    <div class="tabs-list w-full sm:w-auto">
                        <template x-for="t in tabs" :key="t.key">
                            <button class="tabs-trigger flex-1 sm:flex-none"
                                    :data-state="filters.status === t.key ? 'active' : 'inactive'"
                                    @click="filters.status = t.key; loadData()">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="tabCounts[t.key] ?? 0"></span>
                            </button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <input type="search" class="input w-full sm:w-72"
                           placeholder="Search by title, place, or words…"
                           x-model.debounce.300ms="filters.q" @input="loadData()">
                </div>

                {{-- Smart filter row: date window, level, geo --}}
                <div class="flex flex-wrap gap-2 min-w-0">
                    <div class="flex flex-wrap gap-1.5 min-w-0">
                        <template x-for="w in dateWindows" :key="w.key">
                            <button type="button"
                                    @click="filters.date_window = w.key; loadData()"
                                    :class="{
                                        'inline-flex items-center rounded-full border px-2.5 py-1 text-[11.5px] font-medium whitespace-nowrap transition shrink-0': true,
                                        'border-blue-500 bg-blue-50 text-blue-700': filters.date_window === w.key,
                                        'border-slate-200 text-slate-600 hover:border-slate-300': filters.date_window !== w.key,
                                    }"
                                    x-text="w.label"></button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    {{-- Risk-level selector (controller honours risk_level filter). --}}
                    <select class="text-xs h-8 rounded-md border border-slate-300 bg-white px-2 py-1"
                            x-model="filters.risk_level" @change="loadData()">
                        <option value="">Any risk</option>
                        <option value="CRITICAL">Critical</option>
                        <option value="HIGH">High</option>
                        <option value="MEDIUM">Medium</option>
                        <option value="LOW">Low</option>
                    </select>
                    {{-- Response-team / level selector (was the only one rendered before) --}}
                    <select class="text-xs h-8 rounded-md border border-slate-300 bg-white px-2 py-1"
                            x-model="filters.routed_to_level" @change="loadData()">
                        <option value="">Any response team</option>
                        <option value="DISTRICT">District team</option>
                        <option value="PHEOC">Province response centre</option>
                        <option value="NATIONAL">National response centre</option>
                    </select>
                    <select class="text-xs h-8 rounded-md border border-slate-300 bg-white px-2 py-1"
                            x-model="filters.district" @change="loadData()" x-show="(meta?.districts || []).length > 0">
                        <option value="">Any district</option>
                        <template x-for="d in (meta?.districts || [])" :key="d">
                            <option :value="d" x-text="d"></option>
                        </template>
                    </select>
                    {{-- POE selector (controller honours poe filter). Resolves to poe_code on submit. --}}
                    <select class="text-xs h-8 rounded-md border border-slate-300 bg-white px-2 py-1"
                            x-model="filters.poe" @change="loadData()" x-show="(meta?.poes || []).length > 0">
                        <option value="">Any POE</option>
                        <template x-for="p in (meta?.poes || [])" :key="(p.code || p)">
                            <option :value="(p.code || p)" x-text="(p.name || p.code || p)"></option>
                        </template>
                    </select>
                    {{-- Clear-filters: surfaces visibly when any filter is non-default. --}}
                    <button type="button"
                            class="text-xs h-8 rounded-md border border-slate-300 bg-white px-2 py-1 text-slate-600 hover:bg-slate-50"
                            x-show="hasActiveFilters()"
                            @click="resetFilters(); loadData()">Clear filters</button>
                </div>
            </div>

            {{-- ROW LIST --}}
            <div class="divide-y" x-show="!loading && rows.length > 0">
                <template x-for="row in rows" :key="row.id">
                    <button type="button"
                            :class="{
                                'w-full flex items-start gap-3 px-4 sm:px-5 py-3.5 text-left transition min-w-0': true,
                                'hover:bg-muted/40': row.status !== 'CLOSED',
                                'bg-slate-50 opacity-75 hover:opacity-100 hover:bg-slate-100': row.status === 'CLOSED',
                            }"
                            @click="openGateway(row)">

                        {{-- Tier dot --}}
                        <span :class="{
                            'mt-1 inline-block w-2 h-2 rounded-full flex-shrink-0': true,
                            'bg-red-500':   row.status !== 'CLOSED' && row.human?.disease?.tier?.dot === 'red',
                            'bg-amber-500': row.status !== 'CLOSED' && row.human?.disease?.tier?.dot === 'amber',
                            'bg-slate-400': row.status === 'CLOSED' || row.human?.disease?.tier?.dot === 'grey' || !row.human?.disease,
                        }"></span>

                        {{-- Centre column --}}
                        <div class="min-w-0 flex-1">
                            <div class="flex items-start gap-2 flex-wrap min-w-0">
                                <span :class="{
                                        'font-medium text-[14px] leading-snug break-words min-w-0': true,
                                        'line-through decoration-slate-400 decoration-from-font': row.status === 'CLOSED',
                                    }">
                                    <span x-text="row.human?.traveller_name || 'Unnamed traveller'"></span>
                                    <span class="font-normal text-slate-500" x-text="`(${row.human?.classification || 'Under review'})`"></span>
                                </span>
                                <span x-show="row.status === 'CLOSED'"
                                      class="inline-flex items-center gap-1 rounded-full bg-slate-900 text-white px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider whitespace-nowrap shrink-0">Closed</span>
                            </div>
                            <div class="mt-1 flex flex-wrap gap-1.5">
                                {{-- Risk pill --}}
                                <span :class="{
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-semibold uppercase tracking-wide': true,
                                    'bg-rose-100 text-rose-700':       row.human?.risk_tone === 'urgent',
                                    'bg-amber-100 text-amber-700':     row.human?.risk_tone === 'watch',
                                    'bg-slate-100 text-slate-600':     row.human?.risk_tone === 'info',
                                }" x-text="row.human?.risk_label || ''"></span>
                                {{-- Status pill --}}
                                <span :class="{
                                    'inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-semibold': true,
                                    'bg-rose-100 text-rose-700':     row.human?.status_tone === 'urgent',
                                    'bg-amber-100 text-amber-700':   row.human?.status_tone === 'watch',
                                    'bg-emerald-100 text-emerald-700': row.human?.status_tone === 'done',
                                    'bg-slate-100 text-slate-600':   row.human?.status_tone === 'info' || row.human?.status_tone === 'skipped',
                                }" x-text="row.human?.status_label || ''"></span>

                                {{-- Suspected-disease chips: up to 3 ranked diseases from the engine.
                                     Rank-1 chip is solid (the headline); ranks 2-3 are softer outlines. --}}
                                <template x-for="(d, i) in (row.suspected_diseases || []).slice(0, 3)" :key="d.disease_code + i">
                                    <span :class="{
                                        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10.5px] font-medium whitespace-nowrap': true,
                                        'bg-indigo-100 text-indigo-700':    i === 0,
                                        'border border-indigo-200 text-indigo-700 bg-white': i > 0,
                                    }">
                                        <span class="text-[8.5px] font-bold opacity-70" x-show="(row.suspected_diseases || []).length > 1" x-text="`#${d.rank_order}`"></span>
                                        <span x-text="prettyDisease(d.disease_code)"></span>
                                        <span class="text-[9px] opacity-70" x-show="d.confidence != null" x-text="`${Math.round(d.confidence)}%`"></span>
                                    </span>
                                </template>

                                {{-- Blocking-followups chip: red, conspicuous, replaces buried prose --}}
                                <span class="inline-flex items-center gap-1 rounded-full bg-rose-600 text-white px-2 py-0.5 text-[10.5px] font-semibold whitespace-nowrap"
                                      x-show="(row.blocking_followups_count || 0) > 0"
                                      :title="`${row.blocking_followups_count} blocking step(s) must be COMPLETED or NOT_APPLICABLE before close.`">
                                    <span>⛔</span>
                                    <span x-text="`${row.blocking_followups_count} blocking`"></span>
                                </span>

                                {{-- Owner chip: who's holding this --}}
                                <span class="inline-flex items-center gap-1 rounded-full bg-slate-100 text-slate-700 px-2 py-0.5 text-[10.5px] font-medium whitespace-nowrap"
                                      x-show="row.current_owner_name"
                                      :title="`Owner: ${row.current_owner_name} (${row.current_owner_role || '—'})`">
                                    <span>👤</span>
                                    <span x-text="row.current_owner_name"></span>
                                </span>
                            </div>

                            <p class="mt-1 text-[12.5px] text-muted-foreground break-words" x-text="rowSubtitle(row)"></p>

                            {{-- CLOSED rows: surface close_category + close_note inline so operators
                                 reading the Closed tab actually see WHY it was closed. --}}
                            <div class="mt-1 text-[11.5px] text-slate-600 break-words" x-show="row.status === 'CLOSED' && row.close_category">
                                <span class="font-semibold">Closed as</span>
                                <span class="inline-flex items-center rounded-full bg-slate-900 text-white px-1.5 py-0 text-[10px] font-semibold uppercase tracking-wide mx-1"
                                      x-text="(row.close_category || '').replace(/_/g, ' ')"></span>
                                <span x-show="row.close_note" x-text="`— ${row.close_note}`"></span>
                            </div>
                        </div>

                        {{-- Right column - time + inline actions --}}
                        <div class="text-right shrink-0 flex flex-col items-end gap-1.5">
                            <p class="text-[11px] text-muted-foreground tabular-nums" x-text="row.human?.created_human || ''"></p>

                            {{-- Inline action row. Gated by role + alert state. Click stops bubbling
                                 so the row's openGateway doesn't also fire. ≤2 clicks to acknowledge / view. --}}
                            <div class="flex items-center gap-1.5" @click.stop>
                                {{-- Acknowledge: only on OPEN, if user role is permitted --}}
                                <button type="button"
                                        x-show="row.status === 'OPEN' && canActOn(row, 'acknowledge')"
                                        @click.stop="quickAcknowledge(row)"
                                        class="inline-flex items-center justify-center w-7 h-7 rounded-md bg-emerald-50 text-emerald-700 hover:bg-emerald-100 border border-emerald-200 text-[11px] font-semibold"
                                        :title="`Acknowledge alert #${row.id}`">✓</button>

                                {{-- Open case file: always available --}}
                                <a :href="`/admin/alerts/${row.id}/case-file`"
                                   @click.stop
                                   class="inline-flex items-center justify-center w-7 h-7 rounded-md bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-200 text-[11px]"
                                   :title="`Open the case file for alert #${row.id}`">📋</a>

                                {{-- Arrow → gateway (existing behaviour) --}}
                                <span class="text-muted-foreground text-sm">→</span>
                            </div>
                        </div>
                    </button>
                </template>
            </div>

            {{-- EMPTY STATE --}}
            <div class="px-6 py-12 text-center" x-show="!loading && rows.length === 0">
                <p class="text-sm text-muted-foreground">Nothing here right now.</p>
                <p class="mt-1 text-xs text-muted-foreground">When a new case comes in, it will appear at the top.</p>
            </div>

            {{-- LOADING --}}
            <div class="px-6 py-12 text-center" x-show="loading">
                <p class="text-sm text-muted-foreground">Loading…</p>
            </div>

            {{-- LOAD MORE --}}
            <div class="p-4 text-center" x-show="!loading && nextCursor">
                <button class="btn btn-outline" @click="loadMore()">Show more</button>
            </div>
        </div>
    </section>

    {{-- ── SMART-ACTION GATEWAY (full-screen sheet, premium) ─────────────────── --}}
    <div x-show="gateway.open" x-cloak
         class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-md flex items-end sm:items-center justify-center"
         @keydown.escape.window="gateway.open = false">

        <div class="bg-white w-full h-full sm:h-auto sm:max-h-[90vh] sm:max-w-2xl sm:rounded-3xl shadow-2xl flex flex-col overflow-hidden"
             @click.away="gateway.open = false">

            {{-- Sheet handle (mobile) --}}
            <div class="flex justify-center pt-2 sm:hidden shrink-0">
                <span class="w-10 h-1 rounded-full bg-slate-300"></span>
            </div>

            {{-- HEADER --}}
            <header class="px-5 sm:px-7 pt-4 sm:pt-7 pb-3 sm:pb-4 shrink-0">
                <div class="flex items-start gap-3 min-w-0">
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] uppercase tracking-[0.12em] text-slate-500 font-semibold break-words"
                           x-text="gateway.row?.human?.traveller_name ? gateway.row.human.traveller_name : ''"></p>
                        <p class="text-[13px] text-slate-500 break-words"
                           x-text="gateway.row?.human?.classification ? `(${gateway.row.human.classification})` : ''"></p>
                        <h2 class="mt-2 text-xl sm:text-2xl font-bold leading-tight tracking-tight text-slate-900 break-words">What do you want to do with this case?</h2>
                        <p class="mt-1 text-sm text-slate-600 break-words">Pick what fits where you are. Each option does something different.</p>
                    </div>
                    <button type="button" @click="gateway.open = false"
                            class="hidden sm:inline-flex items-center justify-center w-9 h-9 rounded-full text-slate-500 hover:bg-slate-100 shrink-0"
                            aria-label="Close">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                    </button>
                </div>
            </header>

            {{-- BODY --}}
            <div class="overflow-y-auto px-5 sm:px-7 pb-4 grow">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 sm:gap-3">
                    <template x-for="opt in gateway.options" :key="opt.code">
                        <button type="button"
                                @click="actGateway(opt)"
                                class="group relative rounded-2xl border border-slate-200 bg-white p-4 sm:p-5 text-left hover:border-slate-400 hover:shadow-md active:scale-[0.985] transition min-w-0 overflow-hidden">
                            <div class="flex items-start gap-3 min-w-0">
                                <span class="mt-0.5 inline-flex items-center justify-center w-9 h-9 rounded-xl text-base shrink-0"
                                      :class="optionToneClass(opt.code)">
                                    <span x-text="optionGlyph(opt.code)"></span>
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[13.5px] font-semibold text-slate-900 leading-snug break-words" x-text="opt.label"></span>
                                    <span class="block mt-1 text-[12.5px] text-slate-500 break-words" x-text="opt.help"></span>
                                </span>
                                <span class="text-slate-300 group-hover:text-slate-500 shrink-0">→</span>
                            </div>
                        </button>
                    </template>
                </div>

                <p class="mt-4 text-[12.5px] text-slate-500 italic" x-show="gateway.loading">Loading options…</p>
                <p class="mt-4 text-[12.5px] text-slate-500 italic" x-show="!gateway.loading && gateway.options.length === 0">No actions available for this case.</p>
            </div>

            {{-- FOOTER --}}
            <footer class="px-5 sm:px-7 py-3 sm:py-4 border-t border-slate-100 shrink-0">
                <button type="button"
                        class="w-full sm:w-auto sm:ml-auto sm:flex inline-flex items-center justify-center gap-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2.5 sm:py-2 text-sm font-medium text-slate-700 hover:bg-slate-50"
                        @click="gateway.open = false">Cancel</button>
            </footer>

        </div>
    </div>

    {{-- TOAST --}}
    <div x-show="toast.show" x-cloak x-transition
         :class="{
            'fixed bottom-6 right-6 z-50 rounded-lg shadow-lg px-4 py-3 text-sm max-w-sm': true,
            'bg-rose-600 text-white':    toast.tone === 'error',
            'bg-emerald-600 text-white': toast.tone === 'ok',
         }"
         x-text="toast.message"></div>

</div>

<script>
function alertHub(opts) {
    return {
        endpoints: opts.endpoints,

        rows:        [],
        groups:      [],
        meta:        null,
        nextCursor:  null,
        tabCounts:   {},
        loading:     true,

        // Hero insights — kept in a stable shape so the SVG never sees undefined.
        insights: {
            totals: { all: 0, open: 0, acknowledged: 0, closed: 0 },
            trend: [], trend_max: 1,
            risk:   { CRITICAL: 0, HIGH: 0, MEDIUM: 0, LOW: 0 },
            groups: [],
            hotspots: [],
            outcomes: { CONFIRMED: 0, PROBABLE: 0, SUSPECTED: 0, DISCARDED: 0, LOST_TO_FOLLOWUP: 0, UNKNOWN: 0 },
        },

        // risk_level + poe filters added 2026-05-20 — controller already honours them,
        // selectors are now rendered above. owner_user_id stays in the controller layer
        // until we add an owner-picker UI in a follow-up.
        filters: {
            status: 'open', q: '', group: null,
            date_window: 'all', routed_to_level: '',
            district: '', province: '', risk_level: '', poe: '',
        },

        // Default filter snapshot — used by resetFilters() so we don't drift.
        get _defaultFilters() {
            return { status: 'open', q: '', group: null, date_window: 'all', routed_to_level: '', district: '', province: '', risk_level: '', poe: '' };
        },

        tabs: [
            { key: 'open',         label: 'New' },
            { key: 'acknowledged', label: 'Being worked on' },
            { key: 'closed',       label: 'Closed' },
            { key: 'reopened',     label: 'Reopened' },
            { key: 'all',          label: 'All' },
        ],

        dateWindows: [
            { key: 'all',         label: 'Any time' },
            { key: 'past_24h',    label: 'Past 24 hours' },
            { key: 'past_7d',     label: 'Past 7 days' },
            { key: 'past_14d',    label: 'Past 14 days' },
            { key: 'past_30d',    label: 'Past 30 days' },
            { key: 'this_month',  label: 'This month' },
            { key: 'this_year',   label: 'This year' },
        ],

        gateway: { open: false, row: null, title: '', subtitle: '', options: [], loading: false },

        toast: { show: false, message: '', tone: 'ok', timer: null },

        async boot() {
            this.readFiltersFromUrl();
            await Promise.all([this.loadMeta(), this.loadInsights()]);
            await this.loadData();
            // Re-run insights when filters that change cohort fire (window / risk / level / district / poe / status).
            // The KPI strip otherwise shows all-time totals against a filtered list — confusing.
            for (const k of ['date_window','risk_level','routed_to_level','district','poe','status']) {
                this.$watch(`filters.${k}`, () => this.loadInsights().catch(() => {}));
            }
        },

        // URL state ── persist filter selections so links are bookmarkable + shareable.
        // Same pattern used by every Quick Report. Keeps things in sync with bookmarks.
        readFiltersFromUrl() {
            try {
                const u = new URL(window.location.href);
                for (const k of Object.keys(this.filters)) {
                    const v = u.searchParams.get(k);
                    if (v !== null) this.filters[k] = v;
                }
            } catch (_) { /* SSR / bad URL — fail silently */ }
        },
        writeFiltersToUrl() {
            try {
                const u = new URL(window.location.href);
                for (const [k, v] of Object.entries(this.filters)) {
                    if (v === '' || v == null || v === 'all' || k === 'group') {
                        u.searchParams.delete(k);
                    } else {
                        u.searchParams.set(k, v);
                    }
                }
                window.history.replaceState({}, '', u);
            } catch (_) { /* ignore */ }
        },

        hasActiveFilters() {
            const d = this._defaultFilters;
            for (const k of Object.keys(this.filters)) {
                if (k === 'status') continue; // status is the tab, handled separately
                if ((this.filters[k] ?? '') !== (d[k] ?? '')) return true;
            }
            return false;
        },

        resetFilters() {
            const d = this._defaultFilters;
            for (const k of Object.keys(d)) this.filters[k] = d[k];
            this.writeFiltersToUrl();
        },

        // Convert disease_code (e.g. 'ebola_virus_disease') to a human label.
        // We don't have a server lookup here — best-effort string transform that
        // also strips a leading "(suspected) " prefix the engine occasionally emits.
        prettyDisease(code) {
            if (!code) return '';
            const s = String(code).replace(/^\(suspected\)\s*/i, '').replace(/[_\-]+/g, ' ');
            return s.replace(/\b\w/g, c => c.toUpperCase());
        },

        // RBAC: which actions can the current user fire on this alert?
        // The actual server-side guard is authoritative (per /admin/alerts route
        // middleware: NATIONAL_ADMIN, PHEOC_OFFICER, PHEOC_ADMIN,
        // DISTRICT_SUPERVISOR, DISTRICT_ADMIN may acknowledge). This UI gate
        // hides the button so users don't see options they can't fire.
        canActOn(row, action) {
            const role = (this.meta?.actor?.role_key || '').toUpperCase();
            if (action === 'acknowledge') {
                return ['NATIONAL_ADMIN','PHEOC_OFFICER','PHEOC_ADMIN','DISTRICT_SUPERVISOR','DISTRICT_ADMIN'].includes(role)
                    && row.status === 'OPEN';
            }
            return false;
        },

        async quickAcknowledge(row) {
            if (!confirm(`Acknowledge alert #${row.id} for ${row.human?.traveller_name || 'this case'}?`)) return;
            try {
                const r = await this.fetchJson(`/admin/alerts/${row.id}/acknowledge`, {
                    method: 'PATCH',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({}),
                });
                if (r) {
                    this.showToast('Alert acknowledged.', 'ok');
                    // Refresh row data + insights so KPI strip stays honest.
                    this.loadData();
                    this.loadInsights();
                }
            } catch (e) {
                this.showToast(e.message || 'Could not acknowledge.', 'error');
            }
        },

        csrfToken() { return document.querySelector('meta[name="csrf-token"]').content; },

        showToast(message, tone='ok', ms=2500) {
            clearTimeout(this.toast.timer);
            this.toast.message = message;
            this.toast.tone = tone;
            this.toast.show = true;
            this.toast.timer = setTimeout(() => this.toast.show = false, ms);
        },

        async fetchJson(url, init={}) {
            try {
                const res = await fetch(url, {
                    credentials: 'same-origin',
                    ...init,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': this.csrfToken(),
                        ...(init.headers || {}),
                    },
                });
                const body = await res.json().catch(() => ({}));
                if (!res.ok || body.success === false) {
                    const msg = body?.error?.human || body?.message || 'Something went wrong. Try again.';
                    this.showToast(msg, 'error');
                    return null;
                }
                return body;
            } catch (e) {
                this.showToast('Lost connection. Try again.', 'error');
                return null;
            }
        },

        async loadMeta() {
            const body = await this.fetchJson(this.endpoints.meta);
            if (body) this.meta = body.data;
        },

        async loadInsights() {
            const body = await this.fetchJson(this.endpoints.insights);
            if (body && body.data) {
                this.insights = Object.assign(this.insights, body.data);
                if (!this.insights.trend_max || this.insights.trend_max < 1) this.insights.trend_max = 1;
            }
        },

        // Sparkline bar height (clamped 0–40 px). Zeros render as a 1 px sliver
        // so the timeline reads as continuous rather than gappy.
        sparkBar(count) {
            const max = Math.max(1, this.insights.trend_max || 1);
            if (!count) return 1;
            return Math.max(2, Math.round((count / max) * 36));
        },

        // Risk ring slice (% of OPEN alerts in this risk band).
        ringPart(level) {
            const r = this.insights.risk || {};
            const total = (r.CRITICAL || 0) + (r.HIGH || 0) + (r.MEDIUM || 0) + (r.LOW || 0);
            if (!total) return 0;
            return Math.round(((r[level] || 0) / total) * 100);
        },

        // Group-bar width (% of the busiest group).
        groupPct(count) {
            const top = (this.insights.groups || [])[0];
            const max = Math.max(1, top ? top.count : 1);
            return Math.round((count / max) * 100);
        },

        async loadData(append=false) {
            this.loading = !append;
            if (!append) this.writeFiltersToUrl();
            const params = new URLSearchParams();
            params.set('status', this.filters.status);
            if (this.filters.q)               params.set('q', this.filters.q);
            if (this.filters.date_window && this.filters.date_window !== 'all') params.set('date_window', this.filters.date_window);
            if (this.filters.routed_to_level) params.set('routed_to_level', this.filters.routed_to_level);
            if (this.filters.district)        params.set('district', this.filters.district);
            if (this.filters.province)        params.set('province', this.filters.province);
            if (this.filters.risk_level)      params.set('risk_level', this.filters.risk_level);
            if (this.filters.poe)             params.set('poe', this.filters.poe);
            if (append && this.nextCursor)    params.set('cursor', this.nextCursor);

            const body = await this.fetchJson(`${this.endpoints.data}?${params.toString()}`);
            this.loading = false;
            if (!body) return;

            let rows = body.data.rows || [];
            if (this.filters.group) {
                rows = rows.filter(r => (r.human?.disease?.group || 'syndromic_unknown') === this.filters.group);
            }

            this.rows       = append ? [...this.rows, ...rows] : rows;
            this.nextCursor = body.data.next_cursor;
            this.groups     = body.data.groups || [];
            this.tabCounts  = (body.meta && body.meta.tabs) || {};
        },

        loadMore() { this.loadData(true); },

        rowSubtitle(row) {
            const parts = [];
            if (row.poe_code)      parts.push(row.poe_code);
            if (row.district_code) parts.push(row.district_code);
            if (row.human?.routed_to) parts.push('with ' + row.human.routed_to);
            return parts.join(' · ');
        },

        async openGateway(row) {
            this.gateway.row     = row;
            this.gateway.options = [];
            this.gateway.loading = true;
            this.gateway.open    = true;

            const body = await this.fetchJson(this.endpoints.gatewayOf(row.id));
            this.gateway.loading = false;
            if (!body) { this.gateway.open = false; return; }

            this.gateway.title    = body.data.title;
            this.gateway.subtitle = body.data.subtitle;
            this.gateway.options  = body.data.options || [];
        },

        actGateway(opt) {
            const id = this.gateway.row?.id;
            if (!id) return;
            this.gateway.open = false;

            const wizard = this.endpoints.wizardOf(id);

            switch (opt.code) {
                case 'OPEN_WIZARD':
                    window.location.href = wizard;
                    break;
                case 'OPEN_CASEFILE':
                    window.location.href = this.endpoints.casefileOf(id);
                    break;
                case 'OPEN_FALSE_ALARM':
                    window.location.href = wizard + '?action=false-alarm';
                    break;
                case 'OPEN_MASTER_CLOSE':
                    window.location.href = wizard + '?action=master-close';
                    break;
                case 'OPEN_REASSIGN':
                    window.location.href = wizard + '?action=reassign';
                    break;
                case 'OPEN_ESCALATE':
                    window.location.href = wizard + '?action=escalate';
                    break;
                default:
                    window.location.href = wizard;
            }
        },

        optionGlyph(code) {
            switch (code) {
                case 'OPEN_WIZARD':       return '→';
                case 'OPEN_CASEFILE':     return 'i';
                case 'OPEN_REASSIGN':     return '⇄';
                case 'OPEN_ESCALATE':     return '↑';
                case 'OPEN_FALSE_ALARM':  return '×';
                case 'OPEN_MASTER_CLOSE': return '✓';
                default:                  return '·';
            }
        },

        optionToneClass(code) {
            switch (code) {
                case 'OPEN_WIZARD':       return 'bg-blue-50 text-blue-700';
                case 'OPEN_CASEFILE':     return 'bg-slate-100 text-slate-700';
                case 'OPEN_REASSIGN':     return 'bg-violet-50 text-violet-700';
                case 'OPEN_ESCALATE':     return 'bg-amber-50 text-amber-700';
                case 'OPEN_FALSE_ALARM':  return 'bg-rose-50 text-rose-700';
                case 'OPEN_MASTER_CLOSE': return 'bg-emerald-50 text-emerald-700';
                default:                  return 'bg-slate-50 text-slate-600';
            }
        },

    };
}
</script>
@endsection
