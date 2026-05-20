@extends('admin.layout')

@section('crumb', 'Workforce')
@section('title', 'Workforce')

@push('head')
<style type="text/tailwindcss">
    /* ── Layout chassis ─────────────────────────────────────────────── */
    .wf-stack          { @apply space-y-4; }
    .wf-card           { @apply rounded-xl border border-border/70 bg-card text-card-foreground shadow-elevation-1; }
    .wf-card-pad       { @apply px-4 py-3; }
    .wf-card-head      { @apply flex items-center justify-between gap-3 px-4 py-3 border-b border-border/70; }
    .wf-card-title     { @apply text-[13px] font-semibold tracking-tight text-foreground; }
    .wf-card-sub       { @apply text-[11px] text-muted-foreground; }
    .wf-divider        { @apply border-t border-border/70; }

    /* ── Tabs ───────────────────────────────────────────────────────── */
    .wf-tabs           { @apply inline-flex items-center rounded-lg border border-border/70 bg-muted/30 p-0.5; }
    .wf-tab            { @apply px-3 py-1.5 text-[12px] font-medium rounded-md transition-colors text-muted-foreground hover:text-foreground; }
    .wf-tab--active    { @apply bg-card text-foreground shadow-sm; }
    .wf-tab .wf-tab-n  { @apply ml-1.5 text-[10.5px] text-muted-foreground tabular-nums; }
    .wf-tab--active .wf-tab-n { @apply text-foreground/70; }

    /* ── Filter bar ─────────────────────────────────────────────────── */
    .wf-filter-grid    { @apply grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-2 p-3; }

    /* ── Table ──────────────────────────────────────────────────────── */
    .wf-table-wrap     { @apply relative w-full overflow-auto max-h-[640px]; }
    .wf-table          { @apply w-full text-[12.5px] border-separate; border-spacing: 0; }
    .wf-table thead th {
        @apply text-[10px] font-semibold uppercase tracking-[.10em] text-muted-foreground
               bg-muted/50 backdrop-blur-sm px-3 py-2 text-left whitespace-nowrap
               border-b border-border/70 sticky top-0 z-10 select-none;
    }
    .wf-table tbody td {
        @apply px-3 py-2 border-b border-border/40 align-middle whitespace-nowrap;
    }
    .wf-table tbody tr:hover td { @apply bg-muted/30 cursor-pointer; }
    .wf-table tbody tr:last-child td { @apply border-b-0; }
    .wf-cell-primary   { @apply font-medium text-foreground; }
    .wf-cell-sub       { @apply text-[10.5px] text-muted-foreground; }
    .wf-cell-mono      { @apply font-mono text-[11px] text-muted-foreground; }

    /* ── Status pills ───────────────────────────────────────────────── */
    .wf-pill           { @apply inline-flex items-center rounded-full px-2 py-0.5 text-[10.5px] font-semibold; }
    .wf-pill-ok        { @apply bg-success/10 text-success ring-1 ring-success/30; }
    .wf-pill-warn      { @apply bg-warning/15 text-warning ring-1 ring-warning/30; }
    .wf-pill-err       { @apply bg-critical/10 text-critical ring-1 ring-critical/35; }
    .wf-pill-mute      { @apply bg-muted text-foreground/75 ring-1 ring-border/60; }
    .wf-pill-brand     { @apply bg-brand-soft text-brand-ink ring-1 ring-brand/30; }

    /* ── Roles reference grid ───────────────────────────────────────── */
    .wf-roles-grid     { @apply grid grid-cols-1 md:grid-cols-2 gap-3 p-4; }
    .wf-role-card      { @apply rounded-lg border border-border/60 bg-muted/10 p-3; }
    .wf-role-title     { @apply text-[12px] font-semibold text-foreground; }
    .wf-role-scope     { @apply ml-2 text-[10.5px] font-medium text-muted-foreground uppercase tracking-wider; }
    .wf-role-desc      { @apply mt-1 text-[11.5px] text-muted-foreground leading-relaxed; }

    /* ── Wizard modal ───────────────────────────────────────────────── */
    .wf-modal-bg       { @apply fixed inset-0 z-[80] bg-black/55 backdrop-blur-sm; }
    .wf-modal-shell    { @apply fixed inset-0 z-[81] flex items-stretch justify-stretch p-0 sm:p-8 sm:items-center sm:justify-center; }
    .wf-modal          { @apply relative w-full sm:max-w-xl max-h-[100dvh] sm:max-h-[88dvh]
                                 flex flex-col bg-card border border-border shadow-elevation-5
                                 sm:rounded-xl overflow-hidden; }
    .wf-modal-head     { @apply flex items-center justify-between gap-3 px-5 py-3 border-b border-border/70; }
    .wf-modal-body     { @apply flex-1 overflow-auto px-5 py-4 text-[13px]; }
    .wf-modal-foot     { @apply flex items-center justify-between gap-2 px-5 py-3 border-t border-border/70 bg-muted/30; }

    /* ── Wizard step bar ────────────────────────────────────────────── */
    .wf-steps          { @apply flex items-center gap-2 mb-4; }
    .wf-step           { @apply flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground; }
    .wf-step-dot       { @apply inline-flex h-5 w-5 items-center justify-center rounded-full border border-border bg-muted text-[10px] font-semibold; }
    .wf-step--done .wf-step-dot { @apply bg-success/15 border-success/40 text-success; }
    .wf-step--active   { @apply text-foreground; }
    .wf-step--active .wf-step-dot { @apply bg-brand text-brand-foreground border-brand; }
    .wf-step-sep       { @apply h-px flex-1 bg-border/60; }

    .wf-fld            { @apply mt-3 first:mt-0; }
    .wf-fld-lbl        { @apply block text-[11px] font-semibold text-foreground; }
    .wf-fld-hint       { @apply mt-0.5 text-[10.5px] text-muted-foreground leading-relaxed; }
    .wf-fld-input      { @apply mt-1 w-full rounded-md border border-border/70 bg-card px-2.5 py-1.5
                                 text-[13px] text-foreground placeholder:text-muted-foreground/60
                                 focus:outline-none focus:ring-2 focus:ring-ring focus:border-ring; }
    .wf-fld-err        { @apply mt-1 text-[11px] text-critical; }

    /* Role radio cards */
    .wf-rolebox        { @apply flex items-start gap-3 rounded-lg border border-border/60 bg-muted/10 p-3
                                 cursor-pointer transition-colors hover:bg-muted/30; }
    .wf-rolebox--sel   { @apply border-brand ring-2 ring-brand/40 bg-brand-soft; }
    .wf-rolebox-radio  { @apply mt-1 h-3.5 w-3.5 rounded-full border-2 border-border flex-shrink-0; }
    .wf-rolebox--sel .wf-rolebox-radio { @apply bg-brand border-brand ring-2 ring-brand/30; }
    .wf-rolebox-body   { @apply min-w-0; }
    .wf-rolebox-title  { @apply text-[12.5px] font-semibold text-foreground; }
    .wf-rolebox-desc   { @apply mt-0.5 text-[11px] text-muted-foreground leading-snug; }

    /* Result panel (success — temp pw shown once) */
    .wf-result-card    { @apply rounded-lg border border-success/40 bg-success/5 p-4 space-y-3; }
    .wf-result-credentials {
        @apply font-mono text-[14px] font-semibold tracking-wider text-foreground
               bg-card border border-border rounded-md px-3 py-2 select-all;
    }
    .wf-copy-btn       { @apply inline-flex items-center gap-1 rounded-md border border-border/70 bg-card px-2.5 py-1
                                 text-[11px] font-medium text-foreground hover:bg-muted/40 transition-colors; }

    /* Drawer (right-side, per-user) */
    .wf-drawer-bg      { @apply fixed inset-0 z-[70] bg-black/45 backdrop-blur-sm; }
    .wf-drawer-shell   { @apply fixed inset-y-0 right-0 z-[71] w-full sm:max-w-md bg-card border-l border-border shadow-elevation-5 flex flex-col; }
    .wf-drawer-head    { @apply flex items-center justify-between gap-3 px-5 py-3 border-b border-border/70; }
    .wf-drawer-body    { @apply flex-1 overflow-auto px-5 py-4 space-y-4; }
    .wf-drawer-foot    { @apply flex items-center justify-end gap-2 px-5 py-3 border-t border-border/70 bg-muted/30; }
    .wf-drawer-row     { @apply flex items-start justify-between gap-3 py-1.5 border-b border-border/40 last:border-b-0; }
    .wf-drawer-k       { @apply text-[11px] uppercase tracking-wider text-muted-foreground; }
    .wf-drawer-v       { @apply text-[13px] text-foreground text-right max-w-[60%] truncate; }

    /* Progress strip */
    .wf-progress       { @apply fixed inset-x-0 top-0 h-[2px] z-[60] overflow-hidden pointer-events-none; }
    .wf-progress::after{ content: ''; @apply absolute inset-y-0 left-0 bg-brand; width: 30%; animation: wf-slide 1.1s ease-in-out infinite; }
    @keyframes wf-slide { 0% { transform: translateX(-100%); } 100% { transform: translateX(320%); } }
    @media (prefers-reduced-motion: reduce) { .wf-progress::after { animation: none !important; } }
