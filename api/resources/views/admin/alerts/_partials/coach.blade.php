{{--
    Coach + interpretation-modal + wizard-launcher partial.
    One Alpine component shared by the six Alert Operations sub-views.

    USAGE
        @include('admin.alerts._partials.coach', [
            'viewKey'      => 'followups',                 // unique per view
            'viewTitle'    => 'Case follow-ups',
            'oneLiner'     => 'Plain-English screen description.',
            'why'          => 'Why this screen exists.',
            'youDo'        => 'What the operator should do.',
            'connects'     => 'How it connects to other views.',
            'glossary'     => [['term'=>'…','plain'=>'…'], …],
            'wizardOptions'=> [['code'=>'…','label'=>'…','help'=>'…','glyph'=>'·','tone'=>'bg-slate-100 text-slate-700'], …],
            'charts'       => [['key'=>'…','title'=>'…','shows'=>'','read'=>'','good'=>'','concerning'=>'','do'=>'','cant'=>'','source'=>''], …],
        ])

    The component exposes a global `window.alertCoach.<viewKey>` API
    (open/close + interp(chartKey)) so each view's Alpine root can
    call into it without prop-drilling.

    Visual language follows the alert-hub: gradient hero strip with
    soft expandable explainer + chip launcher + interpretation modal.
    No new utility classes — uses theme primitives only.
--}}
@php
    $viewKey       = $viewKey       ?? 'view';
    $viewTitle     = $viewTitle     ?? 'This screen';
    $oneLiner      = $oneLiner      ?? '';
    $why           = $why           ?? '';
    $youDo         = $youDo         ?? '';
    $connects      = $connects      ?? '';
    $glossary      = $glossary      ?? [];
    $wizardOptions = $wizardOptions ?? [];
    $charts        = $charts        ?? [];
    $coachId       = 'alertCoach_' . preg_replace('/[^A-Za-z0-9_]/', '', $viewKey);
@endphp

<section
    id="{{ $coachId }}"
    x-data="alertCoach_{{ $coachId }}()"
    x-init="boot()"
    class="space-y-3"
