@extends('admin.layout')

@section('crumb', 'Workforce')
@section('title', 'Assignments')

@php
    /** @var array $coach Coach Manifest from CoachManifest::forView('assignments'). */
    $coach = $coach ?? \App\Support\Workforce\CoachManifest::forView('assignments');
@endphp

@section('content')
{{--
    Workforce · Assignments — v4 jurisdiction-placement surface.

    Mobile-API impact: NONE. Same /admin/workforce/assignments/* reads
    and mutations (web-only). Schema and controllers unchanged. /api/*
    not touched.

    Viewport discipline: sticky toolbar; table body is the Y-scroll
    region; all modals scroll inside themselves.
--}}
<div x-data="wfAssignments()" x-init="boot()"
     x-effect="window.adminLock?.set?.('page', wizard?.open || preConfirm?.open || postAction?.open || actionSheet?.open || compareSheet?.open || coachSheet?.open)"
     class="flex flex-col gap-4 min-h-[calc(100vh-7rem)]">

    <script type="application/json" id="wf-assignments-coach">@json($coach)</script>

    {{-- ─── Coach intro strip ─────────────────────────────────────────── --}}
    <section class="rounded-2xl border bg-card shadow-sm">
        <button type="button" class="w-full text-left flex items-start gap-3 px-4 sm:px-5 py-3"
                @click="coachIntroOpen=!coachIntroOpen" :aria-expanded="coachIntroOpen">
            <span class="grid place-items-center h-8 w-8 rounded-lg bg-brand-soft text-brand-ink shrink-0 mt-0.5">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
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

    {{-- ─── Hero — KPIs + coverage bars ───────────────────────────────── --}}
    <section class="rounded-2xl border bg-gradient-to-br from-card via-card to-muted/30 shadow-sm overflow-hidden">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-0">
            <div class="lg:col-span-7 grid grid-cols-2 sm:grid-cols-4 gap-0 divide-x divide-y sm:divide-y-0 border-b lg:border-b-0 lg:border-r">
                <button type="button" class="px-4 py-3.5 text-left hover:bg-muted/30 transition" @click="filters.status='active'; loadData(); pushUrl()">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">In force</p>
                    <p class="text-[20px] font-bold tabular-nums" x-text="formatN(tabCounts.active)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">people covering somewhere</p>
                </button>
                <button type="button" class="px-4 py-3.5 text-left hover:bg-muted/30 transition" @click="filters.status='ended'; loadData(); pushUrl()">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Ended</p>
                    <p class="text-[20px] font-bold tabular-nums text-muted-foreground" x-text="formatN(tabCounts.ended)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">historical</p>
                </button>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Primary</p>
                    <p class="text-[20px] font-bold tabular-nums" x-text="formatN(primaryCount)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">default scopes set</p>
                </div>
                <button type="button" class="px-4 py-3.5 text-left hover:bg-muted/30 transition" @click="filters.status='all'; loadData(); pushUrl()">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">All time</p>
                    <p class="text-[20px] font-bold tabular-nums" x-text="formatN(tabCounts.all)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">in your scope</p>
                </button>
            </div>
            <div class="lg:col-span-5 px-4 py-3.5">
                <div class="flex items-center justify-between mb-1.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Coverage by province</p>
                    <p class="text-[10px] text-muted-foreground" x-text="provinceBars.length+' provinces'"></p>
                </div>
                <ul class="space-y-1.5 max-h-32 overflow-auto pr-1">
                    <template x-if="provinceBars.length===0"><li class="text-[11px] italic text-muted-foreground">Not yet recorded</li></template>
                    <template x-for="b in provinceBars.slice(0,6)" :key="b.province">
                        <li class="text-[10.5px]">
                            <button type="button" class="w-full text-left group" @click="filters.province_code=b.province; loadData(); pushUrl()" :title="`Show only ${b.province}`">
                                <div class="flex items-center justify-between gap-2 mb-0.5">
                                    <span class="font-semibold truncate group-hover:text-brand transition" x-text="b.province"></span>
                                    <span class="tabular-nums text-muted-foreground" x-text="b.count"></span>
                                </div>
                                <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                                    <div class="h-full bg-brand rounded-full transition-all" :style="`width:${b.pct}%`"></div>
                                </div>
                            </button>
                        </li>
                    </template>
                </ul>
                <p class="text-[9.5px] text-muted-foreground mt-1.5 italic">Tap a bar to filter the list below.</p>
            </div>
        </div>
    </section>

    {{-- ─── Toolbar + table ──────────────────────────────────────────── --}}
    <section class="card flex-1 flex flex-col min-h-0">
        <div class="card-content !p-0 flex flex-col min-h-0">

            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b bg-card sticky top-0 z-20">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto">
                        <template x-for="t in [{key:'active',label:'In force'},{key:'ended',label:'Ended'},{key:'all',label:'All'}]" :key="t.key">
                            <button class="tabs-trigger flex-1 sm:flex-none" :data-state="filters.status===t.key?'active':'inactive'" @click="filters.status=t.key; loadData(); pushUrl()">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="formatN(tabCounts[t.key]||0)"></span>
                            </button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <div class="relative w-full sm:w-72">
                        <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        <input type="search" class="input pl-8 w-full" placeholder="Search by user…" minlength="2" x-model.debounce.250ms="filters.q" @input="loadData(); pushUrl()">
                    </div>
                    <div class="flex items-center gap-2">
                        <button class="btn btn-outline btn-sm" @click="advanced=!advanced" :data-state="advanced?'active':'inactive'">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L14 13.414V19a1 1 0 01-1.447.894l-4-2A1 1 0 018 17v-3.586L3.293 6.707A1 1 0 013 6V4z"/></svg>
                            <span class="hidden sm:inline">Filters</span>
                            <span x-show="activeFilterCount>0" class="badge badge-brand ml-1 px-1.5 py-0 text-[9.5px]" x-text="activeFilterCount"></span>
                        </button>
                        <button class="btn btn-brand btn-sm" @click="openCreate()">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14m-7-7h14"/></svg>
                            <span>Place a person</span>
                        </button>
                    </div>
                </div>

                <div x-show="advanced" x-cloak x-collapse class="space-y-2 pt-2 border-t border-dashed">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mr-1">Effective window</p>
                        <template x-for="w in windows" :key="w.key">
                            <button class="px-2.5 py-1 text-[11px] rounded-full border transition" :class="filters.window===w.key?'bg-brand text-white border-brand':'bg-card hover:bg-muted/40'" @click="filters.window=(filters.window===w.key?'':w.key); pushUrl()" x-text="w.label"></button>
                        </template>
                        <button x-show="filters.window" class="text-[11px] text-muted-foreground underline" @click="filters.window=''; pushUrl()">clear</button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mr-1">Refine</p>
                        <select class="select w-auto !h-8 text-xs" x-model="filters.province_code" @change="loadData(); pushUrl()">
                            <option value="">Any province</option>
                            <template x-for="p in meta.provinces" :key="p"><option :value="p" x-text="p"></option></template>
                        </select>
                        <select class="select w-auto !h-8 text-xs" x-model="filters.district_code" @change="loadData(); pushUrl()">
                            <option value="">Any district</option>
                            <template x-for="d in meta.districts" :key="d.name"><option :value="d.name" x-text="d.name"></option></template>
                        </select>
                        <select class="select w-auto !h-8 text-xs" x-model="filters.poe_code" @change="loadData(); pushUrl()">
                            <option value="">Any PoE</option>
                            <template x-for="p in meta.poes" :key="p.poe_code"><option :value="p.poe_code" x-text="p.poe_name"></option></template>
                        </select>
                        <select class="select w-auto !h-8 text-xs" x-model="filters.primary" @change="pushUrl()">
                            <option value="">Primary or not</option>
                            <option value="1">Primary only</option>
                            <option value="0">Not primary</option>
                        </select>
                        <button class="text-[11px] text-muted-foreground underline" @click="resetFilters()">reset all</button>
                        <span class="ml-auto text-[10px] text-muted-foreground" x-show="freshness" x-text="freshness"></span>
                    </div>
                </div>
            </div>

            <div x-show="offline" x-cloak class="bg-warning-soft text-warning border-b px-4 py-1.5 text-[11px] text-center">You are offline. New assignments will not save until your connection comes back.</div>

            {{-- Table --}}
            <div class="flex-1 min-h-0 overflow-auto">
                <table class="table">
                    <thead class="table-head sticky top-0 z-10 bg-card"><tr>
                        <th class="table-head-th">Person</th>
                        <th class="table-head-th hidden md:table-cell">Place</th>
                        <th class="table-head-th hidden lg:table-cell">Period</th>
                        <th class="table-head-th text-right">Actions</th>
                    </tr></thead>
                    <tbody class="table-body">
                        <template x-if="loading">
                            <template x-for="i in 6" :key="'sk'+i">
                                <tr class="table-row">
                                    <td class="table-cell"><div class="space-y-1.5"><div class="h-3 w-32 bg-muted/60 rounded animate-pulse"></div><div class="h-2.5 w-24 bg-muted/40 rounded animate-pulse"></div></div></td>
                                    <td class="table-cell hidden md:table-cell"><div class="h-3 w-28 bg-muted/40 rounded animate-pulse"></div></td>
                                    <td class="table-cell hidden lg:table-cell"><div class="h-3 w-32 bg-muted/40 rounded animate-pulse"></div></td>
                                    <td class="table-cell text-right"><div class="h-6 w-6 ml-auto bg-muted/40 rounded animate-pulse"></div></td>
                                </tr>
                            </template>
                        </template>
                        <template x-if="!loading && filteredRows.length===0 && loadError">
                            <tr><td colspan="4" class="table-cell"><div class="empty-state"><p class="text-sm font-semibold text-critical">Could not load assignments.</p><p class="text-[12px] text-muted-foreground" x-text="loadError"></p><button class="btn btn-outline btn-sm" @click="loadData()">Try again</button></div></td></tr>
                        </template>
                        <template x-if="!loading && filteredRows.length===0 && !loadError">
                            <tr><td colspan="4" class="table-cell"><div class="empty-state"><p class="text-sm" x-text="hasActiveFilters?'Nobody matches those filters.':'No assignments yet.'"></p><p class="text-[11.5px] italic text-muted-foreground" x-show="!hasActiveFilters">Place the first person to start.</p>
                                <template x-if="hasActiveFilters"><button class="btn btn-outline btn-sm" @click="resetFilters()">Clear filters</button></template>
                                <template x-if="!hasActiveFilters"><button class="btn btn-brand btn-sm" @click="openCreate()">Place the first person</button></template>
                            </div></td></tr>
                        </template>
                        <template x-for="row in filteredRows" :key="row.id">
                            <tr class="table-row hover:bg-muted/20 transition" :class="{ 'opacity-60': !row.is_active || row.ends_at }">
                                <td class="table-cell">
                                    <div class="flex flex-col">
                                        <span class="text-[12.5px] font-semibold flex items-center gap-1.5">
                                            <span class="truncate max-w-[14rem]" :class="(!row.is_active||row.ends_at)?'line-through text-muted-foreground':''" :title="row.full_name" x-text="row.full_name || ('Anonymous · #'+row.user_id)"></span>
                                            <span x-show="row.is_primary && row.is_active && !row.ends_at" class="badge badge-brand text-[9px] px-1 py-0">primary</span>
                                        </span>
                                        <span class="text-[10.5px] text-muted-foreground"><span x-text="'@'+(row.username||'')"></span> · <span class="badge badge-outline" x-text="roleLabel(row.role_key)"></span></span>
                                    </div>
                                </td>
                                <td class="table-cell hidden md:table-cell text-[11.5px]">
                                    <div class="font-semibold" x-text="row.poe_code || row.district_code || row.province_code || row.country_code || '—'"></div>
                                    <div class="text-muted-foreground" x-text="[row.country_code, row.province_code, row.district_code].filter(Boolean).join(' › ')"></div>
                                </td>
                                <td class="table-cell hidden lg:table-cell text-[11px] text-muted-foreground">
                                    <span :title="row.starts_at ? new Date(row.starts_at).toLocaleString() : ''" x-text="row.starts_at ? new Date(row.starts_at).toLocaleDateString() : '—'"></span>
                                    <span class="mx-1">→</span>
                                    <span :title="row.ends_at ? new Date(row.ends_at).toLocaleString() : 'open ended'" x-text="row.ends_at ? new Date(row.ends_at).toLocaleDateString() : 'open'"></span>
                                    <span x-show="!row.is_active || row.ends_at" class="badge badge-outline ml-1">ended</span>
                                </td>
                                <td class="table-cell text-right">
                                    <button class="btn btn-ghost btn-xs" @click="openActions(row)" title="Actions for this assignment">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/></svg>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center justify-between gap-3 px-4 sm:px-5 py-2.5 border-t bg-muted/20 text-[11px] shrink-0">
                <p class="text-muted-foreground">
                    Showing <span class="font-semibold tabular-nums text-foreground" x-text="formatN(filteredRows.length)"></span>
                    <span x-show="filteredRows.length !== rows.length"> of <span class="tabular-nums" x-text="formatN(rows.length)"></span></span>
                </p>
                <p class="text-muted-foreground" x-show="rows.length>=500">First 500 results — refine your filters to narrow.</p>
                <p class="text-muted-foreground ml-auto" x-show="freshness" x-text="freshness"></p>
            </div>
        </div>
    </section>

    {{-- ─── Wizard ────────────────────────────────────────────────────── --}}
    <template x-if="wizard.open">
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true" @keydown.escape.window="askExitWizard()">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="askExitWizard()"></div>
            <div class="relative w-full sm:max-w-xl bg-card border-t sm:border sm:rounded-2xl shadow-elevation-5 max-h-[92vh] flex flex-col" @click.stop>
                <div class="sm:hidden h-1.5 w-10 rounded-full bg-muted mx-auto mt-2 shrink-0"></div>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink shrink-0">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground" x-text="wizard.mode==='edit'?'Edit assignment':'Place a person'"></p>
                        <h2 class="text-[14px] font-bold truncate" x-text="selectedUserLabel || 'New placement'"></h2>
                    </div>
                    <label class="hidden sm:flex items-center gap-1.5 text-[10px] text-muted-foreground">
                        <input type="checkbox" x-model="stepThru" @change="persistPref('stepthru',stepThru)">
                        <span>Walk me through</span>
                    </label>
                    <button class="btn btn-ghost btn-icon-xs" @click="askExitWizard()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>

                <div class="flex items-center gap-1.5 px-4 sm:px-6 py-3 border-b bg-muted/20">
                    <template x-for="s in [1,2,3]" :key="s">
                        <div class="flex-1 flex items-center gap-1.5">
                            <div class="grid place-items-center h-6 w-6 rounded-full text-[11px] font-bold shrink-0"
                                 :class="wizard.step===s?'bg-brand text-white':wizard.step>s?'bg-success-soft text-success':'bg-muted text-muted-foreground'">
                                <span x-show="wizard.step<=s" x-text="s"></span>
                                <svg x-show="wizard.step>s" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg>
                            </div>
                            <span class="text-[11px] truncate" x-text="['User','Place','Period &amp; Review'][s-1]"></span>
                            <div class="flex-1 h-px bg-border" x-show="s<3"></div>
                        </div>
                    </template>
                </div>

                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-4">
                    <template x-if="currentStepCoach">
                        <div class="rounded-xl border bg-brand-soft/20 p-3" x-show="stepThru || wizard._explainerOpen[wizard.step]">
                            <p class="text-[12.5px] font-semibold leading-tight" x-text="currentStepCoach.ask"></p>
                            <p class="text-[11.5px] text-muted-foreground mt-1 leading-relaxed" x-text="currentStepCoach.explainer"></p>
                            <p class="text-[11px] mt-1.5" x-show="currentStepCoach.example"><span class="text-muted-foreground italic">Example: </span><span x-text="currentStepCoach.example"></span></p>
                            <p class="text-[11px] text-warning mt-1.5" x-show="currentStepCoach.pitfall">⚠ <span x-text="currentStepCoach.pitfall"></span></p>
                        </div>
                    </template>
                    <template x-if="currentStepCoach && !stepThru && !wizard._explainerOpen[wizard.step]">
                        <button class="text-[10.5px] underline text-muted-foreground" @click="wizard._explainerOpen[wizard.step]=true">Show the explainer for this step</button>
                    </template>

                    <template x-if="wizard.step===1">
                        <div class="space-y-3">
                            <div>
                                <label class="label">User <span class="text-critical">*</span></label>
                                <select class="select mt-1.5" x-model.number="wizard.form.user_id" :disabled="wizard.mode==='edit'">
                                    <option :value="0">Select…</option>
                                    <template x-for="u in meta.users" :key="u.id"><option :value="u.id" x-text="u.full_name + ' · '+u.role_key+' · @'+u.username"></option></template>
                                </select>
                                <p class="text-[10px] text-muted-foreground mt-1" x-text="fieldHint('user_id','hint')"></p>
                            </div>
                            <label class="flex items-start gap-2 mt-2 cursor-pointer">
                                <input type="checkbox" x-model="wizard.form.is_primary" class="mt-0.5">
                                <span class="text-[12px]">
                                    Make this their primary
                                    <span class="block text-[10.5px] text-muted-foreground" x-text="fieldHint('is_primary','hint')"></span>
                                </span>
                            </label>
                        </div>
                    </template>

                    <template x-if="wizard.step===2">
                        <div class="space-y-3">
                            <div><label class="label">Country</label><input type="text" class="input mt-1.5" x-model="wizard.form.country_code" placeholder="Uganda"></div>
                            <div>
                                <label class="label">Province / PHEOC</label>
                                <select class="select mt-1.5" x-model="wizard.form.province_code" @change="wizard.form.pheoc_code=wizard.form.province_code">
                                    <option value="">Select…</option>
                                    <template x-for="p in meta.provinces" :key="p"><option :value="p" x-text="p"></option></template>
                                </select>
                                <p class="text-[10px] text-muted-foreground mt-1" x-text="fieldHint('province_code','hint')"></p>
                            </div>
                            <div>
                                <label class="label">District</label>
                                <select class="select mt-1.5" x-model="wizard.form.district_code">
                                    <option value="">Select…</option>
                                    <template x-for="d in meta.districts" :key="d.name"><option :value="d.name" x-text="d.name"></option></template>
                                </select>
                            </div>
                            <div>
                                <label class="label">Point of Entry</label>
                                <select class="select mt-1.5" x-model="wizard.form.poe_code" @change="autoFromPoe()">
                                    <option value="">None — district / province scope only</option>
                                    <template x-for="p in meta.poes" :key="p.poe_code"><option :value="p.poe_code" x-text="p.poe_name + ' · ' + p.district"></option></template>
                                </select>
                                <p class="text-[10px] text-muted-foreground mt-1" x-text="fieldHint('poe_code','hint')"></p>
                            </div>
                        </div>
                    </template>

                    <template x-if="wizard.step===3">
                        <div class="space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div><label class="label">Starts</label><input type="date" class="input mt-1.5" x-model="wizard.form.starts_at_date"></div>
                                <div><label class="label">Ends (optional)</label><input type="date" class="input mt-1.5" x-model="wizard.form.ends_at_date" :placeholder="fieldHint('ends_at','example')"><p class="text-[10px] text-muted-foreground mt-1" x-text="fieldHint('ends_at','hint')"></p></div>
                            </div>
                            <label class="flex items-center gap-2 mt-2"><input type="checkbox" x-model="wizard.form.is_active"> <span class="text-[12px]">Active immediately</span></label>

                            <div class="rounded-xl border bg-muted/20 p-3 mt-3">
                                <p class="text-[12.5px] font-semibold" x-text="coach.pre_confirm?.header || 'Last look'"></p>
                                <p class="text-[11.5px] text-muted-foreground mt-1" x-text="coach.pre_confirm?.note"></p>
                                <div class="space-y-1 text-[12px] mt-2">
                                    <div class="flex justify-between border-b py-1.5"><span class="text-muted-foreground">User</span><span class="font-semibold ml-2 truncate" x-text="selectedUserLabel || '—'"></span></div>
                                    <div class="flex justify-between border-b py-1.5"><span class="text-muted-foreground">Country</span><span x-text="wizard.form.country_code || '—'"></span></div>
                                    <div class="flex justify-between border-b py-1.5"><span class="text-muted-foreground">Province</span><span x-text="wizard.form.province_code || '—'"></span></div>
                                    <div class="flex justify-between border-b py-1.5"><span class="text-muted-foreground">District</span><span x-text="wizard.form.district_code || '—'"></span></div>
                                    <div class="flex justify-between border-b py-1.5"><span class="text-muted-foreground">PoE</span><span x-text="wizard.form.poe_code || '—'"></span></div>
                                    <div class="flex justify-between border-b py-1.5"><span class="text-muted-foreground">Primary</span><span x-text="wizard.form.is_primary?'Yes':'No'"></span></div>
                                    <div class="flex justify-between py-1.5"><span class="text-muted-foreground">Period</span><span><span x-text="wizard.form.starts_at_date||'today'"></span> → <span x-text="wizard.form.ends_at_date||'open'"></span></span></div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <footer class="flex items-center gap-2 px-4 sm:px-6 py-3 border-t shrink-0">
                    <button class="btn btn-outline btn-sm" @click="prevStep()" :disabled="wizard.step===1">Back</button>
                    <div class="flex-1"></div>
                    <button class="btn btn-ghost btn-sm" @click="askExitWizard()">Cancel</button>
                    <button class="btn btn-brand btn-sm" @click="nextStep()" :disabled="!stepValid(wizard.step)" x-show="wizard.step<3">Next</button>
                    <button class="btn btn-brand btn-sm" @click="save()" :disabled="wizard.submitting" x-show="wizard.step===3">
                        <span x-show="!wizard.submitting" x-text="wizard.mode==='edit'?'Save changes':'Place'"></span>
                        <span x-show="wizard.submitting">Saving…</span>
                    </button>
                </footer>
            </div>
        </div>
    </template>

    {{-- ─── Smart-action sheet ─────────────────────────────────────────── --}}
    <template x-if="actionSheet.open">
        <div class="fixed inset-0 z-[55] flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true" @keydown.escape.window="actionSheet.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="actionSheet.open=false"></div>
            <div class="relative w-full sm:max-w-md bg-card border-t sm:border sm:rounded-2xl shadow-elevation-5 max-h-[80vh] flex flex-col" @click.stop>
                <div class="sm:hidden h-1.5 w-10 rounded-full bg-muted mx-auto mt-2 shrink-0"></div>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Manage placement</p>
                        <h2 class="text-[14px] font-bold truncate" x-text="actionSheet.row?.full_name"></h2>
                        <p class="text-[10.5px] text-muted-foreground truncate" x-text="placeLabel(actionSheet.row)"></p>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="compareSheet.open=true; actionSheet.open=false" title="Compare every action">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                    </button>
                    <button class="btn btn-ghost btn-icon-xs" @click="actionSheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto p-3">
                    <div class="grid grid-cols-1 gap-1.5">
                        <template x-for="opt in availableActions" :key="opt.id">
                            <button class="action-tile" @click="dispatchAction(opt.id, actionSheet.row)">
                                <span class="action-tile-icon" :class="opt.tone||''">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" x-html="iconPath(opt.icon)"></svg>
                                </span>
                                <span class="action-tile-body">
                                    <span class="action-tile-title" :class="opt.tone||''" x-text="opt.label"></span>
                                    <span class="action-tile-sub" x-text="opt.one_liner"></span>
                                </span>
                            </button>
                        </template>
                    </div>
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
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Compare actions</p>
                        <h2 class="text-[14px] font-bold">Side-by-side: which one fits this situation?</h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="compareSheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-[11.5px]">
                        <thead class="sticky top-0 bg-card z-10 text-[10px] uppercase tracking-wider text-muted-foreground">
                            <tr class="border-b">
                                <th class="text-left px-4 py-2 sticky left-0 bg-card">Action</th>
                                <template x-for="col in coach.comparison_columns" :key="col">
                                    <th class="text-left px-3 py-2" x-text="col"></th>
                                </template>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="a in actionsArr" :key="a.id">
                                <tr class="border-b align-top">
                                    <td class="px-4 py-2.5 sticky left-0 bg-card">
                                        <p class="font-semibold" x-text="a.label"></p>
                                        <p class="text-muted-foreground text-[10.5px]" x-text="a.one_liner"></p>
                                    </td>
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

    {{-- ─── Pre-confirm ──────────────────────────────────────────────── --}}
    <template x-if="preConfirm.open">
        <div class="fixed inset-0 z-[62] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="preConfirm.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="preConfirm.open=false"></div>
            <div class="relative w-full max-w-md bg-card border rounded-2xl shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold" x-text="preConfirm.title"></h3>
                <p class="text-[12px] text-muted-foreground mt-1" x-text="preConfirm.subtitle"></p>
                <ul class="mt-3 list-disc pl-5 text-[11.5px] space-y-1">
                    <template x-for="c in (preConfirm.consequences||[])" :key="c"><li x-text="c"></li></template>
                </ul>
                <p class="text-[11px] text-muted-foreground mt-3"><span class="font-semibold text-foreground">Reversible:</span> <span x-text="preConfirm.reversible?'yes — '+preConfirm.undo_hint:'no'"></span></p>
                <div class="flex justify-end gap-2 mt-5">
                    <button class="btn btn-outline btn-sm" @click="preConfirm.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" :class="preConfirm.tone==='critical'?'btn-destructive':''" @click="preConfirm.onConfirm && preConfirm.onConfirm()" :disabled="preConfirm.busy">
                        <span x-show="!preConfirm.busy" x-text="preConfirm.confirmLabel||'Continue'"></span>
                        <span x-show="preConfirm.busy">Working…</span>
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
                    <div class="grid place-items-center h-10 w-10 rounded-full bg-success-soft text-success shrink-0">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-[14px] font-bold" x-text="postAction.title || coach.post_action?.header_success || 'Saved'"></h3>
                        <p class="text-[12px] text-muted-foreground mt-1" x-text="postAction.body"></p>
                    </div>
                </div>
                <ul class="mt-3 list-disc pl-5 text-[11.5px] space-y-1" x-show="(postAction.changed||[]).length">
                    <template x-for="c in (postAction.changed||[])" :key="c"><li x-text="c"></li></template>
                </ul>
                <p class="text-[11px] text-muted-foreground mt-3" x-show="postAction.notified"><span class="font-semibold text-foreground">Who was notified:</span> <span x-text="postAction.notified"></span></p>
                <p class="text-[11px] text-muted-foreground mt-1" x-show="postAction.next" x-text="postAction.next"></p>
                <div class="flex flex-wrap justify-end gap-2 mt-5">
                    <button class="btn btn-outline btn-sm" @click="postAction.open=false">Close</button>
                    <template x-if="postAction.cta">
                        <button class="btn btn-brand btn-sm" @click="postAction.cta.fn(); postAction.open=false" x-text="postAction.cta.label"></button>
                    </template>
                </div>
            </div>
        </div>
    </template>

    <div class="fixed inset-x-0 bottom-6 z-[70] flex justify-center px-3 pointer-events-none" x-show="opToast.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="opToast.kind==='success'?'toast-success':'toast-destructive'">
            <div><p class="toast-title" x-text="opToast.title"></p><p class="toast-description" x-text="opToast.body"></p></div>
        </div>
    </div>
</div>

<style>
    .action-tile { display:flex; gap:.75rem; padding:.75rem; border-radius:.75rem; text-align:left; transition:background .15s, border-color .15s; border:1px solid transparent; width:100%; }
    .action-tile:hover { background:hsl(var(--muted)/.5); border-color:hsl(var(--border)); }
    .action-tile-icon { display:grid; place-items:center; height:2.25rem; width:2.25rem; border-radius:.5rem; background:hsl(var(--muted)); flex-shrink:0; }
    .action-tile-body { display:flex; flex-direction:column; gap:.125rem; min-width:0; flex:1; }
    .action-tile-title { font-size:12.5px; font-weight:600; }
    .action-tile-sub { font-size:10.5px; color:hsl(var(--muted-foreground)); line-height:1.3; }
    @media (prefers-reduced-motion: reduce){ * { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
</style>
@endsection

@push('scripts')
<script>
function wfAssignments(){
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const idemKey=()=>{ if(crypto?.randomUUID) return crypto.randomUUID(); return 'k-'+Date.now()+'-'+Math.random().toString(36).slice(2); };
    const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf(),'Idempotency-Key':idemKey()});
    const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null&&v!==0&&v!=='0').map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');
    const blank=()=>({user_id:0,country_code:'Uganda',province_code:'',pheoc_code:'',district_code:'',poe_code:'',is_primary:false,is_active:true,starts_at_date:new Date().toISOString().slice(0,10),ends_at_date:''});

    let coach = {};
    try{ coach = JSON.parse(document.getElementById('wf-assignments-coach')?.textContent || '{}') || {}; }catch(e){ coach = {}; }
    coach.glossary = coach.glossary || [];
    coach.comparison_columns = coach.comparison_columns || ['When this fits','Heads-up','Reversible','Time'];
    const ACTIONS = coach.actions || {};
    const ACTIONS_ARR = Object.values(ACTIONS);

    const ROLE_LABELS = {
        NATIONAL_ADMIN:'National Admin',
        PHEOC_ADMIN:'PHEOC Admin', PHEOC_OFFICER:'PHEOC Officer',
        DISTRICT_ADMIN:'District Admin', DISTRICT_SUPERVISOR:'District Supervisor',
        POE_ADMIN:'PoE Admin', POE_OFFICER:'PoE Officer', POE_DATA_OFFICER:'PoE Data Officer',
        SCREENER:'Screener', OBSERVER:'Observer', SERVICE:'Service Account',
    };
    const WINDOWS = [
        {key:'24h', label:'Past 24h',  ms: 24*3600*1000},
        {key:'7d',  label:'Past 7d',   ms: 7*24*3600*1000},
        {key:'14d', label:'Past 14d',  ms: 14*24*3600*1000},
        {key:'30d', label:'Past 30d',  ms: 30*24*3600*1000},
        {key:'mo',  label:'This month',ms: null, kind:'month'},
        {key:'yr',  label:'This year', ms: null, kind:'year'},
    ];
    const ICON_MAP = {
        plus:'<path d="M12 5v14m-7-7h14"/>',
        pencil:'<path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
        stop:'<path d="M6 18L18 6M6 6l12 12"/>',
        rewind:'<path d="M9 12l2 2 4-4"/>',
        eye:'<path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>',
    };

    return {
        coach, actionsArr: ACTIONS_ARR,
        meta:{country:'Uganda',provinces:[],districts:[],poes:[],users:[]},
        filters:{status:'active',user_id:0,province_code:'',district_code:'',poe_code:'',q:'',window:'',primary:''},
        rows:[], loading:false, loadError:null, advanced:false, freshness:'', tabCounts:{active:0,ended:0,all:0},
        wizard:{open:false,mode:'create',step:1,form:blank(),submitting:false,editingId:null,_explainerOpen:{}},
        actionSheet:{open:false,row:null},
        compareSheet:{open:false},
        coachSheet:{open:false}, coachIntroOpen:false,
        preConfirm:{open:false,title:'',subtitle:'',consequences:[],reversible:false,undo_hint:'',tone:'',confirmLabel:'',busy:false,onConfirm:null},
        postAction:{open:false,title:'',body:'',changed:[],notified:'',next:'',cta:null},
        opToast:{open:false,kind:'success',title:'',body:'',t:null},
        offline: !navigator.onLine,
        meId: parseInt(document.querySelector('meta[name="user-id"]')?.content || '0', 10) || 0,
        windows: WINDOWS,
        stepThru: false,
        _draftTimer: null,

        get selectedUserLabel(){ const u=this.meta.users.find(u=>u.id===this.wizard.form.user_id); return u?u.full_name:''; },
        get hasActiveFilters(){ const f=this.filters; return !!(f.province_code||f.district_code||f.poe_code||f.q||f.window||f.primary||f.user_id); },
        get activeFilterCount(){ const f=this.filters; return ['province_code','district_code','poe_code','window','primary'].reduce((n,k)=>n+(f[k]?1:0),0); },
        get primaryCount(){ return this.rows.filter(r=>r.is_primary && r.is_active && !r.ends_at).length; },

        get filteredRows(){
            let rs = this.rows;
            const w = WINDOWS.find(x=>x.key===this.filters.window);
            if(w){
                const now = new Date();
                let cutoff;
                if(w.kind==='month') cutoff = new Date(now.getFullYear(), now.getMonth(), 1);
                else if(w.kind==='year') cutoff = new Date(now.getFullYear(), 0, 1);
                else cutoff = new Date(now.getTime() - w.ms);
                rs = rs.filter(r => r.starts_at && new Date(r.starts_at) >= cutoff);
            }
            if(this.filters.primary==='1') rs = rs.filter(r => r.is_primary);
            if(this.filters.primary==='0') rs = rs.filter(r => !r.is_primary);
            return rs;
        },

        get provinceBars(){
            const counts = {};
            this.rows.filter(r=>r.is_active && !r.ends_at && r.province_code).forEach(r=>{ counts[r.province_code] = (counts[r.province_code]||0) + 1; });
            const total = Object.values(counts).reduce((s,n)=>s+n,0) || 1;
            return Object.entries(counts).sort((a,b)=>b[1]-a[1]).map(([province,count])=>({province,count,pct:Math.round(count*100/total)}));
        },

        get currentStepCoach(){
            const wiz = ACTIONS.create_assignment;
            const steps = wiz?.steps || [];
            return steps.find(s => s.n === this.wizard.step) || null;
        },

        get availableActions(){
            const row = this.actionSheet.row || {};
            const out = [];
            const push = (id, tone) => { const a = ACTIONS[id]; if(a) out.push({...a, tone:tone||''}); };
            push('edit_assignment');
            if(row.is_active && !row.ends_at) push('end_assignment','text-critical');
            if(!row.is_active || row.ends_at) push('reopen_assignment','text-success');
            return out;
        },

        roleLabel(k){ return ROLE_LABELS[k] || k || '—'; },
        formatN(n){ if(n===null||n===undefined) return '—'; try{ return Number(n).toLocaleString(); }catch(e){ return String(n); } },
        placeLabel(row){ if(!row) return ''; return [row.country_code,row.province_code,row.district_code,row.poe_code].filter(Boolean).join(' › '); },
        fieldHint(fid, key){
            const fields = (coach.modals?.wizard?.fields)||[];
            return fields.find(f=>f.id===fid)?.[key] || '';
        },
        iconPath(name){ return ICON_MAP[name] || ICON_MAP.plus; },

        async boot(){
            this.readUrl();
            this.stepThru = this.readPref('stepthru', false);
            window.addEventListener('online',  ()=>{ this.offline=false; this.loadData(); });
            window.addEventListener('offline', ()=>{ this.offline=true; });
            const url=new URL(window.location.href);
            const uid=parseInt(url.searchParams.get('user_id')||'0',10);
            if(uid>0) this.filters.user_id=uid;
            await Promise.all([this.loadMeta(), this.loadData()]);
            if(uid>0){ this.openCreate(); this.wizard.form.user_id=uid; }
        },
        readUrl(){
            try{
                const u=new URL(window.location.href);
                ['status','province_code','district_code','poe_code','q','window','primary'].forEach(k=>{
                    const v=u.searchParams.get(k); if(v!==null && v!=='') this.filters[k]=v;
                });
                if(u.searchParams.has('window')||u.searchParams.has('primary')||u.searchParams.has('province_code')||u.searchParams.has('district_code')||u.searchParams.has('poe_code')) this.advanced = true;
            }catch(e){}
        },
        pushUrl(){
            try{
                const u=new URL(window.location.href);
                ['status','province_code','district_code','poe_code','q','window','primary'].forEach(k=>{
                    const v=this.filters[k];
                    if(v!==''&&v!==null&&v!==undefined) u.searchParams.set(k,v); else u.searchParams.delete(k);
                });
                window.history.replaceState(null,'',u.toString());
            }catch(e){}
        },
        resetFilters(){ this.filters={status:this.filters.status||'active',user_id:0,province_code:'',district_code:'',poe_code:'',q:'',window:'',primary:''}; this.pushUrl(); this.loadData(); },

        prefKey(k){ return 'wf:assignments:'+k+':'+this.meId; },
        readPref(k,def){ try{ const v=localStorage.getItem(this.prefKey(k)); return v===null?def:JSON.parse(v); }catch(e){ return def; } },
        persistPref(k,v){ try{ localStorage.setItem(this.prefKey(k), JSON.stringify(v)); }catch(e){} },
        readDraft(slot){ try{ const v=localStorage.getItem(this.prefKey('draft:'+slot)); return v?JSON.parse(v):null; }catch(e){ return null; } },
        writeDraft(slot,obj){ try{ localStorage.setItem(this.prefKey('draft:'+slot), JSON.stringify(obj)); }catch(e){} },
        clearDraft(slot){ try{ localStorage.removeItem(this.prefKey('draft:'+slot)); }catch(e){} },
        startDraftAutosave(slot){ this.stopDraftAutosave(); this._draftTimer = setInterval(()=>{ if(this.wizard.open) this.writeDraft(slot, this.wizard.form); }, 10000); },
        stopDraftAutosave(){ if(this._draftTimer){ clearInterval(this._draftTimer); this._draftTimer=null; } },

        async loadMeta(){
            try{ const r=await fetch('/admin/workforce/assignments/meta',{headers:{'Accept':'application/json'}}); const j=await r.json(); if(j.success) this.meta=j.data; }catch(e){}
        },
        async loadData(){
            this.loading=true; this.loadError=null;
            try{
                const r=await fetch('/admin/workforce/assignments/data?'+qs(this.filters),{headers:{'Accept':'application/json'}});
                const j=await r.json();
                if(j.success){
                    this.rows=j.data.rows;
                    this.tabCounts=j.meta?.tabs||this.tabCounts;
                    this.freshness = 'Fresh as of ' + new Date().toLocaleTimeString();
                    if(window.Alpine && Alpine.store('pageMeta')){
                        Alpine.store('pageMeta').rows=this.tabCounts[this.filters.status]??this.tabCounts.all;
                        Alpine.store('pageMeta').kind='wf-assignments';
                    }
                } else { this.loadError=j.message||'Server error'; this.toast('error','Could not load',this.loadError); }
            } catch(e){ this.loadError=e.message; this.toast('error','Network',e.message); }
            finally{ this.loading=false; }
        },

        autoFromPoe(){ const p=this.meta.poes.find(p=>p.poe_code===this.wizard.form.poe_code); if(p){ this.wizard.form.district_code=p.district; this.wizard.form.province_code=p.province; this.wizard.form.pheoc_code=p.province; } },

        openCreate(){
            const draft = this.readDraft('create');
            this.wizard={open:true,mode:'create',step:1,form: draft || blank(),submitting:false,editingId:null,_explainerOpen:{}};
            if(draft) this.toast('warning','Draft restored','We saved what you started earlier.');
            this.startDraftAutosave('create');
        },
        openEdit(row){
            this.wizard={open:true,mode:'edit',step:1,form:{
                user_id:row.user_id,country_code:row.country_code||'Uganda',province_code:row.province_code||'',pheoc_code:row.pheoc_code||row.province_code||'',district_code:row.district_code||'',poe_code:row.poe_code||'',
                is_primary:!!row.is_primary,is_active:!!row.is_active,
                starts_at_date:row.starts_at?row.starts_at.slice(0,10):'',
                ends_at_date:row.ends_at?row.ends_at.slice(0,10):''
            },submitting:false,editingId:row.id,_explainerOpen:{}};
            this.startDraftAutosave('edit:'+row.id);
        },
        stepValid(s){ const f=this.wizard.form; if(s===1) return f.user_id>0; if(s===2) return f.country_code && (f.province_code || f.district_code || f.poe_code); return true; },
        nextStep(){ if(this.stepValid(this.wizard.step) && this.wizard.step<3) this.wizard.step++; },
        prevStep(){ if(this.wizard.step>1) this.wizard.step--; },

        askExitWizard(){
            if(!this.wizard.open) return;
            const dirty = JSON.stringify(this.wizard.form) !== JSON.stringify(blank());
            if(dirty && !confirm('You have unsaved changes. Leave anyway? Your progress is saved as a draft and will return next time.')) return;
            this.wizard.open=false; this.stopDraftAutosave();
        },

        async save(){
            this.wizard.submitting=true;
            const f=this.wizard.form;
            const payload={user_id:f.user_id,country_code:f.country_code,province_code:f.province_code||null,pheoc_code:f.pheoc_code||f.province_code||null,district_code:f.district_code||null,poe_code:f.poe_code||null,is_primary:f.is_primary?1:0,is_active:f.is_active?1:0,starts_at:f.starts_at_date||null,ends_at:f.ends_at_date||null};
            const url=this.wizard.mode==='edit'?'/admin/workforce/assignments/'+this.wizard.editingId:'/admin/workforce/assignments';
            const method=this.wizard.mode==='edit'?'PATCH':'POST';
            try{
                const r=await fetch(url,{method,headers:headersJson(),body:JSON.stringify(payload)});
                const j=await r.json();
                if(!r.ok||!j.success){ this.toast('error','Could not save',j.message||('HTTP '+r.status)); this.wizard.submitting=false; return; }
                this.clearDraft(this.wizard.mode==='edit'?'edit:'+this.wizard.editingId:'create');
                this.stopDraftAutosave();
                this.wizard.open=false;
                this.postAction = {
                    open:true,
                    title: this.wizard.mode==='edit'?'Saved':'Placed',
                    body: this.wizard.mode==='edit' ? 'The assignment row is updated.' : (this.selectedUserLabel || 'The user') + ' now covers ' + (f.poe_code || f.district_code || f.province_code || f.country_code) + '.',
                    changed: this.wizard.mode==='edit' ? ['Place / period / primary fields'] : ['New row in the assignments table','Audit log records who created the placement', f.is_primary?'Primary scope set on the user':'Adds to existing assignments'],
                    notified: 'You. The user, on their next sign-in. National-admin reviewers in the audit log.',
                    next: coach.post_action?.next_step_hint || '',
                    cta: { label:'Open user profile', fn: ()=>{ window.location.href='{{ url('/admin/workforce/users') }}'; } },
                };
                await this.loadData();
            }catch(e){ this.toast('error','Network',e.message); }
            finally{ this.wizard.submitting=false; }
        },

        openActions(row){ this.actionSheet={open:true,row}; },
        dispatchAction(id, row){
            this.actionSheet.open=false;
            switch(id){
                case 'edit_assignment':   return this.openEdit(row);
                case 'end_assignment':    return this.askEnd(row);
                case 'reopen_assignment': return this.askReopen(row);
            }
        },

        askEnd(row){
            const place = row.poe_code || row.district_code || row.province_code || row.country_code;
            this.preConfirm = {
                open:true,
                title:'End '+row.full_name+' covering '+place+'?',
                subtitle:'They will stop seeing data from that place from now.',
                consequences:['Ends_at set to now; row becomes inactive','User stops reading that jurisdiction immediately','If this was their primary, the system falls back to another active assignment, or none','Audit log records who ended it'],
                reversible:true, undo_hint:'Reopen the assignment from the row actions.', tone:'critical',
                confirmLabel:'End coverage',
                onConfirm: async ()=>{
                    this.preConfirm.busy=true;
                    try{
                        const r=await fetch('/admin/workforce/assignments/'+row.id,{method:'DELETE',headers:headersJson()});
                        const j=await r.json();
                        if(j.success){
                            this.preConfirm.open=false;
                            this.postAction = { open:true, title:'Coverage ended', body:row.full_name+' no longer covers '+place+'.', changed:['Row marked ended','Audit log entry written'], notified:'You. The user, on next sign-in.', next:'Reopen later if needed, or place them somewhere new.', cta:null };
                            await this.loadData();
                        } else this.toast('error','Could not end',j.message);
                    }catch(e){ this.toast('error','Network',e.message); }
                    finally{ this.preConfirm.busy=false; }
                }
            };
        },

        askReopen(row){
            const place = row.poe_code || row.district_code || row.province_code || row.country_code;
            this.preConfirm = {
                open:true,
                title:'Reopen '+row.full_name+' covering '+place+'?',
                subtitle:'They will see that place again from now.',
                consequences:['Ends_at cleared; row becomes active','User reads the jurisdiction again from now','Audit log records the reopen'],
                reversible:true, undo_hint:'End the assignment again.', tone:'',
                confirmLabel:'Reopen',
                onConfirm: async ()=>{
                    this.preConfirm.busy=true;
                    try{
                        const r=await fetch('/admin/workforce/assignments/'+row.id+'/restore',{method:'POST',headers:headersJson()});
                        const j=await r.json();
                        if(j.success){
                            this.preConfirm.open=false;
                            this.postAction = { open:true, title:'Coverage reopened', body:row.full_name+' covers '+place+' again.', changed:['Row reactivated','Audit log entry written'], notified:'You. The user, on next sign-in.', next:'', cta:null };
                            await this.loadData();
                        } else this.toast('error','Could not reopen',j.message);
                    }catch(e){ this.toast('error','Network',e.message); }
                    finally{ this.preConfirm.busy=false; }
                }
            };
        },

        toast(kind,title,body){ this.opToast={open:true,kind,title,body,t:null}; clearTimeout(this.opToast.t); this.opToast.t=setTimeout(()=>{this.opToast.open=false;},3000); },
    };
}
</script>
@endpush