</style>
@endpush

@section('content')
<div x-data="workforceApp()" x-init="boot()"
     class="wf-stack"
     :aria-busy="loading ? 'true' : 'false'">

    <div class="wf-progress" x-show="loading" x-cloak></div>

    {{-- ──────────────── HEADER ──────────────── --}}
    <section class="flex flex-col sm:flex-row sm:items-end gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Workforce</p>
            <h1 class="text-[20px] font-semibold tracking-tight">People &middot; Roles &middot; Assignments</h1>
            <p class="help-text mt-1" x-text="headline()">Loading workforce…</p>
        </div>
        <div class="flex-1"></div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="topbar-chip" x-show="ready && payload.country_label" x-cloak>
                <span class="status-dot status-dot-live"></span>
                <span x-text="payload.country_label"></span>
            </span>
            <button type="button" class="btn btn-brand btn-sm" @click="openWizard()" :disabled="!ready">
                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
                Add person
            </button>
        </div>
    </section>

    {{-- ──────────────── MAIN TABS ──────────────── --}}
    <section>
        <div class="wf-tabs" role="tablist" aria-label="Workforce view">
            <button type="button" role="tab" class="wf-tab" :class="mainTab==='people' && 'wf-tab--active'" @click="mainTab='people'">People <span class="wf-tab-n" x-text="(payload.users?.length ?? 0).toLocaleString()"></span></button>
            <button type="button" role="tab" class="wf-tab" :class="mainTab==='roles'  && 'wf-tab--active'" @click="mainTab='roles'">Roles <span class="wf-tab-n" x-text="(payload.roles?.length ?? 0).toLocaleString()"></span></button>
            <button type="button" role="tab" class="wf-tab" :class="mainTab==='audit'  && 'wf-tab--active'" @click="mainTab='audit'">Audit</button>
        </div>
    </section>

    {{-- ──────────────── PEOPLE TAB ──────────────── --}}
    <div x-show="mainTab==='people'" x-cloak class="wf-stack">

        {{-- Status sub-tabs --}}
        <section class="wf-card">
            <div class="wf-card-head">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="wf-card-title">Filter by status</span>
                </div>
                <div class="flex items-center gap-1 shrink-0">
                    <button type="button" class="wf-tab" :class="statusTab==='active'    && 'wf-tab--active'" @click="setStatusTab('active')">Active <span class="wf-tab-n" x-text="tabs.active ?? 0"></span></button>
                    <button type="button" class="wf-tab" :class="statusTab==='invited'   && 'wf-tab--active'" @click="setStatusTab('invited')">Invited <span class="wf-tab-n" x-text="tabs.invited ?? 0"></span></button>
                    <button type="button" class="wf-tab" :class="statusTab==='suspended' && 'wf-tab--active'" @click="setStatusTab('suspended')">Suspended <span class="wf-tab-n" x-text="tabs.suspended ?? 0"></span></button>
                    <button type="button" class="wf-tab" :class="statusTab==='inactive'  && 'wf-tab--active'" @click="setStatusTab('inactive')">Inactive <span class="wf-tab-n" x-text="tabs.inactive ?? 0"></span></button>
                    <button type="button" class="wf-tab" :class="statusTab==='all'       && 'wf-tab--active'" @click="setStatusTab('all')">All <span class="wf-tab-n" x-text="tabs.all ?? 0"></span></button>
                </div>
            </div>

            <div class="wf-filter-grid">
                <div>
                    <label class="label block mb-1 text-[11px]" for="wf-f-q">Search</label>
                    <input id="wf-f-q" type="text" class="select" placeholder="Name, username, email, phone…"
                           x-model="filters.q" @keydown.enter.prevent="loadData()" />
                </div>
                <div>
                    <label class="label block mb-1 text-[11px]" for="wf-f-role">Role</label>
                    <select id="wf-f-role" class="select" x-model="filters.role_key">
                        <option value="">All roles</option>
                        <template x-for="r in (payload.roles ?? [])" :key="r.role_key">
                            <option :value="r.role_key" x-text="r.display_name"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="label block mb-1 text-[11px]" for="wf-f-poe">Point of entry</label>
                    <select id="wf-f-poe" class="select" x-model="filters.poe">
                        <option value="">All entry points</option>
                        <template x-for="p in (payload.poes ?? [])" :key="p.poe_code">
                            <option :value="p.poe_code" x-text="p.poe_name"></option>
                        </template>
                    </select>
                </div>
                <div class="flex items-end gap-2">
                    <button type="button" class="btn btn-outline btn-sm" @click="resetFilters()">Reset</button>
                    <button type="button" class="btn btn-brand btn-sm" @click="loadData()">Apply</button>
                </div>
            </div>
        </section>

        {{-- People table --}}
        <section class="wf-card overflow-hidden" aria-label="People">
            <div class="wf-card-head">
                <div class="min-w-0">
                    <h2 class="wf-card-title">People</h2>
                    <p class="wf-card-sub" x-text="peopleSub()"></p>
                </div>
            </div>
            <div class="wf-table-wrap">
                <table class="wf-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Role</th>
                            <th>Assignment</th>
                            <th>Last login</th>
                            <th>Status</th>
                            <th class="text-right pr-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-if="!ready">
                            <template x-for="i in 6" :key="i">
                                <tr><td colspan="6"><div class="h-5 my-1 rounded bg-muted/30 animate-pulse"></div></td></tr>
                            </template>
                        </template>
                        <template x-for="u in (payload.users ?? [])" :key="u.id">
                            <tr @click="openDrawer(u)">
                                <td>
                                    <div class="wf-cell-primary" x-text="u.full_name"></div>
                                    <div class="wf-cell-sub" x-text="u.username + ' · ' + u.email"></div>
                                </td>
                                <td><span class="wf-pill wf-pill-brand" x-text="roleDisplayName(u.role_key)"></span></td>
                                <td>
                                    <div class="wf-cell-primary" x-text="assignmentLine(u.primary_assignment)"></div>
                                    <div class="wf-cell-sub" x-text="(u.assignments_count > 1) ? ('+' + (u.assignments_count - 1) + ' more') : ''"></div>
                                </td>
                                <td>
                                    <div class="wf-cell-primary" x-text="u.last_login_at ? humanDate(u.last_login_at) : '—'"></div>
                                    <div class="wf-cell-sub" x-text="u.dormant_days !== null ? (u.dormant_days + 'd ago') : 'Never'"></div>
                                </td>
                                <td>
                                    <template x-if="u.is_suspended"><span class="wf-pill wf-pill-err">Suspended</span></template>
                                    <template x-if="!u.is_suspended && u.is_invited"><span class="wf-pill wf-pill-warn">Invited</span></template>
                                    <template x-if="!u.is_suspended && !u.is_invited && u.is_active"><span class="wf-pill wf-pill-ok">Active</span></template>
                                    <template x-if="!u.is_suspended && !u.is_invited && !u.is_active"><span class="wf-pill wf-pill-mute">Inactive</span></template>
                                </td>
                                <td class="text-right pr-3">
                                    <button type="button" class="btn btn-outline btn-xs" @click.stop="openDrawer(u)">Open</button>
                                </td>
                            </tr>
                        </template>
                        <template x-if="ready && (payload.users?.length ?? 0) === 0">
                            <tr><td colspan="6">
                                <div class="text-center py-10 text-muted-foreground text-[12.5px]">
                                    No people match the current filters. Click <strong>Reset</strong> above, or <button type="button" class="text-brand-ink underline" @click="openWizard()">add the first person</button>.
                                </div>
                            </td></tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    {{-- ──────────────── ROLES TAB ──────────────── --}}
    <div x-show="mainTab==='roles'" x-cloak class="wf-stack">
        <section class="wf-card">
            <div class="wf-card-head">
                <div class="min-w-0">
                    <h2 class="wf-card-title">Role reference</h2>
                    <p class="wf-card-sub">What each role can see + do. Authoritative — these scope levels are enforced by middleware on every request, not by the UI.</p>
                </div>
            </div>
            <div class="wf-roles-grid">
                <template x-for="r in (payload.roles ?? [])" :key="r.role_key">
                    <div class="wf-role-card">
                        <div class="flex items-baseline">
                            <span class="wf-role-title" x-text="r.display_name"></span>
                            <span class="wf-role-scope" x-text="r.scope_level + ' scope'"></span>
                        </div>
                        <p class="wf-role-desc" x-text="r.description || roleDefaultDescription(r.role_key, r.scope_level)"></p>
                        <p class="mt-2 text-[10.5px] font-mono text-muted-foreground" x-text="r.role_key"></p>
                    </div>
                </template>
            </div>
        </section>
    </div>

    {{-- ──────────────── AUDIT TAB ──────────────── --}}
    <div x-show="mainTab==='audit'" x-cloak class="wf-stack">
        <section class="wf-card">
            <div class="wf-card-head">
                <h2 class="wf-card-title">Recent workforce activity</h2>
            </div>
            <div class="p-5 text-[12.5px] text-muted-foreground">
                Per-user audit trails live inside each person's drawer (click any row on the <button class="text-brand-ink underline" @click="mainTab='people'">People</button> tab). A global activity stream view is on the roadmap.
            </div>
        </section>
    </div>

    {{-- ──────────────── WIZARD MODAL ──────────────── --}}
    <template x-teleport="body">
        <div x-show="wizardOpen" x-cloak>
            <div class="wf-modal-bg" @click="closeWizard()" aria-hidden="true"></div>
            <div class="wf-modal-shell" @keydown.escape.window="closeWizard()">
                <div class="wf-modal" role="dialog" aria-modal="true" aria-labelledby="wf-wizard-title" x-trap.noscroll.inert="wizardOpen">

                    <div class="wf-modal-head">
                        <div class="min-w-0">
                            <p class="eyebrow">Add person</p>
                            <h3 id="wf-wizard-title" class="text-[15px] font-semibold mt-0.5 truncate" x-text="wizardResult ? (wizardResult.user?.full_name + ' — created') : wizardStepTitle()"></h3>
                        </div>
                        <button type="button" class="btn btn-ghost btn-xs" @click="closeWizard()" aria-label="Close">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                        </button>
                    </div>

                    <div class="wf-modal-body">
                        {{-- Step indicator --}}
                        <div class="wf-steps" x-show="!wizardResult">
                            <div class="wf-step" :class="wizardStep === 1 && 'wf-step--active', wizardStep > 1 && 'wf-step--done'"><span class="wf-step-dot">1</span> Identity</div>
                            <div class="wf-step-sep"></div>
                            <div class="wf-step" :class="wizardStep === 2 && 'wf-step--active', wizardStep > 2 && 'wf-step--done'"><span class="wf-step-dot">2</span> Role</div>
                            <div class="wf-step-sep"></div>
                            <div class="wf-step" :class="wizardStep === 3 && 'wf-step--active', wizardStep > 3 && 'wf-step--done'"><span class="wf-step-dot">3</span> Location &amp; access</div>
                        </div>

                        {{-- STEP 1: IDENTITY --}}
                        <div x-show="!wizardResult && wizardStep === 1">
                            <div class="wf-fld">
                                <label class="wf-fld-lbl" for="wf-w-name">Full name</label>
                                <p class="wf-fld-hint">First + last as on official ID. Visible on every case file the person touches.</p>
                                <input id="wf-w-name" type="text" class="wf-fld-input" x-model="wizard.full_name" placeholder="Jane Achieng" autocomplete="off" />
                                <div class="wf-fld-err" x-show="wizardErrors.full_name" x-text="wizardErrors.full_name"></div>
                            </div>
                            <div class="wf-fld">
                                <label class="wf-fld-lbl" for="wf-w-username">Username</label>
                                <p class="wf-fld-hint">Login handle. Short, no spaces. Used in mobile app + web admin. Cannot be changed without an admin reset.</p>
                                <input id="wf-w-username" type="text" class="wf-fld-input" x-model="wizard.username" placeholder="jane.achieng" autocomplete="off" @input="wizard.username = $event.target.value.toLowerCase().replace(/\s+/g, '.')" />
                                <div class="wf-fld-err" x-show="wizardErrors.username" x-text="wizardErrors.username"></div>
                            </div>
                            <div class="wf-fld">
                                <label class="wf-fld-lbl" for="wf-w-email">Email</label>
                                <p class="wf-fld-hint">Where invitation + password-reset links go. Required even for credential-mode (used for future resets).</p>
                                <input id="wf-w-email" type="email" class="wf-fld-input" x-model="wizard.email" placeholder="jane.achieng@health.go.ug" autocomplete="off" />
                                <div class="wf-fld-err" x-show="wizardErrors.email" x-text="wizardErrors.email"></div>
                            </div>
                            <div class="wf-fld">
                                <label class="wf-fld-lbl" for="wf-w-phone">Phone (optional)</label>
                                <p class="wf-fld-hint">For SMS notifications. Country code optional — defaults to Uganda.</p>
                                <input id="wf-w-phone" type="tel" class="wf-fld-input" x-model="wizard.phone" placeholder="+256 7XX XXX XXX" />
                            </div>
                        </div>

                        {{-- STEP 2: ROLE --}}
                        <div x-show="!wizardResult && wizardStep === 2">
                            <p class="text-[12.5px] text-muted-foreground mb-3">A role decides what the person sees, where, and what they can change. Scope is enforced server-side on every request.</p>
                            <div class="space-y-2">
                                <template x-for="r in (payload.roles ?? [])" :key="r.role_key">
                                    <label class="wf-rolebox" :class="wizard.role_key === r.role_key && 'wf-rolebox--sel'">
                                        <input type="radio" name="wf-role" class="sr-only" :value="r.role_key" x-model="wizard.role_key" />
                                        <span class="wf-rolebox-radio" aria-hidden="true"></span>
                                        <span class="wf-rolebox-body">
                                            <span class="flex items-baseline gap-2">
                                                <span class="wf-rolebox-title" x-text="r.display_name"></span>
                                                <span class="text-[10px] font-mono text-muted-foreground" x-text="r.role_key"></span>
                                                <span class="ml-auto text-[10px] text-muted-foreground" x-text="r.scope_level + ' scope'"></span>
                                            </span>
                                            <span class="wf-rolebox-desc" x-text="r.description || roleDefaultDescription(r.role_key, r.scope_level)"></span>
                                        </span>
                                    </label>
                                </template>
                            </div>
                            <div class="wf-fld-err mt-3" x-show="wizardErrors.role_key" x-text="wizardErrors.role_key"></div>
                        </div>

                        {{-- STEP 3: JURISDICTION + ACCESS --}}
                        <div x-show="!wizardResult && wizardStep === 3">

                            <div class="rounded-lg border border-border/60 bg-muted/20 p-3 mb-3">
                                <p class="text-[11px] font-semibold text-foreground" x-text="'Role: ' + roleDisplayName(wizard.role_key)"></p>
                                <p class="text-[11px] text-muted-foreground mt-0.5" x-text="jurisdictionHint()"></p>
                            </div>

                            {{-- NATIONAL: no location picker --}}
                            <template x-if="selectedScopeLevel() === 'NATIONAL'">
                                <div class="rounded-lg border border-success/30 bg-success/5 p-3 text-[12px] text-foreground">
                                    No location needed. National roles see all provinces, districts, and POEs.
                                </div>
                            </template>

                            {{-- SELF: optional location --}}
                            <template x-if="selectedScopeLevel() === 'SELF'">
                                <div class="rounded-lg border border-border/60 bg-muted/10 p-3 text-[12px] text-muted-foreground">
                                    Service / observer roles work across scope. A location can still be recorded for reporting — pick a POE below if known.
                                </div>
                            </template>

                            {{-- PHEOC: province only --}}
                            <template x-if="selectedScopeLevel() === 'PHEOC'">
                                <div class="wf-fld">
                                    <label class="wf-fld-lbl" for="wf-w-prov">Province</label>
                                    <p class="wf-fld-hint">The person will see this province + every district + every POE inside it.</p>
                                    <select id="wf-w-prov" class="wf-fld-input" x-model="wizard.jurisdiction.province_code">
                                        <option value="">— Select province —</option>
                                        <template x-for="p in (payload.provinces ?? [])" :key="p"><option :value="p" x-text="p"></option></template>
                                    </select>
                                    <div class="wf-fld-err" x-show="wizardErrors.jurisdiction" x-text="wizardErrors.jurisdiction"></div>
                                </div>
                            </template>

                            {{-- DISTRICT: district picker --}}
                            <template x-if="selectedScopeLevel() === 'DISTRICT'">
                                <div class="wf-fld">
                                    <label class="wf-fld-lbl" for="wf-w-dist">District</label>
                                    <p class="wf-fld-hint">The person will see this district + every POE inside it. Pick a POE instead to auto-fill.</p>
                                    <select id="wf-w-dist" class="wf-fld-input" x-model="wizard.jurisdiction.district_code">
                                        <option value="">— Select district —</option>
                                        <template x-for="d in (payload.districts ?? [])" :key="d.name"><option :value="d.name" x-text="d.name"></option></template>
                                    </select>
                                    <div class="wf-fld-err" x-show="wizardErrors.jurisdiction" x-text="wizardErrors.jurisdiction"></div>
                                </div>
                            </template>

                            {{-- POE / SELF (when location optional): POE picker --}}
                            <template x-if="selectedScopeLevel() === 'POE' || selectedScopeLevel() === 'SELF'">
                                <div class="wf-fld">
                                    <label class="wf-fld-lbl" for="wf-w-poe">Point of entry</label>
                                    <p class="wf-fld-hint">District + province are auto-derived from the POE — pick from authoritative <span class="font-mono">ref_poes</span>.</p>
                                    <select id="wf-w-poe" class="wf-fld-input" x-model="wizard.jurisdiction.poe_code">
                                        <option value="">— Select POE —</option>
                                        <template x-for="p in (payload.poes ?? [])" :key="p.poe_code">
                                            <option :value="p.poe_code" x-text="p.poe_name + ' · ' + p.district"></option>
                                        </template>
                                    </select>
                                    <div class="wf-fld-err" x-show="wizardErrors.jurisdiction" x-text="wizardErrors.jurisdiction"></div>
                                    <template x-if="wizard.jurisdiction.poe_code">
                                        <p class="mt-2 text-[11px] text-muted-foreground">
                                            Auto-resolved:
                                            <span class="font-mono" x-text="derivedDistrictFromPoe()"></span> ·
                                            <span class="font-mono" x-text="derivedProvinceFromPoe()"></span>
                                        </p>
                                    </template>
                                </div>
                            </template>

                            {{-- Invite mode --}}
                            <div class="wf-fld mt-5 pt-4 border-t border-border/60">
                                <label class="wf-fld-lbl">How does this person get in?</label>
                                <p class="wf-fld-hint">Either send them a one-time invitation link (preferred when they have email) or generate a temporary password and share it verbally.</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mt-2">
                                    <label class="wf-rolebox" :class="wizard.invite_mode === 'credential' && 'wf-rolebox--sel'">
                                        <input type="radio" name="wf-inv" class="sr-only" value="credential" x-model="wizard.invite_mode" />
                                        <span class="wf-rolebox-radio"></span>
                                        <span class="wf-rolebox-body">
                                            <span class="wf-rolebox-title">Temporary password</span>
                                            <span class="wf-rolebox-desc">Shown once on this screen. Person must change it on first login.</span>
                                        </span>
                                    </label>
                                    <label class="wf-rolebox" :class="wizard.invite_mode === 'email' && 'wf-rolebox--sel'">
                                        <input type="radio" name="wf-inv" class="sr-only" value="email" x-model="wizard.invite_mode" />
                                        <span class="wf-rolebox-radio"></span>
                                        <span class="wf-rolebox-body">
                                            <span class="wf-rolebox-title">Email invite link</span>
                                            <span class="wf-rolebox-desc">7-day link emailed to the address above. Account locked until accepted.</span>
                                        </span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        {{-- SUCCESS RESULT --}}
                        <div x-show="wizardResult" class="wf-result-card">
                            <div class="flex items-start gap-2">
                                <svg class="h-5 w-5 text-success mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                                <div>
                                    <p class="text-[13.5px] font-semibold text-foreground" x-text="wizardResult?.user?.full_name + ' was added.'"></p>
                                    <p class="text-[11.5px] text-muted-foreground mt-0.5" x-text="resultRoleAndJurisdiction()"></p>
                                </div>
                            </div>

                            {{-- Credential mode: show temp password ONCE --}}
                            <template x-if="wizardResult?._invite_mode === 'credential'">
                                <div>
                                    <p class="text-[11px] font-semibold text-foreground mb-1">Temporary password (share verbally — not stored anywhere after this screen)</p>
                                    <div class="wf-result-credentials" x-text="wizardResult._temp_password"></div>
                                    <div class="mt-2 flex items-center gap-2">
                                        <button type="button" class="wf-copy-btn" @click="copy(wizardResult._temp_password)">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                            <span x-text="copied ? 'Copied' : 'Copy'"></span>
                                        </button>
                                        <span class="text-[11px] text-muted-foreground">Username: <span class="font-mono" x-text="wizardResult?.user?.username"></span></span>
                                    </div>
                                </div>
                            </template>

                            {{-- Email mode: show invite URL --}}
                            <template x-if="wizardResult?._invite_mode === 'email'">
                                <div>
                                    <p class="text-[11px] font-semibold text-foreground mb-1">Invitation link (expires in 7 days)</p>
                                    <div class="wf-result-credentials" style="font-size: 11.5px; word-break: break-all" x-text="wizardResult._invite_url"></div>
                                    <div class="mt-2 flex items-center gap-2">
                                        <button type="button" class="wf-copy-btn" @click="copy(wizardResult._invite_url)">
                                            <svg class="h-3 w-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
                                            <span x-text="copied ? 'Copied' : 'Copy'"></span>
                                        </button>
                                        <span class="text-[11px] text-muted-foreground" x-text="'Sent to ' + wizardResult?.user?.email"></span>
                                    </div>
                                </div>
                            </template>
                        </div>

                        {{-- Wizard-level error --}}
                        <div class="wf-fld-err mt-3" x-show="wizardErrors._global" x-text="wizardErrors._global"></div>
                    </div>

                    <div class="wf-modal-foot">
                        <template x-if="!wizardResult">
                            <div class="flex w-full items-center justify-between">
                                <button type="button" class="btn btn-ghost btn-sm" @click="wizardStep > 1 ? wizardStep-- : closeWizard()" x-text="wizardStep > 1 ? 'Back' : 'Cancel'"></button>
                                <div class="flex items-center gap-2">
                                    <span class="text-[11px] text-muted-foreground" x-show="wizardSubmitting">Creating…</span>
                                    <template x-if="wizardStep < 3">
                                        <button type="button" class="btn btn-brand btn-sm" @click="nextStep()">Next</button>
                                    </template>
                                    <template x-if="wizardStep === 3">
                                        <button type="button" class="btn btn-brand btn-sm" @click="submitWizard()" :disabled="wizardSubmitting">Create person</button>
                                    </template>
                                </div>
                            </div>
                        </template>
                        <template x-if="wizardResult">
                            <div class="flex w-full items-center justify-end">
                                <button type="button" class="btn btn-brand btn-sm" @click="closeWizardAndRefresh()">Done</button>
                            </div>
                        </template>
                    </div>

                </div>
            </div>
        </div>
    </template>

    {{-- ──────────────── PER-USER DRAWER ──────────────── --}}
    <template x-teleport="body">
        <div x-show="drawerOpen" x-cloak>
            <div class="wf-drawer-bg" @click="closeDrawer()" aria-hidden="true"></div>
            <div class="wf-drawer-shell" role="dialog" aria-modal="true" x-trap.noscroll.inert="drawerOpen">

                <div class="wf-drawer-head">
                    <div class="min-w-0">
                        <p class="eyebrow">Person</p>
                        <h3 class="text-[15px] font-semibold mt-0.5 truncate" x-text="drawer?.full_name"></h3>
                    </div>
                    <button type="button" class="btn btn-ghost btn-xs" @click="closeDrawer()" aria-label="Close">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="wf-drawer-body" x-show="drawer">

                    <div>
                        <p class="eyebrow">Identity</p>
                        <div class="mt-1">
                            <div class="wf-drawer-row"><span class="wf-drawer-k">Username</span><span class="wf-drawer-v font-mono" x-text="drawer?.username"></span></div>
                            <div class="wf-drawer-row"><span class="wf-drawer-k">Email</span><span class="wf-drawer-v" x-text="drawer?.email"></span></div>
                            <div class="wf-drawer-row"><span class="wf-drawer-k">Phone</span><span class="wf-drawer-v" x-text="drawer?.phone || '—'"></span></div>
                            <div class="wf-drawer-row"><span class="wf-drawer-k">Role</span><span class="wf-drawer-v" x-text="roleDisplayName(drawer?.role_key)"></span></div>
                            <div class="wf-drawer-row"><span class="wf-drawer-k">Assignment</span><span class="wf-drawer-v" x-text="assignmentLine(drawer?.primary_assignment)"></span></div>
                            <div class="wf-drawer-row"><span class="wf-drawer-k">Last login</span><span class="wf-drawer-v" x-text="drawer?.last_login_at ? humanDate(drawer.last_login_at) : 'Never'"></span></div>
                            <div class="wf-drawer-row"><span class="wf-drawer-k">Status</span><span class="wf-drawer-v" x-text="drawerStatus()"></span></div>
                        </div>
                    </div>

                    <div>
                        <p class="eyebrow">Quick actions</p>
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <button type="button" class="btn btn-outline btn-sm" @click="doAction('password-reset')" :disabled="drawerActing">Reset password</button>
                            <button type="button" class="btn btn-outline btn-sm" @click="doAction('mfa-reset')"      :disabled="drawerActing">Reset MFA</button>
                            <button type="button" class="btn btn-outline btn-sm" @click="doAction('unlock')"         :disabled="drawerActing">Unlock account</button>
                            <template x-if="!drawer?.is_suspended">
                                <button type="button" class="btn btn-outline btn-sm" @click="promptSuspend()" :disabled="drawerActing">Suspend…</button>
                            </template>
                            <template x-if="drawer?.is_suspended">
                                <button type="button" class="btn btn-brand   btn-sm" @click="doAction('unsuspend')" :disabled="drawerActing">Reactivate</button>
                            </template>
                            <template x-if="drawer?.is_invited">
                                <button type="button" class="btn btn-outline btn-sm" @click="doAction('regenerate-invite')" :disabled="drawerActing">New invite link</button>
                            </template>
                        </div>
                        <p class="mt-2 text-[10.5px] text-muted-foreground">Every action above is logged to <span class="font-mono">user_audit_log</span> with your actor id.</p>
                    </div>

                    <div x-show="drawerActionResult" class="rounded-lg border border-success/40 bg-success/5 p-3">
                        <p class="text-[12px] font-semibold text-foreground" x-text="drawerActionResult?.message"></p>
                        <template x-if="drawerActionResult?.data?._temp_password">
                            <div class="mt-2">
                                <p class="text-[11px] font-semibold mb-1">New temporary password</p>
                                <div class="wf-result-credentials" x-text="drawerActionResult.data._temp_password"></div>
                                <button type="button" class="wf-copy-btn mt-2" @click="copy(drawerActionResult.data._temp_password)"><span x-text="copied ? 'Copied' : 'Copy'"></span></button>
                            </div>
                        </template>
                        <template x-if="drawerActionResult?.data?._invite_url">
                            <div class="mt-2">
                                <p class="text-[11px] font-semibold mb-1">New invite link (7 days)</p>
                                <div class="wf-result-credentials" style="font-size:11.5px;word-break:break-all" x-text="drawerActionResult.data._invite_url"></div>
                                <button type="button" class="wf-copy-btn mt-2" @click="copy(drawerActionResult.data._invite_url)"><span x-text="copied ? 'Copied' : 'Copy'"></span></button>
                            </div>
                        </template>
                    </div>

                </div>

                <div class="wf-drawer-foot">
                    <button type="button" class="btn btn-ghost btn-sm" @click="closeDrawer()">Close</button>
                </div>
            </div>
        </div>
    </template>

