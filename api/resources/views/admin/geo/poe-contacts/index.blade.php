@extends('admin.layout')

@section('crumb', 'PoE Ops')
@section('title', 'Notification Roster')

@section('content')
<div x-data="poeContacts()" x-init="boot()"
     x-effect="window.adminLock.set('poe-contacts-modal', wizard?.open || confirm?.open || sheet?.open)"
     class="space-y-5">

    {{-- Coverage ribbon — shows live posture of the active roster --}}
    <section class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
        <div class="kpi kpi-glow">
            <p class="kpi-label">Active</p>
            <p class="kpi-value tabular-nums" x-text="tabCounts.active ?? '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">on call</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Inactive</p>
            <p class="kpi-value tabular-nums text-muted-foreground" x-text="tabCounts.inactive ?? '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">deactivated</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Critical fan-out</p>
            <p class="kpi-value tabular-nums text-critical" x-text="coverage.critical ?? '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">receive critical</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">IHR Tier-1</p>
            <p class="kpi-value tabular-nums text-warning" x-text="coverage.tier1 ?? '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">single-case advisories</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Chain</p>
            <p class="kpi-value tabular-nums" x-text="coverage.with_chain ?? '—'"></p>
            <p class="text-[11px] text-muted-foreground mt-1">have escalation</p>
        </div>
        <div class="kpi">
            <p class="kpi-label">Reach</p>
            <p class="kpi-value text-[14px]"><span x-text="coverage.reachable_email ?? 0"></span><span class="text-muted-foreground">·</span><span x-text="coverage.reachable_sms ?? 0"></span></p>
            <p class="text-[11px] text-muted-foreground mt-1">email · sms</p>
        </div>
    </section>

    {{-- Roster --}}
    <section class="card">
        <div class="card-content !p-0">

            {{-- Filter bar --}}
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto">
                        <template x-for="t in [{key:'active',label:'Active'},{key:'inactive',label:'Inactive'},{key:'all',label:'All'}]" :key="t.key">
                            <button class="tabs-trigger flex-1 sm:flex-none" :data-state="filters.status===t.key?'active':'inactive'" @click="filters.status=t.key; loadData()">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="tabCounts[t.key] ?? 0"></span>
                            </button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <input type="search" class="input w-full sm:w-72" placeholder="Search name, email, org, PoE…" x-model.debounce.300ms="filters.q" @input="loadData()">
                    <button class="btn btn-brand btn-sm" @click="openCreate()">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14m-7-7h14"/></svg>
                        Add Contact
                    </button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <select class="select w-auto !h-8 text-xs" x-model="filters.level" @change="loadData()">
                        <option value="">Any level</option>
                        <template x-for="l in meta.levels" :key="l"><option :value="l" x-text="l"></option></template>
                    </select>
                    <select class="select w-auto !h-8 text-xs" x-model="filters.poe_code" @change="onPoeFilterChange()">
                        <option value="">Any PoE</option>
                        <template x-for="p in meta.poes" :key="p.poe_code">
                            <option :value="p.poe_code" x-text="p.poe_name + ' · ' + p.district"></option>
                        </template>
                    </select>
                    <select class="select w-auto !h-8 text-xs" x-model="filters.district_code" @change="loadData()">
                        <option value="">Any district</option>
                        <template x-for="d in meta.districts" :key="d"><option :value="d" x-text="d"></option></template>
                    </select>
                    <select class="select w-auto !h-8 text-xs" x-model="filters.preferred_channel" @change="loadData()">
                        <option value="">Any channel</option>
                        <template x-for="c in meta.channels" :key="c"><option :value="c" x-text="c"></option></template>
                    </select>
                    <button class="btn btn-ghost btn-xs" @click="resetFilters()" x-show="hasActiveFilter()">Clear filters</button>
                </div>
            </div>

            {{-- Table --}}
            <div class="table-wrap !rounded-none !border-0">
                <table class="table">
                    <thead class="table-head">
                        <tr>
                            <th class="table-head-th">Contact</th>
                            <th class="table-head-th hidden md:table-cell">Level</th>
                            <th class="table-head-th hidden md:table-cell">PoE / District</th>
                            <th class="table-head-th hidden lg:table-cell">Receives</th>
                            <th class="table-head-th hidden lg:table-cell">Chain</th>
                            <th class="table-head-th text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="table-body">
                        <template x-if="loading">
                            <tr><td colspan="6" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading…</td></tr>
                        </template>
                        <template x-if="!loading && rows.length===0">
                            <tr><td colspan="6" class="table-cell">
                                <div class="empty-state">
                                    <p class="text-sm">No contacts match the current filters.</p>
                                    <button class="btn btn-brand btn-sm" @click="openCreate()">Add a contact</button>
                                </div>
                            </td></tr>
                        </template>
                        <template x-for="row in rows" :key="row.id">
                            <tr class="table-row" :class="{ 'opacity-60': !row.is_active }">
                                <td class="table-cell">
                                    <button class="flex flex-col text-left" @click="openSheet(row)">
                                        <span class="text-[12.5px] font-semibold flex items-center gap-1.5">
                                            <span x-text="row.full_name"></span>
                                            <span x-show="!row.is_active" class="badge badge-outline text-[9.5px] px-1.5 py-0">inactive</span>
                                        </span>
                                        <span class="text-[10.5px] text-muted-foreground truncate">
                                            <span x-text="row.position || '—'"></span><span class="mx-1">·</span><span x-text="row.organisation || '—'"></span>
                                        </span>
                                        <span class="text-[10.5px] text-muted-foreground truncate">
                                            <span x-text="row.email || '—'"></span><span class="mx-1">·</span><span x-text="row.phone || '—'"></span>
                                        </span>
                                    </button>
                                </td>
                                <td class="table-cell hidden md:table-cell"><span class="badge badge-outline" x-text="row.level"></span></td>
                                <td class="table-cell hidden md:table-cell text-[11.5px]">
                                    <span class="font-medium" x-text="poeName(row.poe_code) || row.poe_code"></span><br>
                                    <span class="text-muted-foreground" x-text="row.district_code || '—'"></span>
                                </td>
                                <td class="table-cell hidden lg:table-cell">
                                    <div class="flex flex-wrap gap-1">
                                        <span x-show="row.receives_critical" class="badge badge-critical">crit</span>
                                        <span x-show="row.receives_high"     class="badge badge-high">high</span>
                                        <span x-show="row.receives_medium"   class="badge badge-medium">med</span>
                                        <span x-show="row.receives_low"      class="badge badge-low">low</span>
                                        <span x-show="row.receives_tier1"    class="badge badge-warning">tier-1</span>
                                        <span x-show="row.receives_tier2"    class="badge badge-info">tier-2</span>
                                        <span x-show="row.receives_breach_alerts" class="badge badge-info">breach</span>
                                        <span x-show="row.receives_followup_reminders" class="badge badge-brand">followup</span>
                                        <span x-show="row.receives_daily_report" class="badge badge-success">daily</span>
                                        <span x-show="row.receives_weekly_report" class="badge badge-soon">weekly</span>
                                    </div>
                                    <div class="text-[10.5px] text-muted-foreground mt-1">
                                        <span x-text="row.preferred_channel"></span>
                                    </div>
                                </td>
                                <td class="table-cell hidden lg:table-cell text-[11px]">
                                    <button x-show="row.has_chain" class="badge badge-outline hover:bg-muted/40" @click="loadChain(row)">
                                        <svg class="h-3 w-3 mr-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 7h7m0 0v7m0-7L10 17"/></svg>
                                        view ladder
                                    </button>
                                    <span x-show="!row.has_chain" class="text-muted-foreground italic">no escalation</span>
                                    <div x-show="row.escalated_in_by && row.escalated_in_by.length" class="text-[10px] text-muted-foreground mt-1">
                                        <span x-text="(row.escalated_in_by||[]).length"></span> upstream
                                    </div>
                                </td>
                                <td class="table-cell text-right">
                                    <div class="inline-flex gap-1 justify-end">
                                        <button class="btn btn-ghost btn-xs" @click="openEdit(row.id)" title="Edit">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </button>
                                        <button x-show="row.is_active" class="btn btn-ghost btn-xs text-critical" @click="confirmDeactivate(row)" title="Deactivate">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                        </button>
                                        <button x-show="!row.is_active" class="btn btn-ghost btn-xs text-success" @click="reactivate(row)" title="Reactivate">
                                            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- ── Detail sheet (right-side panel · escalation ladder + receives matrix) ── --}}
    <template x-if="sheet.open">
        <div class="fixed inset-0 z-40" role="dialog" aria-modal="true" @keydown.escape.window="sheet.open=false">
            <div class="absolute inset-0 bg-black/45 backdrop-blur-sm" @click="sheet.open=false"></div>
            <aside class="absolute inset-y-0 right-0 w-full sm:max-w-md bg-card border-l shadow-elevation-5 flex flex-col" @click.stop>
                <header class="flex items-center gap-3 px-5 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Contact</p>
                        <h2 class="text-[14px] font-bold truncate" x-text="sheet.row?.full_name || '—'"></h2>
                        <p class="text-[10.5px] text-muted-foreground truncate">
                            <span x-text="sheet.row?.position || '—'"></span><span class="mx-1">·</span><span x-text="sheet.row?.organisation || '—'"></span>
                        </p>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="sheet.open=false">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </header>

                <div class="flex-1 overflow-y-auto px-5 py-4 space-y-5">
                    {{-- Identity strip --}}
                    <div class="grid grid-cols-2 gap-3 text-[12px]">
                        <div>
                            <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground">Level</p>
                            <p class="font-semibold"><span class="badge badge-outline" x-text="sheet.row?.level || '—'"></span></p>
                        </div>
                        <div>
                            <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground">Channel</p>
                            <p class="font-semibold" x-text="sheet.row?.preferred_channel || '—'"></p>
                        </div>
                        <div>
                            <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground">PoE</p>
                            <p class="font-semibold" x-text="poeName(sheet.row?.poe_code) || sheet.row?.poe_code || '—'"></p>
                        </div>
                        <div>
                            <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground">District</p>
                            <p class="font-semibold" x-text="sheet.row?.district_code || '—'"></p>
                        </div>
                        <div class="col-span-2">
                            <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground">Reach</p>
                            <p class="font-medium"><span x-text="sheet.row?.email || '—'"></span></p>
                            <p class="text-muted-foreground"><span x-text="sheet.row?.phone || '—'"></span></p>
                            <template x-if="sheet.row?.alternate_email || sheet.row?.alternate_phone">
                                <p class="text-[10.5px] text-muted-foreground mt-1">alt: <span x-text="sheet.row?.alternate_email || ''"></span> <span x-text="sheet.row?.alternate_phone || ''"></span></p>
                            </template>
                        </div>
                        <div class="col-span-2" x-show="sheet.row?.notes">
                            <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground">Notes</p>
                            <p class="text-[12px]" x-text="sheet.row?.notes"></p>
                        </div>
                    </div>

                    {{-- Receives matrix grouped by family --}}
                    <div>
                        <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-2">Subscriptions</p>
                        <div class="space-y-2">
                            <div class="rounded-md border bg-muted/15 px-3 py-2">
                                <p class="text-[11px] font-semibold mb-1">Severity</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <span :class="badgeClass('receives_critical')">critical</span>
                                    <span :class="badgeClass('receives_high')">high</span>
                                    <span :class="badgeClass('receives_medium')">medium</span>
                                    <span :class="badgeClass('receives_low')">low</span>
                                </div>
                            </div>
                            <div class="rounded-md border bg-muted/15 px-3 py-2">
                                <p class="text-[11px] font-semibold mb-1">IHR tier</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <span :class="badgeClass('receives_tier1')">tier-1 single-case</span>
                                    <span :class="badgeClass('receives_tier2')">tier-2 advisory</span>
                                </div>
                            </div>
                            <div class="rounded-md border bg-muted/15 px-3 py-2">
                                <p class="text-[11px] font-semibold mb-1">SLA + follow-up</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <span :class="badgeClass('receives_breach_alerts')">7-1-7 breach</span>
                                    <span :class="badgeClass('receives_followup_reminders')">followup due/overdue</span>
                                </div>
                            </div>
                            <div class="rounded-md border bg-muted/15 px-3 py-2">
                                <p class="text-[11px] font-semibold mb-1">Digests</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <span :class="badgeClass('receives_daily_report')">daily</span>
                                    <span :class="badgeClass('receives_weekly_report')">weekly</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Escalation ladder --}}
                    <div>
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground">Escalation ladder</p>
                            <button class="btn btn-ghost btn-xs" @click="loadChain(sheet.row)" x-show="!sheet.chainLoading">refresh</button>
                            <span class="text-[10.5px] text-muted-foreground" x-show="sheet.chainLoading">loading…</span>
                        </div>
                        <template x-if="!sheet.chain || sheet.chain.length<=1">
                            <p class="text-[12px] text-muted-foreground italic">No escalation target. Alerts terminate at this contact.</p>
                        </template>
                        <template x-if="sheet.chain && sheet.chain.length>1">
                            <ol class="space-y-1.5">
                                <template x-for="(node, idx) in sheet.chain" :key="node.id">
                                    <li class="flex items-start gap-2.5 rounded-md border bg-card px-3 py-2"
                                        :class="idx===0?'border-brand-soft':''">
                                        <div class="grid place-items-center h-6 w-6 rounded-full text-[10.5px] font-bold shrink-0"
                                             :class="idx===0?'bg-brand text-white':'bg-muted text-muted-foreground'">
                                            <span x-text="idx+1"></span>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-[12px] font-semibold flex items-center gap-1.5">
                                                <span x-text="node.full_name"></span>
                                                <span class="badge badge-outline text-[9.5px] px-1.5 py-0" x-text="node.level"></span>
                                            </p>
                                            <p class="text-[10.5px] text-muted-foreground truncate">
                                                <span x-text="node.position || '—'"></span> ·
                                                <span x-text="node.email || node.phone || '—'"></span>
                                            </p>
                                        </div>
                                    </li>
                                </template>
                                <li x-show="sheet.chainMaxed" class="text-[10.5px] text-warning italic px-2">
                                    Chain depth limit reached.
                                </li>
                            </ol>
                        </template>

                        {{-- Inbound edges (who routes INTO this row) --}}
                        <template x-if="sheet.row?.escalated_in_by && sheet.row.escalated_in_by.length">
                            <div class="mt-3">
                                <p class="text-[10.5px] uppercase tracking-wider text-muted-foreground mb-1.5">Receives escalations from</p>
                                <ul class="flex flex-wrap gap-1.5">
                                    <template x-for="src in sheet.row.escalated_in_by" :key="src.id">
                                        <li class="badge badge-outline text-[10.5px]">
                                            <span x-text="src.full_name"></span>
                                            <span class="text-muted-foreground/70 ml-1" x-text="'· ' + src.level"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </template>
                    </div>
                </div>

                <footer class="flex items-center gap-2 px-5 py-3 border-t">
                    <button class="btn btn-outline btn-sm" @click="sheet.open=false">Close</button>
                    <div class="flex-1"></div>
                    <button class="btn btn-ghost btn-sm" x-show="sheet.row?.is_active" @click="confirmDeactivate(sheet.row); sheet.open=false">Deactivate</button>
                    <button class="btn btn-ghost btn-sm text-success" x-show="sheet.row && !sheet.row.is_active" @click="reactivate(sheet.row); sheet.open=false">Reactivate</button>
                    <button class="btn btn-brand btn-sm" @click="openEdit(sheet.row.id); sheet.open=false">Edit</button>
                </footer>
            </aside>
        </div>
    </template>

    {{-- ── Wizard: Identity → Reach → Subscriptions & escalation ── --}}
    <template x-if="wizard.open">
        <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
             role="dialog" aria-modal="true" @keydown.escape.window="wizard.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="wizard.open=false"></div>
            <div class="relative w-full sm:max-w-xl bg-card border-t sm:border sm:rounded-xl shadow-elevation-5 max-h-[92vh] flex flex-col" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14m-7-7h14"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground" x-text="wizard.mode==='edit'?'Edit Contact':'New Contact'"></p>
                        <h2 class="text-[14px] font-bold truncate" x-text="wizard.form.full_name || '—'"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="wizard.open=false">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </header>

                {{-- Step indicator --}}
                <div class="flex items-center gap-1.5 px-4 sm:px-6 py-3 border-b bg-muted/20">
                    <template x-for="s in [1,2,3]" :key="s">
                        <div class="flex-1 flex items-center gap-1.5">
                            <div class="grid place-items-center h-6 w-6 rounded-full text-[11px] font-bold"
                                 :class="wizard.step===s?'bg-brand text-white':wizard.step>s?'bg-success-soft text-success':'bg-muted text-muted-foreground'">
                                <span x-text="s"></span>
                            </div>
                            <span class="text-[11px]" x-text="['Identity','Reach','Subscriptions'][s-1]"></span>
                            <div class="flex-1 h-px bg-border" x-show="s<3"></div>
                        </div>
                    </template>
                </div>

                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-4">
                    {{-- ─── STEP 1 · Identity ─── --}}
                    <template x-if="wizard.step===1">
                        <div class="space-y-3">
                            <div>
                                <label class="label">Full name <span class="text-critical">*</span></label>
                                <input type="text" class="input mt-1.5" x-model="wizard.form.full_name" maxlength="160">
                            </div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="label">Position</label>
                                    <input type="text" class="input mt-1.5" x-model="wizard.form.position" maxlength="120" placeholder="District Health Officer">
                                </div>
                                <div>
                                    <label class="label">Organisation</label>
                                    <input type="text" class="input mt-1.5" x-model="wizard.form.organisation" maxlength="160" placeholder="MoH / UNIPH">
                                </div>
                            </div>
                            <div>
                                <label class="label">Level <span class="text-critical">*</span></label>
                                <select class="select mt-1.5" x-model="wizard.form.level">
                                    <option value="">Select…</option>
                                    <template x-for="l in meta.levels" :key="l"><option :value="l" x-text="l"></option></template>
                                </select>
                                <p class="text-[10.5px] text-muted-foreground mt-1">
                                    POE → DISTRICT → PHEOC → NATIONAL → WHO. The dispatcher routes alerts up this ladder.
                                </p>
                            </div>
                        </div>
                    </template>

                    {{-- ─── STEP 2 · Reach ─── --}}
                    <template x-if="wizard.step===2">
                        <div class="space-y-3">
                            <div>
                                <label class="label">PoE <span class="text-critical">*</span></label>
                                <select class="select mt-1.5" x-model="wizard.form.poe_code" @change="autoDistrict()">
                                    <option value="">Select…</option>
                                    <template x-for="p in meta.poes" :key="p.poe_code">
                                        <option :value="p.poe_code" x-text="p.poe_name + ' · ' + p.district"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="label">District <span class="text-critical">*</span></label>
                                <input type="text" class="input mt-1.5" x-model="wizard.form.district_code" readonly>
                            </div>

                            <div class="rounded-md border bg-amber-50/60 px-3 py-2 text-[11.5px] text-warning-foreground"
                                 x-show="!hasReach()">
                                Provide at least one of email or phone — the dispatcher needs at least one channel to reach this contact.
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="label">Email</label>
                                    <input type="email" class="input mt-1.5" x-model="wizard.form.email" maxlength="160">
                                    <p class="text-[10.5px] text-critical mt-1" x-show="emailInvalid()">Invalid email format.</p>
                                </div>
                                <div>
                                    <label class="label">Phone</label>
                                    <input type="tel" class="input mt-1.5" x-model="wizard.form.phone" maxlength="40" placeholder="+260 …">
                                </div>
                                <div>
                                    <label class="label">Alternate email</label>
                                    <input type="email" class="input mt-1.5" x-model="wizard.form.alternate_email" maxlength="160">
                                </div>
                                <div>
                                    <label class="label">Alternate phone</label>
                                    <input type="tel" class="input mt-1.5" x-model="wizard.form.alternate_phone" maxlength="40">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="label">Preferred channel</label>
                                    <select class="select mt-1.5" x-model="wizard.form.preferred_channel">
                                        <template x-for="c in meta.channels" :key="c"><option :value="c" x-text="c"></option></template>
                                    </select>
                                </div>
                                <div>
                                    <label class="label">Priority order</label>
                                    <input type="number" class="input mt-1.5" min="1" x-model.number="wizard.form.priority_order">
                                    <p class="text-[10.5px] text-muted-foreground mt-1">Lower number = paged sooner within a level.</p>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- ─── STEP 3 · Subscriptions + escalation ─── --}}
                    <template x-if="wizard.step===3">
                        <div class="space-y-4">

                            {{-- Severity --}}
                            <div>
                                <p class="text-[11px] font-semibold mb-1.5">Severity</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <label class="flex items-center gap-2 rounded-md border bg-card px-3 py-2 cursor-pointer hover:bg-muted/30">
                                        <input type="checkbox" x-model="wizard.form.receives_critical">
                                        <span class="badge badge-critical mr-1">crit</span>
                                        <span class="text-[11.5px] flex-1 truncate">Critical alerts</span>
                                    </label>
                                    <label class="flex items-center gap-2 rounded-md border bg-card px-3 py-2 cursor-pointer hover:bg-muted/30">
                                        <input type="checkbox" x-model="wizard.form.receives_high">
                                        <span class="badge badge-high mr-1">high</span>
                                        <span class="text-[11.5px] flex-1 truncate">High-risk alerts</span>
                                    </label>
                                    <label class="flex items-center gap-2 rounded-md border bg-card px-3 py-2 cursor-pointer hover:bg-muted/30">
                                        <input type="checkbox" x-model="wizard.form.receives_medium">
                                        <span class="badge badge-medium mr-1">med</span>
                                        <span class="text-[11.5px] flex-1 truncate">Medium-risk alerts</span>
                                    </label>
                                    <label class="flex items-center gap-2 rounded-md border bg-card px-3 py-2 cursor-pointer hover:bg-muted/30">
                                        <input type="checkbox" x-model="wizard.form.receives_low">
                                        <span class="badge badge-low mr-1">low</span>
                                        <span class="text-[11.5px] flex-1 truncate">Low-risk alerts</span>
                                    </label>
                                </div>
                            </div>

                            {{-- IHR tier --}}
                            <div>
                                <p class="text-[11px] font-semibold mb-1.5">IHR tier advisories</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <label class="flex items-center gap-2 rounded-md border bg-card px-3 py-2 cursor-pointer hover:bg-muted/30">
                                        <input type="checkbox" x-model="wizard.form.receives_tier1">
                                        <span class="badge badge-warning mr-1">tier-1</span>
                                        <span class="text-[11.5px] flex-1 truncate">Single-case · 24h WHO notify</span>
                                    </label>
                                    <label class="flex items-center gap-2 rounded-md border bg-card px-3 py-2 cursor-pointer hover:bg-muted/30">
                                        <input type="checkbox" x-model="wizard.form.receives_tier2">
                                        <span class="badge badge-info mr-1">tier-2</span>
                                        <span class="text-[11.5px] flex-1 truncate">Annex-2 2-of-4 advisories</span>
                                    </label>
                                </div>
                            </div>

                            {{-- SLA + follow-up --}}
                            <div>
                                <p class="text-[11px] font-semibold mb-1.5">SLA + follow-up</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <label class="flex items-center gap-2 rounded-md border bg-card px-3 py-2 cursor-pointer hover:bg-muted/30">
                                        <input type="checkbox" x-model="wizard.form.receives_breach_alerts">
                                        <span class="badge badge-info mr-1">breach</span>
                                        <span class="text-[11.5px] flex-1 truncate">7-1-7 SLA breach alerts</span>
                                    </label>
                                    <label class="flex items-center gap-2 rounded-md border bg-card px-3 py-2 cursor-pointer hover:bg-muted/30">
                                        <input type="checkbox" x-model="wizard.form.receives_followup_reminders">
                                        <span class="badge badge-brand mr-1">followup</span>
                                        <span class="text-[11.5px] flex-1 truncate">Followup due / overdue</span>
                                    </label>
                                </div>
                            </div>

                            {{-- Digests --}}
                            <div>
                                <p class="text-[11px] font-semibold mb-1.5">Digests</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <label class="flex items-center gap-2 rounded-md border bg-card px-3 py-2 cursor-pointer hover:bg-muted/30">
                                        <input type="checkbox" x-model="wizard.form.receives_daily_report">
                                        <span class="badge badge-success mr-1">daily</span>
                                        <span class="text-[11.5px] flex-1 truncate">Daily 07:00 country digest</span>
                                    </label>
                                    <label class="flex items-center gap-2 rounded-md border bg-card px-3 py-2 cursor-pointer hover:bg-muted/30">
                                        <input type="checkbox" x-model="wizard.form.receives_weekly_report">
                                        <span class="badge badge-soon mr-1">weekly</span>
                                        <span class="text-[11.5px] flex-1 truncate">Weekly digest</span>
                                    </label>
                                </div>
                            </div>

                            {{-- Escalation target --}}
                            <div>
                                <p class="text-[11px] font-semibold mb-1.5">Escalation target</p>
                                <select class="select" x-model="wizard.form.escalates_to_contact_id">
                                    <option :value="null">— No escalation —</option>
                                    <template x-for="c in chainCandidates()" :key="c.id">
                                        <option :value="c.id" x-text="c.full_name + ' · ' + c.level + (c.poe_code ? ' · ' + c.poe_code : '')"></option>
                                    </template>
                                </select>
                                <p class="text-[10.5px] text-muted-foreground mt-1">
                                    Maximum chain depth: <span x-text="meta.max_chain_depth ?? 5"></span> hops. Cycles are rejected.
                                </p>
                            </div>

                            {{-- Notes --}}
                            <div>
                                <label class="label">Notes</label>
                                <textarea class="input mt-1.5" rows="2" maxlength="500" x-model="wizard.form.notes" placeholder="Roster context, on-call rotation, etc."></textarea>
                            </div>

                            {{-- Active toggle --}}
                            <label class="flex items-center gap-2 mt-2">
                                <input type="checkbox" x-model="wizard.form.is_active">
                                <span class="text-[12px]">Active (on call)</span>
                            </label>
                        </div>
                    </template>
                </div>

                {{-- Footer --}}
                <footer class="flex items-center gap-2 px-4 sm:px-6 py-3 border-t">
                    <button class="btn btn-outline btn-sm" @click="prevStep()" :disabled="wizard.step===1">Back</button>
                    <div class="flex-1"></div>
                    <button class="btn btn-ghost btn-sm" @click="wizard.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" @click="nextStep()" :disabled="!stepValid(wizard.step)" x-show="wizard.step<3">Next</button>
                    <button class="btn btn-brand btn-sm" @click="save()" :disabled="wizard.submitting || !canSave()" x-show="wizard.step===3">
                        <span x-show="!wizard.submitting" x-text="wizard.mode==='edit'?'Save changes':'Add Contact'"></span>
                        <span x-show="wizard.submitting">Saving…</span>
                    </button>
                </footer>
            </div>
        </div>
    </template>

    {{-- Confirm deactivate --}}
    <template x-if="confirm.open">
        <div class="fixed inset-0 z-[55] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="confirm.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="confirm.open=false"></div>
            <div class="relative w-full max-w-sm bg-card border rounded-xl shadow-elevation-5 p-5" @click.stop>
                <h3 class="text-[14px] font-bold text-critical">Deactivate contact?</h3>
                <p class="text-[12.5px] text-muted-foreground mt-1.5">
                    <span class="font-semibold text-foreground" x-text="confirm.row?.full_name"></span> will no longer receive notifications.
                    Inbound escalations will reroute to their own escalation target. Re-activate later by editing.
                </p>
                <div class="flex justify-end gap-2 mt-5">
                    <button class="btn btn-outline btn-sm" @click="confirm.open=false">Cancel</button>
                    <button class="btn btn-destructive btn-sm" @click="performDeactivate()">Deactivate</button>
                </div>
            </div>
        </div>
    </template>

    {{-- Toast --}}
    <div class="fixed inset-x-0 bottom-6 z-[60] flex justify-center px-3 pointer-events-none" x-show="opToast.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="opToast.kind==='success'?'toast-success':(opToast.kind==='error'?'toast-destructive':'toast-warning')">
            <div>
                <p class="toast-title" x-text="opToast.title"></p>
                <p class="toast-description" x-text="opToast.body"></p>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function poeContacts(){
        const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content || '';
        const headersJson = () => ({
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': csrf(),
        });
        const qs = (o) => Object.entries(o)
            .filter(([_, v]) => v !== '' && v !== null && v !== undefined && v !== 0 && v !== '0')
            .map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v))
            .join('&');
        const blank = () => ({
            country_code: 'Uganda', district_code: '', poe_code: '', level: '', full_name: '',
            position: '', organisation: '',
            phone: '', alternate_phone: '', email: '', alternate_email: '',
            preferred_channel: 'EMAIL', priority_order: 1,
            escalates_to_contact_id: null,
            is_active: true,
            receives_critical: true, receives_high: true, receives_medium: false, receives_low: false,
            receives_tier1: true, receives_tier2: true, receives_breach_alerts: true,
            receives_followup_reminders: true, receives_daily_report: false, receives_weekly_report: false,
            notes: '',
        });
        return {
            // ── state ─────────────────────────────────────────────────────
            meta: { levels: [], channels: [], poes: [], districts: [], chainables: [], max_chain_depth: 5 },
            filters: { status: 'active', level: '', poe_code: '', district_code: '', preferred_channel: '', q: '' },
            rows: [], loading: false,
            tabCounts: { active: 0, inactive: 0, all: 0 },
            coverage: {},
            wizard: { open: false, mode: 'create', step: 1, form: blank(), submitting: false, editingId: null },
            sheet:  { open: false, row: null, chain: [], chainLoading: false, chainMaxed: false },
            confirm: { open: false, row: null },
            opToast: { open: false, kind: 'success', title: '', body: '', t: null },

            // ── boot ──────────────────────────────────────────────────────
            async boot(){
                await Promise.all([this.loadMeta(), this.loadData()]);
            },
            async loadMeta(){
                try {
                    const r = await fetch('/admin/poe/contacts/meta', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    const j = await r.json();
                    if (j.success) this.meta = { ...this.meta, ...j.data };
                } catch (e) { /* meta is best-effort */ }
            },
            async loadData(){
                this.loading = true;
                try {
                    const r = await fetch('/admin/poe/contacts/data?' + qs(this.filters), { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    const j = await r.json();
                    if (j.success) {
                        this.rows = j.data.rows;
                        this.tabCounts = j.meta.tabs || this.tabCounts;
                        this.coverage  = j.meta.coverage || {};
                        if (window.Alpine) {
                            const m = Alpine.store('pageMeta');
                            if (m) { m.rows = this.tabCounts[this.filters.status] ?? this.tabCounts.all; m.version = null; m.kind = 'poe-contacts'; }
                        }
                    } else {
                        this.toast('error', 'Load failed', j.message || 'Unknown error.');
                    }
                } catch (e) {
                    this.toast('error', 'Network', e.message || String(e));
                } finally { this.loading = false; }
            },

            // ── filters ───────────────────────────────────────────────────
            onPoeFilterChange(){
                // When a PoE is selected, snap district filter to that PoE's district.
                const p = this.meta.poes.find(p => p.poe_code === this.filters.poe_code);
                if (p) this.filters.district_code = p.district || '';
                this.loadData();
            },
            hasActiveFilter(){
                const f = this.filters;
                return !!(f.level || f.poe_code || f.district_code || f.preferred_channel || f.q);
            },
            resetFilters(){
                this.filters.level = '';
                this.filters.poe_code = '';
                this.filters.district_code = '';
                this.filters.preferred_channel = '';
                this.filters.q = '';
                this.loadData();
            },

            // ── lookups ───────────────────────────────────────────────────
            poeName(code){
                if (!code) return '';
                const p = this.meta.poes.find(p => p.poe_code === code);
                return p ? p.poe_name : '';
            },
            badgeClass(flag){
                const on = this.sheet.row && this.sheet.row[flag];
                return on
                    ? 'badge badge-brand text-[10.5px] px-1.5 py-0.5'
                    : 'badge badge-outline text-muted-foreground/70 line-through text-[10.5px] px-1.5 py-0.5';
            },

            // ── sheet ─────────────────────────────────────────────────────
            async openSheet(row){
                this.sheet.row = row;
                this.sheet.chain = [row];
                this.sheet.chainMaxed = false;
                this.sheet.open = true;
                if (row.has_chain) await this.loadChain(row);
            },
            async loadChain(row){
                if (!row || !row.id) return;
                this.sheet.chainLoading = true;
                try {
                    const r = await fetch('/admin/poe/contacts/' + row.id + '/chain', { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    const j = await r.json();
                    if (j.success) {
                        if (!this.sheet.open) this.sheet.open = true;
                        this.sheet.row = row;
                        this.sheet.chain = j.data.chain || [];
                        this.sheet.chainMaxed = !!j.data.maxed;
                    } else {
                        this.toast('error', 'Chain', j.message || 'Failed to load chain.');
                    }
                } catch (e) {
                    this.toast('error', 'Network', e.message || String(e));
                } finally { this.sheet.chainLoading = false; }
            },

            // ── wizard helpers ────────────────────────────────────────────
            autoDistrict(){
                const p = this.meta.poes.find(p => p.poe_code === this.wizard.form.poe_code);
                if (p) this.wizard.form.district_code = p.district;
            },
            chainCandidates(){
                // Anyone in the active roster, EXCLUDING self (when editing) and
                // excluding rows whose chain leads back to self (server validates
                // the cycle anyway, but this keeps the picker clean).
                const selfId = this.wizard.editingId;
                return (this.meta.chainables || []).filter(c => c.id !== selfId);
            },
            hasReach(){
                const f = this.wizard.form;
                return (f.email && f.email.trim() !== '') || (f.phone && f.phone.trim() !== '');
            },
            emailInvalid(){
                const v = (this.wizard.form.email || '').trim();
                if (v === '') return false;
                return !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
            },
            stepValid(s){
                const f = this.wizard.form;
                if (s === 1) return !!(f.full_name && f.full_name.trim() && f.level);
                if (s === 2) return !!(f.poe_code && f.district_code && this.hasReach() && !this.emailInvalid());
                return true;
            },
            canSave(){
                return this.stepValid(1) && this.stepValid(2);
            },
            nextStep(){ if (this.stepValid(this.wizard.step) && this.wizard.step < 3) this.wizard.step++; },
            prevStep(){ if (this.wizard.step > 1) this.wizard.step--; },

            // ── wizard open / save ────────────────────────────────────────
            openCreate(){
                this.wizard.open = true; this.wizard.mode = 'create';
                this.wizard.step = 1; this.wizard.editingId = null;
                this.wizard.form = blank();
            },
            async openEdit(id){
                this.wizard.open = true; this.wizard.mode = 'edit';
                this.wizard.step = 1; this.wizard.editingId = id;
                try {
                    const r = await fetch('/admin/poe/contacts/' + id, { headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' } });
                    const j = await r.json();
                    if (j.success) this.wizard.form = { ...blank(), ...j.data };
                    else this.toast('error', 'Load failed', j.message || 'Could not load contact.');
                } catch (e) {
                    this.toast('error', 'Network', e.message || String(e));
                }
            },
            async save(){
                if (this.wizard.submitting || !this.canSave()) return;
                this.wizard.submitting = true;
                const url = this.wizard.mode === 'edit'
                    ? '/admin/poe/contacts/' + this.wizard.editingId
                    : '/admin/poe/contacts';
                const method = this.wizard.mode === 'edit' ? 'PATCH' : 'POST';
                try {
                    const r = await fetch(url, { method, headers: headersJson(), body: JSON.stringify(this.wizard.form) });
                    const j = await r.json();
                    if (!r.ok || !j.success) {
                        const errFld = j?.error?.field ? ' (' + j.error.field + ')' : '';
                        this.toast('error', 'Save failed', (j.message || 'Unknown error.') + errFld);
                        return;
                    }
                    this.toast('success', this.wizard.mode === 'edit' ? 'Updated' : 'Added', 'Roster updated.');
                    this.wizard.open = false;
                    await Promise.all([this.loadData(), this.loadMeta()]);
                } catch (e) {
                    this.toast('error', 'Network', e.message || String(e));
                } finally { this.wizard.submitting = false; }
            },

            // ── deactivate / reactivate ───────────────────────────────────
            confirmDeactivate(row){ this.confirm = { open: true, row }; },
            async performDeactivate(){
                const id = this.confirm.row.id;
                try {
                    const r = await fetch('/admin/poe/contacts/' + id, { method: 'DELETE', headers: headersJson() });
                    const j = await r.json();
                    if (j.success) {
                        this.toast('success', 'Deactivated', 'Contact off the on-call list.');
                        this.confirm.open = false;
                        await Promise.all([this.loadData(), this.loadMeta()]);
                    } else {
                        this.toast('error', 'Failed', j.message || 'Could not deactivate.');
                    }
                } catch (e) {
                    this.toast('error', 'Network', e.message || String(e));
                }
            },
            async reactivate(row){
                try {
                    const r = await fetch('/admin/poe/contacts/' + row.id + '/restore', { method: 'POST', headers: headersJson() });
                    const j = await r.json();
                    if (j.success) {
                        this.toast('success', 'Reactivated', 'Contact back on call.');
                        await Promise.all([this.loadData(), this.loadMeta()]);
                    } else {
                        this.toast('error', 'Failed', j.message || 'Could not reactivate.');
                    }
                } catch (e) {
                    this.toast('error', 'Network', e.message || String(e));
                }
            },

            // ── toast ─────────────────────────────────────────────────────
            toast(kind, title, body){
                clearTimeout(this.opToast.t);
                this.opToast = { open: true, kind, title, body, t: null };
                this.opToast.t = setTimeout(() => { this.opToast.open = false; }, 3500);
            },
        };
    }
</script>
@endpush
