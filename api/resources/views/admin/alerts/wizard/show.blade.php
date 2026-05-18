@extends('admin.layout')

@section('crumb', 'Alerts')
@section('title', 'Walk this case to a close')

@section('content')
<div
    x-data="alertWizard({
        alertId: {{ (int) $alert['id'] }},
        isSuper: {{ $isSuper ? 'true' : 'false' }},
        endpoints: {
            step:         '{{ route('admin.alerts.wizard.step',         ['id' => $alert['id']]) }}',
            progress:     '{{ route('admin.alerts.wizard.progress',     ['id' => $alert['id']]) }}',
            stakeholders: '{{ route('admin.alerts.wizard.stakeholders', ['id' => $alert['id']]) }}',
            answer:       '{{ route('admin.alerts.wizard.answer',       ['id' => $alert['id']]) }}',
            contact:      '{{ route('admin.alerts.wizard.contact',      ['id' => $alert['id']]) }}',
            falseAlarm:   '{{ route('admin.alerts.wizard.false-alarm',  ['id' => $alert['id']]) }}',
            masterClose:  '{{ route('admin.alerts.wizard.master-close', ['id' => $alert['id']]) }}',
            closeAlert:   '{{ route('admin.alerts.close',               ['id' => $alert['id']]) }}',
            casefile:     '{{ route('admin.alerts.case-file',           ['id' => $alert['id']]) }}',
        },
    })"
    x-init="boot()"
    class="w-full max-w-full overflow-x-hidden space-y-4"
