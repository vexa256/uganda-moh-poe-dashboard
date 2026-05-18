{{--
    admin.governance._partials.coach-overlay

    Self-contained v4 Coach overlay for any Governance view. Sibling to
    the page's main x-data root — does NOT interfere with the page's
    Alpine state. Renders:

        · soft intro strip at the top (collapsible to purpose / audience
          / prerequisites, with "Walk me through" toggle and a guide
          button)
        · coach side-sheet (full guide + glossary)
        · comparison side-by-side modal
        · post-action success modal — triggered by the page via the
          custom event "gov-post-action" with detail { title, body,
          changed[], notified, next }

    Required in scope:
        · $coach   — array from \App\Support\Governance\CoachManifest::forView($key)
        · $viewKey — short id ('auth', 'notif-log', …) used as a
                     localStorage namespace for the step-through pref

    Mobile-API impact: NONE.
--}}
@php
    $viewKey = $viewKey ?? ($coach['_view_id'] ?? 'unknown');
@endphp

<div x-data="govCoachOverlay({{ Js::from($viewKey) }})" x-init="boot()" class="contents">

    <script type="application/json" :id="`gov-coach-${viewKey}`">@json($coach)</script>

    {{-- ── Intro strip ───────────────────────────────────────────────── --}}
    <section class="rounded-2xl border bg-card shadow-sm">
        <button type="button" class="w-full text-left flex items-start gap-3 px-4 sm:px-5 py-3"
                @click="introOpen=!introOpen" :aria-expanded="introOpen">
            <span class="grid place-items-center h-8 w-8 rounded-lg bg-brand-soft text-brand-ink shrink-0 mt-0.5">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 18v-5m0-3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <span class="flex-1 min-w-0">
                <span class="block text-[12.5px] font-semibold leading-tight" x-text="coach.view.title"></span>
                <span class="block text-[11.5px] text-muted-foreground mt-0.5 leading-snug" x-text="coach.view.header_intro"></span>
            </span>
            <span class="shrink-0 flex items-center gap-2">
                <label class="hidden sm:flex items-center gap-1.5 text-[10.5px] text-muted-foreground" @click.stop>
                    <input type="checkbox" x-model="stepThru" @change="persistPref('stepthru', stepThru)">
                    <span>Walk me through</span>
                </label>
                <button type="button" class="btn btn-ghost btn-icon-xs" @click.stop="coachSheet.open=true" title="Glossary &amp; full guide">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </button>
                <svg class="h-4 w-4 text-muted-foreground transition-transform" :class="introOpen?'rotate-180':''" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 9l-7 7-7-7"/></svg>
            </span>
        </button>
        <div x-show="introOpen" x-cloak x-collapse class="px-4 sm:px-5 pb-4 border-t pt-3 space-y-2.5 text-[12px]">
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
            <div class="flex flex-wrap gap-2 pt-1" x-show="actionsArr.length">
                <button class="btn btn-outline btn-xs" @click="compareSheet.open=true" x-show="actionsArr.length>1">Compare every action</button>
                <button class="btn btn-outline btn-xs" @click="coachSheet.open=true">Open glossary</button>
            </div>
        </div>
    </section>

    {{-- ── Comparison sheet ──────────────────────────────────────────── --}}
    <template x-if="compareSheet.open">
        <div class="fixed inset-0 z-[58] flex items-end sm:items-center justify-center p-0 sm:p-4" role="dialog" aria-modal="true" @keydown.escape.window="compareSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="compareSheet.open=false"></div>
            <div class="relative w-full sm:max-w-3xl bg-card border-t sm:border sm:rounded-2xl shadow-elevation-5 max-h-[85vh] flex flex-col" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="min-w-0 flex-1"><p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Compare actions</p><h2 class="text-[14px] font-bold">Side-by-side: which one fits?</h2></div>
                    <button class="btn btn-ghost btn-icon-xs" @click="compareSheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-auto">
                    <table class="w-full text-[11.5px]">
                        <thead class="sticky top-0 bg-card z-10 text-[10px] uppercase tracking-wider text-muted-foreground"><tr class="border-b">
                            <th class="text-left px-4 py-2 sticky left-0 bg-card">Action</th>
                            <template x-for="col in (coach.comparison_columns||['When this fits','Heads-up','Reversible','Time'])" :key="col"><th class="text-left px-3 py-2" x-text="col"></th></template>
                        </tr></thead>
                        <tbody>
                            <template x-for="a in actionsArr" :key="a.id">
                                <tr class="border-b align-top">
                                    <td class="px-4 py-2.5 sticky left-0 bg-card"><p class="font-semibold" x-text="a.label"></p><p class="text-muted-foreground text-[10.5px]" x-text="a.one_liner"></p></td>
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

    {{-- ── Coach side-sheet (full guide + glossary) ──────────────────── --}}
    <template x-if="coachSheet.open">
        <div class="fixed inset-0 z-[58] flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="coachSheet.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="coachSheet.open=false"></div>
            <div class="relative w-full sm:max-w-md bg-card border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-4 sm:px-6 py-3 border-b">
                    <div class="min-w-0 flex-1"><p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">Guide</p><h2 class="text-[14px] font-bold" x-text="coach.view.title"></h2></div>
                    <button class="btn btn-ghost btn-icon-xs" @click="coachSheet.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 sm:px-6 py-5 space-y-5 text-[12px]">
                    <section x-show="actionsArr.length">
                        <h3 class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">What you can do here</h3>
                        <ul class="space-y-2">
                            <template x-for="a in actionsArr" :key="'g-'+a.id">
                                <li class="rounded-lg border p-2.5">
                                    <p class="font-semibold" x-text="a.label"></p>
                                    <p class="text-[11px] text-muted-foreground mt-0.5" x-text="a.one_liner"></p>
                                    <p class="text-[10.5px] mt-1" x-show="a.when_to_use"><span class="text-muted-foreground">When this fits: </span><span x-text="a.when_to_use"></span></p>
                                    <p class="text-[10.5px] mt-0.5 text-warning" x-show="a.when_not_to_use">Heads-up: <span x-text="a.when_not_to_use"></span></p>
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

    {{-- ── Post-action modal (event-driven) ──────────────────────────── --}}
    <template x-if="postAction.open">
        <div class="fixed inset-0 z-[63] flex items-center justify-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="postAction.open=false">
            <div class="absolute inset-0 bg-black/60 backdrop-blur-sm" @click="postAction.open=false"></div>
            <div class="relative w-full max-w-md bg-card border rounded-2xl shadow-elevation-5 p-5" @click.stop>
                <div class="flex items-start gap-3">
                    <div class="grid place-items-center h-10 w-10 rounded-full bg-success-soft text-success shrink-0"><svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg></div>
                    <div class="min-w-0 flex-1"><h3 class="text-[14px] font-bold" x-text="postAction.title || 'Recorded'"></h3><p class="text-[12px] text-muted-foreground mt-1" x-text="postAction.body"></p></div>
                </div>
                <ul class="mt-3 list-disc pl-5 text-[11.5px] space-y-1" x-show="(postAction.changed||[]).length"><template x-for="c in (postAction.changed||[])" :key="c"><li x-text="c"></li></template></ul>
                <p class="text-[11px] text-muted-foreground mt-3" x-show="postAction.notified"><span class="font-semibold text-foreground">Who was notified:</span> <span x-text="postAction.notified"></span></p>
                <p class="text-[11px] text-muted-foreground mt-1" x-show="postAction.next" x-text="postAction.next"></p>
                <div class="flex justify-end mt-5"><button class="btn btn-brand btn-sm" @click="postAction.open=false">Close</button></div>
            </div>
        </div>
    </template>
