{{-- ============================================================================
  Admin · Auth · Login — premium · mobile-first · split-screen on desktop
  ----------------------------------------------------------------------------
  Uses the same layout shell primitives from admin.partials.theme (loaded
  inline below because this page runs pre-auth without the main admin layout).
============================================================================ --}}
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light only">
    <meta name="theme-color" content="#ffffff">
    <meta name="robots" content="noindex,nofollow">
    <title>Sign in · PHEOC Command Centre</title>

    @include('admin.partials.theme')

    <style>
        /* Auth-only accents — kept narrow to avoid polluting the theme. */
        .auth-shell {
            min-height: 100svh;
            background:
                radial-gradient(1200px 600px at 100% -20%, hsl(var(--brand) / .12), transparent 60%),
                radial-gradient(900px 500px at -10% 110%, hsl(var(--info) / .10), transparent 60%),
                linear-gradient(180deg, hsl(var(--background)) 0%, hsl(var(--muted) / .4) 100%);
        }
        .auth-hero {
            background:
                radial-gradient(600px 400px at 20% 0%, hsl(var(--brand) / .35), transparent 70%),
                linear-gradient(135deg, hsl(var(--brand-ink)) 0%, hsl(var(--brand)) 55%, hsl(var(--info)) 100%);
        }
        .auth-hero-grid {
            background-image:
                linear-gradient(to right, rgb(255 255 255 / .05) 1px, transparent 1px),
                linear-gradient(to bottom, rgb(255 255 255 / .05) 1px, transparent 1px);
            background-size: 40px 40px;
        }
    </style>
</head>
<body class="h-full bg-background text-foreground antialiased">

