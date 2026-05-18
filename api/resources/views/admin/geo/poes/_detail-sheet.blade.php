{{-- ============================================================================
  Detail Sheet (right slide-over) · single PoE inspector
  ----------------------------------------------------------------------------
  Bulletproof modal pattern: outer wrapper `fixed inset-0 z-50 flex justify-end`,
  backdrop is `absolute inset-0` sibling, panel is `relative` (stacks above
  backdrop via DOM order). ESC-to-close, body lock, focus trap.
============================================================================ --}}
<template x-if="sheet.open">
    <div class="fixed inset-0 z-50 flex justify-end"
         role="dialog" aria-modal="true" aria-label="PoE detail"
         @keydown.escape.window="sheet.open=false">
         x-trap.inert="sheet.open">

        {{-- Backdrop --}}
        <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="sheet.open=false"></div>

        {{-- Panel --}}
        <aside class="relative h-full w-[92vw] max-w-[440px] bg-background border-l shadow-elevation-5 flex flex-col p-5 sm:p-6 overflow-y-auto"
               x-data="{ tab: 'overview' }" @click.stop>

            {{-- Header --}}
            <header class="flex items-start gap-3 pb-3 border-b">
                <div class="grid place-items-center h-9 w-9 rounded-lg bg-brand text-white shrink-0">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1"/></svg>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-[10.5px] font-semibold uppercase tracking-wider text-muted-foreground">PoE detail</p>
                    <h2 class="text-[15px] font-bold leading-tight truncate"
                        x-text="sheet.data?.poe_name || (sheet.busy ? 'Loading…' : '—')"></h2>
                    <p class="text-[10.5px] font-mono text-muted-foreground truncate" x-text="sheet.data?.external_id"></p>
                </div>
                <button type="button" class="btn btn-ghost btn-icon-xs shrink-0" @click="sheet.open=false" aria-label="Close">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
            </header>

            {{-- Loading skeleton --}}
            <template x-if="sheet.busy">
                <div class="space-y-3 mt-4">
                    <div class="skeleton h-8 w-2/3"></div>
                    <div class="skeleton h-4 w-full"></div>
                    <div class="skeleton h-4 w-5/6"></div>
                    <div class="skeleton h-32 w-full"></div>
                </div>
            </template>

            {{-- Loaded --}}
            <template x-if="!sheet.busy && sheet.data">
                <div class="flex flex-col flex-1 min-h-0 mt-3">

                    <div class="tabs-list w-full">
                        <button type="button" class="tabs-trigger flex-1" :data-state="tab==='overview' ? 'active' : 'inactive'" @click="tab='overview'">Overview</button>
                        <button type="button" class="tabs-trigger flex-1" :data-state="tab==='payload'  ? 'active' : 'inactive'" @click="tab='payload'">Bundle</button>
                        <button type="button" class="tabs-trigger flex-1" :data-state="tab==='audit'    ? 'active' : 'inactive'" @click="tab='audit'">Audit</button>
                    </div>

                    <div class="tabs-content space-y-4 overflow-y-auto flex-1" x-show="tab==='overview'">
                        <section class="card">
                            <div class="card-header pb-2"><h3 class="card-title">Identity</h3></div>
                            <div class="card-content !pt-0">
                                <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-[12px]">
                                    <dt class="text-muted-foreground">poe_type</dt><dd><span class="badge badge-outline" x-text="sheet.data.poe_type"></span></dd>
                                    <dt class="text-muted-foreground">transport_mode</dt><dd class="font-mono" x-text="sheet.data.transport_mode"></dd>
                                    <dt class="text-muted-foreground">poe_code</dt><dd class="font-mono truncate" x-text="sheet.data.poe_code"></dd>
                                    <dt class="text-muted-foreground">border_country</dt><dd x-text="sheet.data.border_country || '—'"></dd>
                                </dl>
                            </div>
                        </section>

                        <section class="card">
                            <div class="card-header pb-2"><h3 class="card-title">Location</h3></div>
                            <div class="card-content !pt-0">
                                <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-[12px]">
                                    <dt class="text-muted-foreground">province</dt><dd x-text="sheet.data.admin_level_1"></dd>
                                    <dt class="text-muted-foreground">district</dt><dd x-text="sheet.data.district"></dd>
                                    <dt class="text-muted-foreground">latitude</dt><dd class="font-mono" x-text="sheet.data.latitude || '—'"></dd>
                                    <dt class="text-muted-foreground">longitude</dt><dd class="font-mono" x-text="sheet.data.longitude || '—'"></dd>
                                </dl>
                            </div>
                        </section>

                        <section class="card">
                            <div class="card-header pb-2"><h3 class="card-title">Flags</h3></div>
                            <div class="card-content !pt-0">
                                <div class="flex flex-wrap gap-1.5">
                                    <span class="badge" :class="sheet.data.is_major_entry      ? 'badge-info'    : 'badge-soon'">major: <span class="ml-1" x-text="sheet.data.is_major_entry ? 'yes' : 'no'"></span></span>
                                    <span class="badge" :class="sheet.data.is_recommended_osbp ? 'badge-brand'   : 'badge-soon'">OSBP: <span class="ml-1" x-text="sheet.data.is_recommended_osbp ? 'yes' : 'no'"></span></span>
                                    <span class="badge" :class="sheet.data.is_national_level   ? 'badge-warning' : 'badge-soon'">national: <span class="ml-1" x-text="sheet.data.is_national_level ? 'yes' : 'no'"></span></span>
                                    <span class="badge" :class="sheet.data.is_active           ? 'badge-success' : 'badge-soon'">active: <span class="ml-1" x-text="sheet.data.is_active ? 'yes' : 'no'"></span></span>
                                    <span class="badge badge-soon" x-show="sheet.data.is_retired">retired</span>
                                </div>
                            </div>
                        </section>

                        <section class="card" x-show="sheet.data.payload?.critical_details">
                            <div class="card-header pb-2"><h3 class="card-title">Critical details</h3></div>
                            <div class="card-content !pt-0">
                                <p class="text-[12px] leading-relaxed text-foreground/85" x-text="sheet.data.payload?.critical_details"></p>
                            </div>
                        </section>

                        <section class="card">
                            <div class="card-header pb-2"><h3 class="card-title">Sources</h3></div>
                            <div class="card-content !pt-0 space-y-1.5 text-[12px]">
                                <p><span class="text-muted-foreground">URL:</span>
                                    <a :href="sheet.data.payload?.source_url" target="_blank" rel="noopener" class="text-brand hover:underline" x-text="sheet.data.payload?.source_url || '—'"></a>
                                </p>
                                <p><span class="text-muted-foreground">Origin:</span>
                                    <span x-text="sheet.data.payload?.source_origin || '—'"></span>
                                </p>
                            </div>
                        </section>
                    </div>

                    <div class="tabs-content overflow-y-auto flex-1" x-show="tab==='payload'">
                        <p class="text-[11.5px] text-muted-foreground mb-2">Exact 20-key payload sent in <span class="kbd">/api/poes/bundle</span>.</p>
                        <pre class="text-[11px] bg-muted/40 border rounded-lg p-3 overflow-x-auto font-mono leading-relaxed" x-text="JSON.stringify(sheet.data.payload, null, 2)"></pre>
                    </div>

                    <div class="tabs-content overflow-y-auto flex-1" x-show="tab==='audit'">
                        <dl class="grid grid-cols-2 gap-x-4 gap-y-1.5 text-[12px]">
                            <dt class="text-muted-foreground">internal id</dt><dd class="font-mono" x-text="sheet.data.id"></dd>
                            <dt class="text-muted-foreground">external id</dt><dd class="font-mono" x-text="sheet.data.external_id"></dd>
                            <dt class="text-muted-foreground">display order</dt><dd class="font-mono tabular-nums" x-text="sheet.data.display_order"></dd>
                            <dt class="text-muted-foreground">created_at</dt><dd class="font-mono text-[11px]" x-text="sheet.data.created_at"></dd>
                            <dt class="text-muted-foreground">updated_at</dt><dd class="font-mono text-[11px]" x-text="sheet.data.updated_at"></dd>
                            <template x-if="sheet.data.deleted_at"><dt class="text-muted-foreground">deleted_at</dt></template>
                            <template x-if="sheet.data.deleted_at"><dd class="font-mono text-[11px] text-critical" x-text="sheet.data.deleted_at"></dd></template>
                            <dt class="text-muted-foreground">gazette_source</dt><dd class="text-[11px] truncate" x-text="sheet.data.gazette_source || '—'"></dd>
                        </dl>
                    </div>

                    <footer class="flex items-center gap-2 pt-3 border-t mt-3 shrink-0">
                        <button type="button" class="btn btn-outline btn-sm" @click="sheet.open=false">Close</button>
                        <div class="flex-1"></div>
                        <template x-if="!sheet.data.is_retired">
                            <button type="button" class="btn btn-brand btn-sm" @click="openEdit(sheet.data.id); sheet.open=false">
                                <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                Edit
                            </button>
                        </template>
                    </footer>
                </div>
            </template>
        </aside>
    </div>
</template>
