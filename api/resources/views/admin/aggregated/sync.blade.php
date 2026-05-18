@extends('admin.layout')

@section('crumb', 'Aggregated Reports')
@section('title', 'Sync Queue')

@php
    /** @var array $scope */
    /** @var bool  $canWrite */
@endphp

@section('content')
<div x-data="idsrSync()"
     x-init="boot()"
     x-effect="window.adminLock.set('idsr-sync', confirmResync.open || detail.open)"
     class="space-y-5">

    {{-- ── KPI strip ───────────────────────────────────────────── --}}
    <section class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="kpi kpi-glow"><p class="kpi-label">All submissions</p><p class="kpi-value tabular-nums" x-text="counts.ALL ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">in scope</p></div>
        <div class="kpi"><p class="kpi-label">Synced</p><p class="kpi-value tabular-nums text-success" x-text="counts.SYNCED ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">server-received</p></div>
        <div class="kpi"><p class="kpi-label">Unsynced</p><p class="kpi-value tabular-nums text-warning" x-text="counts.UNSYNCED ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">awaiting retry</p></div>
        <div class="kpi"><p class="kpi-label">Failed</p><p class="kpi-value tabular-nums text-critical" x-text="counts.FAILED ?? 0"></p><p class="text-[11px] text-muted-foreground mt-1">needs triage</p></div>
    </section>

    <section class="card">
        <div class="card-content !p-0">

            {{-- Tabs + actions --}}
            <div class="flex flex-col gap-3 p-4 sm:p-5 border-b">
                <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                    <div class="tabs-list w-full sm:w-auto">
                        <template x-for="t in tabs" :key="t.key">
                            <button class="tabs-trigger flex-1 sm:flex-none" :data-state="tab === t.key ? 'active' : 'inactive'" @click="switchTab(t.key)">
                                <span x-text="t.label"></span>
                                <span class="badge ml-1 px-1.5 py-0 text-[9.5px]" :class="t.key === 'FAILED' ? 'badge-danger' : (t.key === 'UNSYNCED' ? 'badge-warning' : 'badge-outline')" x-text="counts[t.key] ?? 0"></span>
                            </button>
                        </template>
                    </div>
                    <div class="flex-1"></div>
                    <button class="btn btn-outline btn-sm" @click="loadQueue()">
                        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        Refresh
                    </button>
                </div>
            </div>

            {{-- TAB: QUEUE (with sub-tabs via tab state) --}}
            <template x-if="tab !== 'diagnostics'">
                <div>
                    <div class="table-wrap !rounded-none !border-0">
                        <table class="table">
                            <thead class="table-head"><tr>
                                <th class="table-head-th">Submission</th>
                                <th class="table-head-th hidden md:table-cell">Location</th>
                                <th class="table-head-th hidden lg:table-cell">Device</th>
                                <th class="table-head-th text-right">Attempts</th>
                                <th class="table-head-th">Status</th>
                                <th class="table-head-th text-right">Actions</th>
                            </tr></thead>
                            <tbody class="table-body">
                                <template x-if="loading"><tr><td colspan="6" class="table-cell text-center py-8 text-muted-foreground text-sm">Loading queue…</td></tr></template>
                                <template x-if="!loading && items.length === 0"><tr><td colspan="6" class="table-cell"><div class="empty-state py-10"><p class="text-sm text-muted-foreground" x-text="emptyMessage()"></p></div></td></tr></template>
                                <template x-for="row in items" :key="row.id">
                                    <tr class="table-row">
                                        <td class="table-cell" @click="openDetail(row)" style="cursor: pointer">
                                            <div class="flex items-start gap-2">
                                                <div class="min-w-0">
                                                    <div class="text-[12.5px] font-semibold truncate" x-text="row.template_name || row.template_code || 'Untitled'"></div>
                                                    <div class="text-[10.5px] text-muted-foreground" x-text="row.period_label"></div>
                                                    <div class="text-[10.5px] text-muted-foreground font-mono truncate" x-text="row.client_uuid"></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="table-cell hidden md:table-cell text-[11.5px]">
                                            <div class="font-semibold" x-text="row.poe_code"></div>
                                            <div class="text-muted-foreground" x-text="row.district_code"></div>
                                        </td>
                                        <td class="table-cell hidden lg:table-cell text-[11px]">
                                            <div x-text="row.platform"></div>
                                            <div class="text-muted-foreground" x-text="row.app_version || '—'"></div>
                                            <div class="text-muted-foreground font-mono text-[10.5px] truncate" x-text="row.device_id"></div>
                                        </td>
                                        <td class="table-cell text-right tabular-nums" x-text="row.sync_attempt_count"></td>
                                        <td class="table-cell">
                                            <span class="badge" :class="syncBadge(row.sync_status)" x-text="syncLabel(row.sync_status)"></span>
                                            <template x-if="row.last_sync_error">
                                                <span class="text-[10.5px] text-critical block truncate max-w-[260px]" :title="row.last_sync_error" x-text="row.last_sync_error"></span>
                                            </template>
                                        </td>
                                        <td class="table-cell text-right">
                                            @if ($canWrite)
                                            <button class="btn btn-outline btn-xs" @click="askResync(row)" :disabled="row.sync_status === 'SYNCED'">Mark synced</button>
                                            @endif
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                    <div class="p-3 flex items-center justify-between text-[11.5px] text-muted-foreground border-t" x-show="pagination.pages > 1">
                        <div>Page <span class="font-semibold" x-text="pagination.page"></span> of <span x-text="pagination.pages"></span> · <span x-text="pagination.total"></span> rows</div>
                        <div class="flex gap-1">
                            <button class="btn btn-outline btn-xs" @click="pagination.page=1; loadQueue()" :disabled="pagination.page <= 1">«</button>
                            <button class="btn btn-outline btn-xs" @click="pagination.page--; loadQueue()" :disabled="pagination.page <= 1">‹</button>
                            <button class="btn btn-outline btn-xs" @click="pagination.page++; loadQueue()" :disabled="pagination.page >= pagination.pages">›</button>
                            <button class="btn btn-outline btn-xs" @click="pagination.page=pagination.pages; loadQueue()" :disabled="pagination.page >= pagination.pages">»</button>
                        </div>
                    </div>
                </div>
            </template>

            {{-- TAB: DIAGNOSTICS --}}
            <template x-if="tab === 'diagnostics'">
                <div class="p-4 sm:p-5 space-y-4">
                    <div class="alert alert-info">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <div>
                            <p class="alert-title">Device and version mix</p>
                            <p class="alert-description">Distribution of submissions by platform and app version in your scope. Use this to spot out-of-date clients stuck on the sync queue.</p>
                        </div>
                    </div>
                    <div class="table-wrap">
                        <table class="table">
                            <thead class="table-head"><tr>
                                <th class="table-head-th">Platform</th>
                                <th class="table-head-th">App version</th>
                                <th class="table-head-th text-right">Submissions</th>
                                <th class="table-head-th">Share</th>
                            </tr></thead>
                            <tbody class="table-body">
                                <template x-if="!deviceMix.length"><tr><td colspan="4" class="table-cell text-center py-6 text-muted-foreground text-xs">No data.</td></tr></template>
                                <template x-for="(row, idx) in deviceMix" :key="idx">
                                    <tr class="table-row">
                                        <td class="table-cell"><span class="badge badge-outline" x-text="row.platform"></span></td>
                                        <td class="table-cell font-mono text-[11px]" x-text="row.app_version"></td>
                                        <td class="table-cell text-right tabular-nums" x-text="row.count"></td>
                                        <td class="table-cell">
                                            <div class="progress progress-sm"><div class="progress-bar" :style="'width:' + sharePct(row.count) + '%'"></div></div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>
    </section>

    {{-- Detail sheet --}}
    <template x-if="detail.open">
        <div class="fixed inset-0 z-50 flex justify-end" role="dialog" aria-modal="true" @keydown.escape.window="if (!confirmResync.open) detail.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="detail.open=false"></div>
            <div class="relative w-full sm:max-w-lg bg-background border-l shadow-elevation-5 flex flex-col h-full" @click.stop>
                <header class="flex items-center gap-3 px-5 py-3 border-b">
                    <div class="grid place-items-center h-9 w-9 rounded-lg bg-warning-soft text-warning">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="eyebrow">Queue entry #<span x-text="detail.row?.id"></span></p>
                        <h2 class="text-[14px] font-bold truncate" x-text="detail.row?.template_name || detail.row?.template_code || 'Submission'"></h2>
                    </div>
                    <button class="btn btn-ghost btn-icon-xs" @click="detail.open=false"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
                </header>
                <div class="flex-1 overflow-y-auto px-5 py-5 space-y-4" x-show="detail.row">
                    <div class="grid grid-cols-2 gap-3 text-[12px]">
                        <div><p class="eyebrow">Status</p><p><span class="badge" :class="syncBadge(detail.row?.sync_status)" x-text="syncLabel(detail.row?.sync_status)"></span></p></div>
                        <div><p class="eyebrow">Attempts</p><p class="tabular-nums" x-text="detail.row?.sync_attempt_count"></p></div>
                        <div><p class="eyebrow">Period</p><p x-text="detail.row?.period_label"></p></div>
                        <div><p class="eyebrow">Screened</p><p class="tabular-nums" x-text="detail.row?.total_screened"></p></div>
                        <div><p class="eyebrow">PoE</p><p x-text="detail.row?.poe_code"></p></div>
                        <div><p class="eyebrow">District</p><p x-text="detail.row?.district_code"></p></div>
                        <div><p class="eyebrow">Device</p><p class="font-mono text-[11px] break-all" x-text="(detail.row?.platform || '—') + ' · ' + (detail.row?.app_version || '—') + ' · ' + (detail.row?.device_id || 'unknown')"></p></div>
                        <div><p class="eyebrow">Created</p><p class="text-muted-foreground" x-text="detail.row?.created_rel"></p></div>
                    </div>
                    <template x-if="detail.row?.last_sync_error">
                        <div class="alert alert-critical">
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v2m0 4h.01M4.93 19h14.14c1.54 0 2.5-1.67 1.73-3L13.73 4c-.77-1.33-2.69-1.33-3.46 0L3.2 16c-.77 1.33.19 3 1.73 3z"/></svg>
                            <div>
                                <p class="alert-title">Last sync error</p>
                                <p class="alert-description break-all" x-text="detail.row?.last_sync_error"></p>
                            </div>
                        </div>
                    </template>
                    <div>
                        <p class="eyebrow">Client UUID</p>
                        <p class="font-mono text-[11px] break-all" x-text="detail.row?.client_uuid"></p>
                    </div>
                </div>
                @if ($canWrite)
                <footer class="px-5 py-3 border-t flex justify-end gap-2">
                    <button class="btn btn-brand btn-sm" @click="askResync(detail.row)" :disabled="!detail.row || detail.row.sync_status === 'SYNCED'">Mark as synced</button>
                </footer>
                @endif
            </div>
        </div>
    </template>

    {{-- Confirm dialog --}}
    <template x-if="confirmResync.open">
        <div class="fixed inset-0 z-[60] grid place-items-center p-4" role="dialog" aria-modal="true" @keydown.escape.window="confirmResync.open=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="confirmResync.open=false"></div>
            <div class="relative w-full max-w-md bg-background rounded-xl border shadow-elevation-5 p-5 space-y-3" @click.stop>
                <h3 class="text-[14px] font-bold">Mark submission as SYNCED?</h3>
                <p class="text-[12.5px] text-muted-foreground">This clears <span class="font-semibold">last_sync_error</span> and stamps <span class="font-mono">synced_at=now()</span>. It does not re-transmit any data — the submission already lives on the server.</p>
                <div>
                    <label class="label">Type <span class="font-mono text-brand">RESYNC</span> to confirm</label>
                    <input type="text" class="input mt-1 font-mono" x-model="confirmResync.typed" placeholder="RESYNC">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button class="btn btn-ghost btn-sm" @click="confirmResync.open=false">Cancel</button>
                    <button class="btn btn-brand btn-sm" :disabled="confirmResync.saving || confirmResync.typed !== 'RESYNC'" @click="performResync()">
                        <span x-show="!confirmResync.saving">Mark synced</span>
                        <span x-show="confirmResync.saving">Working…</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    <div class="fixed inset-x-0 bottom-6 z-[70] flex justify-center pointer-events-none px-4" x-show="flash.open" x-transition.opacity x-cloak>
        <div class="toast pointer-events-auto max-w-md" :class="flash.variant === 'danger' ? 'toast-destructive' : 'toast-success'">
            <div><p class="toast-title" x-text="flash.title"></p><p class="toast-description" x-text="flash.body"></p></div>
        </div>
    </div>