<div class="auth-shell flex items-stretch justify-center">

    {{-- ── Hero (desktop only) ───────────────────────────────────── --}}
    <aside class="auth-hero hidden lg:flex lg:w-1/2 relative overflow-hidden">
        <div class="auth-hero-grid absolute inset-0 opacity-60"></div>
        <div class="relative z-10 flex flex-col justify-between p-12 text-white w-full">
            {{-- Logo block --}}
            <div class="flex items-center gap-3">
                <div class="grid place-items-center h-11 w-11 rounded-xl bg-white/15 backdrop-blur-sm ring-1 ring-white/20">
                    <svg class="h-6 w-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2l3 6 6 .9-4.5 4.4 1 6.2L12 16.8 6.5 19.5l1-6.2L3 8.9 9 8z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-[16px] font-bold tracking-tight">PHEOC Command Centre</p>
                    <p class="text-[11px] font-medium tracking-[.14em] uppercase text-white/70">Uganda · National</p>
                </div>
            </div>

            {{-- Centred hero copy --}}
            <div class="max-w-md">
                <p class="text-[11px] font-semibold uppercase tracking-[.2em] text-white/70">IHR-2005 · IDSR 3rd Ed</p>
                <h1 class="mt-3 text-[30px] font-bold tracking-tight leading-[1.1]">
                    Public-health<br>intelligence at<br>national scale.
                </h1>
                <p class="mt-4 text-[13px] leading-relaxed text-white/80">
                    Sign in to view Points of Entry, alerts, and the 7-1-7 performance board scoped to your jurisdiction. WHO National Focal Point workspace for IHR notifications.
                </p>
            </div>

            {{-- Footer meta --}}
            <div class="flex items-center gap-2 text-[11px] text-white/60">
                <span class="inline-block h-1.5 w-1.5 rounded-full bg-success"></span>
                <span>System live · v10 bundle</span>
                <span class="text-white/30">·</span>
                <span>UNIPH · Ministry of Health</span>
            </div>
        </div>
    </aside>

    {{-- ── Form column ───────────────────────────────────────────── --}}
    <main class="flex-1 flex items-center justify-center px-5 sm:px-8 py-10 lg:w-1/2">
        <div class="w-full max-w-sm">

            {{-- Mobile-only brand block --}}
            <div class="flex items-center gap-2.5 mb-7 lg:hidden">
                <div class="grid place-items-center h-9 w-9 rounded-lg bg-gradient-to-br from-brand to-brand-ink text-white shadow-[0_4px_12px_-2px_hsl(var(--brand)/.35)]">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 6 6 .9-4.5 4.4 1 6.2L12 16.8 6.5 19.5l1-6.2L3 8.9 9 8z"/></svg>
                </div>
                <div>
                    <p class="text-[13px] font-bold leading-tight">PHEOC Command</p>
                    <p class="text-[10px] text-brand font-semibold tracking-[.14em] uppercase leading-tight">Uganda · National</p>
                </div>
            </div>

            <header class="mb-6">
                <p class="text-[11px] font-semibold uppercase tracking-[.14em] text-muted-foreground/70">Sign in</p>
                <h2 class="mt-1 text-[22px] font-bold tracking-tight leading-tight">Welcome back</h2>
                <p class="mt-1.5 text-[13px] text-muted-foreground leading-relaxed">
                    Use your PHEOC email or username to continue.
                </p>
            </header>

            {{-- Flash messages --}}
            @if (session('status'))
                <div class="alert alert-success mb-4">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4"><path d="M5 13l4 4L19 7"/></svg>
                    <div><p class="alert-description">{{ session('status') }}</p></div>
                </div>
            @endif

            <form method="POST" action="{{ url('/login') }}" class="space-y-4"
                  x-data="{ show: false, submitting: false }"
                  @submit="submitting = true">
                @csrf

                <div>
                    <label class="label flex items-center justify-between">
                        <span>Email or username</span>
                        @error('identifier')
                            <span class="text-[11px] text-critical">{{ $message }}</span>
                        @enderror
                    </label>
                    <input type="text"
                           name="identifier"
                           required
                           autofocus
                           autocomplete="username"
                           value="{{ old('identifier', request()->cookie('admin_last_email')) }}"
                           class="input mt-1.5 @error('identifier') border-critical focus-visible:ring-critical/50 @enderror"
                           placeholder="you@moh.gov.zm">
                </div>

                <div>
                    <label class="label flex items-center justify-between">
                        <span>Password</span>
                        @error('password')
                            <span class="text-[11px] text-critical">{{ $message }}</span>
                        @enderror
                    </label>
                    <div class="relative mt-1.5">
                        <input :type="show ? 'text' : 'password'"
                               name="password"
                               required
                               autocomplete="current-password"
                               class="input pr-10 @error('password') border-critical focus-visible:ring-critical/50 @enderror"
                               placeholder="••••••••">
                        <button type="button"
                                @click="show = !show"
                                class="absolute right-2 top-1/2 -translate-y-1/2 inline-flex h-7 w-7 items-center justify-center rounded-md text-muted-foreground hover:bg-muted/60 hover:text-foreground"
                                aria-label="Toggle password visibility">
                            <svg x-show="!show"  class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg x-show="show" x-cloak class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17.94 17.94A10.06 10.06 0 0112 20c-7 0-11-8-11-8a18.43 18.43 0 014.42-5.94M9.9 4.24A10.9 10.9 0 0112 4c7 0 11 8 11 8a18.3 18.3 0 01-2.16 3.19m-6.72-1.07a3 3 0 11-4.24-4.24M1 1l22 22"/></svg>
                        </button>
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                        <input type="checkbox" name="remember" value="1" class="h-4 w-4 rounded border-input text-brand focus:ring-ring/60">
                        <span class="text-[12.5px] text-foreground/80">Keep me signed in</span>
                    </label>
                    <span class="text-[11.5px] text-muted-foreground/60">Reset via National Admin</span>
                </div>

                <button type="submit"
                        :disabled="submitting"
                        class="w-full btn btn-brand h-11 text-[13.5px] font-semibold shadow-[0_4px_14px_-3px_hsl(var(--brand)/.40)] relative">
                    <span x-show="!submitting" class="inline-flex items-center gap-1.5">
                        Sign in
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                    </span>
                    <span x-show="submitting" class="inline-flex items-center gap-2">
                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 11-18 0"/></svg>
                        Signing in…
                    </span>
                </button>
            </form>

            {{-- Footer info --}}
            <div class="mt-8 text-[11.5px] text-muted-foreground/70 leading-relaxed">
                <p>
                    <span class="font-semibold text-foreground/80">Access tiers:</span>
                    <span class="font-mono text-[10.5px]">NATIONAL_ADMIN</span> sees everything ·
                    <span class="font-mono text-[10.5px]">PHEOC_OFFICER</span> sees their province ·
                    <span class="font-mono text-[10.5px]">DISTRICT_SUPERVISOR</span> sees their district.
                </p>
                <p class="mt-2 text-[10.5px] text-muted-foreground/50">
                    Every write is audited · Scope enforced server-side · RBAC per IHR-2005 §D.5.
                </p>
            </div>
        </div>
    </main>

</div>

<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
