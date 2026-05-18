@extends('admin.layout')

@section('crumb', 'Workforce')
@section('title', 'Roles')

@php
    /** @var array $coach Coach Manifest from CoachManifest::forView('roles'). */
    $coach = $coach ?? \App\Support\Workforce\CoachManifest::forView('roles');
@endphp

@section('content')
{{--
    Workforce · Roles — v4 catalogue surface.

    Mobile-API impact: NONE. Reads /admin/workforce/roles/data and /show,
    one mutation /admin/workforce/roles/{key} PATCH. Schema unchanged,
    controllers unchanged. /api/* not touched.

    Viewport discipline: sticky toolbar; table body is the Y-scroll
    region; matrix scrolls horizontally inside its own frame; detail
    sheet scrolls inside itself.
--}}
<div x-data="wfRoles()" x-init="boot()"
     x-effect="window.adminLock?.set?.('page', sheet?.open || coachSheet?.open || preConfirm?.open || postAction?.open)"
     class="flex flex-col gap-4 min-h-[calc(100vh-7rem)]">

    <script type="application/json" id="wf-roles-coach">@json($coach)</script>

    {{-- ─── Coach intro strip ─────────────────────────────────────────── --}}
    <section class="rounded-2xl border bg-card shadow-sm">
        <button type="button" class="w-full text-left flex items-start gap-3 px-4 sm:px-5 py-3"
                @click="coachIntroOpen=!coachIntroOpen" :aria-expanded="coachIntroOpen">
            <span class="grid place-items-center h-8 w-8 rounded-lg bg-brand-soft text-brand-ink shrink-0 mt-0.5">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 4.804A7.968 7.968 0 005.5 4c-1.255 0-2.443.29-3.5.804v10A7.969 7.969 0 015.5 14c1.669 0 3.218.51 4.5 1.385A7.962 7.962 0 0114.5 14c1.255 0 2.443.29 3.5.804v-10A7.968 7.968 0 0014.5 4c-1.255 0-2.443.29-3.5.804V12"/></svg>
            </span>
            <span class="flex-1 min-w-0">
                <span class="block text-[12.5px] font-semibold leading-tight" x-text="coach.view.title"></span>
                <span class="block text-[11.5px] text-muted-foreground mt-0.5 leading-snug" x-text="coach.view.header_intro"></span>
            </span>
            <span class="shrink-0 flex items-center gap-2">
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
            <button class="btn btn-outline btn-xs mt-1" @click="coachSheet.open=true">
                <svg class="h-3.5 w-3.5 mr-1" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
                Open glossary
            </button>
        </div>
    </section>

    {{-- ─── Hero ──────────────────────────────────────────────────────── --}}
    <section class="rounded-2xl border bg-gradient-to-br from-card via-card to-muted/30 shadow-sm overflow-hidden">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-0">
            <div class="lg:col-span-7 grid grid-cols-2 sm:grid-cols-4 gap-0 divide-x divide-y sm:divide-y-0 border-b lg:border-b-0 lg:border-r">
                <button type="button" class="px-4 py-3.5 text-left hover:bg-muted/30 transition" @click="filters.status='active'; loadData(); pushUrl()">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Active roles</p>
                    <p class="text-[20px] font-bold tabular-nums" x-text="formatN(tabCounts.active)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">in role registry</p>
                </button>
                <button type="button" class="px-4 py-3.5 text-left hover:bg-muted/30 transition" @click="filters.status='inactive'; loadData(); pushUrl()">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Inactive</p>
                    <p class="text-[20px] font-bold tabular-nums text-muted-foreground" x-text="formatN(tabCounts.inactive)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">turned off</p>
                </button>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Tracked permissions</p>
                    <p class="text-[20px] font-bold tabular-nums" x-text="formatN(capabilities.length)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">in the matrix</p>
                </div>
                <div class="px-4 py-3.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Users assigned</p>
                    <p class="text-[20px] font-bold tabular-nums" x-text="formatN(userSum)"></p>
                    <p class="text-[10.5px] text-muted-foreground mt-0.5">holding any role</p>
                </div>
            </div>
            <div class="lg:col-span-5 px-4 py-3.5">
                <div class="flex items-center justify-between mb-1.5">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">By scope level</p>
                    <p class="text-[10px] text-muted-foreground" x-text="scopeBars.length+' levels'"></p>
                </div>
                <ul class="space-y-1.5">
                    <template x-if="scopeBars.length===0"><li class="text-[11px] italic text-muted-foreground">Not yet recorded</li></template>
                    <template x-for="b in scopeBars" :key="b.scope">
                        <li class="text-[10.5px]">
                            <button type="button" class="w-full text-left group" @click="filters.scope=b.scope; pushUrl()" :title="`Show only ${scopeLabel(b.scope)}`">
                                <div class="flex items-center justify-between gap-2 mb-0.5">
                                    <span class="font-semibold group-hover:text-brand transition" x-text="scopeLabel(b.scope)"></span>
                                    <span class="tabular-nums text-muted-foreground"><span x-text="b.users"></span> users · <span x-text="b.roles"></span> roles</span>
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
    <section class="card flex flex-col min-h-0">
        <div class="card-content !p-0 flex flex-col min-h-0">

            <div class="flex flex-col sm:flex-row sm:items-center gap-3 p-4 sm:p-5 border-b bg-card sticky top-0 z-20">
                <div class="tabs-list w-full sm:w-auto">
                    <template x-for="t in [{key:'active',label:'Active'},{key:'inactive',label:'Inactive'},{key:'all',label:'All'}]" :key="t.key">
                        <button class="tabs-trigger flex-1 sm:flex-none" :data-state="filters.status===t.key?'active':'inactive'" @click="filters.status=t.key; loadData(); pushUrl()">
                            <span x-text="t.label"></span>
                            <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="formatN(tabCounts[t.key]||0)"></span>
                        </button>
                    </template>
                </div>
                <div class="flex-1"></div>
                <select class="select w-auto !h-8 text-xs" x-model="filters.scope" @change="pushUrl()">
                    <option value="">Any scope</option>
                    <option value="NATIONAL">National</option>
                    <option value="PHEOC">Region / PHEOC</option>
                    <option value="DISTRICT">District</option>
                    <option value="POE">Point of Entry</option>
                    <option value="SERVICE">Service</option>
                    <option value="OBSERVER">Observer</option>
                </select>
                <div class="relative w-full sm:w-72">
                    <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
                    <input type="search" class="input pl-8 w-full" placeholder="Search role key, name, description…" minlength="2" x-model.debounce.250ms="filters.q" @input="loadData(); pushUrl()">
                </div>
            </div>

            <div x-show="offline" x-cloak class="bg-warning-soft text-warning border-b px-4 py-1.5 text-[11px] text-center">You are offline. Toggling roles is paused until your connection comes back.</div>

            <div class="flex-1 min-h-0 overflow-auto">
                <table class="table">
                    <thead class="table-head sticky top-0 z-10 bg-card"><tr>
                        <th class="table-head-th">Role</th>
                        <th class="table-head-th hidden md:table-cell">Scope</th>
                        <th class="table-head-th hidden lg:table-cell">Permission density</th>
                        <th class="table-head-th text-right">Users</th>
                        <th class="table-head-th text-right">Status</th>
                    </tr></thead>
                    <tbody class="table-body">
                        <template x-if="loading">
                            <template x-for="i in 6" :key="'sk'+i">
                                <tr class="table-row">
                                    <td class="table-cell"><div class="space-y-1"><div class="h-3 w-32 bg-muted/60 rounded animate-pulse"></div><div class="h-2.5 w-24 bg-muted/40 rounded animate-pulse"></div></div></td>
                                    <td class="table-cell hidden md:table-cell"><div class="h-4 w-20 bg-muted/40 rounded animate-pulse"></div></td>
                                    <td class="table-cell hidden lg:table-cell"><div class="h-1.5 w-24 bg-muted/40 rounded animate-pulse"></div></td>
                                    <td class="table-cell text-right"><div class="h-4 w-16 bg-muted/40 rounded ml-auto animate-pulse"></div></td>
                                    <td class="table-cell text-right"><div class="h-4 w-12 bg-muted/40 rounded ml-auto animate-pulse"></div></td>
                                </tr>
                            </template>
                        </template>
                        <template x-if="!loading && filteredRows.length===0">
                            <tr><td colspan="5" class="table-cell">
                                <div class="empty-state">
                                    <p class="text-sm">No roles match those filters.</p>
                                    <p class="text-[11.5px] italic text-muted-foreground">Try clearing the scope filter or the search.</p>
                                    <button class="btn btn-outline btn-sm" @click="resetFilters()">Clear filters</button>
                                </div>
                            </td></tr>
                        </template>
                        <template x-for="row in filteredRows" :key="row.role_key">
                            <tr class="table-row hover:bg-muted/20 cursor-pointer transition" :class="{ 'opacity-60': !row.is_active }" @click="openSheet(row.role_key)">
                                <td class="table-cell">
                                    <div class="flex flex-col">
                                        <span class="text-[12.5px] font-semibold" x-text="row.display_name"></span>
                                        <span class="text-[10.5px] text-muted-foreground font-mono" x-text="row.role_key"></span>
                                    </div>
                                </td>
                                <td class="table-cell hidden md:table-cell">
                                    <span class="badge badge-outline" x-text="scopeLabel(row.scope_level)"></span>
                                </td>
                                <td class="table-cell hidden lg:table-cell">
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-24 rounded-full bg-muted overflow-hidden shrink-0">
                                            <div class="h-full rounded-full transition-all" :class="capRatio(row)>=0.66?'bg-warning':capRatio(row)>=0.33?'bg-brand':'bg-success'" :style="`width:${Math.round(capRatio(row)*100)}%`"></div>
                                        </div>
                                        <span class="text-[11px] tabular-nums text-muted-foreground"><span x-text="capCount(row)"></span>/<span x-text="capabilities.length"></span></span>
                                    </div>
                                </td>
                                <td class="table-cell text-right text-[12px]">
                                    <div class="inline-flex items-center gap-1.5">
                                        <span class="font-semibold tabular-nums" x-text="formatN(row.users_active)"></span>
                                        <span class="text-muted-foreground tabular-nums">/<span x-text="formatN(row.users_total)"></span></span>
                                        <span x-show="row.users_total > 0" class="badge badge-outline px-1.5 py-0 text-[9.5px]" x-text="Math.round(100*row.users_active/Math.max(1,row.users_total))+'%'"></span>
                                    </div>
                                </td>
                                <td class="table-cell text-right">
                                    <span class="badge" :class="row.is_active?'badge-success':'badge-outline'" x-text="row.is_active?'Active':'Inactive'"></span>
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
                <p class="text-muted-foreground italic">Tap a row for capabilities, users, and the active toggle.</p>
                <p class="text-muted-foreground ml-auto" x-show="freshness" x-text="freshness"></p>
            </div>
        </div>
    </section>

    {{-- ─── Capability matrix ────────────────────────────────────────── --}}
    <section class="card" x-show="filteredRows.length">
        <div class="card-header">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h3 class="card-title">Permission matrix</h3>
                    <p class="card-description">One chart, one question — for any pair of role and permission, is access granted? Green dot means yes, grey means no. The numbers under each role are the active / total user count.</p>
                </div>
                <div class="hidden md:flex items-center gap-3 text-[10.5px] text-muted-foreground shrink-0">
                    <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-success"></span> granted</span>
                    <span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-full bg-muted"></span> not granted</span>
                </div>
            </div>
        </div>
        <div class="card-content !p-0">
            <div class="overflow-x-auto">
                <table class="table">
                    <thead class="table-head"><tr>
                        <th class="table-head-th sticky left-0 bg-card z-10 min-w-[210px]">Permission</th>
                        <template x-for="r in filteredRows" :key="r.role_key">
                            <th class="table-head-th text-center text-[10px] whitespace-nowrap">
                                <button type="button" class="hover:text-brand transition" @click="openSheet(r.role_key)">
                                    <span x-text="r.display_name"></span>
                                    <span class="block text-[9px] font-normal text-muted-foreground" x-text="r.users_active+'/'+r.users_total"></span>
                                </button>
                            </th>
                        </template>
                    </tr></thead>
                    <tbody class="table-body">
                        <template x-for="c in capabilities" :key="c">
                            <tr class="table-row">
                                <td class="table-cell sticky left-0 bg-card z-10">
                                    <span class="text-[11.5px] font-semibold" x-text="capLabel(c)"></span>
                                    <span class="block text-[9.5px] font-mono text-muted-foreground" x-text="c"></span>
                                </td>
                                <template x-for="r in filteredRows" :key="r.role_key+'-'+c">
                                    <td class="table-cell text-center">
                                        <span class="inline-block h-3 w-3 rounded-full" :class="r.capabilities[c]?'bg-success':'bg-muted'" :title="r.capabilities[c]?(r.display_name+' can: '+capLabel(c)):(r.display_name+' cannot: '+capLabel(c))"></span>
                                    </td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

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

    {{-- ─── Detail sheet ─────────────────────────────────────────────── --}}
    <template x-if="sheet.open">
        <div class="fixed inset-0 z-[55] flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="sheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="sheet.open=false"></div>
            <div class="relative w-full sm:max-w-lg bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="grid place-items-center h-10 w-10 rounded-lg bg-brand-soft text-brand-ink shrink-0">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 4.804A7.968 7.968 0 005.5 4c-1.255 0-2.443.29-3.5.804v10A7.969 7.969 0 015.5 14c1.669 0 3.218.51 4.5 1.385A7.962 7.962 0 0114.5 14c1.255 0 2.443.29 3.5.804v-10A7.968 7.968 0 0014.5 4c-1.255 0-2.443.29-3.5.804V12"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Role</p>
                        <h2 class="text-[14px] font-bold truncate" x-text="sheet.role?.display_name"></h2>
                        <p class="text-[10.5px] font-mono text-muted-foreground" x-text="sheet.role?.role_key"></p>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="sheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-5">
                    <template x-if="sheet.loading">
                        <div class="space-y-3">
                            <div class="flex gap-2"><div class="h-5 w-16 bg-muted/40 rounded animate-pulse"></div><div class="h-5 w-24 bg-muted/40 rounded animate-pulse"></div></div>
                            <div class="space-y-2"><template x-for="i in 6" :key="'sk'+i"><div class="h-8 bg-muted/30 rounded animate-pulse"></div></template></div>
                        </div>
                    </template>
                    <template x-if="!sheet.loading && sheet.role">
                        <div class="space-y-5">
                            <div class="flex flex-wrap gap-1.5">
                                <span class="badge" :class="sheet.role.is_active?'badge-success':'badge-outline'" x-text="sheet.role.is_active?'Active':'Inactive'"></span>
                                <span class="badge badge-outline" x-text="scopeLabel(sheet.role.scope_level)"></span>
                                <span class="badge badge-brand" x-text="(sheet.users?.length||0)+' user(s)'"></span>
                                <span class="badge badge-info" x-text="sheetCapCount+'/'+capabilities.length+' permissions'"></span>
                            </div>

                            <section class="text-[12px]">
                                <p class="text-muted-foreground mb-1">Description</p>
                                <p x-text="sheet.role.description || 'Not yet recorded.'" :class="!sheet.role.description?'italic text-muted-foreground':''"></p>
                            </section>

                            <section>
                                <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Permissions granted</h3>
                                <p class="text-[10.5px] text-muted-foreground italic mb-2">Each tile is one specific thing this role can do. Greyed-out tiles are tracked but not granted to this role.</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                                    <template x-for="c in capabilities" :key="c">
                                        <div class="flex items-center gap-2 text-[11.5px] border rounded-lg px-3 py-1.5 transition" :class="sheet.role.capabilities[c]?'bg-success-soft/30 border-success/40':'bg-muted/30 opacity-60'">
                                            <span class="inline-block h-2.5 w-2.5 rounded-full shrink-0" :class="sheet.role.capabilities[c]?'bg-success':'bg-muted'"></span>
                                            <div class="min-w-0">
                                                <p class="font-semibold truncate" x-text="capLabel(c)"></p>
                                                <p class="text-[9.5px] font-mono text-muted-foreground truncate" x-text="c"></p>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </section>

                            <section>
                                <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Users holding this role <span class="font-normal normal-case text-muted-foreground/60">(<span x-text="sheet.users?.length||0"></span>)</span></h3>
                                <ul class="divide-y rounded-lg border bg-card max-h-72 overflow-auto">
                                    <template x-if="(sheet.users||[]).length===0"><li class="px-3 py-2 text-[11.5px] italic text-muted-foreground">Not yet recorded.</li></template>
                                    <template x-for="u in (sheet.users||[])" :key="u.id">
                                        <li class="px-3 py-2 text-[11.5px] flex items-center gap-2">
                                            <div class="grid place-items-center h-6 w-6 rounded-full text-[9.5px] font-bold shrink-0 bg-brand-soft text-brand-ink" x-text="initials(u.full_name)"></div>
                                            <div class="min-w-0 flex-1">
                                                <p class="font-semibold truncate" x-text="u.full_name"></p>
                                                <p class="text-[10.5px] text-muted-foreground truncate" x-text="'@'+u.username"></p>
                                            </div>
                                            <span class="badge text-[9.5px]" :class="u.suspended_at?'badge-critical':u.is_active?'badge-success':'badge-outline'" x-text="u.suspended_at?'suspended':u.is_active?'active':'inactive'"></span>
                                        </li>
                                    </template>
                                </ul>
                                <p class="text-[10.5px] text-muted-foreground mt-1.5">
                                    Manage individual access in
                                    <a class="text-brand underline" :href="'{{ url('/admin/workforce/users') }}?role_key='+encodeURIComponent(sheet.role.role_key)">Users (filtered)</a>.
                                </p>
                            </section>

                            <section class="rounded-xl border p-3 bg-muted/20">
                                <h3 class="text-[12px] font-semibold uppercase tracking-wider text-muted-foreground mb-2">Toggle role</h3>
                                <p class="text-[11.5px] text-muted-foreground mb-2.5">Inactive roles disappear from the role picker on the Add User wizard. People who already hold the role keep it.</p>
                                <button class="btn btn-outline btn-sm" @click="askToggle(!sheet.role.is_active)">
                                    <span x-text="sheet.role.is_active?'Deactivate role':'Activate role'"></span>
                                </button>
                                <p class="text-[10.5px] text-muted-foreground mt-1.5" x-show="sheet.freshness" x-text="sheet.freshness"></p>
                            </section>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- ─── Pre-confirm (toggle) ─────────────────────────────────────── --}}
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
                    <button class="btn btn-brand btn-sm" @click="preConfirm.onConfirm && preConfirm.onConfirm()" :disabled="preConfirm.busy">
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
                        <h3 class="text-[14px] font-bold" x-text="postAction.title"></h3>
                        <p class="text-[12px] text-muted-foreground mt-1" x-text="postAction.body"></p>
                    </div>
                </div>
                <ul class="mt-3 list-disc pl-5 text-[11.5px] space-y-1" x-show="(postAction.changed||[]).length">
                    <template x-for="c in (postAction.changed||[])" :key="c"><li x-text="c"></li></template>
                </ul>
                <p class="text-[11px] text-muted-foreground mt-3" x-show="postAction.next" x-text="postAction.next"></p>
                <div class="flex justify-end mt-5">
                    <button class="btn btn-brand btn-sm" @click="postAction.open=false">Close</button>
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
    @media (prefers-reduced-motion: reduce){ * { animation-duration: .01ms !important; transition-duration: .01ms !important; } }
</style>
@endsection

@push('scripts')
<script>
function wfRoles(){
    const csrf=()=>document.querySelector('meta[name="csrf-token"]')?.content||'';
    const idemKey=()=>{ if(crypto?.randomUUID) return crypto.randomUUID(); return 'k-'+Date.now()+'-'+Math.random().toString(36).slice(2); };
    const headersJson=()=>({'Content-Type':'application/json','Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':csrf(),'Idempotency-Key':idemKey()});
    const qs=(o)=>Object.entries(o).filter(([_,v])=>v!==''&&v!==null).map(([k,v])=>encodeURIComponent(k)+'='+encodeURIComponent(v)).join('&');

    let coach = {};
    try{ coach = JSON.parse(document.getElementById('wf-roles-coach')?.textContent || '{}') || {}; }catch(e){ coach = {}; }
    coach.glossary = coach.glossary || [];
    const ACTIONS_ARR = Object.values(coach.actions || {});

    const SCOPE_LABELS = { NATIONAL:'National', PHEOC:'Region / PHEOC', DISTRICT:'District', POE:'Point of Entry', SERVICE:'Service', OBSERVER:'Observer' };
    const CAP_LABELS = {
        reads_national:'Read national data',
        reads_province:'Read region data',
        reads_district:'Read district data',
        reads_poe:'Read PoE data',
        writes_users:'Manage users',
        writes_geo:'Manage geography',
        writes_alerts:'Create alerts',
        writes_aggregated:'Submit aggregated reports',
        closes_alerts:'Close alerts',
        submits_screenings:'Submit screenings',
        manages_roster:'Manage workforce roster',
        exports_data:'Export data',
    };

    return {
        coach, actionsArr: ACTIONS_ARR,
        filters:{status:'active',q:'',scope:''},
        rows:[], capabilities:[], loading:false, freshness:'', tabCounts:{active:0,inactive:0,all:0},
        sheet:{open:false,loading:false,role:null,users:[],freshness:''},
        coachSheet:{open:false}, coachIntroOpen:false,
        preConfirm:{open:false,title:'',subtitle:'',consequences:[],reversible:false,undo_hint:'',confirmLabel:'',busy:false,onConfirm:null},
        postAction:{open:false,title:'',body:'',changed:[],next:''},
        opToast:{open:false,kind:'success',title:'',body:'',t:null},
        offline: !navigator.onLine,
        meId: parseInt(document.querySelector('meta[name="user-id"]')?.content || '0', 10) || 0,

        get userSum(){ return this.rows.reduce((s,r)=>s+(r.users_total||0),0); },

        get filteredRows(){
            let rs = this.rows;
            if(this.filters.scope) rs = rs.filter(r => r.scope_level === this.filters.scope);
            return rs;
        },

        get scopeBars(){
            const buckets = {};
            this.rows.forEach(r=>{
                const s = r.scope_level || 'OTHER';
                if(!buckets[s]) buckets[s] = {scope:s, roles:0, users:0};
                buckets[s].roles++;
                buckets[s].users += (r.users_total||0);
            });
            const total = Object.values(buckets).reduce((s,b)=>s+b.users,0) || 1;
            return Object.values(buckets).sort((a,b)=>b.users-a.users).map(b=>({...b, pct:Math.round(b.users*100/total)}));
        },

        get sheetCapCount(){ if(!this.sheet.role) return 0; return this.capabilities.filter(c => this.sheet.role.capabilities?.[c]).length; },

        scopeLabel(s){ return SCOPE_LABELS[s] || s || '—'; },
        capLabel(c){ return CAP_LABELS[c] || c; },
        capCount(row){ return this.capabilities.filter(c => row.capabilities?.[c]).length; },
        capRatio(row){ return this.capabilities.length ? this.capCount(row)/this.capabilities.length : 0; },
        initials(name){ if(!name) return '·'; return (''+name).trim().split(/\s+/).slice(0,2).map(p=>p[0]||'').join('').toUpperCase() || '·'; },
        formatN(n){ if(n===null||n===undefined) return '—'; try{ return Number(n).toLocaleString(); }catch(e){ return String(n); } },

        async boot(){
            this.readUrl();
            window.addEventListener('online',  ()=>{ this.offline=false; this.loadData(); });
            window.addEventListener('offline', ()=>{ this.offline=true; });
            await this.loadData();
        },
        readUrl(){
            try{
                const u=new URL(window.location.href);
                ['status','q','scope'].forEach(k=>{ const v=u.searchParams.get(k); if(v!==null && v!=='') this.filters[k]=v; });
            }catch(e){}
        },
        pushUrl(){
            try{
                const u=new URL(window.location.href);
                ['status','q','scope'].forEach(k=>{ const v=this.filters[k]; if(v!==''&&v!==null&&v!==undefined) u.searchParams.set(k,v); else u.searchParams.delete(k); });
                window.history.replaceState(null,'',u.toString());
            }catch(e){}
        },
        resetFilters(){ this.filters={status:this.filters.status||'active',q:'',scope:''}; this.pushUrl(); this.loadData(); },

        async loadData(){
            this.loading=true;
            try{
                const r=await fetch('/admin/workforce/roles/data?'+qs(this.filters),{headers:{'Accept':'application/json'}});
                const j=await r.json();
                if(j.success){
                    this.rows=j.data.rows;
                    this.capabilities=j.data.capabilities;
                    this.tabCounts=j.meta?.tabs||this.tabCounts;
                    this.freshness = 'Fresh as of ' + new Date().toLocaleTimeString();
                    if(window.Alpine && Alpine.store('pageMeta')){
                        Alpine.store('pageMeta').rows=this.rows.length;
                        Alpine.store('pageMeta').kind='wf-roles';
                    }
                }
            }catch(e){ this.toast('error','Network',e.message); }
            finally{ this.loading=false; }
        },
        async openSheet(key){
            this.sheet={open:true,loading:true,role:null,users:[],freshness:''};
            try{
                const r=await fetch('/admin/workforce/roles/'+encodeURIComponent(key),{headers:{'Accept':'application/json'}});
                const j=await r.json();
                if(j.success){ this.sheet.role=j.data.role; this.sheet.users=j.data.users; this.sheet.freshness='Refreshed '+new Date().toLocaleTimeString(); }
                else this.toast('error','Could not load',j.message);
            }catch(e){ this.toast('error','Network',e.message); }
            finally{ this.sheet.loading=false; }
        },

        askToggle(turnOn){
            if(!this.sheet.role) return;
            const role = this.sheet.role;
            const becoming = turnOn ? 'active' : 'inactive';
            this.preConfirm = {
                open:true,
                title: (turnOn ? 'Activate' : 'Deactivate') + ' the ' + role.display_name + ' role?',
                subtitle: turnOn
                    ? 'This role will appear in the role picker on the Add User wizard from now on.'
                    : 'This role will disappear from the role picker on the Add User wizard. People who already hold it keep it.',
                consequences: turnOn
                    ? ['Role becomes selectable for new sign-ups','Audit log records the change']
                    : ['Role is hidden from new sign-ups','Existing holders of this role are unaffected','Audit log records the change'],
                reversible:true, undo_hint:'Toggle it back on at any time.',
                confirmLabel: turnOn ? 'Activate' : 'Deactivate',
                onConfirm: async ()=>{
                    this.preConfirm.busy=true;
                    try{
                        const r=await fetch('/admin/workforce/roles/'+encodeURIComponent(role.role_key),{method:'PATCH',headers:headersJson(),body:JSON.stringify({is_active:turnOn?1:0})});
                        const j=await r.json();
                        if(j.success){
                            this.sheet.role=j.data;
                            this.preConfirm.open=false;
                            this.postAction = {
                                open:true,
                                title: 'Role '+becoming,
                                body: role.display_name + ' is now ' + becoming + '.',
                                changed: [turnOn?'Role appears in the Add User picker':'Role removed from the Add User picker','Existing role holders are unaffected'],
                                next: 'Open Users to see who currently holds this role, or close this and continue.',
                            };
                            await this.loadData();
                        } else this.toast('error','Could not save',j.message);
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
