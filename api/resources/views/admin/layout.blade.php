{{-- ============================================================================
  PHEOC COMMAND CENTRE · Admin layout (Walking Skeleton · Rebuild 2026-04-23)
  ----------------------------------------------------------------------------
  Single shell wrapping every /admin/* page. Theme + design system live in
  admin.partials.theme — do NOT inline shadcn primitives here.

  Hooks:
    @section('title')   @section('eyebrow')   @section('subtitle')
    @section('content') @push('head')         @push('scripts')
============================================================================ --}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light only">
    <meta name="theme-color" content="#ffffff">
    <meta name="robots" content="noindex,nofollow">
    <title>@yield('title', 'Command Centre') &middot; {{ config('app.name', 'PHEOC Uganda') }}</title>

    {{-- Design system SSoT — Tailwind CDN config + shadcn primitives --}}
    @include('admin.partials.theme')

    {{-- Alpine — collapse + focus plugins must load before core (per Alpine docs) --}}
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/focus@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>

    @stack('head')
</head>
<body class="min-h-screen bg-background text-foreground antialiased selection:bg-brand/20"
      x-data="adminShell()"
      x-init="init()"
      x-effect="window.adminLock.set('sidebar', sidebarOpen)"
      @keydown.window.escape="sidebarOpen = false">

<div class="flex min-h-screen">

    {{-- Desktop sidebar (fixed rail) --}}
    <aside class="hidden lg:flex lg:w-64 lg:fixed lg:inset-y-0 lg:z-40 lg:border-r lg:bg-background">
        @include('admin.partials.sidebar')
    </aside>

    {{-- Mobile slide-over (bulletproof modal pattern · body-lock via root) --}}
    <template x-if="sidebarOpen">
        <div class="lg:hidden fixed inset-0 z-50 flex"
             role="dialog" aria-modal="true" aria-label="Navigation"
             @keydown.escape.window="sidebarOpen=false">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-sm" @click="sidebarOpen=false"></div>
            <aside class="relative h-full w-72 max-w-[86vw] border-r bg-background shadow-elevation-5" @click.stop>
                @include('admin.partials.sidebar')
            </aside>
        </div>
    </template>

    {{-- Main column --}}
    <div class="flex-1 lg:pl-64 flex flex-col min-w-0">
        {{-- Premium topbar: single row · page title left · live meta + scope right. --}}
        <header class="topbar">
            <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground hover:bg-muted/60 hover:text-foreground lg:hidden"
                    @click="sidebarOpen = true" aria-label="Open navigation">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
            </button>
            <div class="min-w-0 flex-1 flex items-baseline gap-2">
                @hasSection('crumb')
                    <p class="topbar-crumb shrink-0">@yield('crumb')</p>
                    <span class="text-muted-foreground/30 text-[10.5px]">/</span>
                @endif
                <h1 class="topbar-title">@yield('title', 'Command Centre')</h1>
            </div>

            {{-- Right: live page meta + scope chip · all dynamic via Alpine.store('pageMeta') --}}
            <div class="flex items-center gap-2">
                <template x-if="$store.pageMeta?.rows !== null && $store.pageMeta?.rows !== undefined">
                    <span class="topbar-chip topbar-chip-mono" title="Active rows">
                        <span x-text="$store.pageMeta.rows"></span>
                    </span>
                </template>
                <template x-if="$store.pageMeta?.version">
                    <span class="topbar-chip topbar-chip-mono" title="Bundle version">
                        v<span x-text="$store.pageMeta.version"></span>
                    </span>
                </template>
                <span class="topbar-chip">
                    <span class="status-dot status-dot-live"></span>
                    <span class="hidden sm:inline">Uganda · National</span>
                    <span class="sm:hidden">ZM</span>
                </span>
            </div>
        </header>

        <main class="flex-1 px-4 sm:px-6 py-5 max-w-[1400px] w-full">
            @yield('content')
        </main>
    </div>
</div>

{{-- Global toast (fired by navGuard) --}}
<div class="fixed inset-x-0 bottom-6 z-[70] flex justify-center px-3 pointer-events-none"
     x-show="toast.open" x-transition.opacity x-cloak>
    <div class="toast toast-warning pointer-events-auto max-w-md">
        <div class="flex items-start gap-2">
            <svg class="h-4 w-4 shrink-0 text-warning mt-0.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            <div class="min-w-0">
                <p class="toast-title text-warning-foreground" x-text="toast.title"></p>
                <p class="toast-description text-warning-foreground/85" x-text="toast.body"></p>
            </div>
        </div>
    </div>
</div>

<script>
    // ── Body-scroll lock manager · ref-counted ────────────────────────────
    // Every modal (sidebar slide-over, page wizards, sheets, confirms,
    // forms, edit dialogs) registers a key when it opens and clears it on
    // close. Body stays `overflow:hidden` while ≥1 ref is active, restored
    // to default when refs drop to zero. Avoids the leak where an inner
    // <template x-if> destroys before its `x-effect` can unlock body.
    window.adminLock = (window.adminLock || {
        refs: new Set(),
        set(key, on) {
            if (on) { this.refs.add(key); }
            else    { this.refs.delete(key); }
            document.body.style.overflow = this.refs.size > 0 ? 'hidden' : '';
        },
    });
    // Defensive: clear any leftover lock if the user reloads mid-modal.
    document.addEventListener('DOMContentLoaded', () => {
        window.adminLock.refs.clear();
        document.body.style.overflow = '';
    });

    // Cross-component live page metadata for the topbar. Each view's Alpine
    // root pushes here on boot/loadData so the topbar shows live counts and
    // bundle version without coupling to any specific view.
    document.addEventListener('alpine:init', () => {
        Alpine.store('pageMeta', { rows: null, version: null, kind: null });
    });

    // Expose the active user's role + scope label so role-conditional UI can
    // honestly reflect server-side gating (e.g. NATIONAL_ADMIN-only override).
    // The server still enforces — this just hides irrelevant controls.
    @auth
    window.__SCOPE_ROLE__  = @json(strtoupper((string) (auth()->user()->role_key ?? '')));
    window.__SCOPE_LABEL__ = @json((string) (request()->attributes->get('scope')['label'] ?? ''));
    window.__SCOPE_LEVEL__ = @json(strtoupper((string) (request()->attributes->get('scope')['scope_level'] ?? '')));
    @endauth

    function adminShell() {
        return {
            sidebarOpen: false,
            toast: { open: false, title: '', body: '', t: null },
            init() {
                // Defensive: ensure no stale body lock from prior navigation.
                document.body.style.overflow = '';
            },
            navGuard(ev, label) {
                if (ev) { ev.preventDefault(); }
                this.toast.title = label || 'View pending';
                this.toast.body  = 'View pending rebuild under the new IA. Wire its controller + view to flip this from Soon → Live.';
                this.toast.open  = true;
                clearTimeout(this.toast.t);
                this.toast.t = setTimeout(() => { this.toast.open = false; }, 2400);
            },
        };
    }
</script>
@stack('scripts')
</body>
</html>