>
    {{-- COACH STRIP — gradient, mobile-first, dismissible memory per session --}}
    <section class="rounded-3xl bg-gradient-to-br from-slate-900 to-slate-800 text-white shadow-xl overflow-hidden">
        <div class="px-5 py-4 sm:px-6 sm:py-5 space-y-3">
            <div class="flex items-start gap-3 min-w-0">
                <div class="grid place-items-center h-9 w-9 rounded-xl bg-white/10 shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-300 font-semibold">Quick guide · {{ $viewTitle }}</p>
                    <p class="mt-1 text-[13.5px] sm:text-sm leading-snug break-words">{!! e($oneLiner) !!}</p>
                </div>
                <button type="button"
                        class="inline-flex items-center gap-1 rounded-full border border-white/20 bg-white/5 px-2.5 py-1 text-[11px] font-medium text-slate-100 hover:bg-white/10 shrink-0"
                        @click="expanded = !expanded">
                    <span x-show="!expanded">More</span>
                    <span x-show="expanded">Less</span>
                </button>
            </div>

            <div x-show="expanded" x-collapse class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
                <div class="rounded-2xl bg-white/5 border border-white/10 px-3.5 py-3">
                    <p class="text-[10px] uppercase tracking-[0.12em] text-slate-300 font-semibold">Why this exists</p>
                    <p class="mt-1 text-[12px] leading-snug break-words">{!! e($why) !!}</p>
                </div>
                <div class="rounded-2xl bg-white/5 border border-white/10 px-3.5 py-3">
                    <p class="text-[10px] uppercase tracking-[0.12em] text-slate-300 font-semibold">What you do here</p>
                    <p class="mt-1 text-[12px] leading-snug break-words">{!! e($youDo) !!}</p>
                </div>
                <div class="rounded-2xl bg-white/5 border border-white/10 px-3.5 py-3">
                    <p class="text-[10px] uppercase tracking-[0.12em] text-slate-300 font-semibold">How it connects</p>
                    <p class="mt-1 text-[12px] leading-snug break-words">{!! e($connects) !!}</p>
                </div>
            </div>

            @if(!empty($glossary))
                <div x-show="expanded" x-collapse class="pt-2 border-t border-white/10">
                    <p class="text-[10px] uppercase tracking-[0.12em] text-slate-300 font-semibold">Words you'll see</p>
                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach($glossary as $g)
                            <button type="button"
                                    class="inline-flex items-center gap-1 rounded-full border border-white/20 bg-white/5 px-2.5 py-1 text-[11px] text-slate-100 hover:bg-white/10"
                                    @click="openTerm(@js($g))">
                                <span>{{ $g['term'] }}</span>
                                <span class="text-slate-300">?</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

            @if(!empty($wizardOptions))
                <div class="pt-2 flex items-center gap-2">
                    <button type="button"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-white text-slate-900 px-3 py-1.5 text-[12.5px] font-semibold hover:bg-slate-100 shadow"
                            @click="wizard.open = true">
                        <span>What would you like to do?</span>
                        <span class="text-slate-500">→</span>
                    </button>
                    <span class="text-[11px] text-slate-300 hidden sm:inline">Pick a guided step. Each one walks you through it.</span>
                </div>
            @endif
        </div>
    </section>

    {{-- TERM EXPLAINER MODAL --}}
    <div x-show="term.open" x-cloak
         class="fixed inset-0 z-[60] bg-slate-950/70 backdrop-blur-md flex items-end sm:items-center justify-center"
         @keydown.escape.window="term.open = false">
        <div class="bg-white w-full sm:max-w-md sm:rounded-3xl shadow-2xl overflow-hidden" @click.away="term.open = false">
            <header class="px-5 sm:px-6 pt-5 pb-3 border-b border-slate-100">
                <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-500 font-semibold">In plain English</p>
                <h3 class="mt-1 text-base sm:text-lg font-bold text-slate-900" x-text="term.row?.term"></h3>
            </header>
            <div class="px-5 sm:px-6 py-4 text-[13px] text-slate-700 leading-relaxed">
                <p x-text="term.row?.plain"></p>
                <p class="mt-3 text-[11.5px] text-slate-500" x-show="term.row?.technical"><span class="font-semibold">Technical detail:</span> <span x-text="term.row?.technical"></span></p>
            </div>
            <footer class="px-5 sm:px-6 py-3 border-t border-slate-100 flex justify-end">
                <button type="button" class="inline-flex items-center rounded-xl bg-slate-900 text-white px-3 py-1.5 text-[12px] font-semibold" @click="term.open = false">Got it</button>
            </footer>
        </div>
    </div>

    {{-- WIZARD LAUNCHER MODAL ("What do you want to do?") --}}
    <div x-show="wizard.open" x-cloak
         class="fixed inset-0 z-[60] bg-slate-950/70 backdrop-blur-md flex items-end sm:items-center justify-center"
         @keydown.escape.window="wizard.open = false">
        <div class="bg-white w-full h-full sm:h-auto sm:max-h-[88vh] sm:max-w-2xl sm:rounded-3xl shadow-2xl flex flex-col overflow-hidden" @click.away="wizard.open = false">
            <header class="px-5 sm:px-7 pt-5 pb-3 shrink-0 border-b border-slate-100">
                <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-500 font-semibold">{{ $viewTitle }}</p>
                <h3 class="mt-1 text-lg sm:text-xl font-bold text-slate-900">What would you like to do?</h3>
                <p class="mt-1 text-[12.5px] text-slate-500">Each option is a guided walk-through. Nothing happens until you confirm.</p>
            </header>
            <div class="overflow-y-auto px-5 sm:px-7 py-4 grow">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 sm:gap-3">
                    @foreach($wizardOptions as $opt)
                        <button type="button"
                                @click="wizard.open = false; window.dispatchEvent(new CustomEvent('alert-coach:wizard', {detail:{view:'{{ $viewKey }}', code:'{{ $opt['code'] }}'}}))"
                                class="group relative rounded-2xl border border-slate-200 bg-white p-4 text-left hover:border-slate-400 hover:shadow-md active:scale-[0.985] transition min-w-0 overflow-hidden">
                            <div class="flex items-start gap-3 min-w-0">
                                <span class="mt-0.5 inline-flex items-center justify-center w-9 h-9 rounded-xl text-base shrink-0 {{ $opt['tone'] ?? 'bg-slate-100 text-slate-700' }}">{{ $opt['glyph'] ?? '·' }}</span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[13.5px] font-semibold text-slate-900 leading-snug break-words">{{ $opt['label'] }}</span>
                                    <span class="block mt-1 text-[12px] text-slate-500 break-words">{{ $opt['help'] }}</span>
                                </span>
                                <span class="text-slate-300 group-hover:text-slate-500 shrink-0">→</span>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
            <footer class="px-5 sm:px-7 py-3 border-t border-slate-100 shrink-0 flex justify-end">
                <button type="button" class="inline-flex items-center rounded-xl border border-slate-300 bg-white px-3 py-1.5 text-[12px] font-medium text-slate-700 hover:bg-slate-50" @click="wizard.open = false">Cancel</button>
            </footer>
        </div>
    </div>

    {{-- CHART INTERPRETATION MODAL --}}
    <div x-show="interp.open" x-cloak
         class="fixed inset-0 z-[60] bg-slate-950/70 backdrop-blur-md flex items-end sm:items-center justify-center"
         @keydown.escape.window="interp.open = false">
        <div class="bg-white w-full sm:max-w-lg sm:rounded-3xl shadow-2xl flex flex-col overflow-hidden max-h-[88vh]" @click.away="interp.open = false">
            <header class="px-5 sm:px-6 pt-5 pb-3 shrink-0 border-b border-slate-100">
                <p class="text-[10.5px] uppercase tracking-[0.12em] text-slate-500 font-semibold">How to read this chart</p>
                <h3 class="mt-1 text-base sm:text-lg font-bold text-slate-900" x-text="interp.row?.title"></h3>
            </header>
            <div class="overflow-y-auto px-5 sm:px-6 py-4 grow text-[13px] leading-relaxed text-slate-700 space-y-3">
                <p><span class="font-semibold text-slate-900">What it shows.</span> <span x-text="interp.row?.shows"></span></p>
                <p><span class="font-semibold text-slate-900">How to read it.</span> <span x-text="interp.row?.read"></span></p>
                <p><span class="font-semibold text-emerald-700">Good pattern.</span> <span x-text="interp.row?.good"></span></p>
                <p><span class="font-semibold text-rose-700">Concerning pattern.</span> <span x-text="interp.row?.concerning"></span></p>
                <p><span class="font-semibold text-slate-900">If concerning, do this.</span> <span x-text="interp.row?.do"></span></p>
                <p><span class="font-semibold text-slate-900">It cannot tell you.</span> <span x-text="interp.row?.cant"></span></p>
                <p class="text-[11.5px] text-slate-500 pt-2 border-t border-slate-100"><span class="font-semibold">Where the data comes from.</span> <span x-text="interp.row?.source"></span></p>
            </div>
            <footer class="px-5 sm:px-6 py-3 border-t border-slate-100 shrink-0 flex justify-end">
                <button type="button" class="inline-flex items-center rounded-xl bg-slate-900 text-white px-3 py-1.5 text-[12px] font-semibold" @click="interp.open = false">Got it</button>
            </footer>
        </div>
    </div>