</div>

@push('scripts')
<script>
window.__IDSR_SYNC__ = {
    scope:    @json($scope ?? []),
    canWrite: @json((bool) ($canWrite ?? false)),
    csrf:     @json(csrf_token()),
    routes: {
        data:   @json(url('/admin/aggregated/sync/data')),
        resync: @json(url('/admin/aggregated/sync')),
    },
};

function idsrSync() {
    const C = window.__IDSR_SYNC__;
    return {
        tabs: [
            { key: 'FAILED',      label: 'Failed' },
            { key: 'UNSYNCED',    label: 'Unsynced' },
            { key: 'ALL',         label: 'All' },
            { key: 'diagnostics', label: 'Diagnostics' },
        ],
        tab: 'FAILED',
        loading: false,
        items: [],
        deviceMix: [],
        counts: { SYNCED: 0, UNSYNCED: 0, FAILED: 0, ALL: 0 },
        pagination: { page: 1, pages: 1, per_page: 25, total: 0 },
        flash: { open: false, variant: 'success', title: '', body: '', timer: null },

        detail: { open: false, row: null },

        confirmResync: { open: false, id: null, typed: '', saving: false },

        async boot() { await this.loadQueue(); },

        setPageMeta(rows) {
            try {
                if (window.Alpine && Alpine.store) {
                    const s = Alpine.store('pageMeta');
                    if (s) s.rows = rows;
                }
            } catch (_) { /* no-op */ }
        },

        async jsonFetch(url, opts = {}) {
            const headers = Object.assign({
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            }, opts.headers || {});
            const method = (opts.method || 'GET').toUpperCase();
            if (['POST','PATCH','PUT','DELETE'].includes(method)) {
                headers['X-CSRF-TOKEN'] = C.csrf;
                if (opts.body && typeof opts.body === 'object' && !(opts.body instanceof FormData)) {
                    headers['Content-Type'] = 'application/json';
                    opts.body = JSON.stringify(opts.body);
                }
            }
            const res = await fetch(url, { credentials: 'same-origin', ...opts, headers });
            const ct = (res.headers.get('content-type') || '').toLowerCase();
            if (!ct.includes('application/json')) {
                if (res.status === 401 || res.status === 419) throw new Error('Session expired — reload the page.');
                throw new Error('Non-JSON response (HTTP ' + res.status + ').');
            }
            const body = await res.json().catch(() => ({}));
            if (!res.ok) throw new Error(body?.message || ('HTTP ' + res.status));
            return body;
        },

        switchTab(k) { this.tab = k; this.pagination.page = 1; this.loadQueue(); },

        emptyMessage() {
            if (this.tab === 'FAILED')   return 'No failed submissions in scope. 🎉';
            if (this.tab === 'UNSYNCED') return 'Nothing unsynced — everything has reached the server.';
            return 'No submissions in scope.';
        },

        async loadQueue() {
            this.loading = true;
            try {
                const qs = new URLSearchParams({
                    tab: this.tab === 'diagnostics' ? 'ALL' : this.tab,
                    page: String(this.pagination.page),
                    per_page: String(this.pagination.per_page),
                });
                const body = await this.jsonFetch(C.routes.data + '?' + qs.toString());
                this.items = body?.data?.items || [];
                this.counts = body?.data?.counts || this.counts;
                this.deviceMix = body?.data?.device_mix || [];
                this.pagination = {
                    page: body?.data?.page || 1,
                    pages: body?.data?.pages || 1,
                    per_page: body?.data?.per_page || 25,
                    total: body?.data?.total || 0,
                };
                this.setPageMeta(this.counts.FAILED || 0);
            } catch (e) {
                this.toast('danger', 'Load failed', e.message || 'Server error');
                this.items = [];
            } finally {
                this.loading = false;
            }
        },

        openDetail(row) { this.detail = { open: true, row }; },

        askResync(row) {
            if (!row) return;
            this.confirmResync = { open: true, id: row.id, typed: '', saving: false };
        },

        async performResync() {
            if (this.confirmResync.saving) return;
            this.confirmResync.saving = true;
            try {
                await this.jsonFetch(C.routes.resync + '/' + this.confirmResync.id + '/resync', {
                    method: 'POST',
                    body: { confirm: 'RESYNC' },
                });
                this.confirmResync.open = false;
                this.detail.open = false;
                await this.loadQueue();
                this.toast('success', 'Marked synced', '');
            } catch (e) {
                this.toast('danger', 'Resync failed', e.message);
            } finally {
                this.confirmResync.saving = false;
            }
        },

        syncBadge(s) {
            switch ((s || '').toUpperCase()) {
                case 'SYNCED':   return 'badge-success';
                case 'UNSYNCED': return 'badge-warning';
                case 'FAILED':   return 'badge-danger';
                default:         return 'badge-outline';
            }
        },
        // Mirrors src/services/plainLabels.js syncLabel — admin web and
        // mobile app must speak the same language to a non-technical user.
        syncLabel(s) {
            const map = { SYNCED: 'Uploaded', UNSYNCED: 'Waiting to upload', PENDING: 'Waiting to upload', FAILED: 'Upload failed', QUARANTINED: 'Stuck — contact support', UNKNOWN: 'Status unknown' };
            return map[(s || '').toUpperCase()] || (s || '');
        },
        sharePct(n) {
            const total = (this.deviceMix || []).reduce((s, r) => s + (r.count || 0), 0) || 1;
            return Math.round((n / total) * 100);
        },
        toast(variant, title, body) {
            const prev = this.flash?.timer;
            if (prev) clearTimeout(prev);
            this.flash = { open: true, variant, title, body, timer: null };
            this.flash.timer = setTimeout(() => { this.flash.open = false; }, 2800);
        },
    };
}
</script>
@endpush
@endsection
