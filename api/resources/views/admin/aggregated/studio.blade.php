@extends('admin.layout')

@section('crumb', 'Aggregated Reports')
@section('title', 'Template Studio')

@php
    /** @var array $scope */
    /** @var bool  $canWrite */
    /** @var array $meta */
@endphp

@section('content')
<div x-data="idsrStudio()"
     x-init="boot()"
     x-effect="window.adminLock.set('idsr-studio', wizard.open || detail.open || confirmDialog.open || columnDialog.open)"
     class="space-y-5">

    {{-- ── KPI strip ────────────────────────────────────────────────── --}}
    <section class="grid grid-cols-2 sm:grid-cols-5 gap-3">
        <div class="kpi kpi-glow"><p class="kpi-label">All templates</p><p class="kpi-value tabular-nums" x-text="counts.total"></p><p class="text-[11px] text-muted-foreground mt-1">in scope</p></div>
        <div class="kpi"><p class="kpi-label">Published</p><p class="kpi-value tabular-nums text-success" x-text="counts.published"></p><p class="text-[11px] text-muted-foreground mt-1">visible to mobile</p></div>
        <div class="kpi"><p class="kpi-label">Draft</p><p class="kpi-value tabular-nums text-warning" x-text="counts.draft"></p><p class="text-[11px] text-muted-foreground mt-1">not yet live</p></div>
        <div class="kpi"><p class="kpi-label">Retired</p><p class="kpi-value tabular-nums text-muted-foreground" x-text="counts.retired"></p><p class="text-[11px] text-muted-foreground mt-1">archived</p></div>
        <div class="kpi"><p class="kpi-label">Locked</p><p class="kpi-value tabular-nums" x-text="counts.locked"></p><p class="text-[11px] text-muted-foreground mt-1">edit-frozen</p></div>
    </section>

    {{-- ── Header actions ─────────────────────────────────────────── --}}
    <section class="card">
        <div class="card-content !p-0">
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto">
                        <template x-for="t in tabs" :key="t.key">
                            <button class="tabs-trigger flex-1 sm:flex-none" :data-state="filters.status===t.key?'active':'inactive'" @click="filters.status=t.key; loadTemplates()">
                                <span x-text="t.label"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-text="counts[t.key] ?? 0"></span>
                            </button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <input type="search" class="input w-full sm:w-72" placeholder="Search code, name, description…" x-model.debounce.300ms="filters.q" @input="loadTemplates()">
                    @if ($canWrite)
                    <button class="btn btn-brand" @click="openWizard()">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
                        New template
                    </button>
                    @endif
                </div>
            </div>

            {{-- ── Template library table ──────────────────────────── --}}
            <div class="table-wrap !rounded-none !border-0">
                <table class="table">
                    <thead class="table-head"><tr>
                        <th class="table-head-th">Template</th>
                        <th class="table-head-th hidden md:table-cell">Frequency</th>
                        <th class="table-head-th hidden md:table-cell">Columns</th>
                        <th class="table-head-th hidden lg:table-cell">Submissions</th>
                        <th class="table-head-th hidden lg:table-cell">Published</th>
                        <th class="table-head-th text-right">Status</th>
                    </tr></thead>
                    <tbody class="table-body">
                        <template x-if="loading"><tr><td colspan="6" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading templates…</td></tr></template>
                        <template x-if="!loading && templates.length === 0"><tr><td colspan="6" class="table-cell"><div class="empty-state"><p class="text-sm text-muted-foreground">No templates in scope. @if ($canWrite)Use <span class="font-semibold text-brand">New template</span> to create one.@endif</p></div></td></tr></template>
                        <template x-for="tpl in templates" :key="tpl.id">
                            <tr class="table-row cursor-pointer" @click="openDetail(tpl.id)">
                                <td class="table-cell">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full shrink-0" :style="'background:' + (tpl.colour || '#10B981')"></span>
                                        <div class="min-w-0">
                                            <div class="text-[12.5px] font-semibold truncate" x-text="tpl.template_name"></div>
                                            <div class="text-[10.5px] font-mono text-muted-foreground truncate" x-text="tpl.template_code"></div>
                                        </div>
                                        <span class="badge badge-brand ml-1" x-show="tpl.is_default" title="System default template">default</span>
                                        <span class="badge badge-warning ml-1" x-show="tpl.locked" title="Locked — unlock to edit">locked</span>
                                    </div>
                                </td>
                                <td class="table-cell hidden md:table-cell text-[11.5px]">
                                    <span class="badge badge-outline" x-text="freqLabel(tpl.reporting_frequency)"></span>
                                </td>
                                <td class="table-cell hidden md:table-cell text-[11.5px]">
                                    <span class="font-mono tabular-nums" x-text="tpl.columns_enabled + ' / ' + tpl.columns_total"></span>
                                    <span class="text-muted-foreground" x-show="tpl.columns_required > 0"> · <span x-text="tpl.columns_required"></span> req</span>
                                </td>
                                <td class="table-cell hidden lg:table-cell text-[11.5px]">
                                    <span class="tabular-nums" x-text="tpl.submissions_total"></span>
                                    <span class="text-muted-foreground block text-[10.5px]" x-text="tpl.latest_period_rel"></span>
                                </td>
                                <td class="table-cell hidden lg:table-cell text-[11px] text-muted-foreground" x-text="tpl.published_rel"></td>
                                <td class="table-cell text-right">
                                    <span class="badge" :class="statusBadge(tpl.status)" x-text="tpl.status_label || statusLabel(tpl.status)"></span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- ══════════════════════════════════════════════════════════════
         CREATE-TEMPLATE WIZARD (5 steps)
         1 Purpose  ·  2 Start From  ·  3 Columns  ·  4 Review  ·  5 Publish
         ══════════════════════════════════════════════════════════════ --}}
    <template x-if="wizard.open">
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="if (!confirmDialog.open && !columnDialog.open) closeWizard()">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="closeWizard()"></div>
            <div class="relative w-full sm:max-w-2xl bg-background border-l shadow-elevation-5 flex flex-col h-full" @click.stop>

                <header class="flex items-center gap-3 px-5 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v16m8-8H4"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="eyebrow">Step <span x-text="wizard.step"></span> of 5</p>
                        <h2 class="text-[14px] font-bold" x-text="wizardStepTitle()"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="closeWizard()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>

                {{-- Progress --}}
                <div class="px-5 pt-3">
                    <div class="progress">
                        <div class="progress-bar" :style="'width: ' + (wizard.step/5 * 100) + '%'"></div>
                    </div>
                    <div class="flex justify-between text-[10px] font-semibold uppercase tracking-wider text-muted-foreground mt-1.5">
                        <span :class="wizard.step>=1 ? 'text-brand' : ''">Purpose</span>
                        <span :class="wizard.step>=2 ? 'text-brand' : ''">Start</span>
                        <span :class="wizard.step>=3 ? 'text-brand' : ''">Columns</span>
                        <span :class="wizard.step>=4 ? 'text-brand' : ''">Review</span>
                        <span :class="wizard.step>=5 ? 'text-brand' : ''">Save</span>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto px-5 py-5 space-y-4">

                    {{-- STEP 1 — Purpose --}}
                    <template x-if="wizard.step === 1">
                        <div class="space-y-4">
                            <div>
                                <label class="label">Template name</label>
                                <input type="text" class="input mt-1" placeholder="e.g. Weekly VHF Surveillance" x-model="wizard.form.template_name" @input="deriveCode()" maxlength="120">
                                <p class="help-text mt-1">The user-facing name that appears to POE data officers.</p>
                            </div>
                            <div>
                                <label class="label">Template code <span class="text-muted-foreground font-normal">(auto)</span></label>
                                <input type="text" class="input mt-1 font-mono uppercase" x-model="wizard.form.template_code" @input="wizard.form.code_touched=true; wizard.form.template_code = wizard.form.template_code.toUpperCase().replace(/[^A-Z0-9_]/g,'_')" maxlength="60">
                                <p class="help-text mt-1">Uppercase letters, digits, underscores. Used as a stable machine identifier.</p>
                            </div>
                            <div>
                                <label class="label">Description <span class="text-muted-foreground font-normal">(optional)</span></label>
                                <textarea class="textarea mt-1" rows="3" maxlength="500" placeholder="What this report captures and who it is for." x-model="wizard.form.description"></textarea>
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="label">Reporting frequency</label>
                                    <select class="select mt-1" x-model="wizard.form.reporting_frequency">
                                        <template x-for="f in meta.frequencies" :key="f"><option :value="f" x-text="f"></option></template>
                                    </select>
                                </div>
                                <div>
                                    <label class="label">Accent colour</label>
                                    <input type="color" class="input mt-1 !p-0 !h-9" x-model="wizard.form.colour">
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- STEP 2 — Start from --}}
                    <template x-if="wizard.step === 2">
                        <div class="space-y-3">
                            <p class="text-sm text-muted-foreground">Choose how this template starts. You can always edit columns afterwards.</p>
                            <template x-for="opt in startFromOptions" :key="opt.key">
                                <button type="button"
                                        class="w-full text-left p-4 rounded-lg border transition-all"
                                        :class="wizard.form.start_from === opt.key ? 'border-brand bg-brand-soft/40 ring-1 ring-brand/30' : 'border-border hover:border-brand/50'"
                                        @click="wizard.form.start_from = opt.key">
                                    <div class="flex items-start gap-3">
                                        <span class="grid place-items-center h-8 w-8 rounded-md shrink-0" :class="wizard.form.start_from === opt.key ? 'bg-brand text-white' : 'bg-muted text-muted-foreground'">
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="opt.icon"/></svg>
                                        </span>
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm font-semibold" x-text="opt.label"></p>
                                            <p class="text-[11.5px] text-muted-foreground mt-0.5" x-text="opt.help"></p>
                                        </div>
                                    </div>
                                </button>
                            </template>
                        </div>
                    </template>

                    {{-- STEP 3 — Columns (only for CUSTOM, otherwise informational) --}}
                    <template x-if="wizard.step === 3">
                        <div class="space-y-3">
                            <template x-if="wizard.form.start_from !== 'CUSTOM'">
                                <div class="alert alert-info">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    <div>
                                        <p class="alert-title">Skip column setup</p>
                                        <p class="alert-description" x-text="wizard.form.start_from === 'DEFAULT' ? 'We will clone every column from the system default template. You can edit them after save from the template detail sheet.' : 'An empty template will be created. Add columns from the detail sheet after save.'"></p>
                                    </div>
                                </div>
                            </template>
                            <template x-if="wizard.form.start_from === 'CUSTOM'">
                                <div class="space-y-2">
                                    <div class="flex justify-between items-center">
                                        <p class="text-sm font-semibold">Custom columns <span class="text-muted-foreground font-normal">(<span x-text="wizard.form.columns.length"></span>)</span></p>
                                        <button type="button" class="btn btn-outline btn-xs" @click="wizard.form.columns.push(blankColumn())">+ Add column</button>
                                    </div>
                                    <template x-if="wizard.form.columns.length === 0">
                                        <div class="empty-state py-8">
                                            <p class="text-sm text-muted-foreground">No columns yet. Add at least one to continue.</p>
                                        </div>
                                    </template>
                                    <template x-for="(col, idx) in wizard.form.columns" :key="idx">
                                        <div class="border rounded-lg p-3 bg-card space-y-2">
                                            <div class="flex items-center justify-between">
                                                <span class="eyebrow">Column <span x-text="idx + 1"></span></span>
                                                <button type="button" class="btn btn-ghost btn-icon-xs text-critical" @click="wizard.form.columns.splice(idx,1)" title="Remove"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg></button>
                                            </div>
                                            <div class="grid grid-cols-2 gap-2">
                                                <div class="col-span-2">
                                                    <label class="label text-[11px]">Label</label>
                                                    <input type="text" class="input mt-1" x-model="col.column_label" maxlength="160" placeholder="Total fever cases">
                                                </div>
                                                <div class="col-span-2">
                                                    <label class="label text-[11px]">Key <span class="text-muted-foreground">(lowercase_snake)</span></label>
                                                    <input type="text" class="input mt-1 font-mono" x-model="col.column_key" @input="col.column_key = col.column_key.toLowerCase().replace(/[^a-z0-9_]/g,'_')" maxlength="60" placeholder="fever_cases">
                                                </div>
                                                <div>
                                                    <label class="label text-[11px]">Type</label>
                                                    <select class="select mt-1" x-model="col.data_type">
                                                        <template x-for="t in meta.data_types" :key="t"><option :value="t" x-text="t"></option></template>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="label text-[11px]">Roll-up</label>
                                                    <select class="select mt-1" x-model="col.aggregation_fn">
                                                        <template x-for="a in meta.aggregations" :key="a"><option :value="a" x-text="a"></option></template>
                                                    </select>
                                                </div>
                                                <div class="col-span-2 flex gap-3 text-[11.5px]">
                                                    <label class="flex items-center gap-2"><input type="checkbox" x-model="col.is_required"><span>Required</span></label>
                                                    <label class="flex items-center gap-2"><input type="checkbox" x-model="col.is_enabled"><span>Enabled</span></label>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- STEP 4 — Review --}}
                    <template x-if="wizard.step === 4">
                        <div class="space-y-3">
                            <p class="text-sm text-muted-foreground">Confirm the template metadata before save.</p>
                            <div class="card">
                                <div class="card-content space-y-3 !pt-5">
                                    <div class="grid grid-cols-2 gap-3 text-[12px]">
                                        <div><p class="eyebrow">Name</p><p class="font-semibold" x-text="wizard.form.template_name"></p></div>
                                        <div><p class="eyebrow">Code</p><p class="font-mono" x-text="wizard.form.template_code"></p></div>
                                        <div><p class="eyebrow">Frequency</p><p x-text="freqLabel(wizard.form.reporting_frequency)"></p></div>
                                        <div><p class="eyebrow">Start from</p><p x-text="startFromLabel()"></p></div>
                                        <div class="col-span-2" x-show="wizard.form.description"><p class="eyebrow">Description</p><p x-text="wizard.form.description"></p></div>
                                    </div>
                                    <div class="separator separator-h"></div>
                                    <label class="flex items-center gap-2 text-[13px]">
                                        <input type="checkbox" x-model="wizard.form.publish_now">
                                        <span>Publish immediately after save <span class="text-muted-foreground">(visible to POE mobile users on next sync)</span></span>
                                    </label>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- STEP 5 — Save result --}}
                    <template x-if="wizard.step === 5">
                        <div class="space-y-3 text-center py-8">
                            <template x-if="wizard.saving">
                                <div>
                                    <div class="grid place-items-center h-12 w-12 mx-auto rounded-full bg-brand-soft text-brand">
                                        <svg class="h-6 w-6 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 4v4m0 8v4M4 12H0m24 0h-4M6.343 6.343L3.515 3.515m17.97 17.97L18.657 18.657M6.343 17.657l-2.828 2.828m17.97-17.97l-2.828 2.828"/></svg>
                                    </div>
                                    <p class="text-sm font-semibold">Saving…</p>
                                </div>
                            </template>
                            <template x-if="!wizard.saving && wizard.savedId">
                                <div>
                                    <div class="grid place-items-center h-12 w-12 mx-auto rounded-full bg-success-soft text-success">
                                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7"/></svg>
                                    </div>
                                    <p class="text-sm font-semibold">Template created.</p>
                                    <p class="text-muted-foreground text-[12.5px]" x-text="wizard.form.publish_now ? 'Published and live to POE mobile users.' : 'Saved as DRAFT — publish from the detail sheet when ready.'"></p>
                                </div>
                            </template>
                            <template x-if="!wizard.saving && wizard.error">
                                <div>
                                    <div class="grid place-items-center h-12 w-12 mx-auto rounded-full bg-critical-soft text-critical">
                                        <svg class="h-6 w-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z"/></svg>
                                    </div>
                                    <p class="text-sm font-semibold text-critical">Save failed</p>
                                    <p class="text-muted-foreground text-[12.5px]" x-text="wizard.error"></p>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                <footer class="flex items-center justify-between gap-2 px-5 py-3 border-t bg-muted/20">
                    <button class="btn btn-ghost btn-sm" @click="prevStep()" :disabled="wizard.step === 1 || wizard.saving || wizard.savedId">Back</button>
                    <div class="flex gap-2">
                        <button class="btn btn-outline btn-sm" @click="closeWizard()">Cancel</button>
                        <button class="btn btn-brand btn-sm" x-show="wizard.step < 4" @click="nextStep()" :disabled="!canAdvance()">Next</button>
                        <button class="btn btn-brand btn-sm" x-show="wizard.step === 4 && !wizard.savedId" @click="submitWizard()" :disabled="wizard.saving">
                            <span x-show="!wizard.saving" x-text="wizard.form.publish_now ? 'Save & publish' : 'Save draft'"></span>
                            <span x-show="wizard.saving">Saving…</span>
                        </button>
                        <button class="btn btn-brand btn-sm" x-show="wizard.step === 5 && wizard.savedId && !wizard.saving" @click="openSavedAndClose()">Open template</button>
                    </div>
                </footer>
            </div>
        </div>
    </template>

    {{-- ══════════════════════════════════════════════════════════════
         TEMPLATE DETAIL SHEET (sub-tabs: Overview · Columns · Versions · Lifecycle)
         ══════════════════════════════════════════════════════════════ --}}
    <template x-if="detail.open">
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="if (!confirmDialog.open && !columnDialog.open && !wizard.open) detail.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="detail.open=false"></div>
            <div class="relative w-full sm:max-w-4xl bg-background border-l shadow-elevation-5 flex flex-col h-full" @click.stop>

                <header class="flex items-center gap-3 px-5 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg shrink-0" :style="'background:' + ((detail.template?.colour || '#10B981') + '20') + '; color:' + (detail.template?.colour || '#10B981')">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="eyebrow flex items-center gap-1.5">
                            <span class="font-mono" x-text="detail.template?.template_code || '…'"></span>
                            <span class="badge ml-1" :class="statusBadge(detail.template?.status)" x-text="statusLabel(detail.template?.status)"></span>
                            <span class="badge badge-outline ml-1" x-show="detail.template?.reporting_frequency" x-text="freqLabel(detail.template?.reporting_frequency)"></span>
                            <span class="badge badge-warning ml-1" x-show="detail.template?.locked">locked</span>
                            <span class="badge badge-brand ml-1" x-show="detail.template?.is_default">default</span>
                        </p>
                        <h2 class="text-[14px] font-bold truncate" x-text="detail.template?.template_name || 'Loading…'"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="detail.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>

                {{-- sub-tabs --}}
                <div class="border-b">
                    <div class="tabs-list w-full overflow-x-auto px-2">
                        <template x-for="t in ['overview','columns','versions','lifecycle']" :key="t">
                            <button class="tabs-trigger flex-shrink-0 capitalize" :data-state="detail.tab===t?'active':'inactive'" @click="detail.tab=t">
                                <span x-text="t"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-show="t==='columns'" x-text="detail.columns.length"></span>
                                <span class="badge badge-outline ml-1 px-1.5 py-0 text-[9.5px]" x-show="t==='versions' && detail.stats?.submissions_versions" x-text="(detail.stats?.submissions_versions||[]).length"></span>
                            </button>
                        </template>
                    </div>
                </div>

                <div class="flex-1 overflow-y-auto px-5 py-5 space-y-5">
                    <template x-if="detail.loading"><p class="text-sm text-muted-foreground">Loading template…</p></template>

                    {{-- ── OVERVIEW ── --}}
                    <template x-if="!detail.loading && detail.tab === 'overview'">
                        <div class="space-y-4">
                            <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div class="kpi"><p class="kpi-label">Columns</p><p class="kpi-value tabular-nums" x-text="detail.columns.length"></p></div>
                                <div class="kpi"><p class="kpi-label">Enabled</p><p class="kpi-value tabular-nums text-success" x-text="detail.columns.filter(c=>c.is_enabled).length"></p></div>
                                <div class="kpi"><p class="kpi-label">Required</p><p class="kpi-value tabular-nums" x-text="detail.columns.filter(c=>c.is_required).length"></p></div>
                                <div class="kpi"><p class="kpi-label">Submissions</p><p class="kpi-value tabular-nums" x-text="detail.stats?.submissions_count ?? 0"></p></div>
                            </section>
                            <div class="card">
                                <div class="card-content space-y-3 !pt-5">
                                    <div class="flex items-center justify-between">
                                        <div>
                                            <p class="eyebrow">Template meta</p>
                                            <p class="text-[11.5px] text-muted-foreground">Name, description, frequency, colour.</p>
                                        </div>
                                        @if ($canWrite)
                                        <button class="btn btn-outline btn-xs" @click="editMeta.editing = !editMeta.editing" :disabled="detail.template?.locked"><span x-text="editMeta.editing ? 'Cancel' : 'Edit'"></span></button>
                                        @endif
                                    </div>
                                    <template x-if="!editMeta.editing">
                                        <div class="grid grid-cols-2 gap-3 text-[12px]">
                                            <div><p class="eyebrow">Name</p><p x-text="detail.template?.template_name"></p></div>
                                            <div><p class="eyebrow">Code</p><p class="font-mono" x-text="detail.template?.template_code"></p></div>
                                            <div><p class="eyebrow">Frequency</p><p x-text="freqLabel(detail.template?.reporting_frequency)"></p></div>
                                            <div><p class="eyebrow">Version</p><p x-text="detail.template?.version"></p></div>
                                            <div class="col-span-2"><p class="eyebrow">Description</p><p class="text-muted-foreground" x-text="detail.template?.description || '—'"></p></div>
                                            <div><p class="eyebrow">Published</p><p class="text-muted-foreground" x-text="detail.template?.published_at || '—'"></p></div>
                                            <div><p class="eyebrow">Retired</p><p class="text-muted-foreground" x-text="detail.template?.retired_at || '—'"></p></div>
                                            <div><p class="eyebrow">Locked at</p><p class="text-muted-foreground" x-text="detail.template?.locked_at || '—'"></p></div>
                                        </div>
                                    </template>
                                    <template x-if="editMeta.editing">
                                        <form @submit.prevent="submitEditMeta()" class="space-y-3">
                                            <div>
                                                <label class="label">Name</label>
                                                <input class="input mt-1" x-model="editMeta.form.template_name" maxlength="120" required>
                                            </div>
                                            <div>
                                                <label class="label">Description</label>
                                                <textarea class="textarea mt-1" rows="2" maxlength="500" x-model="editMeta.form.description"></textarea>
                                            </div>
                                            <div class="grid grid-cols-2 gap-3">
                                                <div>
                                                    <label class="label">Frequency</label>
                                                    <select class="select mt-1" x-model="editMeta.form.reporting_frequency">
                                                        <template x-for="f in meta.frequencies" :key="f"><option :value="f" x-text="f"></option></template>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="label">Colour</label>
                                                    <input type="color" class="input !p-0 !h-9 mt-1" x-model="editMeta.form.colour">
                                                </div>
                                            </div>
                                            <div class="flex justify-end gap-2">
                                                <button type="button" class="btn btn-ghost btn-sm" @click="editMeta.editing=false">Cancel</button>
                                                <button type="submit" class="btn btn-brand btn-sm" :disabled="editMeta.saving"><span x-show="!editMeta.saving">Save</span><span x-show="editMeta.saving">Saving…</span></button>
                                            </div>
                                        </form>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- ── COLUMNS ── --}}
                    <template x-if="!detail.loading && detail.tab === 'columns'">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between flex-wrap gap-2">
                                <div>
                                    <p class="text-sm font-semibold">Columns <span class="text-muted-foreground font-normal">(<span x-text="detail.columns.filter(c=>c.is_enabled).length"></span> enabled / <span x-text="detail.columns.length"></span> total)</span></p>
                                    <p class="help-text">Core columns are protected and cannot be disabled or deleted. Toggle visibility and dashboard / report inclusion per column.</p>
                                </div>
                                @if ($canWrite)
                                <button class="btn btn-brand btn-sm" @click="openColumnDialog()" :disabled="detail.template?.locked">+ Add column</button>
                                @endif
                            </div>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead class="table-head"><tr>
                                        <th class="table-head-th">Column</th>
                                        <th class="table-head-th hidden md:table-cell">Type</th>
                                        <th class="table-head-th hidden md:table-cell">Category</th>
                                        <th class="table-head-th hidden lg:table-cell">Roll-up</th>
                                        <th class="table-head-th text-center">Required</th>
                                        <th class="table-head-th text-center">Enabled</th>
                                        <th class="table-head-th text-right">Actions</th>
                                    </tr></thead>
                                    <tbody class="table-body">
                                        <template x-if="detail.columns.length === 0"><tr><td colspan="7" class="table-cell"><div class="empty-state py-8"><p class="text-sm text-muted-foreground">No columns on this template.</p></div></td></tr></template>
                                        <template x-for="col in detail.columns" :key="col.id">
                                            <tr class="table-row">
                                                <td class="table-cell">
                                                    <div class="font-semibold text-[12.5px]" x-text="col.column_label"></div>
                                                    <div class="font-mono text-[10.5px] text-muted-foreground" x-text="col.column_key"></div>
                                                </td>
                                                <td class="table-cell hidden md:table-cell text-[11.5px]">
                                                    <span class="badge badge-outline" x-text="col.data_type"></span>
                                                </td>
                                                <td class="table-cell hidden md:table-cell text-[11.5px]">
                                                    <span class="badge badge-secondary" x-text="col.category"></span>
                                                    <span class="badge badge-brand ml-1" x-show="col.is_core">core</span>
                                                </td>
                                                <td class="table-cell hidden lg:table-cell text-[11.5px] font-mono" x-text="col.aggregation_fn"></td>
                                                <td class="table-cell text-center">
                                                    <span class="badge" :class="col.is_required ? 'badge-warning' : 'badge-outline'" x-text="col.is_required ? 'yes' : 'no'"></span>
                                                </td>
                                                <td class="table-cell text-center">
                                                    @if ($canWrite)
                                                    <button class="btn btn-ghost btn-icon-xs" @click="toggleColumnEnabled(col)" :disabled="detail.template?.locked || (col.is_core && col.is_enabled)" :title="col.is_core ? 'Core column — cannot disable' : (col.is_enabled ? 'Disable' : 'Enable')">
                                                        <span class="status-dot" :class="col.is_enabled ? 'status-dot-live' : 'bg-muted-foreground/40'"></span>
                                                    </button>
                                                    @else
                                                    <span class="status-dot" :class="col.is_enabled ? 'status-dot-live' : 'bg-muted-foreground/40'"></span>
                                                    @endif
                                                </td>
                                                <td class="table-cell text-right">
                                                    @if ($canWrite)
                                                    <button class="btn btn-ghost btn-icon-xs" @click="openColumnDialog(col)" :disabled="detail.template?.locked" title="Edit"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></button>
                                                    <button class="btn btn-ghost btn-icon-xs text-critical" @click="confirmDeleteColumn(col)" :disabled="col.is_core || detail.template?.locked" title="Delete"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg></button>
                                                    @endif
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>

                    {{-- ── VERSIONS ── --}}
                    <template x-if="!detail.loading && detail.tab === 'versions'">
                        <div class="space-y-3">
                            <div class="alert alert-info">
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <div>
                                    <p class="alert-title">Version audit</p>
                                    <p class="alert-description">Current template version is <span class="font-semibold" x-text="detail.template?.version"></span>. Versions under which submissions were filed are listed below. Bump version via the Lifecycle tab.</p>
                                </div>
                            </div>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead class="table-head"><tr>
                                        <th class="table-head-th">Version</th>
                                        <th class="table-head-th">Submissions</th>
                                        <th class="table-head-th">First period</th>
                                        <th class="table-head-th">Last period</th>
                                    </tr></thead>
                                    <tbody class="table-body">
                                        <template x-if="!(detail.stats?.submissions_versions||[]).length"><tr><td colspan="4" class="table-cell"><div class="empty-state py-8"><p class="text-sm text-muted-foreground">No submissions yet.</p></div></td></tr></template>
                                        <template x-for="v in (detail.stats?.submissions_versions || [])" :key="v.version">
                                            <tr class="table-row">
                                                <td class="table-cell"><span class="badge badge-secondary" x-text="'v' + v.version"></span></td>
                                                <td class="table-cell tabular-nums" x-text="v.count"></td>
                                                <td class="table-cell text-muted-foreground" x-text="v.first_period || '—'"></td>
                                                <td class="table-cell text-muted-foreground" x-text="v.last_period || '—'"></td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>

                    {{-- ── LIFECYCLE ── --}}
                    <template x-if="!detail.loading && detail.tab === 'lifecycle'">
                        <div class="space-y-3">
                            <p class="text-sm text-muted-foreground">Lifecycle actions apply to the entire template. Actions that cannot run in the current state are disabled with a reason shown on hover.</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                @foreach([
                                    ['PUBLISH',  'Publish',  'Make this template visible to POE mobile users on next sync. Requires at least one enabled column.', 'M5 13l4 4L19 7', 'btn-brand'],
                                    ['RETIRE',   'Retire',   'Removes from mobile visibility; keeps submissions queryable. Default template cannot be retired.',  'M18 6L6 18M6 6l12 12', 'btn-outline'],
                                    ['LOCK',     'Lock',     'Freeze editing on meta and columns. Useful before a reporting window.',                           'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z M6 11V7a4 4 0 018 0v4', 'btn-outline'],
                                    ['UNLOCK',   'Unlock',   'Resume edits on meta and columns.',                                                                 'M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z', 'btn-outline'],
                                    ['BUMP_VERSION', 'Bump version', 'Increment template version counter for audit purposes. Does not change column schema.',     'M7 11l5-5m0 0l5 5m-5-5v12', 'btn-ghost'],
                                    ['DELETE',   'Delete',   'Soft-delete. Submissions remain for audit but the template is hidden. Cascade-confirm required if submissions exist.', 'M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22', 'btn-destructive'],
                                ] as $btn)
                                <button class="btn {{ $btn[4] }} justify-start" @click="runLifecycle('{{ $btn[0] }}')" :disabled="!canRunLifecycle('{{ $btn[0] }}')" :title="lifecycleReason('{{ $btn[0] }}')">
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="{{ $btn[3] }}"/></svg>
                                    <div class="text-left">
                                        <div class="text-[12.5px] font-semibold">{{ $btn[1] }}</div>
                                        <div class="text-[10.5px] font-normal opacity-80">{{ $btn[2] }}</div>
                                    </div>
                                </button>
                                @endforeach
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </template>

    {{-- ══════════════════════════════════════════════════════════════
         COLUMN CREATE / EDIT DIALOG
         ══════════════════════════════════════════════════════════════ --}}
    <template x-if="columnDialog.open">
        <div class="fixed inset-0 z-[60] grid place-items-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="columnDialog.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="columnDialog.open=false"></div>
            <form class="relative w-full max-w-lg bg-background rounded-xl border shadow-elevation-5 flex flex-col max-h-[90vh]" @click.stop @submit.prevent="submitColumnDialog()">
                <header class="flex items-center justify-between px-5 py-3 border-b">
                    <h3 class="text-[14px] font-bold" x-text="columnDialog.col?.id ? 'Edit column' : 'New column'"></h3>
                    <button type="button" class="btn btn-ghost btn-icon-xs" @click="columnDialog.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-5 py-4 space-y-3">
                    <div>
                        <label class="label">Label</label>
                        <input type="text" class="input mt-1" x-model="columnDialog.form.column_label" maxlength="160" required>
                    </div>
                    <div>
                        <label class="label">Key</label>
                        <input type="text" class="input mt-1 font-mono" x-model="columnDialog.form.column_key" :disabled="columnDialog.col?.id" @input="columnDialog.form.column_key = columnDialog.form.column_key.toLowerCase().replace(/[^a-z0-9_]/g,'_')" maxlength="60" required>
                        <p class="help-text mt-1">Lowercase, digits, underscores. Cannot be changed after creation.</p>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="label">Type</label>
                            <select class="select mt-1" x-model="columnDialog.form.data_type" :disabled="columnDialog.col?.id && columnDialog.col?.is_core">
                                <template x-for="t in meta.data_types" :key="t"><option :value="t" x-text="t"></option></template>
                            </select>
                        </div>
                        <div>
                            <label class="label">Roll-up</label>
                            <select class="select mt-1" x-model="columnDialog.form.aggregation_fn">
                                <template x-for="a in meta.aggregations" :key="a"><option :value="a" x-text="a"></option></template>
                            </select>
                        </div>
                        <div>
                            <label class="label">Category</label>
                            <select class="select mt-1" x-model="columnDialog.form.category">
                                <template x-for="c in meta.categories" :key="c"><option :value="c" x-text="c"></option></template>
                            </select>
                        </div>
                        <div>
                            <label class="label">Display order</label>
                            <input type="number" class="input mt-1" x-model.number="columnDialog.form.display_order" min="0" step="1">
                        </div>
                    </div>
                    <div>
                        <label class="label">Help text <span class="text-muted-foreground font-normal">(optional)</span></label>
                        <textarea class="textarea mt-1" rows="2" maxlength="500" x-model="columnDialog.form.help_text"></textarea>
                    </div>
                    <div>
                        <label class="label">Placeholder <span class="text-muted-foreground font-normal">(optional)</span></label>
                        <input type="text" class="input mt-1" x-model="columnDialog.form.placeholder" maxlength="160">
                    </div>
                    <div class="flex flex-wrap gap-4 text-[12.5px]">
                        <label class="flex items-center gap-2"><input type="checkbox" x-model="columnDialog.form.is_required"><span>Required</span></label>
                        <label class="flex items-center gap-2"><input type="checkbox" x-model="columnDialog.form.is_enabled"><span>Enabled</span></label>
                        <label class="flex items-center gap-2"><input type="checkbox" x-model="columnDialog.form.dashboard_visible"><span>Show on dashboard</span></label>
                        <label class="flex items-center gap-2"><input type="checkbox" x-model="columnDialog.form.report_visible"><span>Show in reports</span></label>
                    </div>
                    <template x-if="columnDialog.error">
                        <div class="alert alert-critical">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z"/></svg>
                            <div><p class="alert-title">Save failed</p><p class="alert-description" x-text="columnDialog.error"></p></div>
                        </div>
                    </template>
                </div>
                <footer class="flex items-center justify-end gap-2 px-5 py-3 border-t">
                    <button type="button" class="btn btn-ghost btn-sm" @click="columnDialog.open=false">Cancel</button>
                    <button type="submit" class="btn btn-brand btn-sm" :disabled="columnDialog.saving">
                        <span x-show="!columnDialog.saving">Save column</span><span x-show="columnDialog.saving">Saving…</span>
                    </button>
                </footer>
            </form>
        </div>
    </template>

    {{-- ══════════════════════════════════════════════════════════════
         CONFIRM DIALOG — shared for delete / retire / publish / lock
         ══════════════════════════════════════════════════════════════ --}}
    <template x-if="confirmDialog.open">
        <div class="fixed inset-0 z-[60] grid place-items-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="confirmDialog.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="confirmDialog.open=false"></div>
            <div class="relative w-full max-w-md bg-background rounded-xl border shadow-elevation-5 p-5 space-y-3" @click.stop>
                <div class="flex items-start gap-3">
                    <div class="grid place-items-center h-9 w-9 rounded-full shrink-0" :class="confirmDialog.variant === 'destructive' ? 'bg-critical-soft text-critical' : 'bg-brand-soft text-brand-ink'">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z"/></svg>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-[14px] font-bold" x-text="confirmDialog.title"></h3>
                        <p class="text-[12.5px] text-muted-foreground mt-1" x-text="confirmDialog.body"></p>
                    </div>
                </div>
                <template x-if="confirmDialog.requiresToken">
                    <div>
                        <label class="label">Type <span class="font-mono text-brand" x-text="confirmDialog.token"></span> to confirm</label>
                        <input type="text" class="input mt-1 font-mono" x-model="confirmDialog.typed" placeholder="">
                    </div>
                </template>
                <div class="flex justify-end gap-2 pt-2">
                    <button class="btn btn-ghost btn-sm" @click="confirmDialog.open=false">Cancel</button>
                    <button class="btn btn-sm" :class="confirmDialog.variant === 'destructive' ? 'btn-destructive' : 'btn-brand'" :disabled="confirmDialog.saving || (confirmDialog.requiresToken && confirmDialog.typed !== confirmDialog.token)" @click="confirmDialog.onConfirm && confirmDialog.onConfirm()">
                        <span x-show="!confirmDialog.saving" x-text="confirmDialog.cta"></span>
                        <span x-show="confirmDialog.saving">Working…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    {{-- ── Inline toast ───────────────────────────────────────────── --}}
    <div class="fixed inset-x-0 bottom-6 z-[70] flex justify-center pointer-events-none px-4" x-show="flash.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="flash.variant === 'danger' ? 'toast-destructive' : (flash.variant === 'warning' ? 'toast-warning' : 'toast-success')">
            <div><p class="toast-title" x-text="flash.title"></p><p class="toast-description" x-text="flash.body"></p></div>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.__IDSR_STUDIO__ = {
    meta:       @json($meta ?? []),
    canWrite:   @json((bool) ($canWrite ?? false)),
    scope:      @json($scope ?? []),
    csrf:       @json(csrf_token()),
    routes: {
        data:            @json(url('/admin/aggregated/studio/data')),
        templateShow:    @json(url('/admin/aggregated/studio/template')),            // + /{id}
        templateStore:   @json(url('/admin/aggregated/studio/template')),
        lifecycle:       @json(url('/admin/aggregated/studio/template')),            // + /{id}/lifecycle
        columnsBulk:     @json(url('/admin/aggregated/studio/template')),            // + /{id}/columns/bulk
        columnStore:     @json(url('/admin/aggregated/studio/template')),            // + /{id}/columns
        columnUpdate:    @json(url('/admin/aggregated/studio/columns')),             // + /{colId}
        columnDelete:    @json(url('/admin/aggregated/studio/columns')),             // + /{colId}
    },
};