</section>

@push('scripts')
<script>
function alertCoach_{{ $coachId }}(){
    const STORAGE = 'alertCoach.{{ $viewKey }}.expanded';
    const CHARTS  = @json(array_values($charts));
    return {
        expanded: false,
        wizard:   { open: false },
        term:     { open: false, row: null },
        interp:   { open: false, row: null },

        boot(){
            try {
                const saved = sessionStorage.getItem(STORAGE);
                if (saved === null) {
                    this.expanded = true;            // open by default first time per session
                    sessionStorage.setItem(STORAGE, '0');
                } else {
                    this.expanded = saved === '1';
                }
            } catch(e) { this.expanded = true; }
            this.$watch('expanded', v => { try { sessionStorage.setItem(STORAGE, v ? '1' : '0'); } catch(e){} });

            // Expose a tiny global API so each view's Alpine root
            // can request an interpretation modal without prop-drilling.
            window.alertCoach = window.alertCoach || {};
            window.alertCoach['{{ $viewKey }}'] = {
                interp: (key) => {
                    const row = (CHARTS || []).find(c => c.key === key);
                    if (!row) return;
                    this.interp.row  = row;
                    this.interp.open = true;
                },
                term: (term, plain, technical='') => {
                    this.term.row  = { term, plain, technical };
                    this.term.open = true;
                },
                wizard: () => { this.wizard.open = true; },
            };
        },

        openTerm(row){ this.term.row = row; this.term.open = true; },
    };
}
</script>
@endpush