>

    {{-- ─────────────── COMPACT HEADER (always visible, slim) ──────────────── --}}
    <section class="rounded-2xl border bg-white shadow-sm overflow-hidden">
        <div class="px-4 py-3 sm:px-5 sm:py-3.5 min-w-0">
            <div class="flex items-center gap-2 text-[10.5px] uppercase tracking-[0.12em] text-slate-500 font-semibold min-w-0">
                <span @class([
                    'inline-block w-2 h-2 rounded-full shrink-0',
                    'bg-red-500'    => $alert['human']['disease']['tier']['dot'] === 'red',
                    'bg-amber-500'  => $alert['human']['disease']['tier']['dot'] === 'amber',
                    'bg-slate-400'  => $alert['human']['disease']['tier']['dot'] === 'grey',
                ])></span>
                <span class="truncate">{{ $alert['human']['disease']['tier']['short'] }}</span>
                <span aria-hidden="true">·</span>
                <span class="truncate">{{ $alert['human']['routed_to'] }}</span>
                <span aria-hidden="true">·</span>
                <span class="truncate">{{ $alert['human']['created_human'] }}</span>
            </div>
            <h1 class="mt-0.5 text-base sm:text-lg font-bold leading-snug text-slate-900 break-words">
                {{ $alert['human']['traveller_name'] }}
                <span class="font-normal text-slate-500">({{ $alert['human']['classification'] }})</span>
            </h1>
            <p class="text-[12px] text-slate-500 break-words">
                {{ $alert['poe_code'] }} · {{ $alert['district_code'] }}
            </p>
        </div>
    </section>

    {{-- ─────────────── HERO + RIGHT RAIL (desktop only) ───────────────────── --}}
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 min-w-0">

        {{-- HERO — current step / closure / closed (full width on mobile) --}}
        <main class="lg:col-span-8 min-w-0">

            {{-- LOADING --}}
            <div class="rounded-2xl border bg-white shadow-sm p-8 text-center text-sm text-slate-500"
                 x-show="state.loadingStep">
                Getting your next step ready…
            </div>

            {{-- STEP --}}
            <template x-if="!state.loadingStep && step.kind === 'step'">
                <article class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                    <div class="p-5 sm:p-7 space-y-4 min-w-0">

                        {{-- Step counter chip --}}
                        <p class="text-[11px] uppercase tracking-[0.12em] text-amber-700 font-bold"
                           x-text="`Next step · ${(progress.items.findIndex(i => i.is_next) + 1) || 1} of ${progress.items.length || 14}`"></p>

                        {{-- HERO question --}}
                        <h2 class="text-xl sm:text-2xl font-bold leading-tight tracking-tight text-slate-900 break-words" x-text="step.title"></h2>
                        <p class="text-[13.5px] text-slate-600 leading-snug break-words" x-text="step.short"></p>

                        {{-- WHY: collapsible, default closed (saves vertical space) --}}
                        <div class="min-w-0">
                            <button type="button" @click="whyOpen = !whyOpen"
                                    class="inline-flex items-center gap-1.5 text-[12.5px] font-medium text-amber-700 hover:text-amber-900">
                                <span x-text="whyOpen ? 'Hide why this matters' : 'Why this matters'"></span>
                                <span class="text-[10px]" x-text="whyOpen ? '▴' : '▾'"></span>
                            </button>
                            <div x-show="whyOpen" x-cloak class="mt-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 min-w-0">
                                <p class="text-[14px] text-amber-950 leading-snug break-words" x-text="step.why"></p>
                            </div>
                        </div>

                        {{-- 4 OPTIONS — large tap targets, hero zone --}}
                        <div class="grid grid-cols-1 gap-2">
                            <template x-for="opt in step.options" :key="opt.code">
                                <button type="button"
                                        :class="{
                                            'w-full flex items-center justify-between gap-3 rounded-xl border-2 px-4 py-3.5 text-left transition min-w-0 active:scale-[0.99]': true,
                                            'border-emerald-300 bg-emerald-50 hover:border-emerald-500 hover:bg-emerald-100': opt.tone === 'done',
                                            'border-amber-300 bg-amber-50 hover:border-amber-500 hover:bg-amber-100':         opt.tone === 'watch',
                                            'border-slate-300 bg-slate-50 hover:border-slate-500 hover:bg-slate-100':         opt.tone === 'skipped',
                                            'border-red-300 bg-red-50 hover:border-red-500 hover:bg-red-100':                 opt.tone === 'urgent',
                                            'opacity-60 cursor-progress': state.submitting,
                                        }"
                                        :disabled="state.submitting"
                                        @click="chooseOption(opt)">
                                    <span class="font-semibold text-[13.5px] text-slate-900 break-words flex-1 min-w-0" x-text="opt.label"></span>
                                    <span class="text-slate-400 shrink-0" aria-hidden="true">→</span>
                                </button>
                            </template>
                        </div>

                        {{-- N/A reason inline reveal --}}
                        <div x-show="reasonOpen" x-cloak class="rounded-xl border border-slate-300 bg-slate-50 p-4 space-y-2 min-w-0">
                            <label class="block text-[12px] font-medium text-slate-700" x-text="reasonPrompt"></label>
                            <textarea x-model="reasonText" rows="2"
                                      class="block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                                      placeholder="A short sentence is enough."></textarea>
                            <div class="flex flex-wrap gap-2">
                                <button type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                                        @click="confirmReason()" :disabled="state.submitting || !reasonText.trim()">Save</button>
                                <button type="button"
                                        class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100"
                                        @click="reasonOpen=false; reasonText=''">Cancel</button>
                            </div>
                        </div>

                        {{-- Help-needed inline panel --}}
                        <div x-show="helpOpen" x-cloak class="rounded-xl border border-blue-300 bg-blue-50 p-4 space-y-3 min-w-0">
                            <p class="text-[12px] font-medium text-blue-900">These are the people best placed to help with this step.</p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <template x-for="who in step.who_can_help" :key="who.email + who.kind">
                                    <div class="rounded-lg border border-blue-200 bg-white p-3 min-w-0">
                                        <p class="text-sm font-semibold text-slate-900 break-words" x-text="who.name"></p>
                                        <p class="text-xs text-slate-500 break-words" x-text="who.role_or_pos || who.kind_label"></p>
                                        <p class="text-xs text-slate-500 break-words" x-text="who.organisation"></p>
                                        <div class="mt-2 flex flex-wrap gap-1.5">
                                            <a class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50" :href="'mailto:' + who.email"><span>Email</span></a>
                                            <a class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50" :href="'tel:' + who.phone" x-show="who.phone"><span>Call</span></a>
                                            <button type="button" class="inline-flex items-center gap-1 rounded-md px-2.5 py-1 text-xs font-medium text-slate-600 hover:bg-slate-100" @click="markCalled(who)"><span>I spoke with them</span></button>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="step.who_can_help.length === 0">
                                    <p class="text-xs italic text-blue-900">No one to suggest yet — try the "Show people" panel.</p>
                                </template>
                            </div>
                            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100" @click="helpOpen=false">Close</button>
                        </div>

                    </div>
                </article>
            </template>

            {{-- READY-TO-CLOSE — compact card; the actual closure form lives in a slide-up sheet --}}
            <template x-if="!state.loadingStep && step.kind === 'closure'">
                <article class="rounded-2xl border bg-white shadow-sm overflow-hidden">
                    <div class="p-5 sm:p-7 space-y-4 min-w-0">
                        <p class="text-[11px] uppercase tracking-[0.12em] text-emerald-700 font-bold">Ready to close</p>
                        <h2 class="text-xl sm:text-2xl font-bold leading-tight tracking-tight text-slate-900 break-words" x-text="step.title"></h2>
                        <p class="text-sm text-slate-600 break-words" x-text="step.subtitle"></p>

                        <div class="grid grid-cols-3 gap-2 text-center">
                            <div class="rounded-xl bg-emerald-50 px-3 py-2">
                                <p class="text-lg font-bold text-emerald-700 tabular-nums" x-text="step.summary.completed_count"></p>
                                <p class="text-[10.5px] uppercase tracking-wider text-emerald-700 font-semibold">Done</p>
                            </div>
                            <div class="rounded-xl bg-slate-100 px-3 py-2">
                                <p class="text-lg font-bold text-slate-600 tabular-nums" x-text="step.summary.not_applicable_count"></p>
                                <p class="text-[10.5px] uppercase tracking-wider text-slate-600 font-semibold">Skipped</p>
                            </div>
                            <div class="rounded-xl bg-blue-50 px-3 py-2">
                                <p class="text-lg font-bold text-blue-700 tabular-nums" x-text="step.summary.total_count"></p>
                                <p class="text-[10.5px] uppercase tracking-wider text-blue-700 font-semibold">Total steps</p>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <button type="button" @click="showCloseSheet = true"
                                    class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
                                <span x-text="step.submit"></span>
                            </button>
                            <button type="button" @click="showMasterClose = true" x-show="step.can_master_close"
                                    class="inline-flex items-center gap-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                                <span x-text="step.submit_master"></span>
                            </button>
                        </div>
                    </div>
                </article>
            </template>

            {{-- CLOSED CONFIRMATION --}}
            <template x-if="state.closed">
                <article class="rounded-xl border bg-white shadow-sm overflow-hidden">
                    <div class="p-6 sm:p-8 text-center space-y-3 min-w-0">
                        <div class="inline-flex w-14 h-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </div>
                        <h2 class="text-base sm:text-lg font-bold text-slate-900">This case is closed.</h2>
                        <p class="text-sm text-slate-600 break-words" x-text="state.closedMessage || 'Everyone we told has been notified.'"></p>
                        <div class="flex flex-wrap gap-2 justify-center">
                            <a :href="endpoints.casefile"
                               class="inline-flex items-center gap-1.5 rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Open the full case file</a>
                            <a href="{{ route('admin.alerts.index') }}"
                               class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700">Back to alerts</a>
                        </div>
                    </div>
                </article>
            </template>

        </main>

        {{-- DESKTOP-ONLY compact context rail (steps + stakeholders snapshot) --}}
        <aside class="hidden lg:block lg:col-span-4 min-w-0">
            <div class="rounded-2xl border bg-white shadow-sm overflow-hidden lg:sticky lg:top-4">
                <div class="px-4 py-4 space-y-4 min-w-0">
                    {{-- Mini progress (5 visible, "see all" opens sheet) --}}
                    <section class="min-w-0">
                        <div class="flex items-center justify-between mb-2 min-w-0">
                            <h2 class="text-[12.5px] font-semibold text-slate-900">Progress</h2>
                            <button type="button" class="text-[11.5px] font-medium text-blue-600 hover:text-blue-700 shrink-0" @click="showStepsSheet = true">See all 14</button>
                        </div>
                        <p class="text-[11.5px] text-slate-500 mb-2" x-text="`${doneCount()} done · ${naCount()} skipped · ${pendingCount()} still to do`"></p>
                        <ol class="space-y-1">
                            <template x-for="item in (progress.items || []).slice(0, 5)" :key="item.code">
                                <li class="flex items-start gap-2 min-w-0">
                                    <span :class="{
                                        'mt-0.5 inline-flex items-center justify-center w-4 h-4 rounded-full text-[9px] font-bold flex-shrink-0': true,
                                        'bg-emerald-100 text-emerald-700': item.status_tone === 'done',
                                        'bg-amber-100 text-amber-700':     item.status_tone === 'watch',
                                        'bg-red-100 text-red-700':         item.status_tone === 'urgent',
                                        'bg-slate-100 text-slate-500':     item.status_tone === 'skipped' || item.status_tone === 'info',
                                    }">
                                        <template x-if="item.status_tone === 'done'">✓</template>
                                        <template x-if="item.status_tone !== 'done'">·</template>
                                    </span>
                                    <p class="text-[12.5px] leading-snug text-slate-700 break-words min-w-0" x-text="item.title"></p>
                                </li>
                            </template>
                        </ol>
                    </section>

                    {{-- Stakeholder snapshot (counts only; "see all" opens sheet) --}}
                    <section class="min-w-0 border-t border-slate-100 pt-4">
                        <div class="flex items-center justify-between mb-2 min-w-0">
                            <h2 class="text-[12.5px] font-semibold text-slate-900">Who is involved</h2>
                            <button type="button" class="text-[11.5px] font-medium text-blue-600 hover:text-blue-700 shrink-0" @click="showPeopleSheet = true">See people</button>
                        </div>
                        <div class="grid grid-cols-3 gap-1 text-center">
                            <div class="rounded-lg bg-emerald-50 px-2 py-1.5 min-w-0">
                                <p class="text-base font-bold text-emerald-700 tabular-nums" x-text="stakeholders.responded.length"></p>
                                <p class="text-[10px] uppercase tracking-wider text-emerald-700 font-semibold">Replied</p>
                            </div>
                            <div class="rounded-lg bg-amber-50 px-2 py-1.5 min-w-0">
                                <p class="text-base font-bold text-amber-700 tabular-nums" x-text="stakeholders.silent.length"></p>
                                <p class="text-[10px] uppercase tracking-wider text-amber-700 font-semibold">Waiting</p>
                            </div>
                            <div class="rounded-lg bg-slate-50 px-2 py-1.5 min-w-0">
                                <p class="text-base font-bold text-slate-700 tabular-nums" x-text="stakeholders.notified.length"></p>
                                <p class="text-[10px] uppercase tracking-wider text-slate-700 font-semibold">Total</p>
                            </div>
                        </div>
                    </section>
                </div>
            </div>
        </aside>
    </div>

    {{-- ─────────────── STICKY ACTION BAR (mobile-first) ───────────────────── --}}
    <div class="sticky bottom-2 z-30 lg:static lg:bottom-auto lg:mt-2" x-show="!state.closed">
        <div class="rounded-2xl bg-slate-900 text-white shadow-2xl px-3 py-2.5 sm:px-4 sm:py-3 flex items-center gap-2 min-w-0">
            <button type="button" @click="showStepsSheet = true"
                    class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-xl bg-white/10 hover:bg-white/15 px-3 py-2 text-[12.5px] font-medium min-w-0">
                <span class="truncate">All steps</span>
                <span class="inline-flex items-center justify-center min-w-[18px] px-1.5 py-0.5 rounded-full bg-white/20 text-[10px] tabular-nums shrink-0" x-text="(progress.items || []).length"></span>
            </button>
            <button type="button" @click="showPeopleSheet = true"
                    class="flex-1 inline-flex items-center justify-center gap-1.5 rounded-xl bg-white/10 hover:bg-white/15 px-3 py-2 text-[12.5px] font-medium min-w-0">
                <span class="truncate">People</span>
                <span class="inline-flex items-center justify-center min-w-[18px] px-1.5 py-0.5 rounded-full bg-white/20 text-[10px] tabular-nums shrink-0" x-text="stakeholders.notified.length"></span>
            </button>
            <a :href="endpoints.casefile"
               class="hidden sm:inline-flex items-center justify-center gap-1.5 rounded-xl bg-white/10 hover:bg-white/15 px-3 py-2 text-[12.5px] font-medium">
                <span>Case file</span>
            </a>
            <button type="button" @click="showFalseAlarm = true"
                    class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-rose-500/90 hover:bg-rose-500 px-3 py-2 text-[12.5px] font-medium shrink-0">
                <span class="hidden sm:inline">False alarm</span>
                <span class="sm:hidden">Alarm</span>
            </button>
        </div>
    </div>

    {{-- ─────────────── STEPS SHEET (full-screen on mobile) ────────────────── --}}
    <div x-show="showStepsSheet" x-cloak
         class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-md flex items-end sm:items-center justify-center"
         @keydown.escape.window="showStepsSheet = false">
        <div class="bg-white w-full h-full sm:h-auto sm:max-h-[85vh] sm:max-w-xl sm:rounded-3xl shadow-2xl flex flex-col overflow-hidden"
             @click.away="showStepsSheet = false">
            <div class="flex justify-center pt-2 sm:hidden shrink-0"><span class="w-10 h-1 rounded-full bg-slate-300"></span></div>
            <header class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-3 shrink-0">
                <div class="min-w-0">
                    <h3 class="text-lg font-bold text-slate-900">All 14 steps</h3>
                    <p class="text-[12px] text-slate-500" x-text="`${doneCount()} done · ${naCount()} skipped · ${pendingCount()} still to do`"></p>
                </div>
                <button type="button" @click="showStepsSheet = false" class="inline-flex items-center justify-center w-9 h-9 rounded-full text-slate-500 hover:bg-slate-100 shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </header>
            <div class="overflow-y-auto p-4 sm:p-5">
                <ol class="space-y-2">
                    <template x-for="(item, i) in (progress.items || [])" :key="item.code">
                        <li :class="{
                            'flex items-start gap-3 rounded-xl px-3 py-2.5 min-w-0': true,
                            'bg-amber-50 ring-1 ring-amber-300': item.is_next,
                        }">
                            <span :class="{
                                'mt-0.5 inline-flex items-center justify-center w-6 h-6 rounded-full text-[11px] font-bold flex-shrink-0': true,
                                'bg-emerald-100 text-emerald-700': item.status_tone === 'done',
                                'bg-amber-100 text-amber-700':     item.status_tone === 'watch',
                                'bg-red-100 text-red-700':         item.status_tone === 'urgent',
                                'bg-slate-100 text-slate-500':     item.status_tone === 'skipped' || item.status_tone === 'info',
                            }" x-text="(i + 1)"></span>
                            <div class="min-w-0 flex-1">
                                <p class="text-[14px] font-medium leading-snug text-slate-900 break-words" x-text="item.title"></p>
                                <p class="text-[12px] text-slate-500 break-words" x-text="item.is_next ? 'Next step' : item.status_label"></p>
                            </div>
                        </li>
                    </template>
                </ol>
            </div>
        </div>
    </div>

    {{-- ─────────────── PEOPLE SHEET (full-screen on mobile) ───────────────── --}}
    <div x-show="showPeopleSheet" x-cloak
         class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-md flex items-end sm:items-center justify-center"
         @keydown.escape.window="showPeopleSheet = false">
        <div class="bg-white w-full h-full sm:h-auto sm:max-h-[85vh] sm:max-w-xl sm:rounded-3xl shadow-2xl flex flex-col overflow-hidden"
             @click.away="showPeopleSheet = false">
            <div class="flex justify-center pt-2 sm:hidden shrink-0"><span class="w-10 h-1 rounded-full bg-slate-300"></span></div>
            <header class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-3 shrink-0">
                <div class="min-w-0">
                    <h3 class="text-lg font-bold text-slate-900">Who is involved</h3>
                    <p class="text-[12px] text-slate-500" x-text="`${stakeholders.responded.length} replied · ${stakeholders.silent.length} waiting`"></p>
                </div>
                <button type="button" @click="showPeopleSheet = false" class="inline-flex items-center justify-center w-9 h-9 rounded-full text-slate-500 hover:bg-slate-100 shrink-0">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </header>
            <div class="overflow-y-auto p-4 sm:p-5 space-y-5">
                <section>
                    <p class="text-[11px] uppercase tracking-wider text-emerald-700 font-bold mb-2">{{ trans('alerts.wizard.stakeholders.responded') }}</p>
                    <ul class="space-y-2">
                        <template x-for="p in stakeholders.responded" :key="p.email">
                            <li class="rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 min-w-0">
                                <p class="text-sm font-semibold text-slate-900 break-words" x-text="p.name"></p>
                                <p class="text-[11.5px] text-slate-500 break-words" x-text="p.last_sent"></p>
                            </li>
                        </template>
                        <li class="text-[12.5px] italic text-slate-500" x-show="stakeholders.responded.length === 0">No replies yet.</li>
                    </ul>
                </section>
                <section>
                    <p class="text-[11px] uppercase tracking-wider text-amber-700 font-bold mb-2">{{ trans('alerts.wizard.stakeholders.silent') }}</p>
                    <ul class="space-y-2">
                        <template x-for="p in stakeholders.silent" :key="p.email">
                            <li class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 min-w-0">
                                <p class="text-sm font-semibold text-slate-900 break-words" x-text="p.name"></p>
                                <p class="text-[11.5px] text-slate-500 break-words" x-text="p.last_sent"></p>
                                <div class="mt-2 flex flex-wrap gap-1.5">
                                    <button class="inline-flex items-center rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50" @click="resendTo(p)">{{ trans('alerts.wizard.stakeholders.resend') }}</button>
                                    <button class="inline-flex items-center rounded-md border border-slate-300 bg-white px-2.5 py-1 text-xs font-medium text-slate-700 hover:bg-slate-50" @click="markCalled(p)">{{ trans('alerts.wizard.stakeholders.call') }}</button>
                                </div>
                            </li>
                        </template>
                        <li class="text-[12.5px] italic text-slate-500" x-show="stakeholders.silent.length === 0">Everyone we told has replied.</li>
                    </ul>
                </section>
            </div>
        </div>
    </div>

    {{-- ───────────────────── CLOSE SHEET (the actual form, scrolls inside) ─── --}}
    <div x-show="showCloseSheet" x-cloak
         class="fixed inset-0 z-50 bg-slate-950/70 backdrop-blur-md flex items-end sm:items-center justify-center"
         @keydown.escape.window="showCloseSheet = false">
        <div class="bg-white w-full h-full sm:h-auto sm:max-h-[90vh] sm:max-w-xl sm:rounded-3xl shadow-2xl flex flex-col overflow-hidden"
             @click.away="showCloseSheet = false">
            <div class="flex justify-center pt-2 sm:hidden shrink-0"><span class="w-10 h-1 rounded-full bg-slate-300"></span></div>
            <header class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-3 shrink-0 bg-white">
                <div class="min-w-0">
                    <h3 class="text-base sm:text-lg font-bold text-slate-900 break-words" x-text="step.title || 'Close this case'"></h3>
                    <p class="text-[12.5px] text-slate-500 break-words" x-text="step.subtitle || 'Pick a reason and close it.'"></p>
                </div>
                <button type="button" @click="showCloseSheet = false" class="inline-flex items-center justify-center w-9 h-9 rounded-full text-slate-500 hover:bg-slate-100 shrink-0" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </header>
            <form @submit.prevent="submitClose(); showCloseSheet = false;" class="flex-1 flex flex-col overflow-hidden">
                <div class="overflow-y-auto p-4 sm:p-5 space-y-4 grow min-w-0">
                    <div class="min-w-0">
                        <label class="block text-[11px] uppercase tracking-wider text-slate-500 font-semibold" x-text="step.category_label || 'Why are you closing it?'"></label>
                        <div class="mt-2 grid grid-cols-1 gap-2">
                            <template x-for="cat in step.close_categories" :key="cat.code">
                                <label :class="{
                                    'flex items-start gap-2 rounded-xl border-2 p-3 cursor-pointer min-w-0 transition active:scale-[0.99]': true,
                                    'border-blue-500 bg-blue-50': closeForm.category === cat.code,
                                    'border-slate-200 hover:border-slate-300': closeForm.category !== cat.code,
                                }">
                                    <input type="radio" :value="cat.code" x-model="closeForm.category" class="mt-1 shrink-0">
                                    <span class="min-w-0">
                                        <span class="block text-sm font-semibold text-slate-900 break-words" x-text="cat.label"></span>
                                        <span class="block text-[12.5px] text-slate-500 break-words" x-text="cat.help"></span>
                                    </span>
                                </label>
                            </template>
                        </div>
                    </div>
                    <div x-show="closeForm.category === 'OTHER'" x-cloak class="min-w-0">
                        <label class="block text-[11px] uppercase tracking-wider text-slate-500 font-semibold" x-text="step.note_label"></label>
                        <textarea x-model="closeForm.note" rows="3"
                                  class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm mt-1 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                                  placeholder="A short explanation."></textarea>
                    </div>
                    <div x-show="closeForm.category === 'DUPLICATE'" x-cloak class="min-w-0">
                        <label class="block text-[11px] uppercase tracking-wider text-slate-500 font-semibold" x-text="step.duplicate_label"></label>
                        <input type="number" x-model.number="closeForm.merged_into_alert_id"
                               class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm mt-1 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                               placeholder="Type the other alert's number">
                    </div>
                </div>
                <footer class="px-4 py-3 sm:px-5 sm:py-3 border-t border-slate-100 flex items-center justify-end gap-2 shrink-0 bg-white">
                    <button type="button" class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100" @click="showCloseSheet = false">Cancel</button>
                    <button type="submit"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-50"
                            :disabled="!canCloseSubmit() || state.submitting"
                            x-text="step.submit || 'Close the case'"></button>
                </footer>
            </form>
        </div>
    </div>

    {{-- ───────────────────── REASSIGN MODAL ─────────────────────────────────── --}}
    <div x-show="showReassign" x-cloak
         class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-slate-950/70 backdrop-blur-md p-0 sm:p-4 overflow-y-auto"
         @keydown.escape.window="showReassign = false; clearActionParam()">
        <div class="w-full sm:max-w-lg bg-white sm:rounded-3xl rounded-t-3xl shadow-2xl max-h-[92vh] overflow-y-auto"
             @click.away="showReassign = false; clearActionParam()">
            <div class="p-5 sm:p-6 space-y-4 min-w-0">
                <header class="min-w-0">
                    <h2 class="text-xl sm:text-2xl font-bold text-slate-900 break-words">Hand this case to someone else</h2>
                    <p class="mt-1 text-sm text-slate-600 break-words">Pick who you want to take over. They will get an email straight away.</p>
                </header>

                <div class="space-y-2">
                    <label class="block text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Who should take this on?</label>
                    <input type="search" x-model.debounce.250ms="reassignForm.q" @input="loadReassignCandidates()"
                           class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                           placeholder="Search by name, email, or role…">

                    <div class="max-h-64 overflow-y-auto rounded-xl border border-slate-200 divide-y divide-slate-100" x-show="reassignForm.candidates.length > 0 || reassignForm.loading">
                        <template x-for="c in reassignForm.candidates" :key="c.id">
                            <button type="button"
                                    :class="{ 'w-full flex items-start gap-3 px-3 py-2.5 text-left transition': true, 'bg-blue-50': reassignForm.user_id === c.id, 'hover:bg-slate-50': reassignForm.user_id !== c.id }"
                                    @click="reassignForm.user_id = c.id">
                                <span class="mt-0.5 inline-flex items-center justify-center w-8 h-8 rounded-full bg-slate-100 text-[11px] font-bold text-slate-600 shrink-0" x-text="initialsOf(c.full_name || c.email)"></span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-slate-900 break-words" x-text="c.full_name || c.email"></span>
                                    <span class="block text-[11.5px] text-slate-500 break-words" x-text="(c.role_key || '') + (c.email_valid === false ? ' · email needs fixing' : '')"></span>
                                </span>
                                <span class="text-blue-600 shrink-0" x-show="reassignForm.user_id === c.id">✓</span>
                            </button>
                        </template>
                        <p class="px-3 py-3 text-[12.5px] italic text-slate-400" x-show="reassignForm.loading">Searching…</p>
                        <p class="px-3 py-3 text-[12.5px] italic text-slate-400" x-show="!reassignForm.loading && reassignForm.candidates.length === 0 && reassignForm.q">No matches.</p>
                    </div>
                </div>

                <div class="min-w-0">
                    <label class="block text-[11px] uppercase tracking-wider text-slate-500 font-semibold">A short note (optional)</label>
                    <textarea x-model="reassignForm.reason" rows="2"
                              class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm mt-1 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                              placeholder="Why are you handing this over?"></textarea>
                </div>

                <div class="flex flex-wrap gap-2 justify-end pt-1">
                    <button type="button"
                            class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
                            @click="showReassign = false; clearActionParam()">Cancel</button>
                    <button type="button"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                            :disabled="state.submitting || !reassignForm.user_id"
                            @click="submitReassign()">Hand it over</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ───────────────────── ESCALATE MODAL ─────────────────────────────────── --}}
    <div x-show="showEscalate" x-cloak
         class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-slate-950/70 backdrop-blur-md p-0 sm:p-4 overflow-y-auto"
         @keydown.escape.window="showEscalate = false; clearActionParam()">
        <div class="w-full sm:max-w-lg bg-white sm:rounded-3xl rounded-t-3xl shadow-2xl max-h-[92vh] overflow-y-auto"
             @click.away="showEscalate = false; clearActionParam()">
            <div class="p-5 sm:p-6 space-y-4 min-w-0">
                <header class="min-w-0">
                    <h2 class="text-xl sm:text-2xl font-bold text-slate-900 break-words">Send this case higher up</h2>
                    <p class="mt-1 text-sm text-slate-600 break-words">Move this to the team that can act fastest. They will be told straight away.</p>
                </header>

                <div class="space-y-2">
                    <label class="block text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Send to which team?</label>
                    <div class="grid grid-cols-1 gap-2">
                        <template x-for="lv in escalateForm.levels" :key="lv.code">
                            <label :class="{
                                'flex items-start gap-2 rounded-xl border p-3 cursor-pointer min-w-0': true,
                                'border-blue-500 bg-blue-50 ring-1 ring-blue-300': escalateForm.to_level === lv.code,
                                'border-slate-200 hover:border-slate-300': escalateForm.to_level !== lv.code,
                            }">
                                <input type="radio" :value="lv.code" x-model="escalateForm.to_level" class="mt-1 shrink-0">
                                <span class="min-w-0">
                                    <span class="block text-sm font-medium text-slate-900 break-words" x-text="lv.label"></span>
                                    <span class="block text-[12px] text-slate-500 break-words" x-text="lv.help"></span>
                                </span>
                            </label>
                        </template>
                    </div>
                </div>

                <div class="min-w-0">
                    <label class="block text-[11px] uppercase tracking-wider text-slate-500 font-semibold">Why are you escalating? (required, at least 10 characters)</label>
                    <textarea x-model="escalateForm.reason" rows="3"
                              class="block w-full rounded-xl border border-slate-300 bg-white px-3 py-2 text-sm mt-1 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                              placeholder="What is making this need a higher team?"></textarea>
                </div>

                <div class="flex flex-wrap gap-2 justify-end pt-1">
                    <button type="button"
                            class="inline-flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100"
                            @click="showEscalate = false; clearActionParam()">Cancel</button>
                    <button type="button"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-amber-600 px-4 py-2 text-sm font-medium text-white hover:bg-amber-700 disabled:opacity-50"
                            :disabled="state.submitting || !escalateForm.to_level || (escalateForm.reason || '').trim().length < 10"
                            @click="submitEscalate()">Send it up</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ───────────────────── FALSE-ALARM MODAL ─────────────────────────────── --}}
    <div x-show="showFalseAlarm" x-cloak
         class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-4 overflow-y-auto"
         @keydown.escape.window="showFalseAlarm = false">
        <div class="w-full sm:max-w-md bg-white rounded-t-2xl sm:rounded-2xl shadow-xl max-h-[90vh] overflow-y-auto"
             @click.away="showFalseAlarm = false">
            <div class="p-5 sm:p-6 space-y-4 min-w-0">
                <header class="min-w-0">
                    <h2 class="text-lg font-bold text-slate-900 break-words">{{ trans('alerts.wizard.sweep.title') }}</h2>
                    <p class="mt-1 text-sm text-slate-600 break-words">{{ trans('alerts.wizard.sweep.subtitle') }}</p>
                </header>
                <textarea x-model="falseAlarmReason" rows="3"
                          class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                          placeholder="{{ trans('alerts.wizard.sweep.reason', ['min' => 10]) }}"></textarea>
                <div class="flex flex-wrap gap-2 justify-end">
                    <button type="button"
                            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100"
                            @click="showFalseAlarm = false">{{ trans('alerts.wizard.sweep.cancel') }}</button>
                    <button type="button"
                            class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                            :disabled="state.submitting || (falseAlarmReason || '').trim().length < 10"
                            @click="submitFalseAlarm()">{{ trans('alerts.wizard.sweep.confirm') }}</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ───────────────────── MASTER-CLOSE MODAL ────────────────────────────── --}}
    <div x-show="showMasterClose" x-cloak
         class="fixed inset-0 z-50 flex items-end sm:items-center justify-center bg-black/40 p-0 sm:p-4 overflow-y-auto"
         @keydown.escape.window="showMasterClose = false">
        <div class="w-full sm:max-w-lg bg-white rounded-t-2xl sm:rounded-2xl shadow-xl max-h-[90vh] overflow-y-auto"
             @click.away="showMasterClose = false">
            <div class="p-5 sm:p-6 space-y-4 min-w-0">
                <header class="min-w-0">
                    <h2 class="text-lg font-bold text-slate-900 break-words">Close on behalf of the team</h2>
                    <p class="mt-1 text-sm text-slate-600 break-words">
                        Tell us briefly why you are closing this without the field team's input. We will record everything for the audit trail.
                    </p>
                </header>
                <div class="min-w-0">
                    <label class="block text-sm font-medium text-slate-900 break-words" x-text="step.override_label"></label>
                    <textarea x-model="masterForm.override_reason" rows="3"
                              class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm mt-1 focus:outline-none focus:ring-2 focus:ring-blue-200 focus:border-blue-300"
                              placeholder="{{ trans('alerts.wizard.closure.override_min', ['min' => 30]) }}"></textarea>
                </div>
                <div class="min-w-0">
                    <label class="block text-sm font-medium text-slate-900 break-words" x-text="step.category_label"></label>
                    <select x-model="masterForm.close_category"
                            class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm mt-1">
                        <template x-for="cat in step.close_categories" :key="cat.code">
                            <option :value="cat.code" x-text="cat.label"></option>
                        </template>
                    </select>
                </div>
                <div x-show="masterForm.close_category === 'OTHER'" x-cloak class="min-w-0">
                    <label class="block text-sm font-medium text-slate-900">A short note</label>
                    <textarea x-model="masterForm.close_note" rows="2"
                              class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm mt-1"></textarea>
                </div>
                <div x-show="masterForm.close_category === 'DUPLICATE'" x-cloak class="min-w-0">
                    <label class="block text-sm font-medium text-slate-900">Which alert is this a duplicate of?</label>
                    <input type="number" x-model.number="masterForm.merged_into_alert_id"
                           class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm mt-1">
                </div>
                <div class="flex flex-wrap gap-2 justify-end">
                    <button type="button"
                            class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100"
                            @click="showMasterClose = false">Cancel</button>
                    <button type="button"
                            class="inline-flex items-center gap-1.5 rounded-md bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50"
                            :disabled="state.submitting || !canMasterCloseSubmit()"
                            @click="submitMasterClose()">Close on behalf of the team</button>
                </div>
            </div>
        </div>
    </div>

    {{-- ───────────────────── TOAST ─────────────────────────────────────────── --}}
    <div x-show="toast.show" x-cloak x-transition
         :class="{
            'fixed bottom-4 inset-x-4 sm:inset-x-auto sm:right-6 z-50 rounded-lg shadow-lg px-4 py-3 text-sm sm:max-w-sm break-words': true,
            'bg-rose-600 text-white':    toast.tone === 'error',
            'bg-emerald-600 text-white': toast.tone === 'ok',
         }"
         x-text="toast.message"></div>