</div>

@once
@push('scripts')
<script>
    /**
     * govCoachOverlay(viewKey) — Alpine root for the Governance coach
     * overlay partial. Sibling-scope to the page's main x-data so it
     * never collides with page state.
     *
     * Pages can pop a v4 success modal by dispatching a CustomEvent:
     *   window.dispatchEvent(new CustomEvent('gov-post-action', {detail:{
     *       title, body, changed:[…], notified, next
     *   }}));
     */
    function govCoachOverlay(viewKey) {
        return {
            viewKey,
            coach: {},
            actionsArr: [],
            introOpen: false,
            stepThru: false,
            coachSheet: { open: false },
            compareSheet: { open: false },
            postAction: { open: false, title: '', body: '', changed: [], notified: '', next: '' },
            meId: parseInt(document.querySelector('meta[name="user-id"]')?.content || '0', 10) || 0,

            boot() {
                try {
                    const node = document.getElementById('gov-coach-' + this.viewKey);
                    this.coach = JSON.parse(node?.textContent || '{}') || {};
                } catch (e) { this.coach = {}; }
                this.coach.glossary = this.coach.glossary || [];
                this.coach.comparison_columns = this.coach.comparison_columns || ['When this fits','Heads-up','Reversible','Time'];
                this.actionsArr = Object.values(this.coach.actions || {});
                this.stepThru = this.readPref('stepthru', false);

                // Listen for post-action events from the page.
                window.addEventListener('gov-post-action', (ev) => {
                    const d = ev.detail || {};
                    this.postAction = {
                        open: true,
                        title: d.title || 'Recorded',
                        body: d.body || '',
                        changed: Array.isArray(d.changed) ? d.changed : [],
                        notified: d.notified || '',
                        next: d.next || '',
                    };
                });
            },

            prefKey(k) { return 'gov:' + this.viewKey + ':' + k + ':' + this.meId; },
            readPref(k, def) { try { const v = localStorage.getItem(this.prefKey(k)); return v === null ? def : JSON.parse(v); } catch (e) { return def; } },
            persistPref(k, v) { try { localStorage.setItem(this.prefKey(k), JSON.stringify(v)); } catch (e) {} },
        };
    }
</script>
@endpush
@endonce
