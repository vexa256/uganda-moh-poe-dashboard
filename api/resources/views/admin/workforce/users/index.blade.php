@extends('admin.layout')

@section('crumb', 'Workforce')
@section('title', 'Users')

@php
    /** @var array $coach Coach Manifest from CoachManifest::forView('users'). */
    $coach = $coach ?? \App\Support\Workforce\CoachManifest::forView('users');
@endphp

@section('content')
{{--
    Workforce · Users — v4 operator surface.

    Mobile-API impact: NONE. We render against three already-public, web-only
    routes (workforce/users/{data,meta,show} + the web-only mutation routes
    in routes/web.php). Mobile (/api/*) controllers and routes are untouched.

    Viewport discipline: the layout owns the page chrome; we lock our
    section to a flex column whose toolbar is sticky and whose table body
    is the designated Y-scroll region. Modals scroll inside themselves.
--}}
<div x-data="wfUsers()" x-init="boot()"
     x-effect="window.adminLock?.set?.('page', wizard?.open || sheet?.open || confirm?.open || tempPw?.open || actionSheet?.open || compareSheet?.open || coachSheet?.open || preConfirm?.open || postAction?.open)"
     class="flex flex-col gap-4 min-h-[calc(100vh-7rem)]">

    {{-- Inject coach manifest once so JS reads it without re-translating --}}
    <script type="application/json" id="wf-users-coach">@json($coach)</script>

    {{-- ─────────────────────────────────────────────────────────────────
         Section 0 · Coach intro strip
         A single-line domain-native lead, expandable to purpose / audience
         / prerequisites. This is "Where am I?" answered inline.
    ───────────────────────────────────────────────────────────────────── --}}
    <section class="rounded-2xl border bg-card shadow-sm">
        <button type="button" class="w-full text-left flex items-start gap-3 px-4 sm:px-5 py-3"
                @click="coachIntroOpen=!coachIntroOpen" :aria-expanded="coachIntroOpen">
            <span class="grid place-items-center h-8 w-8 rounded-lg bg-brand-soft text-brand-ink shrink-0 mt-0.5">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 18v-5m0-3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
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
                <button class="btn btn-outline btn-xs" @click="compareSheet.open=true">
                    <svg class="h-3.5 w-3.5 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                    Compare every action
                </button>
                <button class="btn btn-outline btn-xs" @click="coachSheet.open=true">
                    <svg class="h-3.5 w-3.5 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                    Open glossary
                </button>
            </div>
        </div>
    </section>

    {{-- ─────────────────────────────────────────────────────────────────
         Section 1 · Hero — KPIs + status ring + role bars + risk strip
    ───────────────────────────────────────────────────────────────────── --}}
    <section class="rounded-2xl border bg-gradient-to-br from-card via-card to-muted/30 shadow-sm overflow-hidden">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-0">
            {{-- KPI strip --}}
            <div class="lg:col-span-7 grid grid-cols-2 sm:grid-cols-5 gap-0 divide-x divide-y sm:divide-y-0 border-b lg:border-b-0 lg:border-r">
                <button type="button" class="px-4 py-3.5 text-left hover:bg-muted/30 transition" @click="filters.status='active'; loadData(); pushUrl()">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Active</p>
                    <p class="text-[20px] font-bold tabular-nums" x-text="formatN(tabCounts.active)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">on the roster</p>
                </button>
                <button type="button" class="px-4 py-3.5 text-left hover:bg-muted/30 transition" @click="filters.status='suspended'; loadData(); pushUrl()">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Suspended</p>
                    <p class="text-[20px] font-bold tabular-nums text-critical" x-text="formatN(tabCounts.suspended)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">access pulled</p>
                </button>
                <button type="button" class="px-4 py-3.5 text-left hover:bg-muted/30 transition" @click="filters.status='inactive'; loadData(); pushUrl()">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Inactive</p>
                    <p class="text-[20px] font-bold tabular-nums text-muted-foreground" x-text="formatN(tabCounts.inactive)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">deactivated</p>
                </button>
                <button type="button" class="px-4 py-3.5 text-left hover:bg-muted/30 transition" @click="filters.status='invited'; loadData(); pushUrl()">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Pending</p>
                    <p class="text-[20px] font-bold tabular-nums text-info" x-text="formatN(tabCounts.invited)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">invitation open</p>
                </button>
                <button type="button" class="px-4 py-3.5 text-left hover:bg-muted/30 transition col-span-2 sm:col-span-1" @click="filters.status='all'; loadData(); pushUrl()">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Everyone</p>
                    <p class="text-[20px] font-bold tabular-nums" x-text="formatN(tabCounts.all)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">in your scope</p>
                </button>
            </div>

            <div class="lg:col-span-5 px-4 py-3.5 flex items-center gap-4">
                {{-- Status ring (segments are buttons) --}}
                <div class="relative shrink-0">
                    <svg viewBox="0 0 36 36" class="h-24 w-24" aria-hidden="true">
                        <circle cx="18" cy="18" r="15.91549" fill="none" stroke="currentColor" stroke-width="3" class="text-muted/50"/>
                        <template x-for="seg in ringSegments" :key="seg.k">
                            <circle cx="18" cy="18" r="15.91549" fill="none" stroke-width="3.5"
                                :stroke="seg.color"
                                :stroke-dasharray="`${seg.pct} ${100-seg.pct}`"
                                :stroke-dashoffset="seg.offset"
                                stroke-linecap="butt"
                                transform="rotate(-90 18 18)"/>
                        </template>
                    </svg>
                    <div class="absolute inset-0 grid place-items-center text-center pointer-events-none">
                        <div>
                            <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground leading-none">In scope</p>
                            <p class="text-[18px] font-bold leading-none tabular-nums mt-0.5" x-text="formatN(tabCounts.all)"></p>
                        </div>
                    </div>
                </div>

                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between mb-1.5">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">By role</p>
                        <p class="text-[10px] text-muted-foreground" x-text="roleBars.length ? roleBars.length+' roles' : '—'"></p>
                    </div>
                    <ul class="space-y-1">
                        <template x-if="roleBars.length===0"><li class="text-[11px] italic text-muted-foreground">Not yet recorded</li></template>
                        <template x-for="b in roleBars.slice(0,4)" :key="b.role">
                            <li class="text-[10.5px]">
                                <button type="button" class="w-full text-left group" @click="filters.role_key=b.role; loadData(); pushUrl()" :title="`Show only ${b.label}`">
                                    <div class="flex items-center justify-between gap-2 mb-0.5">
                                        <span class="font-semibold truncate group-hover:text-brand transition" x-text="b.label"></span>
                                        <span class="tabular-nums text-muted-foreground" x-text="b.count"></span>
                                    </div>
                                    <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                                        <div class="h-full bg-brand rounded-full transition-all" :style="`width:${b.pct}%`"></div>
                                    </div>
                                </button>
                            </li>
                        </template>
                    </ul>
                    <p class="text-[9.5px] text-muted-foreground mt-1.5 italic">Tap a slice or bar to filter the list below.</p>
                </div>
            </div>
        </div>

        {{-- Risk strip — clickable into flag filter --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 divide-x border-t bg-muted/20">
            <button type="button" class="px-4 py-2.5 text-[11px] text-left hover:bg-muted/40 transition" @click="filters.flag='locked'; pushUrl()">
                <span class="font-semibold tabular-nums" x-text="formatN(riskCounts.locked)"></span>
                <span class="text-muted-foreground ml-1">locked out</span>
            </button>
            <button type="button" class="px-4 py-2.5 text-[11px] text-left hover:bg-muted/40 transition" @click="filters.flag='no_mfa'; pushUrl()">
                <span class="font-semibold tabular-nums text-warning" x-text="formatN(riskCounts.no_mfa)"></span>
                <span class="text-muted-foreground ml-1">without MFA</span>
            </button>
            <button type="button" class="px-4 py-2.5 text-[11px] text-left hover:bg-muted/40 transition" @click="filters.flag='dormant'; pushUrl()">
                <span class="font-semibold tabular-nums" x-text="formatN(riskCounts.dormant)"></span>
                <span class="text-muted-foreground ml-1">dormant 30d+</span>
            </button>
            <button type="button" class="px-4 py-2.5 text-[11px] text-left hover:bg-muted/40 transition" @click="filters.flag='pw_reset'; pushUrl()">
                <span class="font-semibold tabular-nums" x-text="formatN(riskCounts.pw_reset)"></span>
                <span class="text-muted-foreground ml-1">must reset password</span>
            </button>
        </div>
    </section>

    {{-- ─────────────────────────────────────────────────────────────────
         Section 2 · Toolbar (sticky) + table (internal scroll)
    ───────────────────────────────────────────────────────────────────── --}}
    <section class="card flex-1 flex flex-col min-h-0">
        <div class="card-content !p-0 flex flex-col min-h-0">

            {{-- Sticky toolbar --}}
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b bg-card sticky top-0 z-20">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto">
                        <template x-for="t in [{key:'active',label:'Active'},{key:'invited',label:'Pending'},{key:'suspended',label:'Suspended'},{key:'inactive',label:'Inactive'},{key:'all',label:'Everyone'}]" :key="t.key">
                            <button class="tabs-trigger flex-1 sm:flex-none" :data-state="filters.status===t.key?'active':'inactive'" @click="filters.status=t.key; loadData(); pushUrl()">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="formatN(tabCounts[t.key]||0)"></span>
                            </button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <div class="relative w-full sm:w-72">
                        <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                        <input type="search" class="input pl-8 w-full" placeholder="Search name, email, username, phone…" minlength="2" x-model.debounce.250ms="filters.q" @input="loadData(); pushUrl()">
                    </div>
                    <div class="flex items-center gap-2">
                        <button class="btn btn-outline btn-sm" @click="advanced=!advanced" :data-state="advanced?'active':'inactive'">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L14 13.414V19a1 1 0 01-1.447.894l-4-2A1 1 0 018 17v-3.586L3.293 6.707A1 1 0 013 6V4z"/></svg>
                            <span class="hidden sm:inline">Filters</span>
                            <span x-show="activeFilterCount>0" class="badge badge-brand ml-1 px-1.5 py-0 text-[9.5px]" x-text="activeFilterCount"></span>
                        </button>
                        <button class="btn btn-brand btn-sm" @click="openCreate()">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14m-7-7h14"/></svg>
                            <span>Add user</span>
                        </button>
                    </div>
                </div>

                {{-- Advanced filter panel --}}
                <div x-show="advanced" x-cloak x-collapse class="space-y-2 pt-2 border-t border-dashed">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mr-1">Activity window</p>
                        <template x-for="w in windows" :key="w.key">
                            <button class="px-2.5 py-1 text-[11px] rounded-full border transition" :class="filters.window===w.key?'bg-brand text-white border-brand':'bg-card hover:bg-muted/40'" @click="filters.window=(filters.window===w.key?'':w.key); pushUrl()" x-text="w.label"></button>
                        </template>
                        <button x-show="filters.window" class="text-[11px] text-muted-foreground underline" @click="filters.window=''; pushUrl()">clear</button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mr-1">Refine</p>
                        <select class="select w-auto !h-8 text-xs" x-model="filters.role_key" @change="loadData(); pushUrl()">
                            <option value="">Any role</option>
                            <template x-for="r in meta.roles" :key="r.role_key"><option :value="r.role_key" x-text="r.display_name"></option></template>
                        </select>
                        <select class="select w-auto !h-8 text-xs" x-model="filters.province" @change="loadData(); pushUrl()">
                            <option value="">Any province</option>
                            <template x-for="p in meta.provinces" :key="p"><option :value="p" x-text="p"></option></template>
                        </select>
                        <select class="select w-auto !h-8 text-xs" x-model="filters.district" @change="loadData(); pushUrl()">
                            <option value="">Any district</option>
                            <template x-for="d in meta.districts" :key="d"><option :value="d" x-text="d"></option></template>
                        </select>
                        <select class="select w-auto !h-8 text-xs" x-model="filters.poe" @change="loadData(); pushUrl()">
                            <option value="">Any PoE</option>
                            <template x-for="p in meta.poes" :key="p.poe_code"><option :value="p.poe_code" x-text="p.poe_name"></option></template>
                        </select>
                        <select class="select w-auto !h-8 text-xs" x-model="filters.flag" @change="pushUrl()">
                            <option value="">Any flag</option>
                            <option value="locked">Locked out</option>
                            <option value="no_mfa">Without MFA</option>
                            <option value="dormant">Dormant 30d+</option>
                            <option value="pw_reset">Must reset password</option>
                            <option value="never_logged">Never signed in</option>
                        </select>
                        <button class="text-[11px] text-muted-foreground underline" @click="resetFilters()">reset all</button>
                        <span class="ml-auto text-[10px] text-muted-foreground" x-show="freshness" x-text="freshness"></span>
                    </div>
                </div>
            </div>

            {{-- Offline banner --}}
            <div x-show="offline" x-cloak class="bg-warning-soft text-warning border-b px-4 py-1.5 text-[11px] text-center">
                You are offline. Changes will not save until your connection comes back.
            </div>

            {{-- Table — internal scroll region --}}
            <div class="flex-1 min-h-0 overflow-auto" id="wf-users-table-scroll">
                <table class="table">
                    <thead class="table-head sticky top-0 z-10 bg-card"><tr>
                        <th class="table-head-th">Person</th>
                        <th class="table-head-th hidden md:table-cell">Role</th>
                        <th class="table-head-th hidden lg:table-cell">Coverage</th>
                        <th class="table-head-th hidden md:table-cell">Status</th>
                        <th class="table-head-th hidden lg:table-cell">Last sign-in</th>
                        <th class="table-head-th text-right">Actions</th>
                    </tr></thead>
                    <tbody class="table-body">
                        {{-- Skeletons over spinners --}}
                        <template x-if="loading">
                            <template x-for="i in 8" :key="'sk'+i">
                                <tr class="table-row">
                                    <td class="table-cell"><div class="flex items-center gap-2.5"><div class="h-8 w-8 rounded-full bg-muted/60 animate-pulse"></div><div class="space-y-1.5 flex-1"><div class="h-3 w-32 bg-muted/60 rounded animate-pulse"></div><div class="h-2.5 w-48 bg-muted/40 rounded animate-pulse"></div></div></div></td>
                                    <td class="table-cell hidden md:table-cell"><div class="h-4 w-20 bg-muted/40 rounded animate-pulse"></div></td>
                                    <td class="table-cell hidden lg:table-cell"><div class="h-3 w-32 bg-muted/40 rounded animate-pulse"></div></td>
                                    <td class="table-cell hidden md:table-cell"><div class="h-4 w-16 bg-muted/40 rounded animate-pulse"></div></td>
                                    <td class="table-cell hidden lg:table-cell"><div class="h-3 w-20 bg-muted/40 rounded animate-pulse"></div></td>
                                    <td class="table-cell text-right"><div class="h-6 w-6 bg-muted/40 rounded ml-auto animate-pulse"></div></td>
                                </tr>
                            </template>
                        </template>

                        <template x-if="!loading && filteredRows.length===0 && loadError">
                            <tr><td colspan="6" class="table-cell">
                                <div class="empty-state">
                                    <p class="text-sm font-semibold text-critical">Could not load the roster.</p>
                                    <p class="text-[12px] text-muted-foreground" x-text="loadError"></p>
                                    <button class="btn btn-outline btn-sm" @click="loadData()">Try again</button>
                                </div>
                            </td></tr>
                        </template>

                        <template x-if="!loading && filteredRows.length===0 && !loadError">
                            <tr><td colspan="6" class="table-cell">
                                <div class="empty-state">
                                    <p class="text-sm" x-text="hasActiveFilters?'Nobody matches those filters.':'Nobody on the roster yet.'"></p>
                                    <p class="text-[11.5px] text-muted-foreground italic" x-show="!hasActiveFilters">Add the first person to start.</p>
                                    <template x-if="hasActiveFilters"><button class="btn btn-outline btn-sm" @click="resetFilters()">Clear filters</button></template>
                                    <template x-if="!hasActiveFilters"><button class="btn btn-brand btn-sm" @click="openCreate()">Add the first user</button></template>
                                </div>
                            </td></tr>
                        </template>

                        <template x-for="row in filteredRows" :key="row.id">
                            <tr class="table-row hover:bg-muted/20 cursor-pointer transition"
                                :class="{ 'opacity-60 bg-muted/10': !row.is_active && !row.is_invited, 'bg-critical-soft/10': row.is_suspended }"
                                @click="openSheet(row.id)">
                                <td class="table-cell">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <div class="grid place-items-center h-8 w-8 rounded-full text-[11px] font-bold shrink-0"
                                             :class="row.is_suspended?'bg-critical-soft text-critical':row.is_invited?'bg-info-soft text-info':row.is_active?'bg-brand-soft text-brand-ink':'bg-muted text-muted-foreground'"
                                             x-text="initials(row.full_name)"></div>
                                        <div class="min-w-0">
                                            <div class="flex items-center gap-1.5 flex-wrap">
                                                <span class="text-[12.5px] font-semibold truncate max-w-[14rem]" :class="row.is_suspended?'line-through text-muted-foreground':''" :title="row.full_name" x-text="row.full_name || 'Anonymous'"></span>
                                                <span x-show="row.is_self" class="badge badge-brand text-[9px] px-1 py-0">you</span>
                                            </div>
                                            <div class="text-[10.5px] text-muted-foreground truncate max-w-[18rem]" :title="row.email">
                                                <span x-text="'@'+row.username"></span> · <span x-text="row.email || 'no email on file'"></span>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                                <td class="table-cell hidden md:table-cell">
                                    <span class="badge badge-outline" x-text="roleLabel(row.role_key)"></span>
                                </td>
                                <td class="table-cell hidden lg:table-cell text-[11.5px]">
                                    <template x-if="row.primary_assignment">
                                        <div class="min-w-0">
                                            <span class="font-semibold truncate block max-w-[14rem]" :title="primarySub(row.primary_assignment)" x-text="primaryLabel(row.primary_assignment)"></span>
                                            <span class="text-muted-foreground text-[10.5px] truncate block max-w-[14rem]" x-text="primarySub(row.primary_assignment)"></span>
                                        </div>
                                    </template>
                                    <template x-if="!row.primary_assignment"><span class="italic text-muted-foreground">Not yet assigned</span></template>
                                </td>
                                <td class="table-cell hidden md:table-cell">
                                    <div class="inline-flex flex-wrap gap-1">
                                        <span x-show="row.is_active && !row.is_suspended && !row.is_invited" class="badge badge-success">Active</span>
                                        <span x-show="row.is_suspended" class="badge badge-critical">Suspended</span>
                                        <span x-show="!row.is_active && !row.is_suspended && !row.is_invited" class="badge badge-outline">Inactive</span>
                                        <span x-show="row.is_invited" class="badge badge-info">Pending</span>
                                        <span x-show="row.is_locked" class="badge badge-warning" title="Locked due to failed sign-ins">Locked</span>
                                        <span x-show="row.must_change_password" class="badge badge-warning">PW reset</span>
                                        <span x-show="row.mfa_enabled" class="badge badge-brand" title="Multi-factor enabled">MFA</span>
                                    </div>
                                </td>
                                <td class="table-cell hidden lg:table-cell text-[11px] text-muted-foreground">
                                    <span :title="row.last_login_at ? new Date(row.last_login_at).toLocaleString() : ''" x-text="row.last_login_at ? relativeTime(row.last_login_at) : 'never'"></span>
                                    <template x-if="row.dormant_days !== null && row.dormant_days > 30">
                                        <span class="badge badge-warning ml-1" x-text="'dormant '+row.dormant_days+'d'"></span>
                                    </template>
                                </td>
                                <td class="table-cell text-right" @click.stop>
                                    <button class="btn btn-ghost btn-xs" @click="openActions(row)" title="Actions for this person">
                                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="5" r="1.4"/><circle cx="12" cy="12" r="1.4"/><circle cx="12" cy="19" r="1.4"/></svg>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            {{-- Footer --}}
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

    {{-- ╔═══════════════════════════════════════════════════════════════╗
         MODALS — each scrolls inside itself
       ╚═══════════════════════════════════════════════════════════════╝ --}}

    {{-- ─── Add / Edit wizard ─────────────────────────────────────────── --}}
    <template x-if="wizard.open">
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true" @keydown.escape.window="askExitWizard()">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="askExitWizard()"></div>
            <div class="relative w-full sm:max-w-xl bg-card border-t sm:border sm:rounded-2xl shadow-elevation-5 max-h-[92vh] flex flex-col" @click.stop>
                <div class="sm:hidden h-1.5 w-10 rounded-full bg-muted mx-auto mt-2 shrink-0"></div>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink shrink-0">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground" x-text="wizard.mode==='edit'?'Edit user':'Add user'"></p>
                        <h2 class="text-[14px] font-bold truncate" x-text="wizard.form.full_name || 'New member'"></h2>
                    </div>
                    <label class="hidden sm:flex items-center gap-1.5 text-[10px] text-muted-foreground" title="Slow down and walk through each step">
                        <input type="checkbox" x-model="stepThru" @change="persistPref('stepthru',stepThru)">
                        <span>Walk me through</span>
                    </label>
                    <button class="btn btn-ghost btn-icon-xs" @click="askExitWizard()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>

                {{-- Stepper --}}
                <div class="flex items-center gap-1.5 px-4 sm:px-6 py-3 border-b bg-muted/20">
                    <template x-for="s in wizardSteps" :key="s.n">
                        <div class="flex-1 flex items-center gap-1.5">
                            <div class="grid place-items-center h-6 w-6 rounded-full text-[11px] font-bold shrink-0"
                                 :class="wizard.step===s.n?'bg-brand text-white':wizard.step>s.n?'bg-success-soft text-success':'bg-muted text-muted-foreground'">
                                <span x-show="wizard.step<=s.n" x-text="s.n"></span>
                                <svg x-show="wizard.step>s.n" class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg>
                            </div>
                            <span class="text-[11px] truncate" x-text="s.label"></span>
                            <div class="flex-1 h-px bg-border" x-show="s.n<wizardSteps.length"></div>
                        </div>
                    </template>
                </div>

                {{-- Body --}}
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-4">
                    {{-- Coach explainer for current step --}}
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

                    {{-- Conflict banner (concurrent edit) --}}
                    <div x-show="wizard.conflict" x-cloak class="rounded-xl border border-warning/40 bg-warning-soft/30 p-3 text-[12px]">
                        <p class="font-semibold text-warning">Someone else just changed this person.</p>
                        <p class="text-muted-foreground mt-1">The version you opened is older than what is on the server. Reload to see the latest, or save anyway and override their change.</p>
                        <div class="mt-2 flex gap-2">
                            <button class="btn btn-outline btn-xs" @click="reopenEdit()">Reload</button>
                            <button class="btn btn-warning btn-xs" @click="wizard.conflict=false">Save anyway</button>
                        </div>
                    </div>

                    {{-- Step 1 · Identity --}}
                    <template x-if="wizard.step===1">
                        <div class="space-y-3">
                            <div>
                                <label class="label">Full name <span class="text-critical">*</span></label>
                                <input type="text" class="input mt-1.5" x-model="wizard.form.full_name" :placeholder="fieldHint('full_name','example')" autocomplete="off" maxlength="120">
                                <p class="text-[10px] text-muted-foreground mt-1" x-text="fieldHint('full_name','hint')"></p>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="label">Username <span class="text-critical">*</span></label>
                                    <input type="text" class="input mt-1.5" x-model="wizard.form.username" :placeholder="fieldHint('username','example')" :disabled="wizard.mode==='edit'" autocomplete="off" maxlength="32">
                                    <p class="text-[10px] text-muted-foreground mt-1" x-text="fieldHint('username','hint')"></p>
                                </div>
                                <div>
                                    <label class="label">Email <span class="text-critical">*</span></label>
                                    <input type="email" class="input mt-1.5" x-model="wizard.form.email" :placeholder="fieldHint('email','example')" autocomplete="off">
                                    <p class="text-[10px] text-muted-foreground mt-1" x-text="fieldHint('email','hint')"></p>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="label">Phone</label>
                                    <input type="tel" class="input mt-1.5" x-model="wizard.form.phone" :placeholder="fieldHint('phone','example')" autocomplete="off">
                                    <p class="text-[10px] text-muted-foreground mt-1" x-text="fieldHint('phone','hint')"></p>
                                </div>
                                <div>
                                    <label class="label">Country</label>
                                    <input type="text" class="input mt-1.5" x-model="wizard.form.country_code" placeholder="Uganda">
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Step 2 · Role --}}
                    <template x-if="wizard.step===2">
                        <div class="space-y-3">
                            <div>
                                <label class="label">Role <span class="text-critical">*</span></label>
                                <select class="select mt-1.5" x-model="wizard.form.role_key" @change="autoAccountType()">
                                    <option value="">Select…</option>
                                    <template x-for="r in meta.roles" :key="r.role_key">
                                        <option :value="r.role_key" x-text="r.display_name + ' · ' + r.scope_level"></option>
                                    </template>
                                </select>
                                <p class="text-[10.5px] text-muted-foreground mt-1" x-text="selectedRoleDescription || fieldHint('role_key','hint')"></p>
                            </div>
                            <div>
                                <label class="label">Account type <span class="text-critical">*</span></label>
                                <select class="select mt-1.5" x-model="wizard.form.account_type">
                                    <template x-for="t in meta.account_types" :key="t"><option :value="t" x-text="t"></option></template>
                                </select>
                                <p class="text-[10px] text-muted-foreground mt-1" x-text="fieldHint('account_type','hint')"></p>
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div><label class="label">Locale</label><input type="text" class="input mt-1.5" x-model="wizard.form.locale" placeholder="en_UG"></div>
                                <div><label class="label">Timezone</label><input type="text" class="input mt-1.5" x-model="wizard.form.timezone" placeholder="Africa/Kampala"></div>
                            </div>
                            <label class="flex items-center gap-2 mt-2">
                                <input type="checkbox" x-model="wizard.form.is_active">
                                <span class="text-[12px]">Account active</span>
                            </label>
                        </div>
                    </template>

                    {{-- Step 3 · Onboarding (CREATE ONLY) --}}
                    <template x-if="wizard.step===3 && wizard.mode==='create'">
                        <div class="space-y-3">
                            <div class="grid grid-cols-1 gap-2">
                                <template x-for="path in onboardingPaths" :key="path.key">
                                    <label class="flex items-start gap-3 rounded-xl border bg-card px-4 py-3 cursor-pointer transition hover:bg-muted/30"
                                           :class="wizard.form.invite_mode===path.key?'border-brand bg-brand-soft/30 shadow-sm':''">
                                        <input type="radio" name="invite_mode" :value="path.key" x-model="wizard.form.invite_mode" class="mt-1">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <p class="text-[13px] font-bold" x-text="path.label"></p>
                                                <span x-show="path.key==='credential'" class="badge badge-brand text-[9.5px]">recommended</span>
                                            </div>
                                            <p class="text-[11.5px] text-muted-foreground mt-0.5 leading-relaxed" x-text="path.one_liner"></p>
                                            <p class="text-[10.5px] text-muted-foreground mt-1.5" x-show="path.when_to_use"><span class="font-semibold">When this fits: </span><span x-text="path.when_to_use"></span></p>
                                            <ul class="text-[10.5px] mt-1.5 space-y-0.5 pl-4 list-disc text-muted-foreground" x-show="path.consequences?.length">
                                                <template x-for="c in (path.consequences||[])" :key="c"><li x-text="c"></li></template>
                                            </ul>
                                        </div>
                                    </label>
                                </template>
                            </div>
                        </div>
                    </template>

                    {{-- Final step · Pre-confirm summary (review) --}}
                    <template x-if="wizard.step===reviewStep">
                        <div class="space-y-3">
                            <div class="rounded-xl border bg-muted/20 p-3">
                                <p class="text-[12.5px] font-semibold" x-text="coach.pre_confirm?.header || 'Last look'"></p>
                                <p class="text-[11.5px] text-muted-foreground mt-1" x-text="coach.pre_confirm?.note"></p>
                            </div>
                            <div class="space-y-1 text-[12.5px]">
                                <div class="flex justify-between border-b py-1.5"><span class="text-muted-foreground">Full name</span><span class="font-semibold truncate ml-2" x-text="wizard.form.full_name"></span></div>
                                <div class="flex justify-between border-b py-1.5"><span class="text-muted-foreground">Username</span><span class="font-mono" x-text="wizard.form.username"></span></div>
                                <div class="flex justify-between border-b py-1.5"><span class="text-muted-foreground">Email</span><span class="truncate ml-2" x-text="wizard.form.email"></span></div>
                                <div class="flex justify-between border-b py-1.5" x-show="wizard.form.phone"><span class="text-muted-foreground">Phone</span><span x-text="wizard.form.phone"></span></div>
                                <div class="flex justify-between border-b py-1.5"><span class="text-muted-foreground">Role</span><span class="badge badge-outline" x-text="roleLabel(wizard.form.role_key)"></span></div>
                                <div class="flex justify-between border-b py-1.5"><span class="text-muted-foreground">Account type</span><span class="badge" x-text="wizard.form.account_type"></span></div>
                                <div class="flex justify-between border-b py-1.5"><span class="text-muted-foreground">Active</span><span x-text="wizard.form.is_active?'Yes':'No'"></span></div>
                                <div class="flex justify-between py-1.5" x-show="wizard.mode==='create'">
                                    <span class="text-muted-foreground">Onboarding path</span>
                                    <span class="badge" :class="wizard.form.invite_mode==='email'?'badge-info':'badge-brand'" x-text="wizard.form.invite_mode==='email'?'Invitation link':'Temporary password'"></span>
                                </div>
                            </div>
                            <div class="rounded-xl border bg-card p-3 text-[11px] text-muted-foreground space-y-1">
                                <p><span class="font-semibold text-foreground">Who else will see this:</span> the user on next sign-in, their supervisor on the roster, and national-admin reviewers in the audit log.</p>
                                <p><span class="font-semibold text-foreground">Reversible:</span> yes — suspend or deactivate from the row actions.</p>
                            </div>
                        </div>
                    </template>
                </div>

                <footer class="flex items-center gap-2 px-4 sm:px-6 py-3 border-t shrink-0">
                    <button class="btn btn-outline btn-sm" @click="prevStep()" :disabled="wizard.step===1">Back</button>
                    <div class="flex-1"></div>
                    <button class="btn btn-ghost btn-sm" @click="askExitWizard()">Cancel</button>
                    <button class="btn btn-brand btn-sm" @click="nextStep()" :disabled="!stepValid(wizard.step)" x-show="wizard.step<reviewStep">Next</button>
                    <button class="btn btn-brand btn-sm" @click="save()" :disabled="wizard.submitting" x-show="wizard.step===reviewStep">
                        <span x-show="!wizard.submitting" x-text="wizard.mode==='edit'?'Save changes':(wizard.form.invite_mode==='email'?'Send invitation':'Add user')"></span>
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
                    <div class="grid place-items-center h-9 w-9 rounded-full text-[11px] font-bold shrink-0 bg-brand-soft text-brand-ink" x-text="initials(actionSheet.row?.full_name)"></div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Manage user</p>
                        <h2 class="text-[14px] font-bold truncate" x-text="actionSheet.row?.full_name"></h2>
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
                <div class="sm:hidden h-1.5 w-10 rounded-full bg-muted mx-auto mt-2 shrink-0"></div>
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

    {{-- ─── Coach sheet (full guide + glossary) ───────────────────────── --}}
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

    {{-- ─── Detail sheet (profile) ─────────────────────────────────────── --}}
    <template x-if="sheet.open">
        <div class="fixed inset-0 z-[55] flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="sheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="sheet.open=false"></div>
            <div class="relative w-full sm:max-w-2xl bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="grid place-items-center h-10 w-10 rounded-full text-[12px] font-bold shrink-0 bg-brand-soft text-brand-ink" x-text="initials(sheet.user?.full_name)"></div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">User profile</p>
                        <h2 class="text-[15px] font-bold truncate" x-text="sheet.user?.full_name || 'Anonymous'"></h2>
                        <p class="text-[10.5px] text-muted-foreground truncate" x-show="sheet.user"><span x-text="'@'+sheet.user?.username"></span> · <span x-text="sheet.user?.email"></span></p>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="sheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-5">
                    <template x-if="sheet.loading">
                        <div class="space-y-3">
                            <div class="h-4 w-24 bg-muted/50 rounded animate-pulse"></div>
                            <div class="grid grid-cols-2 gap-3">
                                <template x-for="i in 6" :key="'sk'+i"><div class="space-y-1"><div class="h-3 w-16 bg-muted/40 rounded animate-pulse"></div><div class="h-3 w-32 bg-muted/30 rounded animate-pulse"></div></div></template>
                            </div>
                        </div>
                    </template>
                    <template x-if="!sheet.loading && sheet.user">
                        <div class="space-y-5">
                            <div class="inline-flex flex-wrap gap-1.5">
                                <span x-show="sheet.user.is_active && !sheet.user.is_suspended && !sheet.user.is_invited" class="badge badge-success">Active</span>
                                <span x-show="sheet.user.is_suspended" class="badge badge-critical">Suspended</span>
                                <span x-show="!sheet.user.is_active && !sheet.user.is_suspended && !sheet.user.is_invited" class="badge badge-outline">Inactive</span>
                                <span x-show="sheet.user.is_invited" class="badge badge-info">Pending invite</span>
                                <span x-show="sheet.user.is_locked" class="badge badge-warning">Locked</span>
                                <span x-show="sheet.user.must_change_password" class="badge badge-warning">PW reset on next sign-in</span>
                                <span x-show="sheet.user.mfa_enabled" class="badge badge-brand">MFA</span>
                            </div>
                            <section class="grid grid-cols-2 gap-3 text-[12px]">
                                <div><p class="text-muted-foreground">Role</p><p><span class="badge badge-outline" x-text="roleLabel(sheet.user.role_key)"></span></p></div>
                                <div><p class="text-muted-foreground">Account type</p><p><span class="badge" x-text="sheet.user.account_type"></span></p></div>
                                <div><p class="text-muted-foreground">Phone</p><p x-text="sheet.user.phone || '—'"></p></div>
                                <div><p class="text-muted-foreground">Risk score</p><p class="tabular-nums" x-text="sheet.user.risk_score ?? '—'"></p></div>
                                <div><p class="text-muted-foreground">Last sign-in</p><p :title="sheet.user.last_login_at ? new Date(sheet.user.last_login_at).toLocaleString() : ''" x-text="sheet.user.last_login_at ? relativeTime(sheet.user.last_login_at) : 'never'"></p></div>
                                <div><p class="text-muted-foreground">Last IP</p><p class="font-mono text-[11px]" x-text="sheet.user.last_login_ip || '—'"></p></div>
                            </section>
                            <section>
                                <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Quick actions</h3>
                                <div class="flex flex-wrap gap-2">
                                    <button class="btn btn-outline btn-xs" @click="openEdit(sheet.user.id); sheet.open=false">Edit</button>
                                    <button class="btn btn-outline btn-xs" @click="resetPw(sheet.user)">Reset password</button>
                                    <button class="btn btn-outline btn-xs text-info" @click="resendInvite(sheet.user)" x-text="sheet.user.is_invited?'Resend invite':'Send invite link'"></button>
                                    <button class="btn btn-outline btn-xs text-critical" x-show="sheet.user.is_invited" @click="revokeInvite(sheet.user)">Revoke invite</button>
                                    <button class="btn btn-outline btn-xs" x-show="sheet.user.mfa_enabled" @click="resetMfa(sheet.user)">Reset MFA</button>
                                    <button class="btn btn-outline btn-xs text-success" x-show="sheet.user.is_locked" @click="unlock(sheet.user)">Unlock</button>
                                    <button class="btn btn-outline btn-xs text-warning" x-show="!sheet.user.is_suspended && sheet.user.is_active" @click="askSuspend(sheet.user)">Suspend</button>
                                    <button class="btn btn-outline btn-xs text-success" x-show="sheet.user.is_suspended || !sheet.user.is_active" @click="reactivate(sheet.user)">Reactivate</button>
                                </div>
                                <p class="text-[10.5px] text-muted-foreground mt-1.5" x-show="sheet.freshness" x-text="sheet.freshness"></p>
                            </section>
                            <section>
                                <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Active assignments</h3>
                                <template x-if="(sheet.assignments||[]).filter(x=>x.is_active && !x.ends_at).length===0">
                                    <p class="text-[11.5px] italic text-muted-foreground">Not yet assigned. <a class="text-brand underline" :href="'{{ url('/admin/workforce/assignments') }}?user_id='+sheet.user.id">Assign jurisdiction</a>.</p>
                                </template>
                                <ul class="divide-y rounded-lg border bg-card overflow-hidden">
                                    <template x-for="a in (sheet.assignments||[]).filter(x=>x.is_active && !x.ends_at)" :key="a.id">
                                        <li class="px-3 py-2 flex items-center gap-2 text-[12px]">
                                            <span x-show="a.is_primary" class="badge badge-brand">primary</span>
                                            <span class="font-semibold" x-text="a.poe_code || a.district_code || a.province_code || a.country_code"></span>
                                            <span class="text-muted-foreground text-[11px] truncate" x-text="[a.country_code, a.province_code, a.district_code].filter(Boolean).join(' › ')"></span>
                                        </li>
                                    </template>
                                </ul>
                            </section>
                            <section>
                                <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Recent admin actions <span class="text-muted-foreground/60 normal-case font-normal">(last 25)</span></h3>
                                <ul class="divide-y rounded-lg border bg-card max-h-72 overflow-auto">
                                    <template x-if="(sheet.audit||[]).length===0"><li class="px-3 py-2 text-[11.5px] italic text-muted-foreground">Not yet recorded.</li></template>
                                    <template x-for="a in (sheet.audit||[])" :key="a.id">
                                        <li class="px-3 py-2 text-[11.5px] flex items-center gap-2">
                                            <span class="badge badge-outline font-mono" x-text="auditLabel(a.action)"></span>
                                            <span class="text-muted-foreground" :title="new Date(a.created_at).toLocaleString()" x-text="relativeTime(a.created_at)"></span>
                                            <span class="text-muted-foreground ml-auto font-mono text-[10.5px]" x-text="a.ip || ''"></span>
                                        </li>
                                    </template>
                                </ul>
                            </section>
                            <section>
                                <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Recent sign-in events <span class="text-muted-foreground/60 normal-case font-normal">(last 25)</span></h3>
                                <ul class="divide-y rounded-lg border bg-card max-h-72 overflow-auto">
                                    <template x-if="(sheet.auth_events||[]).length===0"><li class="px-3 py-2 text-[11.5px] italic text-muted-foreground">Not yet recorded.</li></template>
                                    <template x-for="e in (sheet.auth_events||[])" :key="e.id">
                                        <li class="px-3 py-2 text-[11.5px] flex items-center gap-2">
                                            <span class="badge" :class="e.severity==='WARN'?'badge-warning':e.severity==='CRITICAL'?'badge-critical':'badge-outline'" x-text="e.event_type"></span>
                                            <span class="text-muted-foreground" :title="new Date(e.created_at).toLocaleString()" x-text="relativeTime(e.created_at)"></span>
                                            <span class="text-muted-foreground ml-auto font-mono text-[10.5px]" x-text="e.ip || ''"></span>
                                        </li>
                                    </template>
                                </ul>
                            </section>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Suspend confirm (typed confirmation) ──────────────────────── --}}
    <template x-if="confirm.open">
        <div class="fixed inset-0 z-[60] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="confirm.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="confirm.open=false"></div>
            <div class="relative w-full max-w-sm bg-card border rounded-2xl shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold text-critical">Suspending <span x-text="confirm.row?.full_name"></span>'s access</h3>
                <p class="text-[12.5px] text-muted-foreground mt-1.5">
                    They will be signed out everywhere and cannot sign in again until you reactivate them. Their assignments and history stay in place.
                </p>
                <label class="label mt-3 block">Reason <span class="text-critical">*</span></label>
                <textarea class="input mt-1.5" rows="3" x-model="confirm.reason" placeholder="At least 30 characters. Plain words. Recorded permanently."></textarea>
                <p class="text-[10.5px] text-muted-foreground mt-1"><span x-text="confirm.reason.length"></span> / 30 characters minimum.</p>
                <label class="label mt-3 block">Type their full name to confirm</label>
                <input type="text" class="input mt-1.5" :placeholder="confirm.row?.full_name" x-model="confirm.typed">
                <div class="flex justify-end gap-2 mt-5">
                    <button class="btn btn-outline btn-sm" @click="confirm.open=false">Cancel</button>
                    <button class="btn btn-destructive btn-sm" :disabled="!suspendReady" @click="performSuspend()">Suspend access</button>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Pre-confirm (generic destructive) ─────────────────────────── --}}
    <template x-if="preConfirm.open">
        <div class="fixed inset-0 z-[62] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="preConfirm.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="preConfirm.open=false"></div>
            <div class="relative w-full max-w-md bg-card border rounded-2xl shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold" x-text="preConfirm.title"></h3>
                <p class="text-[12px] text-muted-foreground mt-1" x-text="preConfirm.subtitle"></p>
                <ul class="mt-3 list-disc pl-5 text-[11.5px] space-y-1">
                    <template x-for="c in (preConfirm.consequences||[])" :key="c"><li x-text="c"></li></template>
                </ul>
                <p class="text-[11px] text-muted-foreground mt-3"><span class="font-semibold text-foreground">Reversible:</span> <span x-text="preConfirm.reversible?'yes — '+preConfirm.undo_hint:'no — make sure before you go ahead'"></span></p>
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

    {{-- ─── Post-action success ───────────────────────────────────────── --}}
    <template x-if="postAction.open">
        <div class="fixed inset-0 z-[63] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="postAction.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="postAction.open=false"></div>
            <div class="relative w-full max-w-md bg-card border rounded-2xl shadow-elevation-5 p-5" @click.stop>
                <div class="flex items-start gap-3">
                    <div class="grid place-items-center h-10 w-10 rounded-full bg-success-soft text-success shrink-0">
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <h3 class="text-[14px] font-bold" x-text="postAction.title || coach.post_action?.header_success || 'Done'"></h3>
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

    {{-- ─── Credentials reveal ────────────────────────────────────────── --}}
    <template x-if="tempPw.open">
        <div class="fixed inset-0 z-[65] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="tempPw.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="tempPw.open=false"></div>
            <div class="relative w-full max-w-md bg-card border rounded-2xl shadow-elevation-5 p-5" @click.stop>
                <template x-if="tempPw.mode==='credential'">
                    <div>
                        <div class="flex items-center gap-2"><span class="badge badge-brand">Temporary password</span></div>
                        <h3 class="text-[15px] font-bold mt-2">Share these credentials once</h3>
                        <p class="text-[12px] text-muted-foreground mt-1">Use a secure out-of-band channel — phone call, in person, or your secure messenger. Never paste this into email or open chat. The user must change the password on first sign-in.</p>
                        <div class="mt-4 space-y-2">
                            <div>
                                <label class="label">Email</label>
                                <div class="mt-1.5 p-2.5 bg-muted/50 rounded-lg flex items-center gap-2 border">
                                    <code class="text-[12.5px] font-mono flex-1 break-all" x-text="tempPw.user?.email"></code>
                                    <button class="btn btn-outline btn-xs" @click="copy(tempPw.user?.email,'Email copied')">Copy</button>
                                </div>
                            </div>
                            <div>
                                <label class="label">Temporary password</label>
                                <div class="mt-1.5 p-2.5 bg-muted/50 rounded-lg flex items-center gap-2 border">
                                    <code class="text-[14px] font-mono flex-1 break-all tracking-wider" x-text="tempPw.password"></code>
                                    <button class="btn btn-outline btn-xs" @click="copy(tempPw.password,'Password copied')">Copy</button>
                                </div>
                            </div>
                            <div>
                                <label class="label">Sign-in URL</label>
                                <div class="mt-1.5 p-2.5 bg-muted/50 rounded-lg flex items-center gap-2 border">
                                    <code class="text-[12px] font-mono flex-1 break-all">{{ url('/login') }}</code>
                                    <button class="btn btn-outline btn-xs" @click="copy('{{ url('/login') }}','URL copied')">Copy</button>
                                </div>
                            </div>
                            <button class="btn btn-outline btn-sm w-full mt-2" @click="copy(tempPw.bundle, 'Bundle copied')">Copy all (email + password + URL)</button>
                        </div>
                    </div>
                </template>
                <template x-if="tempPw.mode==='email'">
                    <div>
                        <div class="flex items-center gap-2"><span class="badge badge-info">Invitation link</span></div>
                        <h3 class="text-[15px] font-bold mt-2">Share this one-time invite</h3>
                        <p class="text-[12px] text-muted-foreground mt-1">
                            Send the link below to <span class="font-semibold text-foreground" x-text="tempPw.user?.full_name"></span>
                            (<span x-text="tempPw.user?.email"></span>). Account stays inactive until they accept. Expires
                            <span x-text="tempPw.expires ? new Date(tempPw.expires).toLocaleString() : ''"></span>.
                        </p>
                        <div class="mt-4 space-y-2">
                            <div>
                                <label class="label">Invitation URL</label>
                                <div class="mt-1.5 p-2.5 bg-muted/50 rounded-lg flex items-center gap-2 border">
                                    <code class="text-[12px] font-mono flex-1 break-all" x-text="tempPw.url"></code>
                                    <button class="btn btn-outline btn-xs" @click="copy(tempPw.url,'Invite link copied')">Copy</button>
                                </div>
                            </div>
                            <div>
                                <label class="label">Suggested message</label>
                                <textarea class="input mt-1.5 font-mono text-[11.5px]" rows="5" readonly x-text="tempPw.message"></textarea>
                                <button class="btn btn-outline btn-xs mt-2" @click="copy(tempPw.message,'Message copied')">Copy message</button>
                            </div>
                        </div>
                    </div>
                </template>
                <p class="text-[11px] text-warning mt-3">This is the only time these details will be shown. Save them now.</p>
                <div class="flex justify-end mt-5"><button class="btn btn-brand btn-sm" @click="tempPw.open=false">Done</button></div>
            </div>
        </div>
    </template>

    {{-- ─── Toast ──────────────────────────────────────────────────────── --}}
    <div class="fixed inset-x-0 bottom-6 z-[70] flex justify-center px-3 pointer-events-none" x-show="opToast.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="opToast.kind==='success'?'toast-success':opToast.kind==='warning'?'toast-warning':'toast-destructive'">
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
function wfUsers(){
    /* ─────────── helpers ─────────── */
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const idemKey=()=>{ if(crypto?.randomUUID) return crypto.randomUUID(); return 'k-'+Date.now()+'-'+Math.random().toString(36).slice(2); };
    const headersJson=(extra={})=>Object.assign({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf(),'Idempotency-Key':idemKey()}, extra);
    const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null&&v!==0&&v!=='0').map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');
    const blank=()=>({full_name:'',username:'',email:'',phone:'',role_key:'',account_type:'POE_OFFICER',country_code:'Uganda',locale:'en_UG',timezone:'Africa/Kampala',is_active:true,invite_mode:'credential'});

    /* ─────────── coach ─────────── */
    let coach = {};
    try{ coach = JSON.parse(document.getElementById('wf-users-coach')?.textContent || '{}') || {}; }catch(e){ coach = {}; }
    const ACTIONS = coach.actions || {};
    coach.glossary = coach.glossary || [];
    coach.comparison_columns = coach.comparison_columns || ['When this fits','Heads-up','Reversible','Time'];
    const ACTIONS_ARR = Object.values(ACTIONS);

    const ROLE_LABELS = {
        NATIONAL_ADMIN:'National Admin',
        PHEOC_ADMIN:'PHEOC Admin', PHEOC_OFFICER:'PHEOC Officer',
        DISTRICT_ADMIN:'District Admin', DISTRICT_SUPERVISOR:'District Supervisor',
        POE_ADMIN:'PoE Admin', POE_OFFICER:'PoE Officer', POE_DATA_OFFICER:'PoE Data Officer',
        SCREENER:'Screener', OBSERVER:'Observer', SERVICE:'Service Account',
    };
    const AUDIT_LABELS = {
        USER_INVITE_CREDENTIAL:'Added with temp password',
        USER_INVITE_EMAIL:'Invitation link sent',
        USER_INVITE_REGENERATE:'Invitation re-issued',
        USER_INVITE_REVOKE:'Invitation revoked',
        USER_UPDATE:'Details updated',
        USER_SUSPEND:'Suspended',
        USER_REACTIVATE:'Reactivated',
        USER_DEACTIVATE:'Deactivated',
        USER_PASSWORD_RESET:'Password reset',
        USER_MFA_RESET:'MFA reset',
        USER_UNLOCK:'Account unlocked',
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
        plus:'<path d="M12 5v14m-7-7h14"/>', pencil:'<path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>',
        eye:'<path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.477 0 8.268 2.943 9.542 7-1.274 4.057-5.065 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>',
        key:'<path d="M15 7a3 3 0 013 3v0a3 3 0 01-3 3 M15 7a3 3 0 00-3 3v6a4 4 0 11-8 0v-1m12-2v-2a3 3 0 00-3-3"/>',
        envelope:'<path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>',
        x:'<path d="M6 18L18 6M6 6l12 12"/>',
        shield:'<path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
        unlock:'<path d="M8 11V7a4 4 0 118 0v4M5 11h14a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2z"/>',
        pause:'<path d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>',
        check:'<path d="M9 12l2 2 4-4"/>',
        pin:'<path d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>',
    };

    return {
        coach, actionsArr: ACTIONS_ARR,
        meta:{roles:[],account_types:[],provinces:[],districts:[],poes:[],country:'Uganda',invite_ttl_days:7},
        filters:{status:'active',role_key:'',province:'',district:'',poe:'',q:'',window:'',flag:''},
        rows:[], loading:false, loadError:null, advanced:false, freshness:'', tabCounts:{active:0,suspended:0,inactive:0,invited:0,all:0},
        wizard:{open:false,mode:'create',step:1,form:blank(),submitting:false,editingId:null,_explainerOpen:{},updated_at:null,conflict:false},
        sheet:{open:false,loading:false,user:null,assignments:[],audit:[],auth_events:[],freshness:''},
        actionSheet:{open:false,row:null},
        compareSheet:{open:false},
        coachSheet:{open:false},
        coachIntroOpen:false,
        confirm:{open:false,row:null,reason:'',typed:''},
        preConfirm:{open:false,title:'',subtitle:'',consequences:[],reversible:false,undo_hint:'',tone:'',confirmLabel:'',busy:false,onConfirm:null},
        postAction:{open:false,title:'',body:'',changed:[],notified:'',next:'',cta:null},
        tempPw:{open:false,mode:'credential',password:'',url:'',expires:null,message:'',user:null,bundle:''},
        opToast:{open:false,kind:'success',title:'',body:'',t:null},
        meId: parseInt(document.querySelector('meta[name="user-id"]')?.content || '0', 10) || 0,
        offline: !navigator.onLine,
        windows: WINDOWS,
        stepThru: false,
        _draftTimer: null,

        get wizardSteps(){ return this.wizard.mode==='edit' ? [{n:1,label:'Identity'},{n:2,label:'Role'},{n:3,label:'Review'}] : [{n:1,label:'Identity'},{n:2,label:'Role'},{n:3,label:'Onboarding'},{n:4,label:'Review'}]; },
        get reviewStep(){ return this.wizard.mode==='edit' ? 3 : 4; },

        get hasActiveFilters(){ const f=this.filters; return !!(f.role_key||f.province||f.district||f.poe||f.q||f.window||f.flag); },
        get activeFilterCount(){ const f=this.filters; return ['role_key','province','district','poe','window','flag'].reduce((n,k)=>n+(f[k]?1:0),0); },

        get filteredRows(){
            let rs = this.rows;
            const w = WINDOWS.find(x=>x.key===this.filters.window);
            if(w){
                const now = new Date();
                let cutoff;
                if(w.kind==='month') cutoff = new Date(now.getFullYear(), now.getMonth(), 1);
                else if(w.kind==='year') cutoff = new Date(now.getFullYear(), 0, 1);
                else cutoff = new Date(now.getTime() - w.ms);
                rs = rs.filter(r => r.last_login_at && new Date(r.last_login_at) >= cutoff);
            }
            switch(this.filters.flag){
                case 'locked':       rs = rs.filter(r => r.is_locked); break;
                case 'no_mfa':       rs = rs.filter(r => !r.mfa_enabled); break;
                case 'dormant':      rs = rs.filter(r => r.dormant_days !== null && r.dormant_days > 30); break;
                case 'pw_reset':     rs = rs.filter(r => r.must_change_password); break;
                case 'never_logged': rs = rs.filter(r => !r.last_login_at); break;
            }
            return rs.map(r => ({...r, is_self: this.meId>0 && r.id===this.meId}));
        },

        get roleBars(){
            const counts={}; this.rows.forEach(r=>{ if(r.role_key) counts[r.role_key]=(counts[r.role_key]||0)+1; });
            const total = Object.values(counts).reduce((s,n)=>s+n,0) || 1;
            return Object.entries(counts).sort((a,b)=>b[1]-a[1]).map(([role,count])=>({role,label:ROLE_LABELS[role]||role,count,pct:Math.round(count*100/total)}));
        },

        get ringSegments(){
            const a=this.tabCounts.active||0, s=this.tabCounts.suspended||0, i=this.tabCounts.inactive||0, p=this.tabCounts.invited||0;
            const total = (this.tabCounts.all||0) || (a+s+i+p) || 1;
            const pct = (n)=>Math.round(n*1000/total)/10;
            const segs=[
                {k:'active',    pct:pct(a), color:'rgb(34 197 94)'},
                {k:'invited',   pct:pct(p), color:'rgb(59 130 246)'},
                {k:'suspended', pct:pct(s), color:'rgb(239 68 68)'},
                {k:'inactive',  pct:pct(i), color:'rgb(148 163 184)'},
            ];
            let off = 0;
            return segs.filter(x=>x.pct>0).map(x=>{ const out={...x,offset:-off}; off += x.pct; return out; });
        },

        get riskCounts(){
            const r = this.rows;
            return {
                locked:   r.filter(x=>x.is_locked).length,
                no_mfa:   r.filter(x=>!x.mfa_enabled).length,
                dormant:  r.filter(x=>x.dormant_days!==null && x.dormant_days>30).length,
                pw_reset: r.filter(x=>x.must_change_password).length,
            };
        },

        get selectedRoleDescription(){ return (this.meta.roles.find(r=>r.role_key===this.wizard.form.role_key)?.description) || ''; },

        get availableActions(){
            const row = this.actionSheet.row || {};
            const out = [];
            const push = (id, tone) => { const a = ACTIONS[id]; if(a) out.push({...a, tone:tone||''}); };
            push('view_profile');
            push('edit_user');
            push('reset_password');
            if(!row.is_suspended) push('send_invite_link');
            if(row.is_invited) push('revoke_invite','text-critical');
            if(row.mfa_enabled) push('reset_mfa');
            if(row.is_locked) push('unlock','text-success');
            if(!row.is_suspended && row.is_active) push('suspend','text-warning');
            if(row.is_suspended || (!row.is_active && !row.is_invited)) push('reactivate','text-success');
            push('assign_jurisdiction');
            return out;
        },

        get currentStepCoach(){
            const wiz = ACTIONS[this.wizard.mode==='edit' ? 'edit_user' : 'add_user'];
            const steps = wiz?.steps || [];
            return steps.find(s => s.n === this.wizard.step) || null;
        },

        get onboardingPaths(){
            const sub = ACTIONS.add_user?.sub_paths || {};
            return [
                {key:'credential', ...sub.credential},
                {key:'email',      ...sub.email},
            ];
        },

        get suspendReady(){
            return this.confirm.reason.trim().length >= 30 &&
                   (this.confirm.row?.full_name || '').trim().toLowerCase() === (this.confirm.typed || '').trim().toLowerCase();
        },

        /* ─────── format helpers ─────── */
        roleLabel(k){ return ROLE_LABELS[k] || k || '—'; },
        auditLabel(a){ return AUDIT_LABELS[a] || a; },
        primaryLabel(a){ return a.poe_code || a.district_code || a.province_code || a.country_code || '—'; },
        primarySub(a){ return [a.country_code, a.province_code, a.district_code].filter(Boolean).join(' › '); },
        initials(name){ if(!name) return '·'; return (''+name).trim().split(/\s+/).slice(0,2).map(p=>p[0]||'').join('').toUpperCase() || '·'; },
        formatN(n){ if(n===null||n===undefined) return '—'; try { return Number(n).toLocaleString(); } catch(e){ return String(n); } },
        relativeTime(iso){
            if(!iso) return 'never'; const d = new Date(iso); if(isNaN(d)) return '—';
            const diff = Date.now() - d.getTime(); const m=60_000, h=3600_000, day=86400_000;
            if(diff < m) return 'just now';
            if(diff < h) return Math.floor(diff/m)+'m ago';
            if(diff < day) return Math.floor(diff/h)+'h ago';
            if(diff < 7*day) return Math.floor(diff/day)+'d ago';
            return d.toLocaleDateString();
        },
        fieldHint(fieldId, key){
            const m = (coach.modals && coach.modals.wizard && coach.modals.wizard.fields) || [];
            const f = m.find(x => x.id===fieldId);
            return f?.[key] || '';
        },
        iconPath(name){ return ICON_MAP[name] || ICON_MAP.plus; },

        /* ─────── boot / lifecycle ─────── */
        async boot(){
            this.readUrl();
            this.stepThru = this.readPref('stepthru', false);
            window.addEventListener('online',  ()=>{ this.offline=false; this.loadData(); });
            window.addEventListener('offline', ()=>{ this.offline=true; });
            window.addEventListener('beforeunload', (e)=>{ if(this.wizard.open && this.wizard.submitting) { e.preventDefault(); e.returnValue=''; } });
            // Intercept browser back inside wizard
            window.addEventListener('popstate', ()=>{ if(this.wizard.open) this.askExitWizard(true); });
            await Promise.all([this.loadMeta(), this.loadData()]);
        },
        readUrl(){
            try{
                const u=new URL(window.location.href);
                ['status','role_key','province','district','poe','q','window','flag'].forEach(k=>{
                    const v=u.searchParams.get(k); if(v!==null && v!=='') this.filters[k]=v;
                });
                if(u.searchParams.has('window')||u.searchParams.has('flag')||u.searchParams.has('role_key')||u.searchParams.has('province')||u.searchParams.has('district')||u.searchParams.has('poe')) this.advanced = true;
            }catch(e){}
        },
        pushUrl(){
            try{
                const u=new URL(window.location.href);
                ['status','role_key','province','district','poe','q','window','flag'].forEach(k=>{
                    const v=this.filters[k];
                    if(v!==''&&v!==null&&v!==undefined) u.searchParams.set(k,v); else u.searchParams.delete(k);
                });
                window.history.replaceState(null,'',u.toString());
            }catch(e){}
        },
        resetFilters(){ this.filters={status:this.filters.status||'active',role_key:'',province:'',district:'',poe:'',q:'',window:'',flag:''}; this.pushUrl(); this.loadData(); },

        /* ─────── localStorage prefs ─────── */
        prefKey(k){ return 'wf:users:'+k+':'+this.meId; },
        readPref(k,def){ try{ const v=localStorage.getItem(this.prefKey(k)); return v===null?def:JSON.parse(v); }catch(e){ return def; } },
        persistPref(k,v){ try{ localStorage.setItem(this.prefKey(k), JSON.stringify(v)); }catch(e){} },
        readDraft(slot){ try{ const v=localStorage.getItem(this.prefKey('draft:'+slot)); return v?JSON.parse(v):null; }catch(e){ return null; } },
        writeDraft(slot,obj){ try{ localStorage.setItem(this.prefKey('draft:'+slot), JSON.stringify(obj)); }catch(e){} },
        clearDraft(slot){ try{ localStorage.removeItem(this.prefKey('draft:'+slot)); }catch(e){} },
        startDraftAutosave(slot){
            this.stopDraftAutosave();
            this._draftTimer = setInterval(()=>{ if(this.wizard.open) this.writeDraft(slot, this.wizard.form); }, 10000);
        },
        stopDraftAutosave(){ if(this._draftTimer){ clearInterval(this._draftTimer); this._draftTimer=null; } },

        /* ─────── data loaders ─────── */
        async loadMeta(){
            try{ const r=await this.fetchWithRetry('/admin/workforce/users/meta'); const j=await r.json(); if(j.success) this.meta=j.data; }
            catch(e){}
        },
        async loadData(){
            this.loading=true; this.loadError=null;
            try{
                const r=await this.fetchWithRetry('/admin/workforce/users/data?'+qs(this.filters));
                const j=await r.json();
                if(j.success){
                    this.rows=j.data.rows;
                    this.tabCounts=j.meta?.tabs||this.tabCounts;
                    this.freshness = 'Fresh as of ' + new Date().toLocaleTimeString();
                    if(window.Alpine && Alpine.store('pageMeta')){
                        Alpine.store('pageMeta').rows=this.tabCounts[this.filters.status]??this.tabCounts.all;
                        Alpine.store('pageMeta').kind='wf-users';
                    }
                } else { this.loadError=j.message||'Server error'; this.toast('error','Could not load',this.loadError); }
            } catch(e){ this.loadError=e.message; this.toast('error','Network',e.message); }
            finally{ this.loading=false; }
        },
        async fetchWithRetry(url, opts={}, attempts=2){
            for(let i=0;i<=attempts;i++){
                try{
                    const r = await fetch(url, Object.assign({headers:{'Accept':'application/json'}}, opts));
                    if(r.ok || r.status<500) return r;
                    if(i===attempts) return r;
                }catch(e){ if(i===attempts) throw e; }
                await new Promise(res=>setTimeout(res, 350*(i+1)));
            }
        },

        /* ─────── role helper ─────── */
        autoAccountType(){
            const map={NATIONAL_ADMIN:'NATIONAL_ADMIN',PHEOC_OFFICER:'PHEOC_ADMIN',PHEOC_ADMIN:'PHEOC_ADMIN',DISTRICT_SUPERVISOR:'DISTRICT_ADMIN',DISTRICT_ADMIN:'DISTRICT_ADMIN',POE_ADMIN:'POE_ADMIN',POE_OFFICER:'POE_OFFICER',POE_DATA_OFFICER:'POE_OFFICER',SCREENER:'POE_OFFICER',OBSERVER:'OBSERVER',SERVICE:'SERVICE'};
            const t=map[this.wizard.form.role_key]; if(t) this.wizard.form.account_type=t;
        },

        /* ─────── wizard ─────── */
        openCreate(){
            const draft = this.readDraft('create');
            this.wizard={open:true,mode:'create',step:1,form: draft || blank(),submitting:false,editingId:null,_explainerOpen:{},updated_at:null,conflict:false};
            if(draft) this.toast('warning','Draft restored','We saved what you started earlier.');
            this.startDraftAutosave('create');
        },
        async openEdit(id){
            this.wizard.open=true; this.wizard.mode='edit'; this.wizard.step=1; this.wizard.editingId=id; this.wizard.form=blank(); this.wizard._explainerOpen={}; this.wizard.conflict=false;
            try{
                const r=await this.fetchWithRetry('/admin/workforce/users/'+id);
                const j=await r.json();
                if(j.success){
                    const u=j.data.user;
                    this.wizard.form={...blank(),
                        full_name:u.full_name, username:u.username, email:u.email, phone:u.phone||'',
                        role_key:u.role_key, account_type:u.account_type,
                        country_code:'Uganda', locale:'en_UG', timezone:'Africa/Kampala',
                        is_active:u.is_active
                    };
                    this.wizard.updated_at = u.updated_at || u.created_at || null;
                    this.startDraftAutosave('edit:'+id);
                }
            }catch(e){ this.toast('error','Could not load',e.message); }
        },
        async reopenEdit(){ if(this.wizard.editingId) await this.openEdit(this.wizard.editingId); },

        stepValid(s){
            const f=this.wizard.form;
            if(s===1) return f.full_name?.trim().length>=2 && f.username?.trim().length>=3 && /\S+@\S+\.\S+/.test(f.email||'');
            if(s===2) return f.role_key && f.account_type;
            if(s===3 && this.wizard.mode==='create') return f.invite_mode==='credential' || f.invite_mode==='email';
            return true;
        },
        nextStep(){ if(this.stepValid(this.wizard.step) && this.wizard.step<this.reviewStep) this.wizard.step++; },
        prevStep(){ if(this.wizard.step>1) this.wizard.step--; },

        askExitWizard(fromPop=false){
            if(!this.wizard.open) return;
            const dirty = JSON.stringify(this.wizard.form) !== JSON.stringify(blank());
            if(dirty && !confirm('You have unsaved changes. Leave anyway? Your progress is saved as a draft and will return next time.')){
                if(fromPop) history.pushState(null,''); // re-trap back
                return;
            }
            this.wizard.open=false; this.stopDraftAutosave();
        },

        async save(){
            this.wizard.submitting=true;
            const url=this.wizard.mode==='edit'?'/admin/workforce/users/'+this.wizard.editingId:'/admin/workforce/users';
            const method=this.wizard.mode==='edit'?'PATCH':'POST';
            try{
                const body = Object.assign({}, this.wizard.form);
                if(this.wizard.mode==='edit' && this.wizard.updated_at) body._client_updated_at = this.wizard.updated_at;
                const r=await fetch(url,{method,headers:headersJson(),body:JSON.stringify(body)});
                const j=await r.json();
                if(r.status===409 && j?.message?.toLowerCase().includes('conflict')){
                    this.wizard.conflict = true; this.wizard.submitting=false; return;
                }
                if(!r.ok||!j.success){ this.toast('error','Could not save',j.message||('HTTP '+r.status)); this.wizard.submitting=false; return; }

                this.clearDraft(this.wizard.mode==='edit'?'edit:'+this.wizard.editingId:'create');
                this.stopDraftAutosave();
                this.wizard.open=false;

                if(j.data?._invite_url || j.data?._temp_password){
                    this.revealCredentials(j.data);
                }
                this.postAction = {
                    open: true,
                    title: this.wizard.mode==='edit' ? 'Saved' : (this.wizard.form.invite_mode==='email' ? 'Invitation issued' : 'User added'),
                    body: this.wizard.mode==='edit' ? 'The roster row is updated.' : (this.wizard.form.invite_mode==='email' ? 'A one-time invitation link has been issued.' : 'The account is live with a temporary password.'),
                    changed: this.wizard.mode==='edit' ? ['Identity, role, or account-type fields'] : ['New roster row created','Audit log records who created the account'],
                    notified: this.wizard.mode==='edit' ? 'Audit log only.' : 'You and the new user, when they sign in.',
                    next: coach.post_action?.next_step_hint || '',
                    cta: this.wizard.mode==='create' ? { label:'Assign jurisdiction', fn: ()=>{ window.location.href='{{ url('/admin/workforce/assignments') }}?user_id='+(j.data?.id||''); } } : null,
                };
                await this.loadData();
            } catch(e){ this.toast('error','Network',e.message); }
            finally{ this.wizard.submitting=false; }
        },

        /* ─────── action dispatcher ─────── */
        dispatchAction(id, row){
            this.actionSheet.open=false;
            switch(id){
                case 'view_profile':       return this.openSheet(row.id);
                case 'edit_user':          return this.openEdit(row.id);
                case 'reset_password':     return this.resetPw(row);
                case 'send_invite_link':   return this.resendInvite(row);
                case 'revoke_invite':      return this.revokeInvite(row);
                case 'reset_mfa':          return this.resetMfa(row);
                case 'unlock':             return this.unlock(row);
                case 'suspend':            return this.askSuspend(row);
                case 'reactivate':         return this.reactivate(row);
                case 'assign_jurisdiction':return window.location.href = '{{ url('/admin/workforce/assignments') }}?user_id='+row.id;
            }
        },

        /* ─────── detail sheet ─────── */
        async openSheet(id){
            this.sheet={open:true,loading:true,user:null,assignments:[],audit:[],auth_events:[],freshness:''};
            try{
                const r=await this.fetchWithRetry('/admin/workforce/users/'+id);
                const j=await r.json();
                if(j.success){ this.sheet.user=j.data.user; this.sheet.assignments=j.data.assignments; this.sheet.audit=j.data.audit; this.sheet.auth_events=j.data.auth_events; this.sheet.freshness='Refreshed '+new Date().toLocaleTimeString(); }
                else this.toast('error','Could not load',j.message);
            }catch(e){ this.toast('error','Network',e.message); }
            finally{ this.sheet.loading=false; }
        },

        openActions(row){ this.actionSheet={open:true,row}; },

        /* ─────── individual writes ─────── */
        askSuspend(row){ this.confirm={open:true,row,reason:'',typed:''}; },
        async performSuspend(){
            if(!this.suspendReady) return;
            const id=this.confirm.row.id;
            try{
                const r=await fetch('/admin/workforce/users/'+id+'/suspend',{method:'POST',headers:headersJson(),body:JSON.stringify({reason:this.confirm.reason})});
                const j=await r.json();
                if(j.success){
                    this.confirm.open=false;
                    this.postAction = {
                        open:true, title:'Suspended', body:this.confirm.row.full_name+' is signed out and cannot sign in until you reactivate.',
                        changed:['Account marked suspended','Reason recorded in the audit log'],
                        notified:'You. The user, on next sign-in attempt. National-admin reviewers in the audit log.',
                        next:'Open the profile to verify the audit entry, or reactivate later when ready.',
                        cta:{ label:'Open profile', fn: ()=>this.openSheet(id) },
                    };
                    await this.loadData();
                    if(this.sheet.open && this.sheet.user?.id===id) this.openSheet(id);
                } else this.toast('error','Could not suspend',j.message);
            }catch(e){ this.toast('error','Network',e.message); }
        },

        reactivate(row){
            this.preConfirm = {
                open:true, title:'Reactivate '+row.full_name+'?',
                subtitle:'They will be able to sign in again.',
                consequences:['Account becomes active','Previous suspension reason stays in the audit log','Old assignments come back unless you have ended them separately'],
                reversible:true, undo_hint:'Suspend again at any time.', tone:'',
                confirmLabel:'Reactivate',
                onConfirm: async ()=>{
                    this.preConfirm.busy=true;
                    try{
                        const r=await fetch('/admin/workforce/users/'+row.id+'/restore',{method:'POST',headers:headersJson()});
                        const j=await r.json();
                        if(j.success){
                            this.preConfirm.open=false;
                            this.postAction = { open:true, title:'Reactivated', body:row.full_name+' can sign in again.', changed:['Account is active','Audit log records the reactivation'], notified:'You. The user, on their next sign-in.', next:'', cta:null };
                            await this.loadData();
                            if(this.sheet.open && this.sheet.user?.id===row.id) this.openSheet(row.id);
                        } else this.toast('error','Could not reactivate',j.message);
                    }catch(e){ this.toast('error','Network',e.message); }
                    finally{ this.preConfirm.busy=false; }
                }
            };
        },

        resetPw(row){
            this.preConfirm = {
                open:true, title:'Reset password for '+row.full_name+'?',
                subtitle:'A new 12-character password will be generated and shown to you once.',
                consequences:['Old password stops working immediately','New temp password shown once on this screen','User forced to change it on next sign-in','Failed-attempt lock cleared'],
                reversible:false, undo_hint:'You cannot recover the old password — reset again if needed.', tone:'',
                confirmLabel:'Reset and reveal',
                onConfirm: async ()=>{
                    this.preConfirm.busy=true;
                    try{
                        const r=await fetch('/admin/workforce/users/'+row.id+'/password-reset',{method:'POST',headers:headersJson()});
                        const j=await r.json();
                        if(j.success){
                            this.preConfirm.open=false;
                            this.revealCredentials({...j.data, full_name:row.full_name, email:row.email, _invite_mode:'credential'});
                            await this.loadData();
                        } else this.toast('error','Could not reset',j.message);
                    }catch(e){ this.toast('error','Network',e.message); }
                    finally{ this.preConfirm.busy=false; }
                }
            };
        },

        resendInvite(row){
            this.preConfirm = {
                open:true, title:(row.is_invited?'Re-issue':'Issue')+' invitation link for '+row.full_name+'?',
                subtitle:'A fresh one-time URL will be generated and shown once. Any previous link stops working.',
                consequences:['New invitation link valid for '+(this.meta.invite_ttl_days||7)+' days','Account stays inactive until the user accepts','Audit log records the new issuance'],
                reversible:true, undo_hint:'Use Revoke invitation to kill the link before it is used.', tone:'',
                confirmLabel:'Issue link',
                onConfirm: async ()=>{
                    this.preConfirm.busy=true;
                    try{
                        const r=await fetch('/admin/workforce/users/'+row.id+'/regenerate-invite',{method:'POST',headers:headersJson()});
                        const j=await r.json();
                        if(j.success){
                            this.preConfirm.open=false;
                            this.revealCredentials({...j.data, full_name:row.full_name, email:row.email, _invite_mode:'email'});
                            await this.loadData();
                            if(this.sheet.open && this.sheet.user?.id===row.id) this.openSheet(row.id);
                        } else this.toast('error','Could not issue',j.message);
                    }catch(e){ this.toast('error','Network',e.message); }
                    finally{ this.preConfirm.busy=false; }
                }
            };
        },

        revokeInvite(row){
            this.preConfirm = {
                open:true, title:'Revoke invitation for '+row.full_name+'?',
                subtitle:'The outstanding link will stop working immediately.',
                consequences:['Outstanding invitation link is invalidated','Account stays in pending state until you issue a new one or deactivate it'],
                reversible:true, undo_hint:'Issue a new link any time.', tone:'critical',
                confirmLabel:'Revoke',
                onConfirm: async ()=>{
                    this.preConfirm.busy=true;
                    try{
                        const r=await fetch('/admin/workforce/users/'+row.id+'/revoke-invite',{method:'POST',headers:headersJson()});
                        const j=await r.json();
                        if(j.success){
                            this.preConfirm.open=false;
                            this.postAction={ open:true, title:'Invitation revoked', body:'The previous link no longer works.', changed:['Invitation token cleared','Audit log entry written'], notified:'You. Anyone using the old link will see expired-link.', next:'Issue a new link if the person is still joining, or deactivate the account.', cta:null };
                            await this.loadData();
                            if(this.sheet.open && this.sheet.user?.id===row.id) this.openSheet(row.id);
                        } else this.toast('error','Could not revoke',j.message);
                    }catch(e){ this.toast('error','Network',e.message); }
                    finally{ this.preConfirm.busy=false; }
                }
            };
        },

        resetMfa(row){
            this.preConfirm = {
                open:true, title:'Reset multi-factor for '+row.full_name+'?',
                subtitle:'They will be asked to enrol a new second factor on their next sign-in.',
                consequences:['Current MFA secret and recovery codes are wiped','User re-enrols on next sign-in','Audit log records the reset'],
                reversible:false, undo_hint:'Once re-enrolled, the previous codes cannot come back.', tone:'',
                confirmLabel:'Reset MFA',
                onConfirm: async ()=>{
                    this.preConfirm.busy=true;
                    try{
                        const r=await fetch('/admin/workforce/users/'+row.id+'/mfa-reset',{method:'POST',headers:headersJson()});
                        const j=await r.json();
                        if(j.success){
                            this.preConfirm.open=false;
                            this.postAction={ open:true, title:'MFA reset', body:row.full_name+' will re-enrol on next sign-in.', changed:['MFA secret cleared'], notified:'You. The user, on next sign-in.', next:'', cta:null };
                            await this.loadData();
                            if(this.sheet.open && this.sheet.user?.id===row.id) this.openSheet(row.id);
                        } else this.toast('error','Could not reset MFA',j.message);
                    }catch(e){ this.toast('error','Network',e.message); }
                    finally{ this.preConfirm.busy=false; }
                }
            };
        },

        async unlock(row){
            try{
                const r=await fetch('/admin/workforce/users/'+row.id+'/unlock',{method:'POST',headers:headersJson()});
                const j=await r.json();
                if(j.success){
                    this.postAction={ open:true, title:'Unlocked', body:row.full_name+' can sign in again with their existing password.', changed:['Failed-attempt counter cleared','Lock removed'], notified:'You.', next:'If they still cannot sign in, reset their password.', cta:null };
                    await this.loadData();
                    if(this.sheet.open && this.sheet.user?.id===row.id) this.openSheet(row.id);
                } else this.toast('error','Could not unlock',j.message);
            }catch(e){ this.toast('error','Network',e.message); }
        },

        /* ─────── credential reveal + utility ─────── */
        async copy(text,label){
            try{ await navigator.clipboard.writeText(text||''); this.toast('success','Copied',label||'On clipboard.'); }
            catch(e){ this.toast('error','Copy failed','Select and copy manually.'); }
        },
        revealCredentials(d){
            const mode = (d?._invite_mode) || (d?._invite_url ? 'email' : 'credential');
            const user = {full_name:d?.full_name, email:d?.email, id:d?.id};
            if(mode==='email'){
                const url = d?._invite_url || '';
                const exp = d?._invite_expires || null;
                const expHuman = exp ? new Date(exp).toLocaleString() : '';
                const message =
                    'Hi '+ (user.full_name||'') +',\n\n'
                    + 'You have been invited to the National PHEOC Command Centre. Activate your account here:\n'
                    + url + '\n\n'
                    + (expHuman ? 'This link expires on '+expHuman+'.\n\n' : '')
                    + '— UNIPH';
                this.tempPw={open:true,mode:'email',password:'',url,expires:exp,message,user,bundle:''};
            } else {
                const pw = d?._temp_password||'';
                const bundle = 'Email: '+(user.email||'')+'\nPassword: '+pw+'\nSign-in: '+window.location.origin+'/login';
                this.tempPw={open:true,mode:'credential',password:pw,url:'',expires:null,message:'',user,bundle};
            }
        },

        toast(kind,title,body){ this.opToast={open:true,kind,title,body,t:null}; clearTimeout(this.opToast.t); this.opToast.t=setTimeout(()=>{this.opToast.open=false;},3500); },
    };
}
</script>
@endpush
