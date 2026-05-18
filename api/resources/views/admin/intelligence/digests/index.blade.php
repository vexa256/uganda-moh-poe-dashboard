{{-- Admin · Intelligence · Digest Builder (intel-digests) --}}
@extends('admin.layout')
@section('crumb', 'Intelligence')
@section('title', $page_title)

@section('content')
<div x-data="digestsPage()" x-init="boot()" class="space-y-5">
    <section class="flex flex-col md:flex-row md:items-end md:justify-between gap-3">
        <div class="min-w-0">
            <p class="eyebrow">Intelligence · Scheduled fan-out</p>
            <h2 class="display-md mt-1">Digest Builder</h2>
            <p class="text-sm text-muted-foreground mt-1 max-w-xl">
                Daily 07:00 + 3-day national 08:00 · preview · manual trigger · cron history.
                Manual runs are audited with <span class="font-mono">triggered_by=MANUAL:&lt;user_id&gt;</span>.
            </p>
        </div>
        <button type="button" class="btn btn-brand btn-sm" @click="loadSummary()">Refresh</button>
    </section>

    <section class="grid grid-cols-1 md:grid-cols-2 gap-3">
        <template x-for="d in (summary?.digests || [])" :key="'dg-' + d.template_code">
            <div class="kpi kpi-glow">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <p class="kpi-label" x-text="d.label"></p>
                        <p class="text-[11px] text-muted-foreground mt-0.5" x-text="d.cadence"></p>
                    </div>
                    <span class="badge font-mono"
                          :class="d.overdue ? 'badge-critical' : (d.last_sent_at ? 'badge-success' : 'badge-warning')"
                          x-text="d.overdue ? 'OVERDUE' : (d.last_sent_at ? 'HEALTHY' : 'UNSEEN')"></span>
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2 text-[11.5px]">
                    <div>
                        <p class="text-muted-foreground">Last sent</p>
                        <p class="font-mono" x-text="d.last_sent_at || '—'"></p>
                        <p class="text-[10.5px] text-muted-foreground" x-text="d.hours_since !== null ? d.hours_since + 'h ago' : ''"></p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Sent · window</p>
                        <p class="tabular-nums font-semibold" x-text="d.sent_window"></p>
                        <p class="text-[10.5px] text-muted-foreground" x-text="d.success_pct + '% ok'"></p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Failed · window</p>
                        <p class="tabular-nums font-semibold text-critical" x-text="d.failed_window"></p>
                        <p class="text-[10.5px] text-muted-foreground" x-text="d.skipped_window + ' skipped'"></p>
                    </div>
                </div>
                <div class="mt-3 flex gap-2">
                    <button type="button" class="btn btn-outline btn-xs" @click="openPreview(d.template_code)">Preview</button>
                    <button type="button" class="btn btn-brand btn-xs" @click="openTrigger(d.template_code, d.label)">
                        Send now…
                    </button>
                </div>
            </div>
        </template>
    </section>

    <section class="card">
        <div class="card-header !pb-2">
            <p class="card-title">Sends · 14-day trend</p>
            <p class="card-description">Daily (brand) vs national intelligence (info).</p>
        </div>
        <div class="card-content !pt-0">
            <template x-if="!loading && summary && summary.trend.length">
                <div>
                    <svg viewBox="0 0 900 160" preserveAspectRatio="none" class="w-full h-36" role="img" aria-label="Digest trend">
                        <template x-for="y in [0.25,0.5,0.75]" :key="'dg-gl-'+y">
                            <line x1="0" x2="900" :y1="160 - (160 * y)" :y2="160 - (160 * y)"
                                  stroke="hsl(var(--border))" stroke-width="0.5" stroke-dasharray="2 3"/>
                        </template>
                        <path :d="line(summary.trend, 'daily',    true)" fill="hsl(var(--brand)/0.15)" stroke="hsl(var(--brand))" stroke-width="1.25"/>
                        <path :d="line(summary.trend, 'national', false)" fill="none" stroke="hsl(var(--info))" stroke-width="1.25" stroke-dasharray="3 2"/>
                    </svg>
                    <div class="flex gap-4 mt-2 text-[11px] text-muted-foreground">
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-brand"></span> DAILY_REPORT</span>
                        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-3 rounded-sm bg-info"></span> NATIONAL_INTELLIGENCE</span>
                    </div>
                </div>
            </template>
        </div>
    </section>

    <section class="card">
        <div class="card-header !pb-2">
            <p class="card-title">Cron history</p>
            <p class="card-description">Every <span class="font-mono">CRON:*</span> + <span class="font-mono">MANUAL:*</span> send in the window.</p>
        </div>
        <div class="card-content !p-0">
            <div class="table-wrap !rounded-none !border-0 border-t-0">
                <table class="table">
                    <thead class="table-head">
                        <tr>
                            <th class="table-head-th">When</th>
                            <th class="table-head-th">Template</th>
                            <th class="table-head-th">Status</th>
                            <th class="table-head-th hidden md:table-cell">Triggered by</th>
                            <th class="table-head-th hidden md:table-cell">Scope</th>
                            <th class="table-head-th hidden md:table-cell">Error</th>
                        </tr>
                    </thead>
                    <tbody class="table-body">
                        <template x-if="loading">
                            <tr><td colspan="6" class="table-cell text-center py-10">
                                <span class="text-muted-foreground text-sm">Loading…</span>
                            </td></tr>
                        </template>
                        <template x-if="!loading && (summary?.history || []).length === 0">
                            <tr><td colspan="6" class="table-cell">
                                <div class="empty-state">
                                    <svg class="empty-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
                                    <p class="text-sm font-medium">No digest activity in the window.</p>
                                </div>
                            </td></tr>
                        </template>
                        <template x-for="r in (summary?.history || [])" :key="'hi-' + r.id">
                            <tr class="table-row">
                                <td class="table-cell font-mono text-[11px] text-muted-foreground" x-text="r.created_at"></td>
                                <td class="table-cell font-mono text-[11.5px]" x-text="r.template_code"></td>
                                <td class="table-cell"><span class="badge" :class="statusBadge(r.status)" x-text="r.status"></span></td>
                                <td class="table-cell hidden md:table-cell font-mono text-[11.5px]" x-text="r.triggered_by"></td>
                                <td class="table-cell hidden md:table-cell font-mono text-[11.5px]" x-text="r.scope"></td>
                                <td class="table-cell hidden md:table-cell text-[11px] text-critical truncate max-w-[22rem]" x-text="r.error_message || '—'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    {{-- Preview drawer --}}
    <template x-if="preview.open">
        <div class="fixed inset-0 z-50 flex"
             x-effect="window.adminLock.set('intel-dig-prev', preview.open)"
             role="dialog" aria-modal="true" aria-label="Digest preview">
            <div class="absolute inset-0 bg-black/45" @click="closePreview()"></div>
            <aside class="relative ml-auto h-full w-full max-w-2xl bg-background border-l shadow-elevation-5 overflow-y-auto" @click.stop>
                <header class="flex items-center justify-between p-4 border-b">
                    <div class="min-w-0">
                        <p class="eyebrow">Preview</p>
                        <p class="font-mono text-[13px] truncate" x-text="preview.template_code"></p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-icon-xs" @click="closePreview()" aria-label="Close">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </header>
                <div class="p-4 space-y-3 text-[12.5px]">
                    <template x-if="preview.loading">
                        <div class="skeleton h-40 w-full"></div>
                    </template>
                    <template x-if="!preview.loading && preview.data">
                        <div class="space-y-3">
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Subject</p>
                                <p class="card !p-3 text-[13px] font-semibold break-words" x-text="preview.data.subject"></p>
                            </div>
                            <div>
                                <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground mb-1">Body (HTML stripped)</p>
                                <pre class="card !p-3 text-[12px] whitespace-pre-wrap break-words max-h-80 overflow-auto" x-text="stripTags(preview.data.body_html)"></pre>
                            </div>
                            <details>
                                <summary class="cursor-pointer text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Variables</summary>
                                <pre class="card !p-3 text-[11px] font-mono whitespace-pre-wrap break-all max-h-64 overflow-auto mt-2"
                                     x-text="JSON.stringify(preview.data.variables, null, 2)"></pre>
                            </details>
                        </div>
                    </template>
                </div>
            </aside>
        </div>
    </template>

    {{-- Trigger confirm --}}
    <template x-if="trigger.open">
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-effect="window.adminLock.set('intel-dig-trig', trigger.open)"
             role="dialog" aria-modal="true" aria-labelledby="dig-trig-title">
            <div class="absolute inset-0 bg-black/45" @click="cancelTrigger()"></div>
            <div class="relative w-full max-w-md rounded-xl border bg-card shadow-elevation-5">
                <header class="p-4 border-b">
                    <p class="eyebrow">Manual trigger</p>
                    <p id="dig-trig-title" class="text-sm font-semibold mt-0.5">
                        <span x-text="trigger.label"></span>
                    </p>
                </header>
                <div class="p-4 space-y-3 text-[12.5px]">
                    <p class="text-muted-foreground">
                        This will invoke
                        <span class="font-mono" x-text="trigger.template_code"></span>
                        immediately with <span class="font-mono">triggered_by=MANUAL:&lt;your user id&gt;</span>.
                        Suppression windows still apply — recipients already touched within their cooldown will be skipped.
                    </p>
                    <label class="flex items-start gap-2">
                        <input type="checkbox" x-model="trigger.confirm" class="mt-0.5">
                        <span class="text-[12px]">I confirm the send to the live roster.</span>
                    </label>
                    <template x-if="trigger.result">
                        <div class="card !p-3 bg-success-soft">
                            <p class="font-mono text-[11.5px]" x-text="JSON.stringify(trigger.result)"></p>
                        </div>
                    </template>
                </div>
                <footer class="p-3 border-t flex justify-end gap-2">
                    <button type="button" class="btn btn-outline btn-sm" @click="cancelTrigger()">Cancel</button>
                    <button type="button" class="btn btn-brand btn-sm"
                            :disabled="! trigger.confirm || trigger.submitting"
                            @click="confirmTrigger()">
                        <svg x-show="trigger.submitting" class="h-3.5 w-3.5 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 12a9 9 0 11-18 0"/></svg>
                        Send now
                    </button>
                </footer>
            </div>
        </div>
    </template>
