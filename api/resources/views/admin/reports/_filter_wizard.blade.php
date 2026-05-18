{{-- Reusable filter wizard drawer — bound to Alpine state `wizard`, `filters`, `meta` --}}
<template x-if="wizard.open">
    <div class="fixed inset-0 z-50 flex" role="dialog" aria-modal="true" aria-labelledby="wizard-heading"
         @keydown.escape.window="wizard.open = false">
        <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="wizard.open = false" aria-hidden="true"></div>
        <div class="relative ml-auto w-full max-w-[480px] bg-background border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
            <header class="flex items-center justify-between px-5 py-3 border-b">
                <div>
                    <p class="eyebrow">Step <span x-text="wizard.step"></span> of 4</p>
                    <h2 id="wizard-heading" class="text-[14px] font-semibold">Configure filters</h2>
                </div>
                <button type="button" class="btn btn-ghost btn-sm" @click="wizard.open = false" aria-label="Close filter wizard">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </header>

            <div class="flex-1 overflow-y-auto p-5 space-y-4">
                {{-- Step 1 — Date range --}}
                <fieldset x-show="wizard.step === 1" class="space-y-3">
                    <legend class="text-[12px] font-semibold text-foreground">1 · Date range</legend>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-[11px] text-muted-foreground">Year
                            <select class="select w-full mt-1" x-model.number="filters.year">
                                <option value="">Any</option>
                                <template x-for="y in (meta.years || [])" :key="y">
                                    <option :value="y" x-text="y"></option>
                                </template>
                            </select>
                        </label>
                        <label class="text-[11px] text-muted-foreground">Quarter
                            <select class="select w-full mt-1" x-model.number="filters.quarter">
                                <option value="">Any</option>
                                <template x-for="(lbl, q) in (meta.quarters || {})" :key="q">
                                    <option :value="q" x-text="lbl"></option>
                                </template>
                            </select>
                        </label>
                    </div>
                    <label class="text-[11px] text-muted-foreground block">Month
                        <select class="select w-full mt-1" x-model.number="filters.month">
                            <option value="">Any</option>
                            <template x-for="(lbl, m) in (meta.months || {})" :key="m">
                                <option :value="m" x-text="lbl"></option>
                            </template>
                        </select>
                    </label>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-[11px] text-muted-foreground">Start date
                            <input type="date" class="input w-full mt-1" x-model="filters.start_date">
                        </label>
                        <label class="text-[11px] text-muted-foreground">End date
                            <input type="date" class="input w-full mt-1" x-model="filters.end_date">
                        </label>
                    </div>
                    <p class="help-text">Priority: Quarter + Year &gt; Month + Year &gt; Year &gt; custom range.</p>
                </fieldset>

                {{-- Step 2 — Geographic scope --}}
                <fieldset x-show="wizard.step === 2" class="space-y-3">
                    <legend class="text-[12px] font-semibold text-foreground">2 · Geographic scope</legend>
                    <label class="text-[11px] text-muted-foreground block">Point of Entry
                        <select class="select w-full mt-1" x-model="filters.poe">
                            <option value="">All POEs in scope</option>
                            <template x-for="(name, id) in (meta.poes || {})" :key="id">
                                <option :value="name" x-text="name"></option>
                            </template>
                        </select>
                    </label>
                    <p class="help-text">Only POEs you are authorised to query appear in this list.</p>
                </fieldset>

                {{-- Step 3 — Categorical filters --}}
                <fieldset x-show="wizard.step === 3" class="space-y-3">
                    <legend class="text-[12px] font-semibold text-foreground">3 · Categorical filters</legend>
                    <label class="text-[11px] text-muted-foreground block">Sex
                        <select class="select w-full mt-1" x-model="filters.sex">
                            <option value="">Any</option>
                            <template x-for="(lbl, g) in (meta.genders || {})" :key="g">
                                <option :value="g" x-text="lbl"></option>
                            </template>
                        </select>
                    </label>
                </fieldset>

                {{-- Step 4 — Confirm --}}
                <fieldset x-show="wizard.step === 4" class="space-y-3">
                    <legend class="text-[12px] font-semibold text-foreground">4 · Run report</legend>
                    <div class="space-y-1 text-[12px]">
                        <template x-for="(v, k) in filters" :key="k">
                            <div class="flex justify-between gap-4" x-show="v !== '' && v !== null && v !== undefined">
                                <span class="text-muted-foreground capitalize" x-text="k.replace(/_/g, ' ')"></span>
                                <span class="font-medium text-foreground truncate" x-text="v"></span>
                            </div>
                        </template>
                    </div>
                    <button type="button" class="btn btn-primary w-full" @click="runReport(); wizard.open = false;">
                        Run report
                    </button>
                    <button type="button" class="btn btn-ghost w-full" @click="resetFilters()">
                        Reset filters
                    </button>
                </fieldset>
            </div>

            <footer class="flex items-center justify-between px-5 py-3 border-t">
                <button type="button" class="btn btn-ghost btn-sm" @click="wizard.step = Math.max(1, wizard.step - 1)" :disabled="wizard.step === 1">
                    Back
                </button>
                <div class="flex items-center gap-1.5">
                    <template x-for="s in 4" :key="s">
                        <span class="inline-block h-1.5 w-5 rounded"
                              :class="s <= wizard.step ? 'bg-brand' : 'bg-muted'"></span>
                    </template>
                </div>
                <button type="button" class="btn btn-primary btn-sm" x-show="wizard.step < 4" @click="wizard.step = Math.min(4, wizard.step + 1)">
                    Next
                </button>
                <span x-show="wizard.step === 4" class="w-[60px]"></span>
            </footer>
        </div>
    </div>
</template>
