{{-- Reusable Data Notes footer — renders $dataNotes blade variable or Alpine x-data.data_notes --}}
<section class="card">
    <div class="card-content">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h2 class="text-[14px] font-semibold">Data notes</h2>
                <p class="help-text">Methodology, thresholds, definitions, and scope rules used to build this report.</p>
            </div>
            <button type="button" class="text-[11px] text-brand hover:underline" @click="notesOpen = !notesOpen"
                    aria-label="Toggle data notes">
                <span x-text="notesOpen ? 'Hide' : 'Show'"></span>
            </button>
        </div>
        <dl x-show="notesOpen" x-collapse x-cloak class="grid grid-cols-1 md:grid-cols-2 gap-4 text-[12px] leading-relaxed">
            <template x-for="(val, key) in (dataNotes || {})" :key="key">
                <div>
                    <dt class="font-semibold text-foreground capitalize" x-text="key.replace(/_/g, ' ')"></dt>
                    <dd class="text-muted-foreground mt-0.5" x-text="val"></dd>
                </div>
            </template>
        </dl>
    </div>
</section>