function idsrStudio() {
    const C = window.__IDSR_STUDIO__;

    const tabs = [
        { key: 'ALL',       label: 'All' },
        { key: 'DRAFT',     label: 'Draft' },
        { key: 'PUBLISHED', label: 'Published' },
        { key: 'RETIRED',   label: 'Retired' },
    ];

    const startFromOptions = [
        { key: 'DEFAULT', label: 'Clone system default',  icon: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2M9 12h6m-3-3v6', help: 'Start with every column from the pre-configured IDSR default. Safe choice — edit afterwards.' },
        { key: 'EMPTY',   label: 'Empty template',         icon: 'M12 4v16m8-8H4', help: 'No columns. Add them one-by-one from the detail sheet after save.' },
        { key: 'CUSTOM',  label: 'Define columns now',     icon: 'M3 4h18M3 10h18M3 16h12', help: 'Type your own column list inline before save.' },
    ];

    return {
        // state
        tabs, startFromOptions,
        meta:      C.meta,
        canWrite:  C.canWrite,
        scope:     C.scope,
        loading:   false,
        templates: [],
        filters:   { status: 'ALL', q: '' },
        counts:    { total: 0, published: 0, draft: 0, retired: 0, locked: 0, ALL: 0, DRAFT: 0, PUBLISHED: 0, RETIRED: 0 },
        flash:     { open: false, variant: 'success', title: '', body: '', timer: null },

        wizard: {
            open: false, step: 1, saving: false, savedId: null, error: '',
            form: {
                template_name: '', template_code: '', code_touched: false, description: '',
                reporting_frequency: 'WEEKLY', colour: '#10B981',
                start_from: 'DEFAULT', columns: [],
                publish_now: false,
            },
        },

        detail: { open: false, id: null, loading: false, tab: 'overview', template: null, columns: [], stats: null },

        editMeta: { editing: false, saving: false, form: {} },

        columnDialog: {
            open: false, col: null, saving: false, error: '',
            form: {
                column_key: '', column_label: '', category: 'CUSTOM', data_type: 'INTEGER',
                aggregation_fn: 'SUM', is_required: false, is_enabled: true,
                dashboard_visible: true, report_visible: true,
                placeholder: '', help_text: '', display_order: 0,
            },
        },

        confirmDialog: {
            open: false, title: '', body: '', cta: 'Confirm', variant: 'default',
            requiresToken: false, token: '', typed: '', saving: false, onConfirm: null,
        },

        async boot() { await this.loadTemplates(); },

        // Resilient JSON fetch: rejects HTML responses (auth redirects) and
        // surfaces Laravel validation payloads as readable errors.
        async jsonFetch(url, opts = {}) {
            const headers = Object.assign({
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            }, opts.headers || {});
            if (opts.method && ['POST','PATCH','PUT','DELETE'].includes(opts.method.toUpperCase())) {
                headers['X-CSRF-TOKEN'] = C.csrf;
                if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
                    headers['Content-Type'] = 'application/json';
                    opts.body = JSON.stringify(opts.body);
                }
            }
            const res = await fetch(url, { credentials: 'same-origin', ...opts, headers });
            const ct = (res.headers.get('content-type') || '').toLowerCase();
            if (!ct.includes('application/json')) {
                if (res.status === 401 || res.status === 419) {
                    throw new Error('Session expired. Reload the page to sign in again.');
                }
                throw new Error('Unexpected non-JSON response (HTTP ' + res.status + ').');
            }
            const body = await res.json().catch(() => ({}));
            if (!res.ok) {
                const msg = body?.message || ('HTTP ' + res.status);
                throw new Error(msg);
            }
            return body;
        },

        statusBadge(s) {
            switch ((s || '').toUpperCase()) {
                case 'PUBLISHED': return 'badge-success';
                case 'DRAFT':     return 'badge-warning';
                case 'RETIRED':   return 'badge-outline';
                case 'ARCHIVED':  return 'badge-secondary';
                default:          return 'badge-outline';
            }
        },
        statusLabel(s) {
            const map = { PUBLISHED: 'Published', DRAFT: 'Draft', RETIRED: 'Retired', ARCHIVED: 'Archived' };
            return map[(s || '').toUpperCase()] || (s || '');
        },
        freqLabel(s) {
            const map = { DAILY: 'Daily', WEEKLY: 'Weekly', MONTHLY: 'Monthly', QUARTERLY: 'Quarterly', AD_HOC: 'Ad-hoc', EVENT: 'Event' };
            return map[(s || '').toUpperCase()] || (s || '');
        },

        // ─── Data ─────────────────────────────────────────────────────
        async loadTemplates() {
            this.loading = true;
            try {
                const qs = new URLSearchParams();
                if (this.filters.status && this.filters.status !== 'ALL') qs.set('status', this.filters.status);
                if (this.filters.q) qs.set('q', this.filters.q);
                const body = await this.jsonFetch(C.routes.data + '?' + qs.toString());
                this.templates = body?.data?.templates || [];
                this.recomputeCounts();
                this.setPageMeta(this.templates.length);
            } catch (e) {
                this.toast('danger', 'Load failed', e.message || 'Could not load templates.');
                this.templates = [];
            } finally {
                this.loading = false;
            }
        },

        recomputeCounts() {
            const c = { total: 0, published: 0, draft: 0, retired: 0, locked: 0, ALL: 0, DRAFT: 0, PUBLISHED: 0, RETIRED: 0 };
            for (const t of this.templates) {
                c.total++; c.ALL++;
                if (t.status === 'PUBLISHED')  { c.published++; c.PUBLISHED++; }
                else if (t.status === 'DRAFT') { c.draft++;     c.DRAFT++;     }
                else if (t.status === 'RETIRED'){c.retired++;   c.RETIRED++;   }
                if (t.locked) c.locked++;
            }
            this.counts = c;
        },

        // ─── Wizard ───────────────────────────────────────────────────
        openWizard() {
            this.wizard.open = true;
            this.wizard.step = 1;
            this.wizard.saving = false;
            this.wizard.savedId = null;
            this.wizard.error = '';
            this.wizard.form = {
                template_name: '', template_code: '', code_touched: false, description: '',
                reporting_frequency: 'WEEKLY', colour: '#10B981',
                start_from: 'DEFAULT', columns: [], publish_now: false,
            };
        },
        closeWizard() {
            const savedId = this.wizard.savedId;
            this.wizard.open = false;
            this.wizard.step = 1;
            this.wizard.savedId = null;
            this.wizard.error = '';
            this.wizard.saving = false;
            if (savedId) this.loadTemplates();
        },
        openSavedAndClose() {
            const id = this.wizard.savedId;
            this.closeWizard();
            if (id) this.openDetail(id);
        },
        deriveCode() {
            if (this.wizard.form.code_touched) return;
            const name = (this.wizard.form.template_name || '').trim();
            this.wizard.form.template_code = name
                .toUpperCase()
                .replace(/[^A-Z0-9]+/g, '_')
                .replace(/^_+|_+$/g, '')
                .slice(0, 60);
        },
        wizardStepTitle() {
            return ['Purpose','Start from','Columns','Review','Save'][this.wizard.step - 1] || '';
        },
        startFromLabel() {
            const opt = this.startFromOptions.find(o => o.key === this.wizard.form.start_from);
            return opt ? opt.label : this.wizard.form.start_from;
        },
        canAdvance() {
            if (this.wizard.step === 1) {
                return !!(this.wizard.form.template_name && this.wizard.form.template_code && /^[A-Z0-9_]+$/.test(this.wizard.form.template_code));
            }
            if (this.wizard.step === 3 && this.wizard.form.start_from === 'CUSTOM') {
                const cols = this.wizard.form.columns;
                if (!cols.length) return false;
                for (const c of cols) {
                    if (!c.column_label || !c.column_key) return false;
                    if (!/^[a-z][a-z0-9_]{1,58}$/.test(c.column_key)) return false;
                }
            }
            return true;
        },
        nextStep()  { if (this.canAdvance() && this.wizard.step < 5) this.wizard.step++; },
        prevStep()  { if (this.wizard.step > 1) this.wizard.step--; },
        blankColumn() {
            return {
                column_key: '', column_label: '', category: 'CUSTOM', data_type: 'INTEGER',
                aggregation_fn: 'SUM', is_required: false, is_enabled: true,
                dashboard_visible: true, report_visible: true,
            };
        },
        async submitWizard() {
            if (this.wizard.saving) return;
            this.wizard.step = 5;
            this.wizard.saving = true;
            this.wizard.error = '';
            try {
                const payload = {
                    country_code:        this.scope?.country_code || 'UG',
                    template_name:       this.wizard.form.template_name,
                    template_code:       this.wizard.form.template_code,
                    description:         this.wizard.form.description || '',
                    reporting_frequency: this.wizard.form.reporting_frequency,
                    colour:              this.wizard.form.colour,
                    start_from:          this.wizard.form.start_from,
                    publish_now:         !!this.wizard.form.publish_now,
                };
                if (this.wizard.form.start_from === 'CUSTOM') {
                    payload.columns = this.wizard.form.columns;
                }
                const body = await this.jsonFetch(C.routes.templateStore, { method: 'POST', body: payload });
                this.wizard.savedId = body?.data?.template?.id || body?.meta?.server_id || null;
                this.toast('success', 'Template saved', this.wizard.form.publish_now ? 'Published to mobile users.' : 'Draft saved.');
            } catch (e) {
                this.wizard.error = e.message || 'Server error.';
            } finally {
                this.wizard.saving = false;
            }
        },

        // ─── Detail sheet ─────────────────────────────────────────────
        async openDetail(id) {
            this.detail.open = true;
            this.detail.id = id;
            this.detail.tab = 'overview';
            this.detail.loading = true;
            this.detail.template = null;
            this.detail.columns = [];
            this.detail.stats = null;
            this.editMeta.editing = false;
            try {
                const body = await this.jsonFetch(C.routes.templateShow + '/' + id);
                this.detail.template = body?.data?.template || null;
                this.detail.columns  = body?.data?.columns  || [];
                this.detail.stats    = body?.data?.stats    || null;
                this.editMeta.form = {
                    template_name: this.detail.template?.template_name || '',
                    description:   this.detail.template?.description || '',
                    reporting_frequency: this.detail.template?.reporting_frequency || 'WEEKLY',
                    colour: this.detail.template?.colour || '#10B981',
                };
            } catch (e) {
                this.toast('danger', 'Load failed', e.message);
                this.detail.open = false;
            } finally {
                this.detail.loading = false;
            }
        },

        async submitEditMeta() {
            if (!this.detail.template || !this.canWrite || this.editMeta.saving) return;
            this.editMeta.saving = true;
            try {
                const body = await this.jsonFetch(C.routes.templateShow + '/' + this.detail.id, {
                    method: 'PATCH',
                    body: this.editMeta.form,
                });
                this.detail.template = { ...this.detail.template, ...(body?.data?.template || {}) };
                this.editMeta.editing = false;
                this.toast('success', 'Saved', 'Template meta updated.');
                this.loadTemplates();
            } catch (e) {
                this.toast('danger', 'Save failed', e.message);
            } finally {
                this.editMeta.saving = false;
            }
        },

        // ─── Column ops ───────────────────────────────────────────────
        openColumnDialog(col = null) {
            this.columnDialog.open = true;
            this.columnDialog.col = col;
            this.columnDialog.error = '';
            this.columnDialog.saving = false;
            if (col) {
                this.columnDialog.form = {
                    column_key:        col.column_key,
                    column_label:      col.column_label,
                    category:          col.category || 'CUSTOM',
                    data_type:         col.data_type || 'INTEGER',
                    aggregation_fn:    col.aggregation_fn || 'SUM',
                    is_required:       !!col.is_required,
                    is_enabled:        !!col.is_enabled,
                    dashboard_visible: !!col.dashboard_visible,
                    report_visible:    !!col.report_visible,
                    placeholder:       col.placeholder || '',
                    help_text:         col.help_text || '',
                    display_order:     col.display_order || 0,
                };
            } else {
                const nextOrder = this.detail.columns.length
                    ? Math.max(...this.detail.columns.map(c => c.display_order || 0)) + 1
                    : 0;
                this.columnDialog.form = {
                    column_key: '', column_label: '', category: 'CUSTOM', data_type: 'INTEGER',
                    aggregation_fn: 'SUM', is_required: false, is_enabled: true,
                    dashboard_visible: true, report_visible: true,
                    placeholder: '', help_text: '', display_order: nextOrder,
                };
            }
        },

        async submitColumnDialog() {
            if (this.columnDialog.saving) return;
            this.columnDialog.saving = true;
            this.columnDialog.error = '';
            try {
                const isEdit = !!this.columnDialog.col?.id;
                const url = isEdit
                    ? C.routes.columnUpdate + '/' + this.columnDialog.col.id
                    : C.routes.columnStore + '/' + this.detail.id + '/columns';
                const body = await this.jsonFetch(url, { method: isEdit ? 'PATCH' : 'POST', body: this.columnDialog.form });
                this.columnDialog.open = false;
                await this.reloadDetail();
                this.loadTemplates();
                this.toast('success', isEdit ? 'Column updated' : 'Column added', body?.data?.column?.column_label || '');
            } catch (e) {
                this.columnDialog.error = e.message;
            } finally {
                this.columnDialog.saving = false;
            }
        },

        async toggleColumnEnabled(col) {
            if (this.detail.template?.locked) return;
            if (col.is_core && col.is_enabled) return;
            try {
                await this.jsonFetch(C.routes.columnUpdate + '/' + col.id, {
                    method: 'PATCH',
                    body: { is_enabled: !col.is_enabled },
                });
                col.is_enabled = !col.is_enabled;
                this.loadTemplates();
            } catch (e) {
                this.toast('danger', 'Toggle failed', e.message);
            }
        },

        confirmDeleteColumn(col) {
            this.confirmDialog = {
                open: true, variant: 'destructive',
                title: 'Delete column?',
                body: `Remove "${col.column_label}" (${col.column_key}) from this template. Historical submission values stay intact.`,
                cta: 'Delete column', requiresToken: false, token: '', typed: '', saving: false,
                onConfirm: async () => {
                    if (this.confirmDialog.saving) return;
                    this.confirmDialog.saving = true;
                    try {
                        await this.jsonFetch(C.routes.columnDelete + '/' + col.id, { method: 'DELETE' });
                        this.confirmDialog.open = false;
                        await this.reloadDetail();
                        this.loadTemplates();
                        this.toast('success', 'Column removed', '');
                    } catch (e) {
                        this.toast('danger', 'Delete failed', e.message);
                    } finally {
                        this.confirmDialog.saving = false;
                    }
                },
            };
        },

        // ─── Lifecycle ────────────────────────────────────────────────
        enabledColumnsCount() {
            return (this.detail.columns || []).filter(c => c.is_enabled).length;
        },
        canRunLifecycle(action) {
            if (!this.canWrite) return false;
            const t = this.detail.template;
            if (!t) return false;
            switch (action) {
                case 'PUBLISH':      return t.status !== 'PUBLISHED' && !t.locked && this.enabledColumnsCount() > 0;
                case 'RETIRE':       return t.status === 'PUBLISHED' && !t.is_default && !t.locked;
                case 'LOCK':         return !t.locked;
                case 'UNLOCK':       return !!t.locked;
                case 'BUMP_VERSION': return !t.locked;
                case 'DELETE':       return !t.is_default && !t.locked;
                default:             return false;
            }
        },
        lifecycleReason(action) {
            if (!this.canWrite) return 'NATIONAL_ADMIN role required';
            const t = this.detail.template;
            if (!t) return '';
            if (t.locked && action !== 'UNLOCK') return 'Template is locked — unlock first';
            if (action === 'RETIRE' && t.is_default) return 'Default template cannot be retired';
            if (action === 'DELETE' && t.is_default) return 'Default template cannot be deleted';
            if (action === 'LOCK'   && t.locked)     return 'Already locked';
            if (action === 'UNLOCK' && !t.locked)    return 'Not locked';
            if (action === 'PUBLISH' && t.status === 'PUBLISHED') return 'Already published';
            if (action === 'PUBLISH' && this.enabledColumnsCount() === 0) return 'At least one enabled column required';
            if (action === 'RETIRE' && t.status !== 'PUBLISHED') return 'Only published templates can be retired';
            return '';
        },
        runLifecycle(action) {
            const t = this.detail.template;
            if (!t) return;
            if (!this.canRunLifecycle(action)) {
                const why = this.lifecycleReason(action) || 'Action not allowed';
                this.toast('warning', 'Cannot ' + action.toLowerCase(), why);
                return;
            }

            if (action === 'DELETE') {
                const subs = this.detail.stats?.submissions_count || 0;
                this.confirmDialog = {
                    open: true, variant: 'destructive',
                    title: 'Delete template?',
                    body: subs > 0
                        ? `This template has ${subs} submission(s). Deletion is soft — submissions remain in the database for audit but the template is hidden from mobile. Type the confirmation token to proceed.`
                        : `Soft-delete "${t.template_name}". Template disappears from mobile on next sync.`,
                    cta: 'Delete template',
                    requiresToken: subs > 0, token: 'DELETE_WITH_SUBMISSIONS', typed: '', saving: false,
                    onConfirm: () => this.performDelete(subs > 0),
                };
                return;
            }
            const labels = { PUBLISH: 'Publish', RETIRE: 'Retire', LOCK: 'Lock', UNLOCK: 'Unlock', BUMP_VERSION: 'Bump version' };
            this.confirmDialog = {
                open: true, variant: 'default',
                title: labels[action] + '?',
                body: {
                    PUBLISH: 'Publish this template to all POE mobile users on next sync. Requires at least one enabled column.',
                    RETIRE: 'Retire this template. Submissions remain queryable.',
                    LOCK: 'Freeze meta and column edits on this template.',
                    UNLOCK: 'Resume edits on this template.',
                    BUMP_VERSION: 'Increment the version counter on this template for audit purposes.',
                }[action],
                cta: labels[action],
                requiresToken: false, token: '', typed: '', saving: false,
                onConfirm: () => this.performLifecycle(action),
            };
        },

        async performLifecycle(action) {
            if (this.confirmDialog.saving) return;
            this.confirmDialog.saving = true;
            try {
                const body = await this.jsonFetch(C.routes.lifecycle + '/' + this.detail.id + '/lifecycle', {
                    method: 'POST',
                    body: { action },
                });
                this.confirmDialog.open = false;
                await this.reloadDetail();
                this.loadTemplates();
                this.toast('success', 'Applied', body?.message || action);
            } catch (e) {
                this.toast('danger', 'Action failed', e.message);
            } finally {
                this.confirmDialog.saving = false;
            }
        },

        async performDelete(needsCascade) {
            if (this.confirmDialog.saving) return;
            this.confirmDialog.saving = true;
            try {
                const qs = needsCascade ? '?cascade=true&confirm=DELETE_WITH_SUBMISSIONS' : '';
                await this.jsonFetch(C.routes.templateShow + '/' + this.detail.id + qs, { method: 'DELETE' });
                this.confirmDialog.open = false;
                this.detail.open = false;
                this.loadTemplates();
                this.toast('success', 'Template deleted', '');
            } catch (e) {
                this.toast('danger', 'Delete failed', e.message);
            } finally {
                this.confirmDialog.saving = false;
            }
        },

        async reloadDetail() {
            if (!this.detail.id) return;
            try {
                const body = await this.jsonFetch(C.routes.templateShow + '/' + this.detail.id);
                this.detail.template = body?.data?.template || null;
                this.detail.columns  = body?.data?.columns  || [];
                this.detail.stats    = body?.data?.stats    || null;
            } catch (_) {
                // leave prior state; next user action will retry.
            }
        },

        // ─── UI helpers ───────────────────────────────────────────────
        toast(variant, title, body) {
            const prev = this.flash?.timer;
            if (prev) clearTimeout(prev);
            this.flash = { open: true, variant, title, body, timer: null };
            this.flash.timer = setTimeout(() => { this.flash.open = false; }, 2800);
        },
        setPageMeta(rows) {
            try {
                if (window.Alpine && Alpine.store) {
                    const s = Alpine.store('pageMeta');
                    if (s) s.rows = rows;
                }
            } catch (_) { /* no-op */ }
        },
    };
}
</script>
@endpush
@endsection