</div>

@push('scripts')
<script>
    const INTEL_DIG_URLS = {
        summary: @json(route('admin.intelligence.digests.summary')),
        preview: @json(route('admin.intelligence.digests.preview')),
        trigger: @json(route('admin.intelligence.digests.trigger')),
    };
    function digestsPage() {
        return {
            loading: true, summary: null,
            preview: { open: false, loading: false, template_code: '', data: null },
            trigger: { open: false, submitting: false, confirm: false, template_code: '', label: '', result: null },

            async boot() { await this.loadSummary(); },

            async loadSummary() {
                this.loading = true;
                try {
                    const r = await fetch(INTEL_DIG_URLS.summary, { headers: { Accept: 'application/json' } });
                    const j = await r.json();
                    if (j.ok) this.summary = j.data;
                } catch (e) {}
                this.loading = false;
            },

            async openPreview(code) {
                this.preview = { open: true, loading: true, template_code: code, data: null };
                try {
                    const r = await fetch(INTEL_DIG_URLS.preview, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json', 'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        },
                        body: JSON.stringify({ template_code: code }),
                    });
                    const j = await r.json();
                    if (j.ok) this.preview.data = j.data;
                } catch (e) {}
                this.preview.loading = false;
            },
            closePreview() { this.preview.open = false; this.preview.data = null; },

            openTrigger(code, label) {
                this.trigger = { open: true, submitting: false, confirm: false,
                    template_code: code, label, result: null };
            },
            cancelTrigger() { this.trigger.open = false; this.trigger.confirm = false; },

            async confirmTrigger() {
                if (! this.trigger.confirm) return;
                this.trigger.submitting = true;
                try {
                    const r = await fetch(INTEL_DIG_URLS.trigger, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json', 'Accept': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                        },
                        body: JSON.stringify({ template_code: this.trigger.template_code, confirm: true }),
                    });
                    const j = await r.json();
                    if (j.ok) {
                        this.trigger.result = j.data;
                        this.loadSummary();
                    }
                } catch (e) {}
                this.trigger.submitting = false;
            },

            line(points, key, fill) {
                if (!points || !points.length) return '';
                const w = 900, h = 160;
                const maxV = Math.max(1, ...points.map(p => Math.max(p.daily || 0, p.national || 0)));
                const step = w / Math.max(points.length - 1, 1);
                const pts = points.map((p, i) => [i * step, h - ((p[key] || 0) / maxV) * (h - 4) - 2]);
                const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(2) + ' ' + p[1].toFixed(2)).join(' ');
                return fill ? (line + ' L ' + w + ' ' + h + ' L 0 ' + h + ' Z') : line;
            },

            statusBadge(s) {
                return {
                    'badge-success':  s === 'SENT',
                    'badge-critical': s === 'FAILED',
                    'badge-danger':   s === 'BOUNCED',
                    'badge-warning':  s === 'SKIPPED',
                    'badge-info':     s === 'QUEUED',
                };
            },
            stripTags(html) {
                if (!html) return '';
                const d = document.createElement('div');
                d.innerHTML = html;
                return d.textContent || '';
            },
        };
    }
</script>
@endpush
@endsection
