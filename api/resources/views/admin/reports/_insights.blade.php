{{-- Reusable AI Insights panel — bound to Alpine `insights` array --}}
<section class="card" aria-labelledby="insights-heading">
    <div class="card-content !p-0">
        <div class="flex items-center justify-between p-4 sm:p-5 border-b">
            <div>
                <h2 id="insights-heading" class="text-[14px] font-semibold flex items-center gap-2">
                    <svg class="h-4 w-4 text-brand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                    AI Insights
                </h2>
                <p class="help-text">Deterministic rule-based insights. Every conclusion cites the rule that fired.</p>
            </div>
            <span class="text-[11px] text-muted-foreground" aria-live="polite">
                <span x-text="(insights || []).length"></span>
                <span>findings</span>
            </span>
        </div>
        <div class="p-4 sm:p-5 space-y-2" role="list">
            <template x-if="!insights || insights.length === 0">
                <div class="empty-state py-6" role="listitem">
                    <p class="text-sm text-muted-foreground">Run the report to see insights.</p>
                </div>
            </template>
            <template x-for="(ins, i) in (insights || [])" :key="i">
                <div class="rounded-md border px-3 py-2.5 flex items-start gap-2.5"
                     :class="{
                        'border-critical/50 bg-critical-soft/40': ins.level === 'critical',
                        'border-warning/50  bg-warning-soft/40':  ins.level === 'warning',
                        'border-brand/40    bg-brand-soft/30':    ins.level === 'info',
                        'border-success/40  bg-success-soft/30':  ins.level === 'success',
                        'border-border':                          ins.level === 'note',
                     }"
                     role="listitem">
                    <span class="mt-0.5 inline-flex h-5 w-5 items-center justify-center rounded-full shrink-0 text-[10px] font-bold uppercase"
                          :class="{
                            'bg-critical text-critical-foreground': ins.level === 'critical',
                            'bg-warning  text-warning-foreground':  ins.level === 'warning',
                            'bg-brand    text-white':               ins.level === 'info',
                            'bg-success  text-success-foreground':  ins.level === 'success',
                            'bg-muted    text-muted-foreground':    ins.level === 'note',
                          }"
                          x-text="ins.level?.charAt(0)?.toUpperCase() || '•'"
                          aria-hidden="true"></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[12.5px] font-semibold text-foreground" x-text="ins.title"></p>
                        <p class="text-[11.5px] text-muted-foreground mt-0.5 leading-relaxed" x-text="ins.body"></p>
                        <p class="text-[10px] font-mono text-muted-foreground/70 mt-1">rule: <span x-text="ins.rule"></span></p>
                    </div>
                </div>
            </template>
        </div>
    </div>
</section>