</div>

@push('scripts')
<script>
    const WF = {
        urls: {
            data:    @json(url('/admin/workforce/data')),
            wizard:  @json(url('/admin/workforce/wizard')),
            userOp:  (id, op) => @json(url('/admin/workforce/users/')) + '/' + id + '/' + op,
        },
        csrf: @json(csrf_token()),
    };

    function workforceApp() {
        return {
            ready: false,
            loading: false,
            payload: { users: [], roles: [], provinces: [], districts: [], poes: [], country_iso2: 'UG', country_label: 'Uganda' },
            tabs: { active: 0, invited: 0, suspended: 0, inactive: 0, all: 0 },

            mainTab: 'people',
            statusTab: 'active',
            filters: { q: '', role_key: '', poe: '' },

            // Wizard
            wizardOpen: false,
            wizardStep: 1,
            wizardSubmitting: false,
            wizardResult: null,
            wizardErrors: {},
            wizard: {
                full_name: '', username: '', email: '', phone: '',
                role_key: '',
                jurisdiction: { province_code: '', district_code: '', poe_code: '' },
                invite_mode: 'credential',
            },

            // Drawer
            drawerOpen: false,
            drawer: null,
            drawerActing: false,
            drawerActionResult: null,

            copied: false,

            boot() {
                this.loadData();
            },

            async loadData() {
                this.loading = true;
                try {
                    const params = new URLSearchParams({
                        status:   this.statusTab,
                        q:        this.filters.q || '',
                        role_key: this.filters.role_key || '',
                        poe:      this.filters.poe || '',
                    });
                    const res = await fetch(`${WF.urls.data}?${params}`, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                    });
                    if (!res.ok) throw new Error(`HTTP ${res.status}`);
                    const body = await res.json();
                    if (!body?.success) throw new Error(body?.message || 'Bad response');
                    this.payload = body.data || this.payload;
                    this.tabs    = body.meta?.tabs || this.tabs;
                    this.ready   = true;
                } catch (e) {
                    console.error('[workforce] load failed', e);
                    this.ready = true;
                } finally {
                    this.loading = false;
                }
            },

            setStatusTab(t) {
                this.statusTab = t;
                this.loadData();
            },

            resetFilters() {
                this.filters = { q: '', role_key: '', poe: '' };
                this.loadData();
            },

            // ── Display helpers ─────────────────────────────────────────
            headline() {
                if (!this.ready) return 'Loading workforce…';
                const n = this.payload.users?.length ?? 0;
                const scope = this.payload.country_label || 'Uganda';
                if (n === 0) return `No people yet — click Add person to invite the first.`;
                return `${n.toLocaleString()} ${n === 1 ? 'person' : 'people'} in scope · ${scope}`;
            },
            peopleSub() {
                const n = this.payload.users?.length ?? 0;
                return n === 0 ? 'No matches.' : `Showing ${n.toLocaleString()} of ${this.tabs[this.statusTab] ?? n}`;
            },
            roleDisplayName(roleKey) {
                if (!roleKey) return '—';
                const r = (this.payload.roles || []).find(x => x.role_key === roleKey);
                return r ? r.display_name : roleKey;
            },
            roleDefaultDescription(roleKey, scopeLevel) {
                const lvl = (scopeLevel || '').toUpperCase();
                const map = {
                    NATIONAL: 'Full read + write across the whole country. Sees every province, district, POE.',
                    PHEOC:    'Sees one province and every district + POE inside it. Cannot write outside that province.',
                    DISTRICT: 'Sees one district and every POE inside it. Cannot write outside that district.',
                    POE:      'Sees one entry point. Can run screenings, manage assignments there, but cannot see other POEs.',
                    SELF:     'Programmatic / observer role. No write access outside own records.',
                };
                return map[lvl] || 'Scope-limited role.';
            },
            assignmentLine(a) {
                if (!a) return '—';
                if (a.poe_code)      return a.poe_code + (a.district_code ? ' · ' + a.district_code : '');
                if (a.district_code) return a.district_code + (a.province_code ? ' · ' + a.province_code : '');
                if (a.province_code) return a.province_code;
                return 'National';
            },
            humanDate(iso) {
                if (!iso) return '—';
                try {
                    const d = new Date(iso.replace(' ', 'T'));
                    return d.toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
                } catch (e) { return iso; }
            },

            // ── Wizard ──────────────────────────────────────────────────
            openWizard() {
                this.wizard = {
                    full_name: '', username: '', email: '', phone: '',
                    role_key: '',
                    jurisdiction: { province_code: '', district_code: '', poe_code: '' },
                    invite_mode: 'credential',
                };
                this.wizardErrors = {};
                this.wizardResult = null;
                this.wizardStep = 1;
                this.wizardSubmitting = false;
                this.wizardOpen = true;
            },
            closeWizard() {
                this.wizardOpen = false;
            },
            closeWizardAndRefresh() {
                this.closeWizard();
                this.loadData();
            },
            wizardStepTitle() {
                return ['', 'Identity', 'Role', 'Location & access'][this.wizardStep] || 'Add person';
            },
            selectedScopeLevel() {
                const r = (this.payload.roles || []).find(x => x.role_key === this.wizard.role_key);
                return r ? r.scope_level : '';
            },
            jurisdictionHint() {
                const lvl = this.selectedScopeLevel();
                const map = {
                    NATIONAL: 'National scope — no specific location needed.',
                    PHEOC:    'Pick the province this person coordinates.',
                    DISTRICT: 'Pick the district this person supervises. Province is auto-derived.',
                    POE:      'Pick the entry point. District + province are auto-derived from authoritative ref_poes.',
                    SELF:     'Location is optional for service / observer roles.',
                };
                return map[lvl] || 'Pick a location.';
            },
            derivedDistrictFromPoe() {
                const p = (this.payload.poes || []).find(x => x.poe_code === this.wizard.jurisdiction.poe_code);
                return p?.district || '';
            },
            derivedProvinceFromPoe() {
                const p = (this.payload.poes || []).find(x => x.poe_code === this.wizard.jurisdiction.poe_code);
                return p?.province || '';
            },
            nextStep() {
                this.wizardErrors = {};
                if (this.wizardStep === 1) {
                    if (!this.wizard.full_name?.trim()) this.wizardErrors.full_name = 'Full name is required.';
                    if (!this.wizard.username?.trim()) this.wizardErrors.username  = 'Username is required.';
                    if (!this.wizard.email?.trim())    this.wizardErrors.email     = 'Email is required.';
                    if (this.wizard.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.wizard.email)) this.wizardErrors.email = 'Email looks malformed.';
                    if (Object.keys(this.wizardErrors).length) return;
                }
                if (this.wizardStep === 2) {
                    if (!this.wizard.role_key) { this.wizardErrors.role_key = 'Pick a role.'; return; }
                }
                this.wizardStep++;
            },
            async submitWizard() {
                this.wizardErrors = {};
                // Step 3 client-side check
                const lvl = this.selectedScopeLevel();
                const j   = this.wizard.jurisdiction;
                if (lvl === 'PHEOC'    && !j.province_code) { this.wizardErrors.jurisdiction = 'Pick a province.';     return; }
                if (lvl === 'DISTRICT' && !j.district_code) { this.wizardErrors.jurisdiction = 'Pick a district.';     return; }
                if (lvl === 'POE'      && !j.poe_code)      { this.wizardErrors.jurisdiction = 'Pick a point of entry.'; return; }

                this.wizardSubmitting = true;
                try {
                    const res = await fetch(WF.urls.wizard, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': WF.csrf,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify(this.wizard),
                    });
                    const body = await res.json().catch(() => ({}));
                    if (!res.ok || !body?.success) {
                        this.wizardErrors._global = body?.message || `HTTP ${res.status}`;
                        if (body?.error?.hint) this.wizardErrors._global += ' — ' + body.error.hint;
                        return;
                    }
                    this.wizardResult = body.data;
                } catch (e) {
                    this.wizardErrors._global = 'Network error: ' + (e?.message || e);
                } finally {
                    this.wizardSubmitting = false;
                }
            },
            resultRoleAndJurisdiction() {
                if (!this.wizardResult) return '';
                const role = this.roleDisplayName(this.wizardResult.user?.role_key);
                const asg  = this.assignmentLine(this.wizardResult.user?.primary_assignment);
                return `${role} · ${asg}`;
            },

            // ── Drawer (per-user) ───────────────────────────────────────
            openDrawer(u) {
                this.drawer = u;
                this.drawerActionResult = null;
                this.drawerOpen = true;
            },
            closeDrawer() {
                this.drawerOpen = false;
                this.drawer = null;
                this.drawerActionResult = null;
            },
            drawerStatus() {
                const u = this.drawer; if (!u) return '';
                if (u.is_suspended) return 'Suspended';
                if (u.is_invited)   return 'Invitation pending';
                if (!u.is_active)   return 'Inactive';
                return 'Active';
            },
            async doAction(op) {
                if (!this.drawer) return;
                this.drawerActing = true;
                this.drawerActionResult = null;
                try {
                    const res = await fetch(WF.urls.userOp(this.drawer.id, op), {
                        method: 'POST',
                        headers: {
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': WF.csrf,
                        },
                        credentials: 'same-origin',
                    });
                    const body = await res.json().catch(() => ({}));
                    if (!res.ok || !body?.success) {
                        this.drawerActionResult = { message: body?.message || `HTTP ${res.status}`, data: null };
                        return;
                    }
                    this.drawerActionResult = body;
                    await this.loadData();
                    // Re-bind drawer to refreshed user record if still in list.
                    const fresh = (this.payload.users || []).find(x => x.id === this.drawer.id);
                    if (fresh) this.drawer = fresh;
                } catch (e) {
                    this.drawerActionResult = { message: 'Network error: ' + (e?.message || e), data: null };
                } finally {
                    this.drawerActing = false;
                }
            },
            async promptSuspend() {
                const reason = window.prompt('Reason for suspension (required, ≥ 4 chars):');
                if (!reason || reason.trim().length < 4) return;
                this.drawerActing = true;
                this.drawerActionResult = null;
                try {
                    const res = await fetch(WF.urls.userOp(this.drawer.id, 'suspend'), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                            'X-CSRF-TOKEN': WF.csrf,
                        },
                        credentials: 'same-origin',
                        body: JSON.stringify({ reason: reason.trim() }),
                    });
                    const body = await res.json().catch(() => ({}));
                    this.drawerActionResult = body;
                    if (res.ok && body?.success) {
                        await this.loadData();
                        const fresh = (this.payload.users || []).find(x => x.id === this.drawer.id);
                        if (fresh) this.drawer = fresh;
                    }
                } catch (e) {
                    this.drawerActionResult = { message: 'Network error: ' + (e?.message || e), data: null };
                } finally {
                    this.drawerActing = false;
                }
            },

            copy(value) {
                if (!value) return;
                navigator.clipboard.writeText(String(value)).then(() => {
                    this.copied = true;
                    setTimeout(() => this.copied = false, 1500);
                }).catch(() => {});
            },
        };
    }
</script>
@endpush
@endsection