</div>

<script>
function alertWizard(opts) {
    return {
        alertId:  opts.alertId,
        isSuper:  opts.isSuper,
        endpoints: opts.endpoints,

        step:        { kind: 'step', options: [], who_can_help: [] },
        progress:    { items: [], gating_count: 0, next_step_code: null },
        stakeholders:{ notified: [], responded: [], silent: [] },

        state: { loadingStep: true, loadingProgress: true, submitting: false, closed: false, closedMessage: '' },
        whyOpen: false,
        showStepsSheet: false,
        showPeopleSheet: false,
        showCloseSheet:  false,

        reasonOpen:  false,
        reasonText:  '',
        reasonPrompt:'',
        helpOpen:    false,

        showFalseAlarm:  false,
        falseAlarmReason:'',

        showMasterClose: false,
        masterForm: { override_reason: '', close_category: 'RESOLVED', close_note: '', merged_into_alert_id: null },

        closeForm:  { category: 'RESOLVED', note: '', merged_into_alert_id: null },

        showReassign: false,
        reassignForm: { q: '', candidates: [], user_id: null, reason: '', loading: false },

        showEscalate: false,
        escalateForm: {
            to_level: '',
            reason: '',
            levels: [
                { code: 'DISTRICT', label: 'District team',            help: 'For action on the ground in the district.' },
                { code: 'PHEOC',    label: 'Province response centre', help: 'For coordination across districts in the province.' },
                { code: 'NATIONAL', label: 'National response centre', help: 'For action that needs national authority or partners.' },
            ],
        },

        toast: { show: false, message: '', tone: 'ok', timer: null },

        async boot() {
            await Promise.all([this.loadStep(), this.loadProgress(), this.loadStakeholders()]);

            // Honour ?action=… so the master list can deep-link straight into a specific flow.
            const params = new URLSearchParams(window.location.search);
            const action = params.get('action');
            switch (action) {
                case 'false-alarm':  this.showFalseAlarm = true; break;
                case 'master-close': if (this.isSuper) this.showMasterClose = true; break;
                case 'reassign':     this.showReassign  = true;  this.loadReassignCandidates(); break;
                case 'escalate':     this.showEscalate  = true;  break;
            }
            // Clear the param so a refresh doesn't re-pop the modal.
            if (action) {
                const url = new URL(window.location);
                url.searchParams.delete('action');
                window.history.replaceState({}, '', url);
            }
        },

        clearActionParam() {
            try {
                const url = new URL(window.location);
                if (url.searchParams.has('action')) {
                    url.searchParams.delete('action');
                    window.history.replaceState({}, '', url);
                }
            } catch {}
        },

        initialsOf(name) {
            return (name || '?').trim().split(/\s+/).map(p => p[0] || '').join('').slice(0, 2).toUpperCase();
        },

        doneCount()    { return (this.progress.items || []).filter(i => i.status_tone === 'done').length; },
        naCount()      { return (this.progress.items || []).filter(i => i.status_tone === 'skipped').length; },
        pendingCount() { return (this.progress.items || []).filter(i => i.status_tone === 'urgent' || i.status_tone === 'watch').length; },

        csrfToken() { return document.querySelector('meta[name="csrf-token"]').content; },
        idempotencyKey() { return (crypto?.randomUUID?.() || (Date.now()+'-'+Math.random())); },

        showToast(message, tone='ok', ms=3000) {
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
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrfToken(), ...(init.headers || {}) },
                });
                const body = await res.json().catch(() => ({}));
                if (!res.ok || body.success === false) {
                    this.showToast(body?.error?.human || body?.message || 'Something went wrong. Try again.', 'error');
                    return null;
                }
                return body.data ?? body;
            } catch { this.showToast('Lost connection. Try again.', 'error'); return null; }
        },

        async loadStep() {
            this.state.loadingStep = true;
            const data = await this.fetchJson(this.endpoints.step);
            if (data) this.applyStep(data);
            this.state.loadingStep = false;
        },

        applyStep(data) {
            if (data.kind === 'closed') {
                this.state.closed = true;
                this.state.closedMessage = data.message || '';
                return;
            }
            const wasNotClosure = (this.step.kind || '') !== 'closure';
            this.step = Object.assign({ options: [], who_can_help: [] }, data);
            if (data.kind === 'closure') {
                this.closeForm.category = 'RESOLVED';
                this.closeForm.note = '';
                this.closeForm.merged_into_alert_id = null;
                // Auto-open the close sheet the moment we transition into the closure
                // state — saves the user a tap and stops the form rendering as a
                // page-scroll target. They can dismiss to review, then re-open.
                if (wasNotClosure) this.showCloseSheet = true;
            }
        },

        async loadProgress() {
            this.state.loadingProgress = true;
            const data = await this.fetchJson(this.endpoints.progress);
            if (data) this.progress = data;
            this.state.loadingProgress = false;
        },

        async loadStakeholders() {
            const data = await this.fetchJson(this.endpoints.stakeholders);
            if (data) this.stakeholders = data;
        },

        chooseOption(opt) {
            if (opt.code === 'NOT_APPLICABLE') {
                this.reasonPrompt = 'Tell us briefly why this does not apply.';
                this.reasonText   = '';
                this.reasonOpen   = true;
                return;
            }
            if (opt.code === 'NEED_HELP') { this.helpOpen = true; return; }
            this.submitAnswer(opt.code, {});
        },

        async confirmReason() {
            const reason = this.reasonText.trim();
            if (!reason) return;
            this.reasonOpen = false;
            await this.submitAnswer('NOT_APPLICABLE', { reason });
            this.reasonText = '';
        },

        async submitAnswer(optionCode, extra) {
            this.state.submitting = true;
            const data = await this.fetchJson(this.endpoints.answer, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Idempotency-Key': this.idempotencyKey() },
                body: JSON.stringify({ step_code: this.step.step_code, option_code: optionCode, ...extra }),
            });
            this.state.submitting = false;
            if (data) { this.applyStep(data); await this.loadProgress(); }
        },

        canCloseSubmit() {
            if (!this.closeForm.category) return false;
            if (this.closeForm.category === 'OTHER'    && !(this.closeForm.note || '').trim()) return false;
            if (this.closeForm.category === 'DUPLICATE' && !this.closeForm.merged_into_alert_id) return false;
            return true;
        },

        async submitClose() {
            if (!this.canCloseSubmit()) return;
            this.state.submitting = true;
            const data = await this.fetchJson(this.endpoints.closeAlert, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Idempotency-Key': this.idempotencyKey() },
                body: JSON.stringify({
                    close_category:       this.closeForm.category,
                    close_note:           this.closeForm.note || null,
                    merged_into_alert_id: this.closeForm.merged_into_alert_id || null,
                }),
            });
            this.state.submitting = false;
            if (data) { this.state.closed = true; this.state.closedMessage = 'Everyone we told has been notified.'; this.showToast('Case closed.', 'ok'); }
        },

        canMasterCloseSubmit() {
            const f = this.masterForm;
            if ((f.override_reason || '').trim().length < 30) return false;
            if (!f.close_category) return false;
            if (f.close_category === 'OTHER'    && !(f.close_note || '').trim()) return false;
            if (f.close_category === 'DUPLICATE' && !f.merged_into_alert_id) return false;
            return true;
        },

        async submitMasterClose() {
            this.state.submitting = true;
            const data = await this.fetchJson(this.endpoints.masterClose, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Idempotency-Key': this.idempotencyKey() },
                body: JSON.stringify(this.masterForm),
            });
            this.state.submitting = false;
            this.showMasterClose = false;
            if (data) { this.state.closed = true; this.state.closedMessage = data.message || 'Closed on behalf of the team.'; this.showToast('Case closed on behalf of the team.', 'ok'); }
        },

        async submitFalseAlarm() {
            const reason = (this.falseAlarmReason || '').trim();
            if (reason.length < 10) return;
            this.state.submitting = true;
            const data = await this.fetchJson(this.endpoints.falseAlarm, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Idempotency-Key': this.idempotencyKey() },
                body: JSON.stringify({ reason }),
            });
            this.state.submitting = false;
            this.showFalseAlarm = false;
            this.falseAlarmReason = '';
            if (data) { this.state.closed = true; this.state.closedMessage = data.message || 'Closed as a false alarm.'; this.showToast('Closed as a false alarm.', 'ok'); }
        },

        async resendTo(person) {
            await this.fetchJson(this.endpoints.contact, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Idempotency-Key': this.idempotencyKey() },
                body: JSON.stringify({ action: 'RESEND_EMAIL', message: '', contact_email: person.email, contact_name: person.name, template_code: 'WIZARD_REMIND_RESPONDER' }),
            });
            this.showToast('Reminder sent.', 'ok');
            this.loadStakeholders();
        },

        async loadReassignCandidates() {
            this.reassignForm.loading = true;
            const params = new URLSearchParams();
            if (this.reassignForm.q) params.set('q', this.reassignForm.q);
            const data = await this.fetchJson('{{ route('admin.alerts.reassign-candidates') }}?' + params.toString());
            this.reassignForm.loading = false;
            if (data) this.reassignForm.candidates = data.rows || data.candidates || data || [];
        },

        async submitReassign() {
            if (!this.reassignForm.user_id) return;
            this.state.submitting = true;
            const data = await this.fetchJson('{{ route('admin.alerts.reassign', ['id' => $alert['id']]) }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Idempotency-Key': this.idempotencyKey() },
                body: JSON.stringify({ to_user_id: this.reassignForm.user_id, user_id: this.reassignForm.user_id, reason: this.reassignForm.reason || null }),
            });
            this.state.submitting = false;
            if (data) {
                this.showReassign = false;
                this.clearActionParam();
                this.showToast('Handed over.', 'ok');
                setTimeout(() => window.location.href = '{{ route('admin.alerts.index') }}', 600);
            }
        },

        async submitEscalate() {
            const reason = (this.escalateForm.reason || '').trim();
            if (!this.escalateForm.to_level || reason.length < 10) return;
            this.state.submitting = true;
            const data = await this.fetchJson('{{ route('admin.alerts.escalate', ['id' => $alert['id']]) }}', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Idempotency-Key': this.idempotencyKey() },
                body: JSON.stringify({ to_level: this.escalateForm.to_level, reason }),
            });
            this.state.submitting = false;
            if (data) {
                this.showEscalate = false;
                this.clearActionParam();
                this.showToast('Sent up.', 'ok');
                setTimeout(() => window.location.href = '{{ route('admin.alerts.index') }}', 600);
            }
        },

        async markCalled(person) {
            await this.fetchJson(this.endpoints.contact, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Idempotency-Key': this.idempotencyKey() },
                body: JSON.stringify({ action: 'MARKED_CALLED', message: '', contact_email: person.email || null, contact_name: person.name || null }),
            });
            this.showToast('Recorded that you spoke by phone.', 'ok');
            this.helpOpen = false;
        },

    };
}
</script>
@endsection
