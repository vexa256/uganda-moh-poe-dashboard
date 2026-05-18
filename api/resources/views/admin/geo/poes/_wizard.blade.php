{{-- ============================================================================
  Step Wizard · Create / Edit a PoE
  ----------------------------------------------------------------------------
  Bulletproof modal pattern (2026-04-23 fix):
    · Outer wrapper: `fixed inset-0 z-50 flex items-end sm:items-center
      justify-center p-0 sm:p-4` — covers viewport, flex-centers panel.
    · Backdrop: `absolute inset-0 bg-black/55 backdrop-blur-sm` — fills
      wrapper, click-to-close, ESC-to-close.
    · Panel: `relative` (sibling to backdrop in same stacking context) —
      later in DOM, so it stacks above backdrop without z-index battles.
    · Body scroll lock via x-effect.

  All steps share the parent Alpine root (poeRegistry()); never define
  x-data here. Theme primitives only.
============================================================================ --}}
<template x-if="wizard.open">
    <div class="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4"
         role="dialog" aria-modal="true" aria-label="PoE wizard"
         @keydown.escape.window="wizard.open=false">
         x-trap.inert="wizard.open">

        {{-- Backdrop (sibling, absolute fill, click to dismiss) --}}
        <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="wizard.open=false"></div>

        {{-- Panel (relative, stacks above backdrop via DOM order) --}}
        <div class="relative w-full sm:max-w-2xl bg-card border-t sm:border sm:rounded-xl shadow-elevation-5 max-h-[92vh] flex flex-col"
             @click.stop>

            {{-- Header (sticky) --}}
            <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand-soft text-brand-ink shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14m-7-7h14"/></svg>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground"
                       x-text="wizard.mode === 'edit' ? 'Edit Point of Entry' : 'New Point of Entry'"></p>
                    <h2 class="text-[14px] font-bold leading-tight truncate"
                        x-text="wizard.form.poe_name || 'Untitled'"></h2>
                </div>
                <button type="button" class="btn btn-ghost btn-icon-xs shrink-0" @click="wizard.open=false" aria-label="Close">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </header>

            {{-- Progress strip --}}
            <div class="flex items-center gap-1.5 px-4 sm:px-6 py-3 border-b bg-muted/20">
                <template x-for="s in [1,2,3,4,5]" :key="s">
                    <div class="flex-1 flex items-center gap-1.5 min-w-0">
                        <div class="grid place-items-center h-6 w-6 rounded-full text-[11px] font-bold shrink-0"
                             :class="wizard.step === s ? 'bg-brand text-white'
                                   : wizard.step > s    ? 'bg-success-soft text-success'
                                   :                      'bg-muted text-muted-foreground'">
                            <template x-if="wizard.step > s">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7"/></svg>
                            </template>
                            <template x-if="wizard.step <= s">
                                <span x-text="s"></span>
                            </template>
                        </div>
                        <div class="flex-1 h-px bg-border" x-show="s < 5"></div>
                    </div>
                </template>
            </div>

            <div class="px-4 sm:px-6 pt-3 text-[11.5px] text-muted-foreground">
                <span x-text="wizard.step === 1 ? '1 / 5 · Identity'
                            : wizard.step === 2 ? '2 / 5 · Location'
                            : wizard.step === 3 ? '3 / 5 · Profile flags'
                            : wizard.step === 4 ? '4 / 5 · Context & sources'
                            :                     '5 / 5 · Review & save'"></span>
            </div>

            {{-- Body (scrollable) --}}
            <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-5">

                {{-- ════════ STEP 1 · IDENTITY ════════ --}}
                <template x-if="wizard.step === 1">
                    <div class="space-y-4">
                        <div>
                            <label class="label">Official PoE name <span class="text-critical">*</span></label>
                            <input type="text" class="input mt-1.5"
                                   placeholder="e.g. Mwami, Kasumbalesa, Mfuwe International Airport"
                                   x-model="wizard.form.poe_name"
                                   @input.debounce.300ms="scheduleSuggest()">
                            <p class="help-text mt-1.5">poe_code follows poe_name automatically. Type, transport, and source URL are server-derived.</p>
                        </div>

                        <div class="rounded-lg border bg-brand-soft/40 p-3" x-show="wizard.suggestions">
                            <p class="text-[10.5px] font-semibold uppercase tracking-wider text-brand-ink">Auto-derived</p>
                            <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-[12px]">
                                <dt class="text-muted-foreground">Type</dt>
                                <dd class="font-mono" x-text="wizard.suggestions?.poe_type"></dd>
                                <dt class="text-muted-foreground">Transport</dt>
                                <dd class="font-mono" x-text="wizard.suggestions?.transport_mode"></dd>
                                <template x-if="wizard.suggestions?.is_recommended_osbp">
                                    <dt class="text-muted-foreground">OSBP</dt>
                                </template>
                                <template x-if="wizard.suggestions?.is_recommended_osbp">
                                    <dd class="font-mono text-brand">recommended (commissioned)</dd>
                                </template>
                            </dl>
                        </div>

                        <div class="alert alert-warning" x-show="wizard.dupes.length > 0">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            <div>
                                <p class="alert-title">Possible duplicates</p>
                                <p class="alert-description">A PoE with a similar name already exists:</p>
                                <ul class="mt-1 space-y-0.5 text-[12px]">
                                    <template x-for="c in wizard.dupes" :key="c.id">
                                        <li>
                                            <span class="font-mono text-[10.5px] text-muted-foreground" x-text="c.external_id"></span> ·
                                            <span class="font-semibold" x-text="c.poe_name"></span> ·
                                            <span class="text-muted-foreground" x-text="c.admin_level_1 + ' / ' + c.district"></span>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                        </div>

                        <details class="rounded-lg border bg-muted/30 p-3">
                            <summary class="cursor-pointer text-[12px] font-semibold text-foreground select-none">Override derived poe_type</summary>
                            <select class="select mt-2" x-model="wizard.form.poe_type" @change="markTouched('poe_type')">
                                <template x-for="t in meta.poe_types" :key="t">
                                    <option :value="t" x-text="t"></option>
                                </template>
                            </select>
                            <p class="help-text mt-2">transport_mode is server-derived from poe_type — only 'other' lets the caller hint.</p>
                        </details>
                    </div>
                </template>

                {{-- ════════ STEP 2 · LOCATION ════════ --}}
                <template x-if="wizard.step === 2">
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="label">Regional PHEOC <span class="text-critical">*</span></label>
                                <select class="select mt-1.5" x-model="wizard.form.province_id" @change="onWizardProvinceChange()">
                                    <option value="">Select region…</option>
                                    <template x-for="p in meta.provinces" :key="p.id">
                                        <option :value="p.id" x-text="p.name"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="label">District <span class="text-critical">*</span></label>
                                <select class="select mt-1.5" x-model="wizard.form.district_id"
                                        :disabled="!wizard.form.province_id"
                                        @change="scheduleSuggest()">
                                    <option value="">Select district…</option>
                                    <template x-for="d in districtsForWizardForm" :key="d.id">
                                        <option :value="d.id" x-text="d.name"></option>
                                    </template>
                                </select>
                                <p class="help-text mt-1.5" x-show="!wizard.form.province_id">Pick a province first.</p>
                            </div>
                        </div>

                        <div x-show="wizard.form.poe_type === 'land_border'">
                            <label class="label">Border country <span class="text-critical">*</span></label>
                            <select class="select mt-1.5" x-model="wizard.form.border_country" @change="markTouched('border_country')">
                                <option value="">Select neighbour…</option>
                                <template x-for="b in meta.neighbours" :key="b">
                                    <option :value="b" x-text="b"></option>
                                </template>
                            </select>
                            <p class="help-text mt-1.5" x-show="wizard.suggestions?.border_country">
                                Suggested: <span class="font-semibold" x-text="wizard.suggestions?.border_country"></span> (most common in this district).
                            </p>
                        </div>

                        <div class="rounded-lg border bg-muted/30 p-3" x-show="wizard.form.poe_type !== 'land_border'">
                            <p class="text-[12px] text-muted-foreground">
                                border_country is null for <span class="font-mono" x-text="wizard.form.poe_type"></span> PoEs (server enforces).
                            </p>
                        </div>
                    </div>
                </template>

                {{-- ════════ STEP 3 · PROFILE FLAGS ════════ --}}
                <template x-if="wizard.step === 3">
                    <div class="space-y-3">
                        <p class="text-[12px] text-muted-foreground">
                            These flags drive mobile UX (recommended PoE chips, traveller tips) and surveillance prioritisation.
                        </p>

                        <label class="flex items-start gap-3 rounded-lg border bg-card p-3 cursor-pointer hover:bg-muted/30">
                            <input type="checkbox" x-model="wizard.form.is_major_entry" class="mt-0.5">
                            <div class="min-w-0 flex-1">
                                <p class="text-[12.5px] font-semibold">Major entry point</p>
                                <p class="text-[11.5px] text-muted-foreground">High traveller / cargo volume; appears on priority dashboards.</p>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 rounded-lg border bg-card p-3 cursor-pointer hover:bg-muted/30">
                            <input type="checkbox" x-model="wizard.form.is_recommended_osbp" class="mt-0.5">
                            <div class="min-w-0 flex-1">
                                <p class="text-[12.5px] font-semibold">Recommended OSBP</p>
                                <p class="text-[11.5px] text-muted-foreground">
                                    Commissioned One-Stop Border Post — Chirundu / Nakonde / Mwami / Kazungula Road today.
                                    <span x-show="wizard.suggestions?.is_recommended_osbp" class="text-brand font-semibold">Server suggests ON.</span>
                                </p>
                            </div>
                        </label>

                        <label class="flex items-start gap-3 rounded-lg border bg-card p-3 cursor-pointer hover:bg-muted/30">
                            <input type="checkbox" x-model="wizard.form.is_national_level" class="mt-0.5">
                            <div class="min-w-0 flex-1">
                                <p class="text-[12.5px] font-semibold">National-level (IHR designated)</p>
                                <p class="text-[11.5px] text-muted-foreground">Annex-1A IHR-designated national PoE. Currently Entebbe International and Kabalega International qualify.</p>
                            </div>
                        </label>

                        <details class="rounded-lg border bg-muted/30 p-3">
                            <summary class="cursor-pointer text-[12px] font-semibold text-foreground select-none">Advanced</summary>
                            <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div><label class="label">Display order</label><input type="number" class="input mt-1.5" x-model="wizard.form.display_order" placeholder="auto"></div>
                                <div><label class="label">poe_code (override)</label><input type="text" class="input mt-1.5" x-model="wizard.form.poe_code" :placeholder="wizard.form.poe_name"></div>
                                <div><label class="label">Latitude</label><input type="number" step="0.000001" class="input mt-1.5" x-model="wizard.form.latitude"></div>
                                <div><label class="label">Longitude</label><input type="number" step="0.000001" class="input mt-1.5" x-model="wizard.form.longitude"></div>
                            </div>
                        </details>
                    </div>
                </template>

                {{-- ════════ STEP 4 · CONTEXT ════════ --}}
                <template x-if="wizard.step === 4">
                    <div class="space-y-4">
                        <div>
                            <label class="label">Critical details</label>
                            <textarea class="textarea mt-1.5 min-h-[140px]" x-model="wizard.form.critical_details"
                                      @input="markTouched('critical_details')"
                                      placeholder="Gazetted border station…"></textarea>
                            <p class="help-text mt-1.5">Server pre-fills a starter template by type and frontier — edit freely. Appears in the mobile PoE drawer.</p>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div><label class="label">Source URL</label><input type="url" class="input mt-1.5" x-model="wizard.form.source_url" @input="markTouched('source_url')"></div>
                            <div><label class="label">Source origin</label><input type="text" class="input mt-1.5" x-model="wizard.form.source_origin" @input="markTouched('source_origin')"></div>
                        </div>
                        <p class="help-text">Defaults pivot on type — Department of Immigration for land borders, ZACL for airports.</p>
                    </div>
                </template>

                {{-- ════════ STEP 5 · REVIEW ════════ --}}
                <template x-if="wizard.step === 5">
                    <div class="space-y-4">
                        <div class="rounded-lg border bg-card p-4">
                            <div class="flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">External ID (deterministic)</p>
                                    <p class="text-[14px] font-mono font-bold text-brand-ink truncate"
                                       x-text="wizard.mode === 'edit' ? wizard.form.external_id : (wizard.suggestions?.external_id_guess || 'will be assigned on save')"></p>
                                </div>
                                <span class="badge badge-brand shrink-0">ZM-PROV-DIST-NAME-NNN</span>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="table">
                                <tbody class="table-body">
                                    <tr class="table-row"><td class="table-cell text-muted-foreground w-1/3">poe_name</td><td class="table-cell font-semibold" x-text="wizard.form.poe_name"></td></tr>
                                    <tr class="table-row"><td class="table-cell text-muted-foreground">poe_code</td><td class="table-cell font-mono" x-text="wizard.form.poe_code || wizard.form.poe_name"></td></tr>
                                    <tr class="table-row"><td class="table-cell text-muted-foreground">type / transport</td><td class="table-cell"><span class="font-mono" x-text="wizard.form.poe_type"></span> / <span class="font-mono" x-text="wizard.form.transport_mode"></span></td></tr>
                                    <tr class="table-row"><td class="table-cell text-muted-foreground">province / district</td><td class="table-cell" x-text="(meta.provinces.find(p=>p.id==wizard.form.province_id)?.name || '—') + ' / ' + (meta.districts.find(d=>d.id==wizard.form.district_id)?.name || '—')"></td></tr>
                                    <tr class="table-row"><td class="table-cell text-muted-foreground">border_country</td><td class="table-cell" x-text="wizard.form.border_country || 'null (non-land)'"></td></tr>
                                    <tr class="table-row"><td class="table-cell text-muted-foreground">flags</td><td class="table-cell">
                                        <span class="badge badge-info" x-show="wizard.form.is_major_entry">major</span>
                                        <span class="badge badge-brand" x-show="wizard.form.is_recommended_osbp">OSBP</span>
                                        <span class="badge badge-warning" x-show="wizard.form.is_national_level">national</span>
                                        <span class="text-muted-foreground" x-show="!wizard.form.is_major_entry && !wizard.form.is_recommended_osbp && !wizard.form.is_national_level">none</span>
                                    </td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="alert alert-info">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <div>
                                <p class="alert-title">On save</p>
                                <p class="alert-description">Bundle bumps from v<span class="font-mono" x-text="bundleVersion"></span> → v<span class="font-mono" x-text="bundleVersion + 1"></span>. Mobile clients refresh on next sync.</p>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Footer (sticky) --}}
            <footer class="flex items-center gap-2 px-4 sm:px-6 py-3 border-t">
                <button type="button" class="btn btn-outline btn-sm" @click="prevStep()" :disabled="wizard.step === 1">Back</button>
                <div class="flex-1"></div>
                <button type="button" class="btn btn-ghost btn-sm" @click="wizard.open=false">Cancel</button>
                <template x-if="wizard.step < wizard.totalSteps">
                    <button type="button" class="btn btn-brand btn-sm" @click="nextStep()" :disabled="!stepValid(wizard.step)">Next</button>
                </template>
                <template x-if="wizard.step === wizard.totalSteps">
                    <button type="button" class="btn btn-brand btn-sm" @click="save()" :disabled="wizard.submitting">
                        <span x-show="!wizard.submitting" x-text="wizard.mode === 'edit' ? 'Save changes' : 'Create PoE'"></span>
                        <span x-show="wizard.submitting">Saving…</span>
                    </button>
                </template>
            </footer>
        </div>
    </div>
</template>
