{{-- Admin · System · Migrations (NATIONAL_ADMIN only) --}}
@extends('admin.layout')

@section('crumb', 'System')
@section('title', 'Database Migrations')

@section('content')
@php
    /** @var array $data */
    $pending  = $data['pending']  ?? [];
    $phantoms = $data['phantoms'] ?? [];
@endphp

<div x-data="migrationsPage()" x-init="bootstrap(@js($data))"
     class="flex flex-col gap-4 min-h-[calc(100vh-7rem)]">

    <header class="flex items-center justify-between bg-white rounded-xl border border-slate-200 px-5 py-4 shadow-sm">
        <div>
            <h1 class="text-lg font-semibold text-slate-900">Database Migrations</h1>
            <p class="text-sm text-slate-600">
                Inspect schema status and apply any pending migrations against the live database.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button"
                    @click="refresh()"
                    class="inline-flex items-center gap-2 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Refresh
            </button>
            <button type="button"
                    @click="doRun(true)"
                    :disabled="running"
                    class="inline-flex items-center gap-2 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm font-medium text-amber-900 hover:bg-amber-100 disabled:opacity-50">
                Dry-run (preview SQL)
            </button>
            <button type="button"
                    @click="confirmRun()"
                    :disabled="running || state.pending_count === 0"
                    class="inline-flex items-center gap-2 rounded-md bg-emerald-600 px-3 py-2 text-sm font-medium text-white hover:bg-emerald-700 disabled:opacity-50">
                <span x-text="running ? 'Running…' : ('Run ' + state.pending_count + ' pending')"></span>
            </button>
        </div>
    </header>

    {{-- KPIs ------------------------------------------------------------ --}}
    <div class="grid grid-cols-2 lg:grid-cols-6 gap-3">
        <template x-for="kpi in kpis()" :key="kpi.label">
            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
                <div class="text-xs uppercase tracking-wide text-slate-500" x-text="kpi.label"></div>
                <div class="mt-1 text-2xl font-semibold text-slate-900" x-text="kpi.value"></div>
                <div class="text-xs text-slate-500" x-text="kpi.hint"></div>
            </div>
        </template>
    </div>

    {{-- Status banner --------------------------------------------------- --}}
    <template x-if="!state.db_reachable">
        <div class="bg-rose-50 border border-rose-200 text-rose-900 rounded-xl px-4 py-3 text-sm">
            <strong>Database unreachable.</strong>
            <span x-text="state.db_error"></span>
        </div>
    </template>

    <template x-if="state.db_reachable && state.pending_count === 0 && state.phantom_count === 0">
        <div class="bg-emerald-50 border border-emerald-200 text-emerald-900 rounded-xl px-4 py-3 text-sm">
            ✅ Schema is up to date. No pending migrations.
        </div>
    </template>

    {{-- Pending ---------------------------------------------------------- --}}
    <section class="bg-white rounded-xl border border-slate-200 shadow-sm">
        <header class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="font-semibold text-slate-900">Pending migrations</h2>
            <span class="text-xs text-slate-500" x-text="state.pending_count + ' file(s)'"></span>
        </header>
        <div class="px-5 py-3 max-h-72 overflow-y-auto">
            <template x-if="state.pending_count === 0">
                <p class="text-sm text-slate-500">None.</p>
            </template>
            <ol class="text-sm text-slate-700 list-decimal list-inside space-y-1">
                <template x-for="m in state.pending" :key="m">
                    <li x-text="m"></li>
                </template>
            </ol>
        </div>
    </section>

    {{-- Phantoms -------------------------------------------------------- --}}
    <template x-if="state.phantom_count > 0">
        <section class="bg-white rounded-xl border border-amber-300 shadow-sm">
            <header class="px-5 py-3 border-b border-amber-200 flex items-center justify-between bg-amber-50">
                <h2 class="font-semibold text-amber-900">Phantom rows (in DB but no file)</h2>
                <span class="text-xs text-amber-700" x-text="state.phantom_count + ' row(s)'"></span>
            </header>
            <div class="px-5 py-3 max-h-60 overflow-y-auto">
                <p class="text-xs text-amber-800 mb-2">
                    These migration rows exist in the database but the corresponding files are missing from the
                    deploy. Usually means the file was deleted after being applied. Investigate before clearing.
                </p>
                <ol class="text-sm text-slate-700 list-decimal list-inside space-y-1">
                    <template x-for="m in state.phantoms" :key="m">
                        <li x-text="m"></li>
                    </template>
                </ol>
            </div>
        </section>
    </template>

    {{-- Last run output ------------------------------------------------- --}}
    <template x-if="lastRun">
        <section class="bg-white rounded-xl border border-slate-200 shadow-sm">
            <header class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
                <h2 class="font-semibold text-slate-900">
                    Last run · <span x-text="lastRun.dry_run ? 'DRY-RUN' : 'EXECUTED'"></span>
                    <span class="text-xs ml-2"
                          :class="lastRun.ok ? 'text-emerald-600' : 'text-rose-600'"
                          x-text="lastRun.ok ? 'OK' : 'FAILED'"></span>
                </h2>
                <span class="text-xs text-slate-500" x-text="lastRun.elapsed_ms + ' ms'"></span>
            </header>
            <pre class="px-5 py-3 text-xs text-slate-800 whitespace-pre-wrap break-words max-h-96 overflow-y-auto"
                 x-text="lastRun.output || '(no output)'"></pre>
        </section>
    </template>
</div>

<script>
function migrationsPage() {
    return {
        state: {},
        running: false,
        lastRun: null,
        bootstrap(initial) {
            this.state = initial || {};
        },
        kpis() {
            return [
                { label: 'Env',         value: this.state.app_env || '—',              hint: this.state.db_database || '' },
                { label: 'DB tables',   value: this.state.tables_count ?? '—',         hint: 'live count' },
                { label: 'Files',       value: this.state.files_count ?? '—',          hint: 'migration files' },
                { label: 'Applied',     value: this.state.applied_count ?? '—',        hint: 'rows in migrations' },
                { label: 'Pending',     value: this.state.pending_count ?? '—',        hint: 'will run on click' },
                { label: 'Phantoms',    value: this.state.phantom_count ?? '—',        hint: 'rows w/o files' },
            ];
        },
        async refresh() {
            const r = await fetch('{{ route('admin.system.migrations.status') }}', { headers: { 'Accept': 'application/json' } });
            this.state = await r.json();
        },
        confirmRun() {
            if (!confirm(`Run ${this.state.pending_count} pending migration(s) against ${this.state.db_database}?\n\nThis writes to the live database. Proceed?`)) {
                return;
            }
            this.doRun(false);
        },
        async doRun(dry) {
            this.running = true;
            this.lastRun = null;
            try {
                const url = '{{ route('admin.system.migrations.run') }}' + (dry ? '?dry=1' : '');
                const r = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content || '',
                    },
                });
                const json = await r.json();
                this.lastRun = json;
                if (json.status_after) this.state = json.status_after;
                if (!json.ok) {
                    alert((json.code || 'ERROR') + ': ' + (json.error || 'See output below.'));
                }
            } catch (e) {
                alert('Network error: ' + e.message);
            } finally {
                this.running = false;
            }
        },
    }
}
</script>
@endsection
